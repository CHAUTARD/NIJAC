<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Suivi des nominations (EN28).
 *
 * Liste de toutes les rencontres du périmètre du nominateur (nommées ou non) avec les frais saisis
 * par le JA (péage, kilomètres, défiscalisation — renseignés depuis EN21) et un
 * bouton de rappel par ligne (masqué une fois les frais saisis) : renvoie au JA le modèle « Convocation » de la table
 * messagerie (lien EN21 vers sa convocation / note de frais).
 *
 * Accès Nominateur ou Administrateur (filtre "auth").
 */
class SuiviNominationController extends BaseController
{
    private const ID_MESSAGE_CONVOCATION = 3;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../vendor/autoload.php'; // PhpSpreadsheet (import de l'édition FFTT 131)
    }

    private function deptsAutorises(): array
    {
        return getDepartementsAutorises($_SESSION['utilisateur']['id_departement'] ?? null);
    }

    /** Journalise l'exception et renvoie un message générique (pas de SQL / chemin côté navigateur). */
    private function erreurTechnique(\Throwable $e, string $action, string $msg): ResponseInterface
    {
        error_log("[NIJAC] EN28 $action : " . $e->getMessage());

        return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, $msg)]);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('suivi_nomination_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'divisionNoms' => getDivisionNoms(),
        ]);
    }

    public function data(): ResponseInterface
    {
        try {
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'nominations' => []]);
            }

            $pdo  = getPDO();
            // Accusé de réception EN21 : colonne ajoutée par EA98, lue seulement si présente.
            $colAccuse = nominationAAccuseReception($pdo) ? 'n.AccuseReception' : 'NULL AS AccuseReception';
            // Repère « 131 » : colonne ajoutée par EA98, lue seulement si présente.
            $colAccuse .= nominationAF131($pdo) ? ', n.F131' : ', 0 AS F131';
            // Toutes les rencontres du périmètre (même critère qu'EN14 : club recevant), nommées ou non.
            // Une nomination au plus par rencontre (uq_nomination_rencontre) → une ligne par rencontre ;
            // sans nomination, les colonnes n.* / ja.* sont NULL (ligne « Aucun JA »).
            $stmt = $pdo->prepare('
                SELECT r.Id_Rencontre, n.Id_Nomination, ja.Id_JA, r.Date, r.Heure, r.ArbitrageCRA,
                       ed.Division, dv.Color AS DivisionColor, ed.Id_Club AS IdClubDom, ed.Nom AS NomDom, ee.Nom AS NomExt,
                       CONCAT(ja.Prenom, \' \', ja.Nom) AS NomJa, ja.Email AS EmailJa, ja.NumCompteEBP, ja.Telephone,
                       n.Peage, n.Kilometre, n.Defiscalisation, n.DateSaisie, n.Valide, ' . $colAccuse . ',
                       cl.Nom AS NomClub, cl.CorNom, cl.CorEmail, cl.RefNom, cl.RefMail
                FROM rencontre r
                JOIN equipe ed     ON ed.Id_Equipe    = r.Id_EquipeDom
                JOIN division dv   ON dv.Division     = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN club cl   ON cl.Id_Club     = ed.Id_Club   -- destinataires de « Relancer le club »
                -- Toute nomination est valide par défaut (Valide = 1) ; le OR couvre les
                -- arbitrages club restés à 0 avant la migration EA98.
                LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre AND (n.Valide = 1 OR r.ArbitrageCRA = 0)
                LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                LEFT JOIN ja           ON ja.Id_JA        = d.Id_JA
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY r.Date DESC, r.Heure, r.Id_Rencontre
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'nominations' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'data', 'Liste indisponible.');
        }
    }

    /** JA actifs du périmètre du nominateur, pour la liste déroulante de la popup de modification. */
    public function jaListe(): ResponseInterface
    {
        try {
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'ja' => []]);
            }
            $stmt = getPDO()->prepare('
                SELECT Id_JA, Nom, Prenom, Id_Club FROM ja
                WHERE JA1 = 1 AND CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY Nom, Prenom
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'ja' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'jaListe', 'Liste des JA indisponible.');
        }
    }

    /**
     * GET ja-disponibles?rencontre=ID : JA proposés pour nommer / modifier le JA d'une rencontre en arbitrage CRA.
     * Même filtre que les contrôles du clic (saisir()/modifier()), en une requête : JA actif du périmètre,
     * règle stricte d'EN14 (jaDisponiblePourNomination()) et règle du jour (controlerJourJa()).
     * Le JA actuellement nommé est toujours inclus (actuel = true), même s'il ne passe plus la règle.
     */
    public function jaDisponibles(): ResponseInterface
    {
        try {
            $ja = $this->listerJaDisponibles(getPDO(), $this->deptsAutorises(), (int) $this->request->getGet('rencontre'));

            return $this->response->setJSON(['ok' => true, 'ja' => $ja]);
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'jaDisponibles', 'Liste des JA disponibles indisponible.');
        }
    }

    /** Cœur de jaDisponibles() (sans requête HTTP). Refus métier : RuntimeException. */
    private function listerJaDisponibles(\PDO $pdo, array $depts, int $idRenc): array
    {
        if ($idRenc <= 0 || !$depts) {
            throw new \RuntimeException('Paramètres invalides.');
        }
        $in = implode(',', array_fill(0, count($depts), '?'));

        // Périmètre : même critère que saisir()/modifier() (club recevant).
        $stmt = $pdo->prepare("
            SELECT r.Date, ed.Id_Club AS IdClubDom, d.Id_JA AS IdJaActuel
            FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre
            LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
            WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN ($in)
        ");
        $stmt->execute([$idRenc, ...$depts]);
        $rc = $stmt->fetch();
        if (!$rc) {
            throw new \RuntimeException('Rencontre introuvable ou hors de votre périmètre.');
        }
        $actuel = (int) $rc['IdJaActuel'];

        // Réponse $r du JA sur la rencontre ou la journée : même condition que jaDisponiblePourNomination().
        $rep = fn(string $r) => "EXISTS (SELECT 1 FROM disponible dx WHERE dx.Id_JA = j.Id_JA AND dx.Reponse = '$r'
                AND (dx.Id_Rencontre = ? OR (dx.Id_Rencontre IS NULL AND dx.DateCompetition = ?)))";
        // Autres nominations du JA ce jour-là : même périmètre que controlerJourJa().
        $jour = 'FROM nomination n2
                 JOIN disponible d2 ON d2.Id_Disponible = n2.Id_Disponible
                 JOIN rencontre  r2 ON r2.Id_Rencontre  = n2.Id_Rencontre
                 JOIN equipe    ed2 ON ed2.Id_Equipe    = r2.Id_EquipeDom
                 WHERE d2.Id_JA = j.Id_JA AND n2.Id_Rencontre <> ? AND r2.Date = ?';
        $stmt = $pdo->prepare("
            SELECT j.Id_JA, j.Nom, j.Prenom, j.Id_Club
            FROM ja j
            WHERE j.Id_JA = ? OR (
                j.JA1 = 1 AND j.CodeDept IN ($in)
                AND {$rep('O')} AND NOT {$rep('N')}
                AND (SELECT COUNT(*) $jour) < 2
                AND NOT EXISTS (SELECT 1 $jour AND ed2.Id_Club <> ?)
            )
            ORDER BY j.Nom, j.Prenom
        ");
        $d = $rc['Date'];
        $stmt->execute([$actuel, ...$depts, $idRenc, $d, $idRenc, $d, $idRenc, $d, $idRenc, $d, $rc['IdClubDom']]);

        return array_map(fn($j) => $j + ['actuel' => (int) $j['Id_JA'] === $actuel], $stmt->fetchAll());
    }

    /**
     * Correction d'une nomination depuis EN28 : Arbitrage (rencontre.ArbitrageCRA), JA,
     * péage, kilomètres, défiscalisation, indicateur FFTT 131 (case de la popup, si colonne EA98). DateSaisie prend la date du jour (le compte EBP
     * se modifie dans la fiche JA, EN11). Le changement de JA garde la nomination (EmailEnvoye,
     * DateNomination inchangés ; Valide forcé à 1 — un JA nommé = nomination valide d'office) et applique la règle de 2 nominations max par JA et par jour.
     * Nouveau JA en arbitrage CRA (CRA→CRA, club→CRA) : règle stricte d'EN14 (controlerDispoCra()) ;
     * vers un arbitrage club (CRA→club, club→club) : souple (JA actif, disponible en 'P').
     */
    public function modifier(): ResponseInterface
    {
        try {
            $pdo      = getPDO();
            $depts    = $this->deptsAutorises();
            $idNom    = (int) $this->request->getPost('id_nomination');
            $idJa     = (int) $this->request->getPost('id_ja');
            $arbCra   = $this->request->getPost('arbitrage') === '0' ? 0 : 1;
            $peageRaw = trim((string) $this->request->getPost('peage'));
            $kmRaw    = trim((string) $this->request->getPost('km'));
            $defisc   = $this->request->getPost('defisc') ? 1 : 0;
            // Case « FFTT 131 » de la popup : null si absente de la requête (ancien comportement : remise à 0).
            $f131Raw  = $this->request->getPost('f131');

            if ($idNom <= 0 || $idJa <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            if ($f131Raw !== null && !in_array((string) $f131Raw, ['0', '1'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Indicateur FFTT 131 invalide (0 ou 1).']);
            }
            $f131 = $f131Raw === null ? 0 : (int) $f131Raw;
            $peage = str_replace(',', '.', $peageRaw === '' ? '0' : $peageRaw);
            if (!is_numeric($peage) || (float) $peage < 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Péage invalide.']);
            }
            if (!ctype_digit($kmRaw === '' ? '0' : $kmRaw)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Kilomètres invalides (entier positif).']);
            }
            $km = (int) $kmRaw;

            $stmt = $pdo->prepare('
                SELECT n.Id_Nomination, n.Id_Rencontre, n.Id_Disponible, d.Id_JA AS IdJaActuel, r.Date, ed.Id_Club AS IdClubDom
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre r  ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed    ON ed.Id_Equipe    = r.Id_EquipeDom
                WHERE n.Id_Nomination = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idNom, ...$depts]);
            $nom = $stmt->fetch();
            if (!$nom) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination introuvable.']);
            }

            $pdo->beginTransaction();
            try {
                $idDispo = (int) $nom['Id_Disponible'];
                if ($idJa !== (int) $nom['IdJaActuel']) {
                    $note = 'Juge-arbitre modifié depuis EN28 le ' . date('d/m/Y');
                    if ($arbCra === 1) {
                        // Arbitrage CRA demandé (CRA→CRA, club→CRA) : règle stricte d'EN14, ligne 'O' réutilisée.
                        $this->controlerDispoCra($pdo, $depts, $idJa, (int) $nom['Id_Rencontre'], $nom['Date']);
                        $this->controlerJourJa($pdo, $idJa, (int) $nom['Id_Rencontre'], $nom['Date'], $nom['IdClubDom']);
                        $idDispo = $this->disponibleOuiCra($pdo, $idJa, (int) $nom['Id_Rencontre'], $nom['Date'], $note);
                    } else {
                        $ja = $pdo->prepare('SELECT JA1 FROM ja WHERE Id_JA = ?');
                        $ja->execute([$idJa]);
                        if ((int) $ja->fetchColumn() !== 1) {
                            throw new \RuntimeException('Juge-arbitre introuvable ou inactif.');
                        }
                        $this->controlerJourJa($pdo, $idJa, (int) $nom['Id_Rencontre'], $nom['Date'], $nom['IdClubDom']);

                        // Arbitrage club (CRA→club, club→club) : disponible (JA, rencontre) réutilisée si le JA a répondu O/P,
                        // rouverte en P s'il avait répondu N, créée en P sinon.
                        $d = $pdo->prepare('SELECT Id_Disponible, Reponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
                        $d->execute([$idJa, $nom['Id_Rencontre']]);
                        $dispo = $d->fetch();
                        if ($dispo) {
                            $idDispo = (int) $dispo['Id_Disponible'];
                            if ($dispo['Reponse'] === 'N') {
                                $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), Note = ? WHERE Id_Disponible = ?")
                                    ->execute([$note, $idDispo]);
                            }
                        } else {
                            $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
                                ->execute([$idJa, $nom['Id_Rencontre'], $nom['Date'], $note]);
                            $idDispo = (int) $pdo->lastInsertId();
                        }
                    }

                    // Nouveau JA : l'accusé de réception de l'ancien JA n'est pas hérité (colonne EA98).
                    if (nominationAAccuseReception($pdo)) {
                        $pdo->prepare('UPDATE nomination SET AccuseReception = NULL WHERE Id_Nomination = ?')->execute([$idNom]);
                    }
                }

                $pdo->prepare('UPDATE rencontre SET ArbitrageCRA = ? WHERE Id_Rencontre = ?')->execute([$arbCra, $nom['Id_Rencontre']]);
                // FFTT 131 (colonne EA98) : la case de la popup fait foi ; absente de la requête → remise à 0.
                $setF131 = nominationAF131($pdo) ? ', F131 = ' . $f131 : '';
                $pdo->prepare("
                    UPDATE nomination SET Id_Disponible = ?, Peage = ?, Kilometre = ?, Defiscalisation = ?, DateSaisie = CURDATE(), Valide = 1$setF131
                    WHERE Id_Nomination = ?
                ")->execute([$idDispo, $peage, $km, $defisc, $idNom]);

                $pdo->commit();
            } catch (\RuntimeException $e) {
                $pdo->rollBack();

                return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Nomination modifiée.']);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'modifier', 'Modification impossible.');
        }
    }

    /**
     * Règle EN14/EN25 : 2 nominations max par JA et par date, et uniquement sur le même club
     * recevant. Lève une RuntimeException (message affichable) si $idJa ne peut pas être nommé.
     */
    private function controlerJourJa(\PDO $pdo, int $idJa, int $idRencontre, string $date, string $idClubDom): void
    {
        $nb = $pdo->prepare('
            SELECT COUNT(*) AS nb, COALESCE(SUM(ed2.Id_Club <> ?), 0) AS autres_clubs
            FROM nomination n
            JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
            JOIN rencontre r2  ON r2.Id_Rencontre = n.Id_Rencontre
            JOIN equipe   ed2  ON ed2.Id_Equipe   = r2.Id_EquipeDom
            WHERE d.Id_JA = ? AND n.Id_Rencontre != ? AND r2.Date = ?
        ');
        $nb->execute([$idClubDom, $idJa, $idRencontre, $date]);
        $deja = $nb->fetch();
        if ((int) $deja['nb'] >= 2) {
            throw new \RuntimeException('Ce JA a déjà 2 nominations ce jour-là (maximum).');
        }
        if ((int) $deja['autres_clubs'] > 0) {
            throw new \RuntimeException('Ce JA est déjà nommé ce jour-là sur une rencontre d\'un autre club.');
        }
    }

    /**
     * Rencontre sans nomination :
     *  - arbitrage club (ArbitrageCRA = 0), « Saisir le JA » : le nominateur enregistre le JA qui a
     *    officié, restée sans réponse du club. Même création qu'EN25 (ArbitreClubController::enregistrer) :
     *    disponible 'P' + nomination Valide = 1, EmailEnvoye = 0, frais + DateSaisie du jour ;
     *  - arbitrage CRA (ArbitrageCRA = 1), « Nommer un JA » : mêmes règles qu'EN14 (affecterJa), dont la
     *    disponibilité stricte — voir creerNominationCra(). Frais ignorés (saisis par le JA en EN21).
     * Aucun email envoyé.
     */
    public function saisir(): ResponseInterface
    {
        try {
            $peage = str_replace(',', '.', trim((string) $this->request->getPost('peage')) ?: '0');
            $kmRaw = trim((string) $this->request->getPost('km')) ?: '0';
            if (!is_numeric($peage) || (float) $peage < 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Péage invalide.']);
            }
            if (!ctype_digit($kmRaw)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Kilomètres invalides (entier positif).']);
            }

            $cra = $this->creerNomination(
                getPDO(),
                $this->deptsAutorises(),
                (int) $this->request->getPost('id_rencontre'),
                (int) $this->request->getPost('id_ja'),
                $peage,
                (int) $kmRaw,
                $this->request->getPost('defisc') ? 1 : 0
            );

            return $this->response->setJSON(['ok' => true, 'msg' => $cra ? 'Juge-arbitre nommé.' : 'Juge-arbitre enregistré.']);
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'saisir', 'Enregistrement impossible.');
        }
    }

    /**
     * Cœur de saisir() (sans requête HTTP). Refus métier : RuntimeException.
     * Renvoie true si la rencontre est en arbitrage CRA.
     */
    private function creerNomination(\PDO $pdo, array $depts, int $idRenc, int $idJa, string $peage, int $km, int $defisc): bool
    {
        if ($idRenc <= 0 || $idJa <= 0 || !$depts) {
            throw new \RuntimeException('Paramètres invalides.');
        }
        $in = implode(',', array_fill(0, count($depts), '?'));

        // Périmètre : même critère que data() (club recevant).
        $stmt = $pdo->prepare("
            SELECT r.Id_Rencontre, r.Date, r.ArbitrageCRA, ed.Id_Club AS IdClubDom,
                   (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNom
            FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN ($in)
        ");
        $stmt->execute([$idRenc, ...$depts]);
        $rc = $stmt->fetch();
        if (!$rc) {
            throw new \RuntimeException('Rencontre introuvable ou hors de votre périmètre.');
        }
        if ($rc['ArbitrageCRA'] === null) {
            throw new \RuntimeException('Type d\'arbitrage (CRA / Club) non renseigné pour cette rencontre.');
        }
        $cra = (int) $rc['ArbitrageCRA'] !== 0;
        if ((int) $rc['NbNom'] > 0) {
            throw new \RuntimeException('Un JA est déjà désigné pour cette rencontre : utilisez « Modifier ».');
        }

        $ja = $pdo->prepare("SELECT 1 FROM ja WHERE Id_JA = ? AND JA1 = 1 AND CodeDept IN ($in)");
        $ja->execute([$idJa, ...$depts]);
        if (!$ja->fetchColumn()) {
            throw new \RuntimeException('Juge-arbitre introuvable, inactif ou hors de votre périmètre.');
        }

        $pdo->beginTransaction();
        try {
            if ($cra) {
                $this->controlerDispoCra($pdo, $depts, $idJa, $idRenc, $rc['Date']);
            }
            $this->controlerJourJa($pdo, $idJa, $idRenc, $rc['Date'], $rc['IdClubDom']);

            if ($cra) {
                $this->creerNominationCra($pdo, $idJa, $idRenc, $rc['Date']);
                $pdo->commit();

                return true;
            }

            $idDispo = $this->disponibleClub($pdo, $idJa, $idRenc, $rc['Date'], 'Juge-arbitre saisi depuis EN28 (arbitrage club) le ' . date('d/m/Y'));

            // uq_nomination_rencontre bloque une saisie concurrente (club via EN25, autre nominateur).
            $pdo->prepare(
                'INSERT INTO nomination (Id_Rencontre, Id_Disponible, Peage, Kilometre, Defiscalisation, DateSaisie, Valide, EmailEnvoye)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), 1, 0)'
            )->execute([$idRenc, $idDispo, $peage, $km, $defisc]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new \RuntimeException('Un JA vient d\'être désigné pour cette rencontre : rechargez la liste.');
            }
            throw $e;
        }

        return false;
    }

    /**
     * Nomination d'un arbitrage CRA (règles d'EN14), après controlerDispoCra() dans la transaction :
     * la ligne 'O' est réutilisée / matérialisée (disponibleOuiCra()), puis même insertion qu'EN14.
     */
    private function creerNominationCra(\PDO $pdo, int $idJa, int $idRenc, string $date): void
    {
        $idDispo = $this->disponibleOuiCra($pdo, $idJa, $idRenc, $date, 'Juge-arbitre nommé depuis EN28 (arbitrage CRA) le ' . date('d/m/Y'));

        // Même insertion qu'EN14 (affecterNomination) : frais/DateSaisie laissés au JA (EN21).
        $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, DateNomination, Valide, EmailEnvoye) VALUES (?, ?, CURDATE(), 1, 0)')
            ->execute([$idRenc, $idDispo]);
    }

    /**
     * Contrôles d'EN14 (verifierEtNommer) à l'instant de l'écriture pour un JA nommé en arbitrage CRA,
     * dans la transaction : verrous rencontre / JA / disponible (FOR UPDATE, même ordre qu'EN14),
     * JA actif et du périmètre, puis disponibilité stricte (jaDisponiblePourNomination(), partagée).
     * Refus : RuntimeException (message affichable).
     */
    private function controlerDispoCra(\PDO $pdo, array $depts, int $idJa, int $idRenc, string $date): void
    {
        $pdo->prepare('SELECT Id_Rencontre FROM rencontre WHERE Id_Rencontre = ? FOR UPDATE')->execute([$idRenc]);
        $st = $pdo->prepare('SELECT JA1, CodeDept FROM ja WHERE Id_JA = ? FOR UPDATE');
        $st->execute([$idJa]);
        $ja = $st->fetch();
        $pdo->prepare('
            SELECT Id_Disponible FROM disponible
            WHERE Id_JA = ? AND (Id_Rencontre = ? OR (Id_Rencontre IS NULL AND DateCompetition = ?))
            FOR UPDATE
        ')->execute([$idJa, $idRenc, $date]);

        if (!$ja || (int) $ja['JA1'] !== 1 || !in_array((string) $ja['CodeDept'], $depts, true)) {
            throw new \RuntimeException('Juge-arbitre introuvable, inactif ou hors de votre périmètre.');
        }
        $err = jaDisponiblePourNomination($pdo, $idJa, $idRenc, $date);
        if ($err !== null) {
            throw new \RuntimeException($err);
        }
    }

    /**
     * Id_Disponible 'O' du JA pour la rencontre (garanti par controlerDispoCra()) : ligne rencontre 'O'
     * réutilisée, sinon matérialisée en 'O' datée de la réponse 'O' de la journée, comme EN14
     * (NominationController::resoudreDisponible()) ; une ligne rencontre existante ('P') est mise à jour.
     * $trace (annulation EN30) : ['cree' => true] si ligne insérée, sinon ['cree' => false, 'avant' => valeurs
     * Reponse / DateReponse / DateCompetition / Note avant mise à jour, ou null si ligne réutilisée telle quelle].
     */
    private static function disponibleOuiCra(\PDO $pdo, int $idJa, int $idRenc, string $date, string $note, ?array &$trace = null): int
    {
        $d = $pdo->prepare('SELECT Id_Disponible, Reponse, DateReponse, DateCompetition, Note FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $d->execute([$idJa, $idRenc]);
        $dispo = $d->fetch(\PDO::FETCH_ASSOC);
        if ($dispo && $dispo['Reponse'] === 'O') {
            $trace = ['cree' => false, 'avant' => null];

            return (int) $dispo['Id_Disponible'];
        }

        $j = $pdo->prepare("SELECT DateReponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre IS NULL AND DateCompetition = ? AND Reponse = 'O' LIMIT 1");
        $j->execute([$idJa, $date]);
        $dRep = $j->fetchColumn() ?: null;
        if ($dispo) {
            $pdo->prepare("UPDATE disponible SET Reponse = 'O', DateReponse = COALESCE(?, CURDATE()), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                ->execute([$dRep, $date, $note, $dispo['Id_Disponible']]);
            $trace = ['cree' => false, 'avant' => array_diff_key($dispo, ['Id_Disponible' => 0])];

            return (int) $dispo['Id_Disponible'];
        }
        $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'O', COALESCE(?, CURDATE()), ?)")
            ->execute([$idJa, $idRenc, $date, $dRep, $note]);
        $trace = ['cree' => true, 'avant' => null];

        return (int) $pdo->lastInsertId();
    }

    /**
     * Arbitrage club : disponible (JA, rencontre) réutilisée si elle existe (comme EN25), mise en 'P' ; sinon créée en 'P'.
     * $trace : voir disponibleOuiCra().
     */
    private static function disponibleClub(\PDO $pdo, int $idJa, int $idRenc, string $date, string $note, ?array &$trace = null): int
    {
        $d = $pdo->prepare('SELECT Id_Disponible, Reponse, DateReponse, DateCompetition, Note FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $d->execute([$idJa, $idRenc]);
        $dispo   = $d->fetch(\PDO::FETCH_ASSOC);
        $idDispo = $dispo ? (int) $dispo['Id_Disponible'] : 0;
        if ($idDispo) {
            $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                ->execute([$date, $note, $idDispo]);
            $trace = ['cree' => false, 'avant' => array_diff_key($dispo, ['Id_Disponible' => 0])];

            return $idDispo;
        }
        $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
            ->execute([$idJa, $idRenc, $date, $note]);
        $trace = ['cree' => true, 'avant' => null];

        return (int) $pdo->lastInsertId();
    }

    // ── Mise à jour depuis l'édition FFTT 131 « Activités détaillées des arbitres » ──

    /** Écart toléré (jours) entre la date du fichier et celle de la rencontre en base (rencontre avancée / reportée). */
    private const F131_TOLERANCE_JOURS = 7;

    /** Seuls organismes retenus (colonne N « Organisme Division » de l'édition 131, égalité exacte après trim, sans casse). */
    private const ORGANISMES_131 = ['L09', 'FEDE'];

    /** Taille maximale d'une édition 131 téléversée (octets). */
    private const F131_TAILLE_MAX = 5 * 1024 * 1024;

    /** Nom accepté pour un fichier 131 du dépôt serveur (les temporaires Excel « ~$… » sont exclus par construction). */
    private const F131_MOTIF_NOM = '/^131_[0-9A-Za-z_-]{1,64}\.xlsx$/';

    /**
     * Dossier de dépôt Importation/ (racine du projet, jamais servi en HTTP : .htaccess « RewriteRule ^Importation/ - [F,L] »),
     * chemin canonique (realpath) ; créé s'il manque. null si inaccessible.
     */
    private static function dossierImportation(): ?string
    {
        $dir = __DIR__ . '/../../../Importation';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $reel = realpath($dir);

        return $reel === false ? null : $reel;
    }

    /**
     * Fichiers 131_*.xlsx du dépôt Importation/ (nom, chemin canonique, taille, date de modification), du plus récent
     * au plus ancien (date de modification). Chemins construits côté serveur uniquement : glob() sur un motif fixe,
     * nom contrôlé (F131_MOTIF_NOM), fichier ordinaire (pas de lien symbolique) dont le realpath reste dans Importation/.
     */
    private static function fichiers131(): array
    {
        $dir = self::dossierImportation();
        if ($dir === null) {
            return [];
        }
        $res = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '131_*.xlsx') ?: [] as $f) {
            $nom  = basename($f);
            $reel = realpath($f);
            if (!preg_match(self::F131_MOTIF_NOM, $nom) || is_link($f) || $reel === false || !is_file($reel)
                || dirname($reel) !== $dir) {
                continue;
            }
            $res[] = ['nom' => $nom, 'chemin' => $reel, 'taille' => (int) filesize($reel), 'mtime' => (int) filemtime($reel)];
        }
        usort($res, fn ($a, $b) => [$b['mtime'], $b['nom']] <=> [$a['mtime'], $a['nom']]);

        return $res;
    }

    /**
     * Infos publiques (sans chemin) des fichiers 131 du dépôt, du plus récent au plus ancien : fichiers[] = {nom, taille,
     * date} (le premier est celui qu'analyse f131Apercu()), fichier = le plus récent ou null.
     */
    private static function infoFichier131(): array
    {
        $liste = array_map(
            fn ($f) => ['nom' => $f['nom'], 'taille' => $f['taille'], 'date' => date('d/m/Y H:i:s', $f['mtime'])],
            self::fichiers131()
        );

        return ['fichiers' => $liste, 'fichier' => $liste[0] ?? null];
    }

    /** GET suivi-nomination/f131/fichier : tous les fichiers 131_*.xlsx d'Importation/, plus récent d'abord (étape 2 de la popup). */
    public function f131Fichier(): ResponseInterface
    {
        return $this->response->setJSON(['ok' => true] + self::infoFichier131());
    }

    /**
     * POST suivi-nomination/f131/supprimer (`nom`) : supprime UN fichier 131 d'Importation/. Le nom reçu n'est jamais
     * utilisé comme chemin : il doit respecter F131_MOTIF_NOM puis être retrouvé à l'identique dans la liste calculée
     * côté serveur (fichiers131() : motif fixe, pas de lien, realpath directement dans Importation/) ; c'est le chemin
     * de cette liste qui est supprimé. Tout autre nom (~$*, autre motif, chemin, fichier absent) est refusé.
     */
    public function f131Supprimer(): ResponseInterface
    {
        $nom = (string) $this->request->getPost('nom');
        if (!preg_match(self::F131_MOTIF_NOM, $nom)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de fichier 131 invalide.']);
        }
        $cible = null;
        foreach (self::fichiers131() as $f) {
            if ($f['nom'] === $nom) {
                $cible = $f['chemin'];
                break;
            }
        }
        if ($cible === null) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Fichier introuvable dans Importation : $nom."] + self::infoFichier131());
        }
        if (!@unlink($cible)) {
            clearstatcache();

            return $this->response->setJSON(['ok' => false,
                'msg' => "Suppression impossible de $nom : fichier verrouillé (ouvert dans Excel ?) ou droits insuffisants."] + self::infoFichier131());
        }
        clearstatcache();

        return $this->response->setJSON(['ok' => true, 'msg' => "Fichier supprimé : $nom"] + self::infoFichier131());
    }

    /**
     * POST suivi-nomination/f131/televerser : fichier `xlsx` (multipart) enregistré dans Importation/ sous un nom
     * généré par le serveur, 131_<jjmmaaaaHHMMSS>.xlsx (jamais le nom client ; extension forcée .xlsx, donc jamais
     * exécutable). Contrôles : upload valide, ≤ F131_TAILLE_MAX, extension client .xlsx, type MIME détecté (finfo)
     * xlsx/zip et classeur reconnu par le lecteur Xlsx de PhpSpreadsheet. Aucune analyse ici (voir f131Apercu()).
     * Les autres 131_*.xlsx ne sont pas touchés (suppression une à une : f131Supprimer()).
     */
    public function f131Televerser(): ResponseInterface
    {
        $file = $this->request->getFile('xlsx');
        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
        }
        if ($file->getSize() > self::F131_TAILLE_MAX) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier trop volumineux (5 Mo maximum).']);
        }
        if (strtolower($file->getClientExtension()) !== 'xlsx'
            || !in_array($file->getMimeType(), ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format Excel .xlsx est accepté.']);
        }
        try {
            if (!\PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->canRead($file->getTempName())) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Classeur Excel .xlsx illisible.']);
            }
            $dir = self::dossierImportation();
            if ($dir === null || !is_writable($dir)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Dossier Importation inaccessible en écriture.']);
            }
            $nom = '131_' . date('dmYHis') . '.xlsx';
            if (file_exists($dir . DIRECTORY_SEPARATOR . $nom)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Un fichier vient d\'être déposé à la même seconde : réessayez.']);
            }
            $file->move($dir, $nom, false);   // course à la même seconde : CI4 suffixe « _1 » (toujours 131_*.xlsx)
            if (!$file->hasMoved()) {
                throw new \RuntimeException('Enregistrement du fichier impossible.');
            }
            $nom = $file->getName();
            @chmod($dir . DIRECTORY_SEPARATOR . $nom, 0640);

            return $this->response->setJSON(['ok' => true, 'msg' => "Fichier déposé ($nom)"] + self::infoFichier131());
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'téléversement 131', 'Enregistrement du fichier impossible.');
        }
    }

    /**
     * POST suivi-nomination/f131/apercu (sans paramètre) : analyse du fichier 131_*.xlsx le PLUS RÉCENT d'Importation/
     * (jamais un fichier ni un nom envoyé par le navigateur) + chargement incrémental de la table de travail f131_travail
     * (nouvelles lignes seulement, jamais vidée ; seule table écrite, AUCUNE nomination) ; le navigateur ouvre ensuite EN30, où chaque ligne applicable se
     * valide une à une (ControleF131Controller::valider()).
     */
    public function f131Apercu(): ResponseInterface
    {
        $tous = self::fichiers131();
        if (!$tous) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier 131_*.xlsx dans Importation : déposez-le d\'abord (« Parcourir… »).']);
        }
        $serveur = $tous[0];
        if ($serveur['taille'] > self::F131_TAILLE_MAX) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier trop volumineux (5 Mo maximum).']);
        }
        try {
            $pdo = getPDO();
            if (!nominationAF131($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Colonne nomination.F131 absente : chargez l\'écran EA98 (Administration BDD).']);
            }
            if (!self::f131TravailExiste($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Table f131_travail absente : chargez l\'écran EA98 (Administration BDD).']);
            }
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun département autorisé.']);
            }
            try {
                $lu = self::lire131($serveur['chemin']);
            } catch (\Throwable $e) {
                error_log('[NIJAC] EN28 import 131 : ' . get_class($e));   // jamais le contenu du fichier (données personnelles)

                return $this->response->setJSON(['ok' => false, 'msg' => $e instanceof \RuntimeException ? $e->getMessage() : 'Classeur illisible.']);
            }

            $res = $this->traiter131($pdo, $depts, $lu);

            return $this->response->setJSON(['ok' => true, 'fichier' => $serveur['nom']] + $res);
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'import 131', 'Chargement impossible : table de travail inchangée.');
        }
    }

    /**
     * Lit l'édition 131 (sans base). Ligne d'en-tête repérée par A = « N° Licence » et G = « Arb/JA »
     * (ligne 2 dans l'export FFTT, la ligne 1 porte le titre). Colonnes utilisées : A licence, B nom, C prénom,
     * G fonction (seul « JA » est retenu), I date JJ/MM/AAAA, L équipe 1 (recevante), M équipe 2,
     * N organisme (seuls ORGANISMES_131 sont retenus, égalité exacte sans casse), O division.
     * Retourne nbLignes, vides, ignores[valeur de G] = n, horsOrganisme (lignes JA dont N ∉ ORGANISMES_131),
     * dateMin/dateMax (AAAA-MM-JJ, toutes lignes non vides), lignes[] (G = JA et N retenu) et
     * toutes[] : chaque ligne retenue (même test que lignes[] ; ligne Excel — informative, stockée dans f131_travail.Ligne
     * à l'insertion seulement —, date convertie, col[A..P] brutes
     * normalisées) → table de travail f131_travail. nbLignes = Σ ignores + horsOrganisme + count(lignes).
     * Public static : testable hors CodeIgniter.
     */
    public static function lire131(string $chemin): array
    {
        $lecteur = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');   // format imposé, pas de détection
        $lecteur->setReadDataOnly(true);
        $feuille = $lecteur->load($chemin)->getSheet(0);
        $txt     = fn ($v) => trim(preg_replace('/\s+/u', ' ', (string) $v));
        $maxR    = $feuille->getHighestDataRow();

        $entete = 0;
        for ($r = 1; $r <= min(10, $maxR); $r++) {
            if (CraDispoController::norm((string) $feuille->getCell('A' . $r)->getValue()) === 'n licence'
                && CraDispoController::norm((string) $feuille->getCell('G' . $r)->getValue()) === 'arb ja') {
                $entete = $r;
                break;
            }
        }
        if (!$entete) {
            throw new \RuntimeException('Ce fichier n\'est pas une édition 131 : en-têtes « N° Licence » (colonne A) et « Arb/JA » (colonne G) introuvables.');
        }

        $res = ['nbLignes' => 0, 'vides' => 0, 'ignores' => [], 'horsOrganisme' => 0, 'dateMin' => null, 'dateMax' => null, 'lignes' => [], 'toutes' => []];
        foreach ($feuille->rangeToArray("A" . ($entete + 1) . ":P$maxR", null, false, false, true) as $r => $c) {
            $c = array_map($txt, $c);
            if (implode('', $c) === '') {
                $res['vides']++;
                continue;
            }
            $res['nbLignes']++;
            $date = self::date131($c['I']);
            if ($date !== null) {
                $res['dateMin'] = min($res['dateMin'] ?? $date, $date);
                $res['dateMax'] = max($res['dateMax'] ?? $date, $date);
            }
            if (strtoupper($c['G']) !== 'JA') {
                $g = $c['G'] === '' ? '(vide)' : $c['G'];
                $res['ignores'][$g] = ($res['ignores'][$g] ?? 0) + 1;
                continue;
            }
            if (!in_array(strtoupper($c['N']), self::ORGANISMES_131, true)) {   // ni f131_travail, ni analyse, ni EN30
                $res['horsOrganisme']++;
                continue;
            }
            $res['toutes'][] = ['ligne' => $r, 'date' => $date, 'col' => $c];   // col['I'] = date brute
            $lic = preg_replace('/\s+/', '', $c['A']);
            $res['lignes'][] = [
                'id'      => count($res['lignes']),   // rang dans le fichier (identifiant d'analyse, pas la ligne Excel)
                'licence' => ctype_digit($lic) && (int) $lic > 0 ? (int) $lic : null,   // ja.Id_JA = n° de licence (entier)
                'brut'    => $c['A'],
                'nom'     => trim($c['B'] . ' ' . $c['C']),
                'date'    => $date,
                'dateTxt' => $c['I'],
                'dom'     => $c['L'],
                'ext'     => $c['M'],
                'division' => $c['O'],
            ];
        }
        ksort($res['ignores']);

        return $res;
    }

    /** Date de l'édition 131 : texte JJ/MM/AAAA (export FFTT) ou date Excel numérique → AAAA-MM-JJ, null si illisible. */
    private static function date131(string $v): ?string
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (is_numeric($v) && (float) $v > 1) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }

        return null;
    }

    /**
     * Analyse les lignes JA de l'édition 131 contre la base (compte rendu chiffré, voir analyser131()) puis recharge
     * la table de travail f131_travail dans une transaction (chargement incrémental sans vidage, voir rechargerF131Travail() :
     * table inchangée en cas d'erreur). Le compte rendu porte sur les lignes du fichier ; retenues = ajoutees + dejaPresentes.
     * Aucune nomination n'est écrite ici : la validation se fait ligne par ligne dans EN30.
     */
    private function traiter131(\PDO $pdo, array $depts, array $lu): array
    {
        $an = self::analyser131($pdo, $depts, $lu['lignes'], $lu['dateMin'], $lu['dateMax']);

        $pdo->beginTransaction();
        try {
            $charge = $this->rechargerF131Travail($pdo, $lu['toutes']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'nbLignes' => $lu['nbLignes'], 'vides' => $lu['vides'], 'ignores' => $lu['ignores'],
            'horsOrganisme' => $lu['horsOrganisme'], 'organismes' => self::ORGANISMES_131,
            'dateMin'  => $lu['dateMin'], 'dateMax' => $lu['dateMax'],
            'retenues' => count($lu['lignes']),
            'ajoutees' => $charge['ajoutees'], 'dejaPresentes' => $charge['dejaPresentes'],
            'parCategorie' => $an['parCat'],
        ] + $an['nb'];
    }

    /**
     * Écrit UNE opération de analyser131()['ops'] (validation d'une ligne dans EN30), DANS la transaction de l'appelant :
     * 'creer' → disponible ('O' si arbitrage CRA, 'P' si club) + nomination (DateNomination du jour, Valide = 1,
     * EmailEnvoye = 0, F131 = 1) ; 'remplacer' → nouveau JA (Valide = 1, F131 = 1, AccuseReception NULL, frais et DateSaisie conservés)
     * seulement si la nomination n'a pas changé depuis l'analyse (WHERE Id_Disponible = ancien), sinon RuntimeException.
     * Création seulement : Peage = 0, Kilometre = 0, Defiscalisation = 0, DateSaisie = CURDATE() (même convention
     * qu'EN25 ArbitreClubController / EN28 modifier()).
     * Création concurrente : PDOException 23000 (uq_nomination_rencontre), à traiter par l'appelant (rollback).
     * Retourne le descripteur d'annulation (EN30 « Annuler la dernière validation », ControleF131Controller::annuler()) :
     * ['op', 'idNomination', 'idDispo' (Id_Disponible posé), 'dispo' (trace de disponibleOuiCra() / disponibleClub()),
     *  'avant' (remplacement : Id_Disponible, Valide, F131, EmailEnvoye [, AccuseReception] de la nomination avant l'UPDATE ; création : null)].
     */
    public static function ecrireOperation131(\PDO $pdo, string $op, array $rc, int $idJa): array
    {
        $note    = 'Juge-arbitre mis à jour depuis le fichier FFTT 131 le ' . date('d/m/Y');
        $idRenc  = (int) $rc['Id_Rencontre'];
        $trace   = null;
        $idDispo = +$rc['ArbitrageCRA']
            ? self::disponibleOuiCra($pdo, $idJa, $idRenc, $rc['Date'], $note, $trace)
            : self::disponibleClub($pdo, $idJa, $idRenc, $rc['Date'], $note, $trace);
        if ($op === 'creer') {
            $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, Peage, Kilometre, Defiscalisation, DateSaisie, DateNomination, Valide, EmailEnvoye, F131)
                           VALUES (?, ?, 0, 0, 0, CURDATE(), CURDATE(), 1, 0, 1)')
                ->execute([$idRenc, $idDispo]);

            return ['op' => 'creer', 'idNomination' => (int) $pdo->lastInsertId(), 'idDispo' => $idDispo, 'dispo' => $trace, 'avant' => null];
        }
        $aAccuse = nominationAAccuseReception($pdo);
        $av = $pdo->prepare('SELECT Id_Disponible, Valide, F131, EmailEnvoye' . ($aAccuse ? ', AccuseReception' : '') . ' FROM nomination WHERE Id_Nomination = ? FOR UPDATE');
        $av->execute([$rc['Id_Nomination']]);
        $avant = $av->fetch(\PDO::FETCH_ASSOC) ?: null;
        $razAccuse = $aAccuse ? ', AccuseReception = NULL' : '';
        $upd = $pdo->prepare("UPDATE nomination SET Id_Disponible = ?, Valide = 1, F131 = 1$razAccuse
                              WHERE Id_Nomination = ? AND Id_Disponible = ?");
        $upd->execute([$idDispo, $rc['Id_Nomination'], $rc['Id_Disponible']]);
        if ($upd->rowCount() !== 1) {
            throw new \RuntimeException('La nomination a changé pendant la validation : aucune modification enregistrée, relancez l\'analyse.');
        }

        return ['op' => 'remplacer', 'idNomination' => (int) $rc['Id_Nomination'], 'idDispo' => $idDispo, 'dispo' => $trace, 'avant' => $avant];
    }

    /**
     * Analyse SANS écriture des lignes JA de l'édition 131 (format de lire131()['lignes']) contre la base.
     * Partagée par le chargement EN28 (traiter131 : compte rendu) et le contrôle EN30 (ControleF131Controller : rapport
     * par ligne et validation ligne par ligne, qui n'applique que l'opération ops[id] de la ligne validée).
     * Clé de rapprochement ligne → rencontre : équipe 1 = équipe recevante, équipe 2 = équipe extérieure (noms
     * normalisés : casse, accents, ponctuation), date de la rencontre à ±F131_TOLERANCE_JOURS de celle du fichier
     * (date exacte prioritaire) ; 0 ou plusieurs candidates = incohérence.
     * Cas : A même JA → conforme ; B autre JA → remplacé si arbitrage club ou rencontre passée, sinon incohérence ;
     * C sans nomination → créée ; D plusieurs lignes JA pour une rencontre → incohérence ; E ligne illisible,
     * JA inconnu, rencontre introuvable / ambiguë → incohérence. Hors périmètre (club recevant) → compté, sans écriture.
     * Retourne nb (compteurs), parCat (incohérences par catégorie), ops[id] = [op 'creer'|'remplacer', rencontre (dont DivisionDom = code division de l'équipe recevante), Id_JA]
     * (écriture applicable, voir ecrireOperation131()), parLigne[id] = verdict de chaque ligne
     * {action, rencontre, ja, motif, avertissements[]} (action : Conforme, Création, Remplacement, Incohérence,
     * Hors périmètre), deptRecevant[id] = département du club recevant (lignes rapprochées d'une rencontre seulement)
     * et jaInconnu[id] = « licence invalide » / « JA inconnu… » pour TOUTE ligne dont la licence n'est pas un JA connu
     * (informatif, calculé avant le rapprochement : motifs des lignes non traitables d'EN30 ; aucun effet sur verdicts ni ops).
     * id = identifiant unique de la ligne ($l['id']) : rang dans le fichier pour EN28, Id_F131 pour EN30 — jamais la ligne Excel.
     */
    public static function analyser131(\PDO $pdo, array $depts, array $lignes, ?string $dateMin, ?string $dateMax): array
    {
        $parLigne = [];
        $deptRecevant = [];   // id de ligne → département du club recevant de la rencontre rapprochée (positions 3-4 de Id_Club)
        $nb       = ['conformes' => 0, 'creees' => 0, 'modifiees' => 0, 'horsPerimetre' => 0, 'incoherences' => 0];
        $parCat   = [];
        $verdict  = function (string $action, array $l, string $rencontre, string $ja, string $motif) use (&$parLigne) {
            $k = $l['id'];
            $parLigne[$k] ??= ['action' => '', 'rencontre' => '', 'ja' => '', 'motif' => '', 'avertissements' => []];
            if ($action === 'Avertissement') {
                $parLigne[$k]['avertissements'][] = $motif;
            } else {
                $parLigne[$k] = ['action' => $action, 'rencontre' => $rencontre, 'ja' => $ja, 'motif' => $motif] + $parLigne[$k];
            }
        };
        $incoherence = function (string $cat, array $l, string $rencontre, string $ja, string $motif = '') use (&$nb, &$parCat, $verdict) {
            $nb['incoherences']++;
            $parCat[$cat] = ($parCat[$cat] ?? 0) + 1;
            $verdict('Incohérence', $l, $rencontre, $ja, $motif === '' ? $cat : "$cat : $motif");
        };
        $fr       = fn (string $d) => implode('/', array_reverse(explode('-', substr($d, 0, 10))));
        $fichierJa = fn (array $l) => trim($l['nom'] . ' (licence ' . ($l['licence'] ?? $l['brut']) . ')');

        // JA connus (Id_JA = licence)
        $jas = [];
        foreach ($pdo->query('SELECT Id_JA, Nom, Prenom FROM ja') as $j) {
            $jas[(int) $j['Id_JA']] = trim($j['Nom'] . ' ' . $j['Prenom']);
        }
        $libJa = fn (int $id) => ($jas[$id] ?? '?') . " ($id)";

        // Rencontres candidates (toutes, pour distinguer « hors périmètre » d'« introuvable »), indexées par équipes normalisées.
        $parEquipes = [];
        if ($dateMin !== null) {
            $tol  = self::F131_TOLERANCE_JOURS;
            $stmt = $pdo->prepare('
                SELECT r.Id_Rencontre, r.Date, r.ArbitrageCRA, ed.Nom AS NomDom, ee.Nom AS NomExt, ed.Id_Club AS IdClubDom, ed.Division AS DivisionDom,
                       n.Id_Nomination, n.Id_Disponible, d.Id_JA AS IdJaActuel
                FROM rencontre r
                JOIN equipe ed      ON ed.Id_Equipe    = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe    = r.Id_EquipeExt
                LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre
                LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                WHERE r.Date BETWEEN ? AND ?
            ');
            $stmt->execute([
                date('Y-m-d', strtotime($dateMin . " -$tol days")),
                date('Y-m-d', strtotime($dateMax . " +$tol days")),
            ]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $rc) {
                $parEquipes[CraDispoController::norm((string) $rc['NomDom']) . '|' . CraDispoController::norm((string) $rc['NomExt'])][] = $rc;
            }
        }

        // 1. Rapprochement de chaque ligne ; les lignes rapprochées sont regroupées par rencontre (cas D).
        $parRenc = [];
        $parJour = [];
        $jaInconnu = [];   // id de ligne → motif JA (toutes les lignes, informatif : n'intervient dans aucun verdict)
        foreach ($lignes as $l) {
            if ($l['licence'] === null || !isset($jas[$l['licence']])) {
                $jaInconnu[$l['id']] = $l['licence'] === null ? 'licence invalide' : 'JA inconnu (licence absente de la base)';
            }
            if ($l['licence'] !== null && $l['date'] !== null) {
                $parJour[$l['licence'] . '|' . $l['date']][] = $l;
            }
            $libFichier = trim($l['dom'] . ' – ' . $l['ext'], ' –');
            if ($l['date'] === null) {
                $incoherence('Date illisible', $l, $libFichier, $fichierJa($l));
                continue;
            }
            if ($l['dom'] === '' || $l['ext'] === '') {
                $incoherence('Équipes absentes', $l, $libFichier ?: $l['division'], $fichierJa($l), 'épreuve sans rencontre par équipes');
                continue;
            }
            $cands = array_filter(
                $parEquipes[CraDispoController::norm($l['dom']) . '|' . CraDispoController::norm($l['ext'])] ?? [],
                fn ($rc) => (new \DateTime($rc['Date']))->diff(new \DateTime($l['date']))->days <= self::F131_TOLERANCE_JOURS
            );
            if (count($cands) > 1) {
                $cands = array_filter($cands, fn ($rc) => $rc['Date'] === $l['date']);
            }
            if (count($cands) !== 1) {
                $incoherence($cands ? 'Rencontre ambiguë' : 'Rencontre introuvable', $l, $libFichier, $fichierJa($l));
                continue;
            }
            $rc     = reset($cands);
            $libRc  = $rc['NomDom'] . ' – ' . ($rc['NomExt'] ?? '?');
            $deptRecevant[$l['id']] = substr((string) $rc['IdClubDom'], 2, 2);   // filtre par département d'EN30
            if (!in_array(substr((string) $rc['IdClubDom'], 2, 2), $depts, true)) {
                $nb['horsPerimetre']++;
                $verdict('Hors périmètre', $l, $libRc, $fichierJa($l), 'club recevant hors de vos départements');
                continue;
            }
            if ($l['licence'] === null || !isset($jas[$l['licence']])) {
                $incoherence('JA inconnu', $l, $libRc, $fichierJa($l), 'licence absente de la base');
                continue;
            }
            if ($rc['Date'] !== $l['date']) {
                $verdict('Avertissement', $l, $libRc, $libJa($l['licence']), 'date en base ' . $fr($rc['Date']) . ' différente du fichier');
            }
            $parRenc[$rc['Id_Rencontre']][] = [$l, $rc, $libRc];
        }

        // 2. Décision par rencontre.
        $ops = [];
        foreach ($parRenc as $groupe) {
            if (count($groupe) > 1) {
                foreach ($groupe as [$l, , $libRc]) {
                    $incoherence('Plusieurs JA pour une rencontre', $l, $libRc, $libJa($l['licence']), count($groupe) . ' lignes JA');
                }
                continue;
            }
            [$l, $rc, $libRc] = $groupe[0];
            $idJa = $l['licence'];
            if ($rc['Id_Nomination'] === null) {
                $nb['creees']++;
                $verdict('Création', $l, $libRc, $libJa($idJa), 'nomination à créer (' . (+$rc['ArbitrageCRA'] ? 'arbitrage CRA' : 'arbitrage club') . ')');
                $ops[$l['id']] = ['creer', $rc, $idJa];
            } elseif ((int) $rc['IdJaActuel'] === $idJa) {
                $nb['conformes']++;
                $verdict('Conforme', $l, $libRc, $libJa($idJa), 'même JA nommé');
            } elseif ((int) $rc['ArbitrageCRA'] === 0 || $rc['Date'] < date('Y-m-d')) {
                $nb['modifiees']++;
                $verdict('Remplacement', $l, $libRc, $libJa((int) $rc['IdJaActuel']) . ' → ' . $libJa($idJa), 'JA à remplacer');
                $ops[$l['id']] = ['remplacer', $rc, $idJa];
            } else {
                $incoherence('Autre JA nommé (arbitrage CRA à venir)', $l, $libRc, $libJa($idJa), 'JA nommé : ' . $libJa((int) $rc['IdJaActuel']));
            }
        }

        // Un JA plus de 2 fois le même jour : signalé seulement.
        foreach ($parJour as $duJour) {
            if (count($duJour) > 2) {
                foreach ($duJour as $l) {
                    $verdict('Avertissement', $l, '', '', count($duJour) . ' arbitrages ce jour-là');
                }
            }
        }

        return ['nb' => $nb, 'parCat' => $parCat, 'ops' => $ops, 'parLigne' => $parLigne, 'deptRecevant' => $deptRecevant, 'jaInconnu' => $jaInconnu];
    }

    /** Colonnes de f131_travail alimentées par colonne du fichier (A → P) et longueur maximale (VARCHAR) ; Licence et Date sont dérivées. */
    private const F131_TRAVAIL_COLS = [
        'A' => ['LicenceBrute', 20], 'B' => ['Nom', 100], 'C' => ['Prenom', 100], 'D' => ['NumClub', 20],
        'E' => ['Club', 150], 'F' => ['Fonction', 100], 'G' => ['ArbJA', 20], 'H' => ['TypeEpreuve', 100],
        'I' => ['DateBrute', 20], 'J' => ['Journee', 20], 'K' => ['Poule', 50], 'L' => ['Equipe1', 150],
        'M' => ['Equipe2', 150], 'N' => ['OrganismeDivision', 100], 'O' => ['Division', 150], 'P' => ['Epreuve', 255],
    ];

    /** f131_travail existe-t-elle ? (créée par EA98). Cache statique : une seule vérification par requête. Utilisée aussi par EN30. */
    public static function f131TravailExiste(\PDO $pdo): bool
    {
        static $existe = null;
        if ($existe === null) {
            try {
                $existe = (bool) $pdo->query(
                    "SELECT 1 FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'f131_travail'"
                )->fetchColumn();
            } catch (\PDOException $e) {
                $existe = false;
            }
        }

        return $existe;
    }

    /**
     * Clé de dédoublonnage d'une ligne de f131_travail : LicenceBrute + Date (AAAA-MM-JJ, sinon DateBrute si date
     * illisible) + Equipe1 + Equipe2, valeurs trimées, comparaison insensible à la casse. Les valeurs passées sont
     * celles stockées (déjà tronquées à la taille de la colonne, '' → NULL), pour que fichier et table concordent.
     */
    private static function cleF131(?string $licBrute, ?string $date, ?string $dateBrute, ?string $eq1, ?string $eq2): string
    {
        $n = fn (?string $v) => mb_strtolower(trim((string) $v));

        return implode("\x1F", [$n($licBrute), $date !== null ? $date : '#' . $n($dateBrute), $n($eq1), $n($eq2)]);
    }

    /**
     * Chargement INCRÉMENTAL de f131_travail (plus aucun vidage) avec les lignes retenues ($lu['toutes'], colonne G = « JA »
     * et colonne N dans ORGANISMES_131). Appelée DANS la transaction de traiter131() (f131Apercu, qui a vérifié
     * l'existence de la table). Une ligne du fichier dont la clé cleF131() existe déjà dans la table (ou plus haut dans
     * le même fichier) n'est pas réinsérée : sa ligne existante reçoit seulement les champs informatifs du fichier
     * (jamais Ligne, n° de ligne Excel posé à l'insertion à titre informatif, sur lequel aucune logique ne repose),
     * son Traiter (0 = à traiter, 1 = traité) est conservé. Les nouvelles lignes sont insérées par lots avec
     * Traiter = 0 (DEFAULT). Valeurs texte trimées, '' → NULL, tronquées à la taille de la colonne ; Licence entière si
     * chiffres seuls, sinon NULL. Retourne ['ajoutees' => n, 'dejaPresentes' => n] (somme = count($toutes)).
     */
    private function rechargerF131Travail(\PDO $pdo, array $toutes): array
    {
        $txt  = fn (string $v, int $max) => ($v = trim($v)) === '' ? null : mb_substr($v, 0, $max);
        $cols = array_merge(['Ligne', 'Licence', 'Date'], array_column(self::F131_TRAVAIL_COLS, 0));

        // Clés existantes (verrouillées : deux chargements simultanés ne peuvent pas insérer le même enregistrement).
        $existantes = [];
        foreach ($pdo->query('SELECT Id_F131, LicenceBrute, Date, DateBrute, Equipe1, Equipe2 FROM f131_travail FOR UPDATE') as $e) {
            $existantes[self::cleF131($e['LicenceBrute'], $e['Date'], $e['DateBrute'], $e['Equipe1'], $e['Equipe2'])] ??= (int) $e['Id_F131'];
        }

        $nouvelles = [];   // valeurs dans l'ordre de $cols
        $maj       = null; // UPDATE préparé des lignes déjà présentes (Traiter jamais modifié)
        $deja      = 0;
        foreach ($toutes as $t) {
            $lic = preg_replace('/\s+/', '', $t['col']['A']);
            $val = ['Ligne' => $t['ligne'], 'Licence' => ctype_digit($lic) && strlen($lic) <= 9 ? (int) $lic : null, 'Date' => $t['date']];
            foreach (self::F131_TRAVAIL_COLS as $lettre => [$col, $max]) {
                $val[$col] = $txt((string) ($t['col'][$lettre] ?? ''), $max);
            }
            $cle = self::cleF131($val['LicenceBrute'], $val['Date'], $val['DateBrute'], $val['Equipe1'], $val['Equipe2']);
            if (isset($existantes[$cle])) {
                $deja++;
                if ($existantes[$cle] > 0) {   // ligne en base (≤ 0 : doublon d'une nouvelle ligne du même fichier)
                    $info = $val;
                    unset($info['Ligne']);     // n° de ligne Excel : informatif, posé à l'insertion seulement
                    $maj ??= $pdo->prepare('UPDATE f131_travail SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($info))) . ' WHERE Id_F131 = ?');
                    $maj->execute(array_merge(array_values($info), [$existantes[$cle]]));
                }
                continue;
            }
            $existantes[$cle] = 0;
            $nouvelles[]      = array_values($val);
        }

        $place = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $sql   = 'INSERT INTO f131_travail (' . implode(', ', $cols) . ') VALUES ';
        $stmts = [];   // une requête préparée par taille de lot
        foreach (array_chunk($nouvelles, 200) as $lot) {
            $n = count($lot);
            $stmts[$n] ??= $pdo->prepare($sql . implode(', ', array_fill(0, $n, $place)));
            $stmts[$n]->execute(array_merge(...$lot));
        }

        return ['ajoutees' => count($nouvelles), 'dejaPresentes' => $deja];
    }

    /** Renvoie au JA de la nomination le modèle « Convocation » (lien EN21 vers ses frais). */
    public function rappel(): ResponseInterface
    {
        try {
            $pdo   = getPDO();
            $idNom = (int) $this->request->getPost('id_nomination');
            $depts = $this->deptsAutorises();
            if ($idNom <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination invalide.']);
            }

            $stmt = $pdo->prepare('
                SELECT n.Id_Nomination, ja.Id_JA, ja.Nom, ja.Prenom, ja.Email,
                       ed.Nom AS NomDom, ee.Nom AS NomExt, cl.Nom AS NomClub,
                       r.Date, r.Heure, r.Journee, r.Poule, ed.Division, RIGHT(ed.Division, 1) AS SexeCode
                FROM nomination n
                JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
                JOIN ja            ON ja.Id_JA        = d.Id_JA
                JOIN rencontre r   ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed     ON ed.Id_Equipe    = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN Club cl  ON cl.Id_Club      = ed.Id_Club
                WHERE n.Id_Nomination = ? AND n.Valide = 1 AND n.DateSaisie IS NULL
                  AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idNom, ...$depts]);
            $nom = $stmt->fetch();
            if (!$nom) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination introuvable ou frais déjà saisis.']);
            }
            if (empty($nom['Email'])) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ce JA n\'a pas d\'adresse email.']);
            }

            $errRl = checkRateLimit(1);   // même garde-fou d'envoi que le Centre d'envoi (EN15)
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
            }

            $moi = $_SESSION['utilisateur'] ?? [];
            $tpl = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_CONVOCATION, (int) ($moi['id'] ?? 0));
            if (!$tpl) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Modèle de message introuvable (messagerie n°' . self::ID_MESSAGE_CONVOCATION . ').']);
            }

            $marqueurs = construireMarqueursMessage($nom, $moi, [
                'id_nomination' => $nom['Id_Nomination'],
                'sexe_code'     => $nom['SexeCode'],
                'date'          => $nom['Date'],
                'heure'         => $nom['Heure'],
                'journee'       => $nom['Journee'],
                'poule'         => $nom['Poule'],
                'division'      => $nom['Division'],
                'dom'           => $nom['NomDom'],
                'ext'           => $nom['NomExt'],
                'nom_club'      => $nom['NomClub'],
            ]);
            $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);
            $corps = $rendu['corps'];
            if (str_contains($corps, 'data:image/')) {
                $corps = preg_replace('/src="data:image\/[^;]+;base64,[^"]*"/', 'src=""', $corps); // antispam
            }

            $dest   = getEmailDestinataire($nom['Email']);
            $isHtml = strip_tags($corps) !== $corps;
            $nomMoi = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''));
            $mail   = getNijacMailer();
            $mail->isHTML($isHtml);
            $mail->addAddress($dest, $nom['Prenom'] . ' ' . $nom['Nom']);
            if (!empty($tpl['Cc']) && !empty($moi['email'])) {
                $mail->addCC(getEmailDestinataire($moi['email']), $nomMoi);
            }
            if (!empty($tpl['ReplyTo']) && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], $nomMoi);
            }
            $mail->Subject = (isModeDeveloppement() && $dest !== $nom['Email'])
                ? "[DEV → {$nom['Email']}] {$rendu['sujet']}" : $rendu['sujet'];
            $mail->Body = $corps;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
            }
            $mail->send();
            enregistrerEnvois(1);

            // Affiché dans un toast HTML côté client : nom du JA échappé.
            return $this->response->setJSON(['ok' => true, 'msg' => 'Rappel envoyé à ' . htmlspecialchars($nom['Prenom'] . ' ' . $nom['Nom']) . '.']);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'rappel', 'Envoi impossible (voir le journal des erreurs).');
        }
    }

    /**
     * « Relancer le club » : renvoie au club recevant d'une rencontre en arbitrage club
     * encore sans JA le message n°7 (lien EN25), via envoyerDemandeJaClub() partagée avec EN14.
     */
    public function relanceClub(): ResponseInterface
    {
        try {
            $pdo    = getPDO();
            $idRenc = (int) $this->request->getPost('id_rencontre');
            $depts  = $this->deptsAutorises();
            if ($idRenc <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre invalide.']);
            }

            // Périmètre du nominateur : même critère que data() (club recevant). Arbitrage club
            // et email du correspondant sont vérifiés par envoyerDemandeJaClub().
            $stmt = $pdo->prepare('
                SELECT (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNom
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idRenc, ...$depts]);
            $rc = $stmt->fetch();
            if (!$rc) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre introuvable ou hors de votre périmètre.']);
            }
            if ((int) $rc['NbNom'] > 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le club a déjà répondu : un JA est désigné pour cette rencontre.']);
            }

            $errRl = checkRateLimit(1);   // même garde-fou d'envoi que le rappel au JA
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
            }

            $res = envoyerDemandeJaClub($pdo, $idRenc, $_SESSION['utilisateur'] ?? []);
            if ($res['ok']) {
                enregistrerEnvois(1);
            }

            // Affiché dans un toast HTML côté client : nom du correspondant échappé.
            return $this->response->setJSON(['ok' => $res['ok'], 'msg' => htmlspecialchars($res['msg'])]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'relanceClub', 'Envoi impossible (voir le journal des erreurs).');
        }
    }
}
