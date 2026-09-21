<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Désignation du JA par le club (EN25).
 *
 * Page PUBLIQUE, sans authentification — lien tokenisé `?renc=TOKEN`
 * (Obfuscator de l'Id_Rencontre) envoyé au correspondant du club recevant
 * depuis EN14 (bouton « Demander le JA au club », message système n°7).
 *
 * Le correspondant choisit dans une liste déroulante le juge-arbitre qui
 * dirigera la rencontre — uniquement les JA actifs rattachés au club,
 * triés alphabétiquement. La réponse
 * crée une `nomination` (Peage/Kilometre/Defiscalisation = 0, Valide = 1,
 * EmailEnvoye = 0) pour l'arbitrage club.
 *
 * À renseigner dans les 5 jours qui suivent la rencontre : au-delà, simple
 * avertissement, la saisie reste possible.
 */
class ArbitreClubController extends BaseController
{
    private const DELAI_JOURS = 5;

    /**
     * Condition SQL (table `ja` non aliasée) excluant les JA déjà nommés ce jour-là sur 2 autres
     * rencontres, ou sur une rencontre d'un autre club recevant. Paramètres : voir paramsJaLibre().
     */
    private const SQL_JA_LIBRE = "AND NOT EXISTS (
        SELECT 1 FROM nomination nj
        JOIN disponible dj ON dj.Id_Disponible = nj.Id_Disponible
        JOIN rencontre  rj ON rj.Id_Rencontre  = nj.Id_Rencontre
        JOIN equipe     ej ON ej.Id_Equipe     = rj.Id_EquipeDom
        WHERE dj.Id_JA = ja.Id_JA AND nj.Id_Rencontre <> ? AND rj.Date = ?
        GROUP BY dj.Id_JA
        HAVING COUNT(*) >= 2 OR SUM(ej.Id_Club <> ?) > 0
    )";

    /** Paramètres de SQL_JA_LIBRE pour la rencontre $ctx (contexteRencontre()). */
    private function paramsJaLibre(array $ctx): array
    {
        return [$ctx['Id_Rencontre'], $ctx['Date'], $ctx['Id_Club']];
    }

    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] arbitre_club PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données.']);
        }
    }

    /** Id_Rencontre depuis le token `?renc=` (ou `renc` en POST), ou 0. */
    private function idRencontre(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $id = $this->obf->deobfuscate($raw);

        return $id > 0 ? $id : 0;
    }

    /** Contexte rencontre (équipes, salle, club recevant + correspondant). */
    private function contexteRencontre(\PDO $pdo, int $idRencontre): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                    ed.Division, ed.Id_Club, ed.Nom AS NomDom, ev.Nom AS NomExt,
                    dv.Nom AS DivisionNom,
                    cl.Nom AS NomClub, cl.CorNom, cl.CorEmail,
                    COALESCE(sr.Nom, sc.Nom)     AS SalleNom,
                    COALESCE(sr.Adresse, sc.Adresse) AS SalleAdresse,
                    COALESCE(sr.Cp, sc.Cp)       AS SalleCp,
                    COALESCE(sr.Ville, sc.Ville) AS SalleVille
             FROM rencontre r
             JOIN equipe   ed ON ed.Id_Equipe = r.Id_EquipeDom
             LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
             LEFT JOIN division dv ON dv.Division = ed.Division
             LEFT JOIN club cl ON cl.Id_Club = ed.Id_Club
             LEFT JOIN salle sr ON sr.Id_Salle = r.id_Salle
             LEFT JOIN salle sc ON sc.Id_Club = ed.Id_Club AND sc.EstPrincipale = 1
             WHERE r.Id_Rencontre = ?"
        );
        $stmt->execute([$idRencontre]);
        $r = $stmt->fetch();

        return $r ?: null;
    }

    /**
     * JA proposables : uniquement les JA actifs rattachés au club recevant,
     * triés Nom/Prénom, hors JA que la règle « 2 nominations max par jour, sur le même
     * club recevant » (cf. EN14) interdit de nommer sur cette rencontre.
     * @return array<int,array{Id_JA:int,Nom:string,Prenom:string,EstClub:int}>
     */
    private function listeJa(\PDO $pdo, array $ctx): array
    {
        $stmt = $pdo->prepare(
            "SELECT Id_JA, Nom, Prenom, 1 AS EstClub
             FROM ja
             WHERE COALESCE(Actif, 1) = 1 AND Id_Club = ? " . self::SQL_JA_LIBRE . "
             ORDER BY Nom, Prenom"
        );
        $stmt->execute([$ctx['Id_Club'], ...$this->paramsJaLibre($ctx)]);

        return $stmt->fetchAll();
    }

    private function enRetard(string $dateRencontre): bool
    {
        return strtotime($dateRencontre . ' +' . self::DELAI_JOURS . ' days') < strtotime(date('Y-m-d'));
    }

    private function estJaActif(\PDO $pdo, int $idJa): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ja WHERE Id_JA = ? AND COALESCE(Actif, 1) = 1');
        $stmt->execute([$idJa]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Recherche de JA « hors club » (option de #sel-ja) : n'importe quel JA
     * actif de la base, par nom/prénom — pas seulement ceux du club recevant.
     */
    public function rechercherJa(): ResponseInterface
    {
        return $this->tryJson(function () {
            $nom = trim((string) $this->request->getGet('nom'));
            if ($nom === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom manquant.']);
            }

            $pdo = getPDO();
            $id  = $this->idRencontre((string) $this->request->getGet('renc'));
            $ctx = $id ? $this->contexteRencontre($pdo, $id) : null;

            $stmt = $pdo->prepare(
                "SELECT Id_JA, Nom, Prenom
                 FROM ja
                 WHERE COALESCE(Actif, 1) = 1
                   AND (CONCAT(Nom, ' ', Prenom) LIKE ? OR CONCAT(Prenom, ' ', Nom) LIKE ?)
                   " . ($ctx ? self::SQL_JA_LIBRE : '') . "
                 ORDER BY Nom, Prenom
                 LIMIT 10"
            );
            $like = '%' . $nom . '%';
            $stmt->execute([$like, $like, ...($ctx ? $this->paramsJaLibre($ctx) : [])]);

            return $this->response->setJSON(['ok' => true, 'jas' => $stmt->fetchAll()]);
        });
    }

    public function index()
    {
        demarrerSessionNijac();

        $pdo = getPDO();
        $id  = $this->idRencontre($this->request->getGet('renc') ?? '');

        $ctx    = $id ? $this->contexteRencontre($pdo, $id) : null;
        $data   = ['erreur' => '', 'ctx' => null, 'jas' => [], 'enRetard' => false, 'dejaFait' => null, 'token' => trim($this->request->getGet('renc') ?? '')];

        if (!$ctx) {
            $data['erreur'] = 'Lien invalide ou rencontre introuvable.';
        } else {
            $dispo = $pdo->prepare(
                "SELECT CONCAT(j.Prenom, ' ', j.Nom) AS NomJa, n.EmailEnvoye
                 FROM nomination n
                 JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                 JOIN ja j ON j.Id_JA = d.Id_JA
                 WHERE n.Id_Rencontre = ?"
            );
            $dispo->execute([$id]);
            $data['dejaFait']  = $dispo->fetch() ?: null;
            $data['ctx']       = $ctx;
            $data['jas']       = $this->listeJa($pdo, $ctx);
            $data['enRetard']  = $this->enRetard($ctx['Date']);
        }

        session_write_close();
        unset($_SESSION);

        return view('arbitre_club_index', $data);
    }

    public function enregistrer(): ResponseInterface
    {
        demarrerSessionNijac();
        session_write_close();

        return $this->tryJson(function () {
            $pdo  = getPDO();
            $id   = $this->idRencontre($this->request->getPost('renc') ?? '');
            $idJa = (int) $this->request->getPost('id_ja');

            $ctx = $id ? $this->contexteRencontre($pdo, $id) : null;
            if (!$ctx) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre introuvable.']);
            }

            // Le JA choisi doit être un JA actif — du club (liste proposée) ou « hors
            // club » (recherché par nom via rechercherJa(), voir #sel-ja côté vue).
            if (!$idJa || !$this->estJaActif($pdo, $idJa)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Juge-arbitre invalide ou inactif.']);
            }

            // Même règle qu'EN14 : 2 nominations max par JA et par date, et uniquement sur le
            // même club recevant (un JA déjà nommé ce jour-là ailleurs ne peut pas être désigné).
            $jour = $pdo->prepare('
                SELECT COUNT(*) AS nb, COALESCE(SUM(ed2.Id_Club <> ?), 0) AS autres_clubs
                FROM nomination n
                JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre  r2 ON r2.Id_Rencontre = n.Id_Rencontre
                JOIN equipe    ed2 ON ed2.Id_Equipe   = r2.Id_EquipeDom
                WHERE d.Id_JA = ? AND n.Id_Rencontre != ? AND r2.Date = ?
            ');
            $jour->execute([$ctx['Id_Club'], $idJa, $id, $ctx['Date']]);
            $deja = $jour->fetch();
            if ((int) $deja['nb'] >= 2) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ce juge-arbitre a déjà 2 nominations ce jour-là. Merci d\'en choisir un autre.']);
            }
            if ((int) $deja['autres_clubs'] > 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ce juge-arbitre est déjà nommé ce jour-là sur une rencontre d\'un autre club. Merci d\'en choisir un autre.']);
            }

            // Nomination existante : refus si la convocation est déjà partie.
            $existe = $pdo->prepare('SELECT Id_Nomination, Id_Disponible, EmailEnvoye FROM nomination WHERE Id_Rencontre = ?');
            $existe->execute([$id]);
            $nom = $existe->fetch();
            if ($nom && (int) $nom['EmailEnvoye'] === 1) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'La convocation a déjà été envoyée pour cette rencontre. Contactez la CRA.']);
            }

            $pdo->beginTransaction();
            try {
                if ($nom) {
                    $pdo->prepare('DELETE FROM nomination WHERE Id_Nomination = ?')->execute([$nom['Id_Nomination']]);
                    $pdo->prepare('DELETE FROM disponible WHERE Id_Disponible = ? AND Id_Rencontre = ?')
                        ->execute([$nom['Id_Disponible'], $id]);
                }

                // disponible : réutilise la ligne (JA, rencontre) si elle existe.
                $d = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
                $d->execute([$idJa, $id]);
                $idDispo = $d->fetchColumn();
                $note    = 'Juge-arbitre désigné par le club le ' . date('d/m/Y');
                if ($idDispo) {
                    $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                        ->execute([$ctx['Date'], $note, $idDispo]);
                } else {
                    $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
                        ->execute([$idJa, $id, $ctx['Date'], $note]);
                    $idDispo = (int) $pdo->lastInsertId();
                }

                $pdo->prepare(
                    'INSERT INTO nomination (Id_Rencontre, Id_Disponible, Peage, Kilometre, Defiscalisation, DateSaisie, Valide, EmailEnvoye)
                     VALUES (?, ?, 0, 0, 0, CURDATE(), 0, 0)'
                )->execute([$id, $idDispo]);

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $ja = $pdo->prepare("SELECT CONCAT(Prenom, ' ', Nom) AS N FROM ja WHERE Id_JA = ?");
            $ja->execute([$idJa]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Merci, votre réponse a bien été enregistrée.', 'ja' => $ja->fetchColumn()]);
        });
    }
}
