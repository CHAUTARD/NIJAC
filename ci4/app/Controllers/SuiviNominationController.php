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
     * péage, kilomètres, défiscalisation. DateSaisie prend la date du jour (le compte EBP
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

            if ($idNom <= 0 || $idJa <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
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
                $pdo->prepare('
                    UPDATE nomination SET Id_Disponible = ?, Peage = ?, Kilometre = ?, Defiscalisation = ?, DateSaisie = CURDATE(), Valide = 1
                    WHERE Id_Nomination = ?
                ')->execute([$idDispo, $peage, $km, $defisc, $idNom]);

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

            // disponible (JA, rencontre) réutilisée si elle existe (comme EN25), sinon créée en 'P'.
            $d = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
            $d->execute([$idJa, $idRenc]);
            $idDispo = $d->fetchColumn();
            $note    = 'Juge-arbitre saisi depuis EN28 (arbitrage club) le ' . date('d/m/Y');
            if ($idDispo) {
                $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                    ->execute([$rc['Date'], $note, $idDispo]);
            } else {
                $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
                    ->execute([$idJa, $idRenc, $rc['Date'], $note]);
                $idDispo = (int) $pdo->lastInsertId();
            }

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
    private function disponibleOuiCra(\PDO $pdo, int $idJa, int $idRenc, string $date, string $note): int
    {
        $d = $pdo->prepare('SELECT Id_Disponible, Reponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $d->execute([$idJa, $idRenc]);
        $dispo = $d->fetch();
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
