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
            // Forfait (rencontre.Forfait, EA98) : 1 = équipe recevante, 2 = équipe extérieure ; NomForfait = nom de
            // l'équipe forfait tel qu'affiché dans les colonnes Domicile / Extérieur. Colonne absente → 0 / NULL.
            $colAccuse .= rencontreAForfait($pdo)
                ? ', r.Forfait, CASE r.Forfait WHEN 1 THEN ed.Nom WHEN 2 THEN ee.Nom END AS NomForfait'
                : ', 0 AS Forfait, NULL AS NomForfait';
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
     */
    private static function disponibleOuiCra(\PDO $pdo, int $idJa, int $idRenc, string $date, string $note): int
    {
        $d = $pdo->prepare('SELECT Id_Disponible, Reponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $d->execute([$idJa, $idRenc]);
        $dispo = $d->fetch(\PDO::FETCH_ASSOC);
        if ($dispo && $dispo['Reponse'] === 'O') {
            return (int) $dispo['Id_Disponible'];
        }

        $j = $pdo->prepare("SELECT DateReponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre IS NULL AND DateCompetition = ? AND Reponse = 'O' LIMIT 1");
        $j->execute([$idJa, $date]);
        $dRep = $j->fetchColumn() ?: null;
        if ($dispo) {
            $pdo->prepare("UPDATE disponible SET Reponse = 'O', DateReponse = COALESCE(?, CURDATE()), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                ->execute([$dRep, $date, $note, $dispo['Id_Disponible']]);

            return (int) $dispo['Id_Disponible'];
        }
        $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'O', COALESCE(?, CURDATE()), ?)")
            ->execute([$idJa, $idRenc, $date, $dRep, $note]);

        return (int) $pdo->lastInsertId();
    }

    /** Arbitrage club : disponible (JA, rencontre) réutilisée si elle existe (comme EN25), mise en 'P' ; sinon créée en 'P'. */
    private static function disponibleClub(\PDO $pdo, int $idJa, int $idRenc, string $date, string $note): int
    {
        $d = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $d->execute([$idJa, $idRenc]);
        $idDispo = (int) $d->fetchColumn();
        if ($idDispo) {
            $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                ->execute([$date, $note, $idDispo]);

            return $idDispo;
        }
        $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
            ->execute([$idJa, $idRenc, $date, $note]);

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

    /** Seules divisions renseignées par la mise à jour 131 (equipe.Division de l'équipe recevante, code court). */
    private const DIVISIONS_LOT = ['R3M', 'R4M'];

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

    /**
     * GET suivi-nomination/f131/fichier : tous les fichiers 131_*.xlsx d'Importation/, plus récent d'abord (étape 2 de la popup),
     * + suivis[] = les SUIVI_131_MAX_LISTE derniers journaux CSV de _Suivi/ {nom, taille, date} (bloc « Fichiers de suivi »).
     */
    public function f131Fichier(): ResponseInterface
    {
        $suivis = array_map(
            fn ($f) => ['nom' => $f['nom'], 'taille' => $f['taille'], 'date' => date('d/m/Y H:i:s', $f['mtime'])],
            array_slice(self::fichiersSuivi(), 0, self::SUIVI_131_MAX_LISTE)
        );

        return $this->response->setJSON(['ok' => true, 'suivis' => $suivis] + self::infoFichier131());
    }

    /** Nom accepté pour un journal CSV de _Suivi/ (celui que génère ecrireJournal131(), suffixe _2… compris). */
    private const SUIVI_131_MOTIF_NOM = '/^suivi_131_\d{8}_\d{6}(_\d{1,2})?\.csv$/';

    /** Nombre de journaux listés dans la popup. */
    private const SUIVI_131_MAX_LISTE = 10;

    /**
     * Journaux CSV de _Suivi/ (nom, chemin canonique, taille, date de modification), du plus récent au plus ancien. Chemins
     * construits côté serveur uniquement : glob() sur un motif fixe, nom contrôlé (SUIVI_131_MOTIF_NOM), fichier ordinaire
     * (pas de lien symbolique) dont le realpath est directement dans _Suivi/.
     */
    private static function fichiersSuivi(): array
    {
        $dir = self::dossierSuivi();
        if ($dir === null) {
            return [];
        }
        $res = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'suivi_131_*.csv') ?: [] as $f) {
            $nom  = basename($f);
            $reel = realpath($f);
            if (!preg_match(self::SUIVI_131_MOTIF_NOM, $nom) || is_link($f) || $reel === false || !is_file($reel)
                || dirname($reel) !== $dir) {
                continue;
            }
            $res[] = ['nom' => $nom, 'chemin' => $reel, 'taille' => (int) filesize($reel), 'mtime' => (int) filemtime($reel)];
        }
        usort($res, fn ($a, $b) => [$b['mtime'], $b['nom']] <=> [$a['mtime'], $a['nom']]);

        return $res;
    }

    /**
     * Supprime tous les journaux CSV de _Suivi/ (appelée par f131Apercu() après une analyse RÉUSSIE uniquement ; jamais à
     * l'application ni au dépôt). Seuls les fichiers de fichiersSuivi() sont visés (motif SUIVI_131_MOTIF_NOM, pas de lien,
     * realpath directement dans _Suivi/) : jamais .htaccess, .gitignore ni autre fichier. Un fichier non supprimable
     * (verrouillé, droits) n'interrompt rien. Aucun contenu lu ni journalisé. Retourne [nb supprimés, noms non supprimés].
     */
    private static function purgerSuivis131(): array
    {
        $supprimes = 0;
        $echecs    = [];
        foreach (self::fichiersSuivi() as $f) {
            if (@unlink($f['chemin'])) {
                $supprimes++;
            } else {
                $echecs[] = $f['nom'];
            }
        }
        clearstatcache();

        return [$supprimes, $echecs];
    }

    /**
     * GET suivi-nomination/f131/suivi?nom=… : ouvre (téléchargement) un journal CSV de _Suivi/ — dossier interdit en accès
     * HTTP direct. Le nom reçu n'est jamais utilisé comme chemin : regex stricte SUIVI_131_MOTIF_NOM puis nom retrouvé à
     * l'identique dans fichiersSuivi() (liste serveur), dont le chemin est lu. Sinon 404. Aucune suppression.
     */
    public function f131Suivi(): ResponseInterface
    {
        $nom   = (string) $this->request->getGet('nom');
        $cible = null;
        if (preg_match(self::SUIVI_131_MOTIF_NOM, $nom)) {
            foreach (self::fichiersSuivi() as $f) {
                if ($f['nom'] === $nom) {
                    $cible = $f['chemin'];
                    break;
                }
            }
        }
        $contenu = $cible !== null ? @file_get_contents($cible) : false;
        if ($contenu === false) {
            return $this->response->setStatusCode(404)
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/plain', 'UTF-8')
                ->setBody('Fichier de suivi introuvable.');
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $nom . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($contenu);
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

    /** Périmètre de la mise à jour 131 : tous les départements actifs pour un administrateur, sinon getDepartementsAutorises(). */
    private function perimetre131(): array
    {
        if (!empty($_SESSION['utilisateur']['is_admin'])) {
            return array_values(array_unique(array_map(fn ($d) => (string) $d['CodeDept'], getDeptActifs())));
        }

        return array_map('strval', $this->deptsAutorises());
    }

    /**
     * Lit et analyse (SANS écriture) le fichier 131_*.xlsx le PLUS RÉCENT d'Importation/ — jamais un fichier ni un chemin
     * envoyé par le navigateur — pour le périmètre perimetre131(). Partagé par f131Apercu() et f131Appliquer().
     * Retourne [nom du fichier, lire131(), analyser131()]. Refus métier : RuntimeException (message affichable).
     */
    private function analyserFichier131(\PDO $pdo): array
    {
        $tous = self::fichiers131();
        if (!$tous) {
            throw new \RuntimeException('Aucun fichier 131_*.xlsx dans Importation : déposez-le d\'abord (« Parcourir… »).');
        }
        $serveur = $tous[0];
        if ($serveur['taille'] > self::F131_TAILLE_MAX) {
            throw new \RuntimeException('Fichier trop volumineux (5 Mo maximum).');
        }
        if (!nominationAF131($pdo)) {
            throw new \RuntimeException('Colonne nomination.F131 absente : chargez l\'écran EA98 (Administration BDD).');
        }
        $depts = $this->perimetre131();
        if (!$depts) {
            throw new \RuntimeException('Aucun département autorisé.');
        }
        try {
            $lu = self::lire131($serveur['chemin']);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN28 import 131 : ' . get_class($e));   // jamais le contenu du fichier (données personnelles)
            throw new \RuntimeException($e instanceof \RuntimeException ? $e->getMessage() : 'Classeur illisible.');
        }

        return [$serveur['nom'], $lu, self::analyser131($pdo, $depts, $lu['lignes'], $lu['dateMin'], $lu['dateMax'])];
    }

    /** Partie publique du compte rendu (sans les opérations internes). */
    private static function compteRendu131(string $fichier, array $lu, array $an): array
    {
        return [
            'fichier'  => $fichier,
            'nbLignes' => $lu['nbLignes'], 'vides' => $lu['vides'], 'ignores' => $lu['ignores'],
            'horsOrganisme' => $lu['horsOrganisme'], 'organismes' => self::ORGANISMES_131, 'divisions' => self::DIVISIONS_LOT,
            'dateMin'  => $lu['dateMin'], 'dateMax' => $lu['dateMax'],
            'retenues' => count($lu['lignes']),
            'nb'       => count($an['ops']),
        ] + $an['nb'];
    }

    /**
     * POST suivi-nomination/f131/apercu (sans paramètre) : analyse SANS AUCUNE ÉCRITURE du fichier le plus récent
     * d'Importation/ (analyserFichier131()). Réponse : compte rendu chiffré + nb = N rencontres R3M / R4M sans JA nommé
     * auxquelles le fichier donne un JA connu + rencontres[] (« À renseigner » / « Ignoré : motif »). Le bouton
     * d'application n'est affiché que si N > 0. Seule écriture : une analyse réussie efface les anciens journaux CSV de
     * _Suivi/ (purgerSuivis131() → suivisSupprimes = n, suivisNonSupprimes = noms non supprimables).
     */
    public function f131Apercu(): ResponseInterface
    {
        try {
            [$fichier, $lu, $an] = $this->analyserFichier131(getPDO());
            // Analyse réussie seulement (toute erreur ci-dessus sort avant) : purge des anciens journaux de suivi.
            [$supprimes, $nonSupprimes] = self::purgerSuivis131();

            return $this->response->setJSON([
                'ok' => true, 'rencontres' => $an['rencontres'],
                'suivisSupprimes' => $supprimes, 'suivisNonSupprimes' => $nonSupprimes,
            ] + self::compteRendu131($fichier, $lu, $an));
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'analyse 131', 'Analyse impossible.');
        }
    }

    /**
     * POST suivi-nomination/f131/appliquer (`fichier`, `nb` : ce que l'utilisateur a vu et confirmé, contrôle de cohérence
     * SEULEMENT — aucune donnée de ligne n'est reçue) : le serveur relit le fichier le plus récent et recalcule tout
     * (analyserFichier131()) ; refus si ce n'est plus le même fichier ou si N a changé. Puis, dans UNE transaction, crée la
     * nomination de chaque rencontre « À renseigner » (ecrireCreation131() : R3M / R4M sans nomination, jamais de
     * remplacement) et écrit le journal CSV _Suivi/suivi_131_<aaaammjj_HHMMSS>.csv avant le commit (journal impossible →
     * rollback ; commit en échec → journal supprimé). Création concurrente (uq_nomination_rencontre) → rollback complet.
     * Aucun email. Réponse {ok, msg, nb, lignes[] (Renseigné / Ignoré : motif), journal (nom du CSV)}.
     */
    public function f131Appliquer(): ResponseInterface
    {
        $fichierVu = (string) $this->request->getPost('fichier');
        $nbVu      = (string) $this->request->getPost('nb');
        if (!preg_match(self::F131_MOTIF_NOM, $fichierVu) || !ctype_digit($nbVu)) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Paramètres invalides : relancez l\'analyse.']);
        }
        $journal = null;
        try {
            $pdo = getPDO();
            [$fichier, , $an] = $this->analyserFichier131($pdo);
            if ($fichier !== $fichierVu) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => "Le fichier le plus récent du serveur a changé ($fichier) : relancez l'analyse. Aucune modification enregistrée."]);
            }
            $nb = count($an['ops']);
            if ($nb === 0) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Aucune rencontre ' . implode(' / ', self::DIVISIONS_LOT) . ' sans JA à renseigner : aucune modification enregistrée.']);
            }
            if ($nb !== (int) $nbVu) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => "La situation a changé depuis l'analyse ($nbVu rencontre(s) annoncée(s), $nb maintenant) : relancez l'analyse. Aucune modification enregistrée."]);
            }

            $moi     = (string) ($_SESSION['utilisateur']['login'] ?? '');
            $csv     = [];
            $faites  = [];   // Id_Rencontre → Id_Nomination créé
            $pdo->beginTransaction();
            try {
                $horodatage = date('d/m/Y H:i:s');
                $jour       = date('d/m/Y', strtotime((string) $pdo->query('SELECT CURDATE()')->fetchColumn()));
                foreach ($an['ops'] as [$rc, $idJa]) {
                    $cree = self::ecrireCreation131($pdo, $rc, $idJa);
                    $faites[(int) $rc['Id_Rencontre']] = $cree['idNomination'];
                    $r = current(array_filter($an['rencontres'], fn ($x) => $x['idRencontre'] === (int) $rc['Id_Rencontre']));
                    $csv[] = [
                        $horodatage, $moi, (int) $rc['Id_Rencontre'], date('d/m/Y', strtotime($rc['Date'])), $rc['DivisionDom'],
                        $rc['NomDom'], $rc['NomExt'] ?? '', $cree['idNomination'], $cree['idDispo'], $idJa,
                        $r['nomJa'] ?? '', $r['prenomJa'] ?? '', 1, 1, 0, 0, 0, $jour,
                    ];
                }
                [$journal, $cheminJournal] = self::ecrireJournal131($csv);
                try {
                    $pdo->commit();
                } catch (\Throwable $e) {
                    @unlink($cheminJournal);   // rien n'a été enregistré : pas de journal
                    $journal = null;
                    throw $e;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof \PDOException && $e->getCode() === '23000') {
                    throw new \RuntimeException('Un JA a été nommé entre-temps sur une de ces rencontres : aucune modification enregistrée, relancez l\'analyse.');
                }
                throw $e;
            }

            $lignes = array_map(function ($r) use ($faites) {
                if (isset($faites[$r['idRencontre']])) {
                    $r['resultat']     = 'Renseigné';
                    $r['idNomination'] = $faites[$r['idRencontre']];
                }

                return $r;
            }, $an['rencontres']);

            return $this->response->setJSON([
                'ok'      => true,
                'nb'      => $nb,
                'msg'     => $nb . ' rencontre' . ($nb > 1 ? 's' : '') . ' ' . implode(' / ', self::DIVISIONS_LOT) . ' renseignée' . ($nb > 1 ? 's' : '') . ' (F131 = 1).',
                'lignes'  => $lignes,
                'journal' => $journal,
            ]);
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN28 application 131 : ' . get_class($e) . ' ' . $e->getCode());   // aucune donnée du fichier

            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => messageErreur($e, 'Mise à jour impossible : aucune modification enregistrée.')]);
        }
    }

    /**
     * Lit l'édition 131 (sans base). Ligne d'en-tête repérée par A = « N° Licence » et G = « Arb/JA »
     * (ligne 2 dans l'export FFTT, la ligne 1 porte le titre). Colonnes utilisées : A licence, B nom, C prénom,
     * G fonction (seul « JA » est retenu), I date JJ/MM/AAAA, L équipe 1 (recevante), M équipe 2,
     * N organisme (seuls ORGANISMES_131 sont retenus, égalité exacte sans casse). La division de la rencontre est celle
     * de la base (equipe.Division de l'équipe recevante), pas la colonne O (libellé long).
     * Retourne nbLignes, vides, ignores[valeur de G] = n, horsOrganisme (lignes JA dont N ∉ ORGANISMES_131),
     * dateMin/dateMax (AAAA-MM-JJ, toutes lignes non vides) et lignes[] (G = JA et N retenu).
     * nbLignes = Σ ignores + horsOrganisme + count(lignes).
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

        $res = ['nbLignes' => 0, 'vides' => 0, 'ignores' => [], 'horsOrganisme' => 0, 'dateMin' => null, 'dateMax' => null, 'lignes' => []];
        foreach ($feuille->rangeToArray("A" . ($entete + 1) . ":P$maxR", null, false, false, true) as $c) {
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
            if (!in_array(strtoupper($c['N']), self::ORGANISMES_131, true)) {   // compté seulement, jamais analysé
                $res['horsOrganisme']++;
                continue;
            }
            $lic = preg_replace('/\s+/', '', $c['A']);
            $res['lignes'][] = [
                'licence' => ctype_digit($lic) && (int) $lic > 0 ? (int) $lic : null,   // ja.Id_JA = n° de licence (entier)
                'brut'    => $c['A'],
                'nom'     => trim($c['B'] . ' ' . $c['C']),
                'date'    => $date,
                'dom'     => $c['L'],
                'ext'     => $c['M'],
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
     * Analyse SANS écriture des lignes JA de l'édition 131 (format de lire131()['lignes']) contre la base, limitée à ce
     * que la mise à jour applique : les rencontres R3M / R4M (DIVISIONS_LOT = equipe.Division de l'équipe recevante) du
     * périmètre $depts qui n'ont AUCUNE nomination et auxquelles le fichier donne un JA connu (licence = ja.Id_JA).
     * Rapprochement ligne → rencontre : équipe 1 = équipe recevante, équipe 2 = équipe extérieure (noms normalisés : casse,
     * accents, ponctuation), date de la rencontre à ±F131_TOLERANCE_JOURS de celle du fichier (date exacte prioritaire) ;
     * 0 ou plusieurs candidates = ligne non rapprochée.
     * Retourne nb (lignes : nonRapprochees, horsPerimetre, autresDivisions, lignesDivisions ; rencontres R3M / R4M :
     * dejaNommees, aRenseigner, ignorees), rencontres[] (rencontres R3M / R4M sans JA rapprochées d'au moins une ligne, triées
     * par date : idRencontre, date, division, dom, ext, idJa, nomJa, prenomJa, club, resultat « À renseigner » ou
     * « Ignoré : motif », note) et ops[] = [rencontre, Id_JA] des seules rencontres « À renseigner » (ecrireCreation131()).
     * Une rencontre sans JA en forfait (rencontre.Forfait ≠ 0, lu seulement si rencontreAForfait()) n'a pas besoin de JA :
     * jamais « À renseigner », « Ignoré : forfait » (compté dans ignorees).
     * Public static : testable hors CodeIgniter.
     */
    public static function analyser131(\PDO $pdo, array $depts, array $lignes, ?string $dateMin, ?string $dateMax): array
    {
        $nb = ['nonRapprochees' => 0, 'horsPerimetre' => 0, 'autresDivisions' => 0, 'lignesDivisions' => 0,
               'dejaNommees' => 0, 'aRenseigner' => 0, 'ignorees' => 0];
        $fr = fn (string $d) => implode('/', array_reverse(explode('-', substr($d, 0, 10))));

        // JA connus (Id_JA = licence) et leur club.
        $jas = [];
        foreach ($pdo->query('SELECT j.Id_JA, j.Nom, j.Prenom, c.Nom AS NomClub FROM ja j LEFT JOIN club c ON c.Id_Club = j.Id_Club') as $j) {
            $jas[(int) $j['Id_JA']] = $j;
        }

        // Rencontres candidates (toutes, pour distinguer « hors périmètre » de « introuvable »), indexées par équipes normalisées.
        $parEquipes = [];
        if ($dateMin !== null) {
            $tol  = self::F131_TOLERANCE_JOURS;
            // Forfait (EA98) : une rencontre en forfait n'a pas besoin de JA (colonne absente → jamais forfait).
            $colForfait = rencontreAForfait($pdo) ? 'r.Forfait' : '0 AS Forfait';
            $stmt = $pdo->prepare('
                SELECT r.Id_Rencontre, r.Date, r.ArbitrageCRA, ed.Nom AS NomDom, ee.Nom AS NomExt, ed.Id_Club AS IdClubDom,
                       ed.Division AS DivisionDom, n.Id_Nomination, ' . $colForfait . '
                FROM rencontre r
                JOIN equipe ed      ON ed.Id_Equipe    = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe    = r.Id_EquipeExt
                LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre
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

        // 1. Rapprochement de chaque ligne ; seules les lignes des rencontres R3M / R4M du périmètre sont regroupées par rencontre.
        $parRenc = [];
        foreach ($lignes as $l) {
            if ($l['date'] === null || $l['dom'] === '' || $l['ext'] === '') {
                $nb['nonRapprochees']++;
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
                $nb['nonRapprochees']++;
                continue;
            }
            $rc = reset($cands);
            if (!in_array(substr((string) $rc['IdClubDom'], 2, 2), $depts, true)) {
                $nb['horsPerimetre']++;
                continue;
            }
            if (!in_array((string) $rc['DivisionDom'], self::DIVISIONS_LOT, true)) {
                $nb['autresDivisions']++;
                continue;
            }
            $nb['lignesDivisions']++;
            $parRenc[$rc['Id_Rencontre']][] = [$l, $rc];
        }

        // 2. Décision par rencontre R3M / R4M : déjà nommée → rien ; sinon un seul JA connu → à renseigner, autrement ignorée.
        $rencontres = [];
        $ops        = [];
        foreach ($parRenc as $groupe) {
            $rc = $groupe[0][1];
            if ($rc['Id_Nomination'] !== null) {
                $nb['dejaNommees']++;
                continue;
            }
            $licences = array_unique(array_map(fn ($g) => $g[0]['licence'] ?? 'brut:' . $g[0]['brut'], $groupe));
            $l        = $groupe[0][0];
            $ja       = $l['licence'] !== null ? ($jas[$l['licence']] ?? null) : null;
            $motif    = null;
            if ((int) ($rc['Forfait'] ?? 0) !== 0) {
                $motif = 'forfait';   // pas de JA à renseigner sur une rencontre en forfait (« Ignoré : forfait »)
            } elseif (count($licences) > 1) {
                $motif = count($licences) . ' JA différents dans le fichier pour cette rencontre';
            } elseif ($l['licence'] === null) {
                $motif = 'licence illisible dans le fichier (' . ($l['brut'] === '' ? 'vide' : $l['brut']) . ')';
            } elseif ($ja === null) {
                $motif = 'JA inconnu : licence ' . $l['licence'] . ' absente de la base';
            }
            $rencontres[] = [
                'idRencontre' => (int) $rc['Id_Rencontre'],
                'date'        => $rc['Date'],
                'division'    => $rc['DivisionDom'],
                'dom'         => $rc['NomDom'],
                'ext'         => $rc['NomExt'] ?? '',
                'idJa'        => $motif === null ? (int) $l['licence'] : null,
                'nomJa'       => $motif === null ? (string) $ja['Nom'] : implode(' / ', array_unique(array_map(fn ($g) => $g[0]['nom'], $groupe))),
                'prenomJa'    => $motif === null ? (string) $ja['Prenom'] : '',
                'club'        => $motif === null ? (string) ($ja['NomClub'] ?? '') : '',
                'resultat'    => $motif === null ? 'À renseigner' : 'Ignoré : ' . $motif,
                'note'        => $motif === null && $rc['Date'] !== $l['date'] ? 'date du fichier ' . $fr($l['date']) . ' différente' : '',
            ];
            if ($motif === null) {
                $nb['aRenseigner']++;
                $ops[] = [$rc, (int) $l['licence']];
            } else {
                $nb['ignorees']++;
            }
        }
        usort($rencontres, fn ($a, $b) => [$a['date'], $a['dom']] <=> [$b['date'], $b['dom']]);

        return ['nb' => $nb, 'rencontres' => $rencontres, 'ops' => $ops];
    }

    /**
     * Crée, DANS la transaction de l'appelant, la nomination d'une rencontre sans JA à partir du fichier 131 :
     * disponible ('O' si arbitrage CRA, 'P' sinon : disponibleOuiCra() / disponibleClub()) + nomination (Valide = 1,
     * F131 = 1, Peage = 0, Kilometre = 0, Defiscalisation = 0, DateSaisie et DateNomination = CURDATE(), EmailEnvoye = 0,
     * AccuseReception NULL par défaut). Aucun email. Création concurrente : PDOException 23000 (uq_nomination_rencontre),
     * à traiter par l'appelant (rollback complet). Retourne ['idNomination' => n, 'idDispo' => n].
     */
    private static function ecrireCreation131(\PDO $pdo, array $rc, int $idJa): array
    {
        $note    = 'Juge-arbitre renseigné depuis le fichier FFTT 131 le ' . date('d/m/Y');
        $idRenc  = (int) $rc['Id_Rencontre'];
        $idDispo = +$rc['ArbitrageCRA']
            ? self::disponibleOuiCra($pdo, $idJa, $idRenc, $rc['Date'], $note)
            : self::disponibleClub($pdo, $idJa, $idRenc, $rc['Date'], $note);
        $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, Peage, Kilometre, Defiscalisation, DateSaisie, DateNomination, Valide, EmailEnvoye, F131)
                       VALUES (?, ?, 0, 0, 0, CURDATE(), CURDATE(), 1, 0, 1)')
            ->execute([$idRenc, $idDispo]);

        return ['idNomination' => (int) $pdo->lastInsertId(), 'idDispo' => $idDispo];
    }

    /**
     * Dossier du journal _Suivi/ (racine du projet, jamais servi en HTTP : .htaccess racine « RewriteRule ^_Suivi/ - [F,L] »
     * + _Suivi/.htaccess), chemin canonique (realpath) ; créé s'il manque. null si inaccessible.
     */
    private static function dossierSuivi(): ?string
    {
        $dir = __DIR__ . '/../../../_Suivi';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $reel = realpath($dir);

        return $reel === false || !is_writable($reel) ? null : $reel;
    }

    /** En-tête du journal CSV de la mise à jour FFTT 131 (une ligne par nomination créée). */
    private const SUIVI_131_COLONNES = [
        'Horodatage', 'Utilisateur', 'Id_Rencontre', 'Date rencontre', 'Division', 'Equipe recevante', 'Equipe visiteuse',
        'Id_Nomination', 'Id_Disponible', 'Id_JA', 'Nom', 'Prenom', 'Valide', 'F131', 'Peage', 'Kilometre', 'Defiscalisation', 'DateSaisie',
    ];

    /**
     * Écrit le journal CSV _Suivi/suivi_131_<aaaammjj_HHMMSS>.csv (nom généré par le serveur, création exclusive : jamais
     * d'écrasement, suffixe _2… si la seconde est déjà prise) : UTF-8 avec BOM (Excel), séparateur « ; », fin de ligne CRLF,
     * guillemets échappés par fputcsv(), champs texte neutralisés contre l'injection de formule (csvSafe()).
     * Retourne [nom, chemin]. RuntimeException si le fichier ne peut pas être écrit.
     */
    private static function ecrireJournal131(array $lignes): array
    {
        $dir = self::dossierSuivi();
        if ($dir === null) {
            throw new \RuntimeException('Dossier _Suivi inaccessible en écriture : aucune modification enregistrée.');
        }
        $base = 'suivi_131_' . date('Ymd_His');
        $h    = false;
        for ($i = 1; $i <= 20 && $h === false; $i++) {
            $nom    = $base . ($i > 1 ? '_' . $i : '') . '.csv';
            $chemin = $dir . DIRECTORY_SEPARATOR . $nom;
            $h      = @fopen($chemin, 'x');   // 'x' : échoue si le fichier existe déjà
        }
        if ($h === false) {
            throw new \RuntimeException('Journal CSV impossible à créer dans _Suivi : aucune modification enregistrée.');
        }
        $ok = fwrite($h, "\xEF\xBB\xBF") !== false
            && fputcsv($h, self::SUIVI_131_COLONNES, ';', '"', '', "\r\n") !== false;
        foreach ($lignes as $l) {
            $ok = $ok && fputcsv($h, array_map('csvSafe', $l), ';', '"', '', "\r\n") !== false;
        }
        $ok = fclose($h) && $ok;
        if (!$ok) {
            @unlink($chemin);
            throw new \RuntimeException('Journal CSV impossible à écrire dans _Suivi : aucune modification enregistrée.');
        }
        @chmod($chemin, 0640);

        return [$nom, $chemin];
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
