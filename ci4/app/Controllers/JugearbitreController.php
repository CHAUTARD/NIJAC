<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * NIJAC – Gestion des Juges-Arbitres (E007), portage CI4 de Nominateur/jugearbitre.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * un Nominateur consulte/modifie la grille et importe des comptes EBP ; import
 * Excel FFTT, import/scan API FFTT par département et mise à jour ponctuelle
 * de Id_LaPoste sont restreints à l'admin — vérifié individuellement dans
 * chaque méthode, comme le fait jugearbitre.php
 * (`in_array($action, $actionsAdmin) && !$isAdmin`).
 *
 * Pas de Model : introspection dynamique des colonnes (auto-migration FFTT),
 * upsert en masse, appels API FFTT avec retry — trop éloignés du Query
 * Builder simple. Réutilise getPDO() directement, comme le fichier legacy.
 */
class JugearbitreController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../vendor/autoload.php';
    }

    private function isAdmin(): bool
    {
        return !empty($_SESSION['utilisateur']['is_admin']);
    }

    /**
     * Auto-migration colonnes enrichissement FFTT sur `ja` — exécutée avant
     * chaque action, comme le fait jugearbitre.php (uniquement pour les
     * requêtes AJAX, pas au rendu de page).
     */
    private function ensureFfttColumns(\PDO $pdo): void
    {
        $colsJa   = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(), 'Field');
        $ffttCols = [
            'Classement'             => 'INT NULL DEFAULT NULL',
            'DateValidationFFTT'     => 'VARCHAR(10) NULL DEFAULT NULL',
            'GradeFFTT'              => "VARCHAR(20) NULL DEFAULT NULL COMMENT 'Grade arbitrage retourné par xml_licence_b'",
            'DateEnrichissementFFTT' => 'DATETIME NULL DEFAULT NULL',
            'Cp'                     => 'VARCHAR(10) NULL DEFAULT NULL',
            'Ville'                  => 'VARCHAR(100) NULL DEFAULT NULL',
        ];
        foreach ($ffttCols as $col => $def) {
            if (!in_array($col, $colsJa)) {
                $pdo->exec("ALTER TABLE ja ADD COLUMN $col $def");
            }
        }
    }

    /** Rang du grade : J3=3, J2=2, JA1=1 — plus c'est haut, plus c'est prioritaire */
    private function gradeRank(string $grade): int
    {
        if (preg_match('/3/', $grade)) {
            return 3;
        }
        if (preg_match('/2/', $grade)) {
            return 2;
        }

        return 1;
    }

    /** Déduplique un tableau de JA (clé Nom+Prénom) en gardant le grade le plus haut */
    private function deduplicateJA(array $rows, string $nomKey, string $prenomKey, string $gradeKey): array
    {
        $byPerson = [];
        foreach ($rows as $r) {
            $key = mb_strtoupper($r[$nomKey] . '|' . $r[$prenomKey]);
            if (!isset($byPerson[$key]) || $this->gradeRank((string) $r[$gradeKey]) > $this->gradeRank((string) $byPerson[$key][$gradeKey])) {
                $byPerson[$key] = $r;
            }
        }

        return array_values($byPerson);
    }

    private function formaterTelephone(?string $tel): ?string
    {
        if ($tel === null || $tel === '') {
            return null;
        }
        $t = preg_replace('/\D/', '', $tel);
        if (strlen($t) === 10) {
            return implode('.', str_split($t, 2));
        }

        return $tel;
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'      => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'     => $moi['id_departement'] ?? '',
            'changeLogin'     => !empty($moi['change_login']),
            'isAdmin'         => $this->isAdmin(),
            'deptUser'        => $this->isAdmin() ? '' : ($moi['id_departement'] ?? ''),
            'deptActifs'      => getDeptActifs(),
            'deptLimitrophes' => getDepartementsLimitrophes(),
        ];

        return view('jugearbitre_index', $data);
    }

    public function liste(): ResponseInterface
    {
        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $dept = $this->request->getGet('dept');
        $dept = ($dept !== null && $dept !== '') ? $dept : null;

        $deptPad   = $dept !== null ? str_pad((string) $dept, 2, '0', STR_PAD_LEFT) : null;
        $whereDept = $dept !== null
            ? 'WHERE (
                   SUBSTRING(j.Id_Club, 3, 2) = ?
                   OR ((j.Id_Club IS NULL OR j.Id_Club = \'\') AND LEFT((SELECT lp2.CodePostal FROM laposte lp2 WHERE lp2.Id_LaPoste = j.Id_LaPoste LIMIT 1), 2) = ?)
               )'
            : '';

        $stmt = $pdo->prepare(
            'SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone,
                    j.Grade, j.Actif, j.Id_Club, j.Id_LaPoste,
                    j.Defiscalisation, j.Nationale, j.NumCompteEBP,
                    j.Classement, j.DateValidationFFTT, j.GradeFFTT, j.DateEnrichissementFFTT,
                    j.Cp, j.Ville,
                    (SELECT cl.Nom FROM Club cl WHERE cl.Id_Club = j.Id_Club LIMIT 1) AS NomClub,
                    COALESCE(j.Cp,    (SELECT lp.CodePostal FROM laposte lp WHERE lp.Id_LaPoste = j.Id_LaPoste LIMIT 1)) AS CodePostalJA,
                    COALESCE(j.Ville, (SELECT lp.Nom        FROM laposte lp WHERE lp.Id_LaPoste = j.Id_LaPoste LIMIT 1)) AS VilleJA,
                    (SELECT COUNT(*) FROM disponible d WHERE d.Id_JA = j.Id_JA) AS NbDispo
             FROM ja j
             ' . $whereDept . '
             ORDER BY j.Nom, j.Prenom'
        );
        $stmt->execute($dept !== null ? [$deptPad, $deptPad] : []);
        $rows = $stmt->fetchAll();
        $rows = $this->deduplicateJA($rows, 'Nom', 'Prenom', 'Grade');

        $rows = array_map(static fn ($r) => [
            'Id_JA'                  => $r['Id_JA'],
            'Nom'                    => $r['Nom'],
            'Prenom'                 => $r['Prenom'],
            'Email'                  => $r['Email'],
            'Telephone'              => $r['Telephone'],
            'Grade'                  => $r['Grade'],
            'Actif'                  => $r['Actif'],
            'Id_Club'                => $r['Id_Club'],
            'Id_LaPoste'             => $r['Id_LaPoste'],
            'NumCompteEBP'           => $r['NumCompteEBP'],
            'Defiscalisation'        => $r['Defiscalisation'],
            'Nationale'              => $r['Nationale'],
            'NbDispo'                => $r['NbDispo'],
            'NomClub'                => $r['NomClub'],
            'CP'                     => $r['CodePostalJA'],
            'Ville'                  => $r['VilleJA'],
            'Classement'             => $r['Classement'],
            'DateValidationFFTT'     => $r['DateValidationFFTT'],
            'GradeFFTT'              => $r['GradeFFTT'],
            'DateEnrichissementFFTT' => $r['DateEnrichissementFFTT'],
        ], $rows);

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function clubsParDept(): ResponseInterface
    {
        $pdo  = getPDO();
        $this->ensureFfttColumns($pdo);

        $dept = trim((string) ($this->request->getGet('dept') ?? ''));
        if ($dept === '') {
            $stmt = $pdo->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom');
        } else {
            $deptPad = str_pad($dept, 2, '0', STR_PAD_LEFT);
            $stmt    = $pdo->prepare(
                'SELECT cl.Id_Club, cl.Nom
                 FROM Club cl
                 JOIN Salle s  ON s.Id_Club   = cl.Id_Club AND s.EstPrincipale = 1
                 JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                 WHERE LEFT(lp.CodePostal, 2) = ?
                 ORDER BY cl.Nom'
            );
            $stmt->execute([$deptPad]);
        }

        return $this->response->setJSON(['ok' => true, 'clubs' => $stmt->fetchAll()]);
    }

    /**
     * Non appelée par le JS de jugearbitre.php (aucun autre fichier n'y fait
     * appel non plus) — portée à l'identique pour parité, comme
     * liste_tables_db dans CleanController.
     */
    public function majLaposte(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $idJA      = (int) ($this->request->getPost('id_ja') ?? 0);
        $idLaPoste = ($this->request->getPost('id_laposte') ?? '') !== '' ? (int) $this->request->getPost('id_laposte') : null;
        $cp        = ($this->request->getPost('cp') ?? '') !== '' ? trim($this->request->getPost('cp')) : null;
        $ville     = ($this->request->getPost('ville') ?? '') !== '' ? trim($this->request->getPost('ville')) : null;

        if ($idJA <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Id_JA invalide.']);
        }

        $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
            ->execute([$idLaPoste, $cp, $ville, $idJA]);

        return $this->response->setJSON(['ok' => true]);
    }

    public function majBdd(): ResponseInterface
    {

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $lignes = json_decode($this->request->getPost('lignes') ?? '[]', true);
        if (!is_array($lignes)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $inserts       = 0;
        $updates       = 0;
        $erreurs       = [];
        $avertissements = [];

        // Id_Club a une contrainte FK vers Club : un code présent dans l'Excel FFTT
        // mais pas encore synchronisé dans la table Club (nouveau club, renommage,
        // fusion...) ferait échouer l'INSERT/UPDATE en bloc. On le détecte en amont
        // et on enregistre quand même le JA sans club (à corriger manuellement),
        // plutôt que de perdre toute la ligne pour une seule référence orpheline.
        $clubsValides = array_flip(array_column($pdo->query('SELECT Id_Club FROM Club')->fetchAll(), 'Id_Club'));

        $stmtCheck  = $pdo->prepare('SELECT COUNT(*) FROM ja WHERE Id_JA = ?');
        $stmtInsert = $pdo->prepare(
            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Telephone, Grade, Actif,
                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                             Cp, Ville)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtUpdate = $pdo->prepare(
            'UPDATE ja SET Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                           Actif=?, Id_Club=?, Id_LaPoste=?,
                           Defiscalisation=?, Nationale=?, NumCompteEBP=?,
                           Cp=?, Ville=?
             WHERE Id_JA=?'
        );
        $stmtInsertAuto = $pdo->prepare(
            'INSERT INTO ja (Nom, Prenom, Email, Telephone, Grade, Actif,
                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                             Cp, Ville)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lignes as $l) {
            $id        = (int) ($l['id'] ?? 0);
            $nom       = trim($l['nom'] ?? '');
            $prenom    = trim($l['prenom'] ?? '');
            $email     = ($l['email'] ?? '') !== '' ? $l['email'] : null;
            $tel       = $this->formaterTelephone(($l['telephone'] ?? '') !== '' ? $l['telephone'] : null);
            $grade     = trim($l['grade'] ?? '');
            $actif     = !empty($l['actif']) ? 1 : 0;
            $defisc    = !empty($l['defiscalisation']) ? 1 : 0;
            $nationale = !empty($l['nationale']) ? 1 : 0;
            $idClub    = ($l['id_club'] ?? '') !== '' ? trim($l['id_club']) : null;
            $idLap     = ($l['id_laposte'] ?? '') !== '' ? (int) $l['id_laposte'] : null;
            $cpteEbp   = ($l['num_compte_ebp'] ?? '') !== '' ? trim($l['num_compte_ebp']) : null;
            // Cp/Ville sont NOT NULL en base : chaîne vide plutôt que null si inconnu
            $cp    = trim((string) ($l['cp'] ?? ''));
            $ville = trim((string) ($l['ville'] ?? ''));

            if ($nom === '') {
                continue;
            }

            if ($idClub !== null && !isset($clubsValides[$idClub])) {
                $avertissements[] = "$nom $prenom : club « $idClub » inconnu (pas encore synchronisé) — enregistré sans club.";
                $idClub = null;
            }

            try {
                if ($id > 0) {
                    $stmtCheck->execute([$id]);
                    if ((int) $stmtCheck->fetchColumn() > 0) {
                        $stmtUpdate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $id]);
                        $updates++;
                    } else {
                        $stmtInsert->execute([$id, $nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville]);
                        $inserts++;
                    }
                } else {
                    $stmtInsertAuto->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville]);
                    $inserts++;
                }
            } catch (\PDOException $ex) {
                $erreurs[] = "$nom $prenom : " . $ex->getMessage();
            }
        }

        $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
        if ($avertissements) {
            $msg .= ' Avertissements : ' . implode(' | ', $avertissements);
        }
        if ($erreurs) {
            $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
        }

        return $this->response->setJSON(['ok' => empty($erreurs), 'msg' => $msg]);
    }

    public function getClubsDept(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $dep = trim($this->request->getPost('dep') ?? '');
        if ($dep === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
        }

        $api   = getFfttApi();
        $clubs = $api->getClubsDepartement($dep);

        return $this->response->setJSON(['ok' => true, 'clubs' => array_map(static fn ($c) => [
            'numero' => $c['numero'] ?? $c['numclu'] ?? '',
            'nom'    => $c['nom'] ?? $c['nomclub'] ?? '',
        ], $clubs)]);
    }

    public function importFfttClub(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(180);
        $api         = getFfttApi();
        $membres     = $api->getLicenciesClub($numClub);
        $trouves     = [];
        $erreurs     = 0;
        $erreursMsgs = [];

        foreach ($membres as $m) {
            $licence = trim((string) ($m['licence'] ?? ''));
            if ($licence === '') {
                continue;
            }

            try {
                $lb = null;
                for ($tentative = 0; $tentative < 2; $tentative++) {
                    try {
                        $lb = $api->getLicenceB($licence);
                        break;
                    } catch (\RuntimeException $e) {
                        if ($tentative === 0) {
                            usleep(600_000);
                        } else {
                            throw $e;
                        }
                    }
                }
                if (!$lb) {
                    continue;
                }

                $ja  = trim((string) ($lb['ja'] ?? ''));
                $arb = trim((string) ($lb['arb'] ?? ''));
                if ($ja === '' && $arb === '') {
                    continue;
                }

                $grade  = $ja ?: $arb;
                $nom    = mb_strtoupper(trim((string) ($lb['nom'] ?? '')), 'UTF-8');
                $prenom = trim((string) ($lb['prenom'] ?? ''));
                $email  = trim((string) ($lb['email'] ?? ''));
                $idClub = trim((string) ($lb['numclub'] ?? $numClub));

                // Seuls JA1, JA2, JA3 — les AR sont exclus
                if (!preg_match('/^JA[123]$/i', $grade)) {
                    continue;
                }

                $gradeNorm = strtoupper($grade);

                // Actif = saison couverte par la licence encore en cours (1er juillet → 30 juin)
                $dateValidStr = trim((string) ($lb['validation'] ?? ''));
                $actif        = 0;
                if ($dateValidStr !== '' && preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $dateValidStr, $mv)) {
                    $validated     = new \DateTime($mv[3] . '-' . $mv[2] . '-' . str_pad($mv[1], 2, '0', STR_PAD_LEFT));
                    $seasonEndYear = (int) $validated->format('n') >= 7
                        ? (int) $validated->format('Y') + 1
                        : (int) $validated->format('Y');
                    $actif = new \DateTime('today') <= new \DateTime($seasonEndYear . '-06-30') ? 1 : 0;
                }

                // Résolution CP / Ville / Id_LaPoste depuis les données FFTT
                $cpFFTT    = trim((string) ($lb['cp'] ?? ''));
                $villeFFTT = normaliserVille((string) ($lb['ville'] ?? ''));
                $idLaPoste = null;
                $cpFinal   = $cpFFTT !== '' ? $cpFFTT : '';
                $villeFinal = $villeFFTT;
                if ($cpFFTT !== '') {
                    $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                    $stmtLap->execute([$cpFFTT]);
                    $lap = $stmtLap->fetch();
                    if ($lap) {
                        $idLaPoste  = $lap['Id_LaPoste'];
                        $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string) ($lap['Nom'] ?? ''));
                    }
                }

                $exists = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                $exists->execute([$licence]);
                if ($exists->fetchColumn()) {
                    $pdo->prepare(
                        'UPDATE ja SET GradeFFTT=?, Actif=?, DateEnrichissementFFTT=NOW(),
                         Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                         WHERE Id_JA=?'
                    )->execute([$gradeNorm, $actif, $cpFinal, $villeFinal, $idLaPoste, $licence]);
                    $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'mis_a_jour'];
                } else {
                    $pdo->prepare(
                        'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, GradeFFTT, Actif, Id_Club,
                                         Defiscalisation, Nationale, DateEnrichissementFFTT,
                                         Id_LaPoste, Cp, Ville)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), ?, ?, ?)'
                    )->execute([$licence, $nom, $prenom, $email ?: null, $gradeNorm, $gradeNorm, $actif, $idClub,
                        $idLaPoste, $cpFinal, $villeFinal]);
                    $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'nouveau'];
                }
            } catch (\RuntimeException $e) {
                $erreurs++;
                $msg = $e->getMessage();
                error_log("[NIJAC] import_fftt_club $numClub licence=$licence : $msg");
                if (count($erreursMsgs) < 10) {
                    $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
    }

    public function scanFfttClub(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(180);
        $api         = getFfttApi();
        $membres     = $api->getLicenciesClub($numClub);
        $trouves     = [];
        $erreurs     = 0;
        $erreursMsgs = [];

        foreach ($membres as $m) {
            $licence = trim((string) ($m['licence'] ?? ''));
            if ($licence === '') {
                continue;
            }

            try {
                $lb = null;
                for ($tentative = 0; $tentative < 2; $tentative++) {
                    try {
                        $lb = $api->getLicenceB($licence);
                        break;
                    } catch (\RuntimeException $e) {
                        if ($tentative === 0) {
                            usleep(600_000);
                        } else {
                            throw $e;
                        }
                    }
                }
                if (!$lb) {
                    continue;
                }

                $ja  = trim((string) ($lb['ja'] ?? ''));
                $arb = trim((string) ($lb['arb'] ?? ''));
                if ($ja === '' && $arb === '') {
                    continue;
                }
                $grade = $ja ?: $arb;
                if (!preg_match('/^JA[123]$/i', $grade)) {
                    continue;
                }

                $gradeNorm = strtoupper($grade);
                $nom       = mb_strtoupper(trim((string) ($lb['nom'] ?? '')), 'UTF-8');
                $prenom    = trim((string) ($lb['prenom'] ?? ''));
                $email     = trim((string) ($lb['email'] ?? ''));
                $idClub    = trim((string) ($lb['numclub'] ?? $numClub));

                $dateValidStr = trim((string) ($lb['validation'] ?? ''));
                $actif        = 0;
                if ($dateValidStr !== '' && preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $dateValidStr, $mv)) {
                    $validated     = new \DateTime($mv[3] . '-' . $mv[2] . '-' . str_pad($mv[1], 2, '0', STR_PAD_LEFT));
                    $seasonEndYear = (int) $validated->format('n') >= 7
                        ? (int) $validated->format('Y') + 1
                        : (int) $validated->format('Y');
                    $actif = new \DateTime('today') <= new \DateTime($seasonEndYear . '-06-30') ? 1 : 0;
                }

                $cpFFTT    = trim((string) ($lb['cp'] ?? ''));
                $villeFFTT = normaliserVille((string) ($lb['ville'] ?? ''));
                $idLaPoste = null;
                $cpFinal   = $cpFFTT;
                $villeFinal = $villeFFTT;
                if ($cpFFTT !== '') {
                    $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                    $stmtLap->execute([$cpFFTT]);
                    $lap = $stmtLap->fetch();
                    if ($lap) {
                        $idLaPoste  = $lap['Id_LaPoste'];
                        $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string) ($lap['Nom'] ?? ''));
                    }
                }

                $stmtEx = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                $stmtEx->execute([$licence]);
                $enBase = (bool) $stmtEx->fetchColumn();

                $trouves[] = [
                    'licence'    => $licence,
                    'nom'        => $nom,
                    'prenom'     => $prenom,
                    'email'      => $email,
                    'grade'      => $gradeNorm,
                    'actif'      => $actif,
                    'id_club'    => $idClub,
                    'id_laposte' => $idLaPoste,
                    'cp'         => $cpFinal,
                    'ville'      => $villeFinal,
                    'en_base'    => $enBase,
                ];
            } catch (\RuntimeException $e) {
                $erreurs++;
                $msg = $e->getMessage();
                error_log("[NIJAC] scan_fftt_club $numClub licence=$licence : $msg");
                if (count($erreursMsgs) < 10) {
                    $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
    }

    public function importFfttSelected(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $licences = json_decode($this->request->getPost('licences') ?? '[]', true);
        if (!is_array($licences)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $nouveaux = 0;
        $maj      = 0;

        foreach ($licences as $ja) {
            $licence    = trim((string) ($ja['licence'] ?? ''));
            $nom        = trim((string) ($ja['nom'] ?? ''));
            $prenom     = trim((string) ($ja['prenom'] ?? ''));
            $email      = trim((string) ($ja['email'] ?? '')) ?: null;
            $grade      = trim((string) ($ja['grade'] ?? ''));
            $actif      = (int) ($ja['actif'] ?? 0);
            $idClub     = trim((string) ($ja['id_club'] ?? ''));
            $idLaPoste  = $ja['id_laposte'] ?? null;
            $cpFinal    = trim((string) ($ja['cp'] ?? ''));
            $villeFinal = trim((string) ($ja['ville'] ?? ''));

            if ($licence === '' || $grade === '') {
                continue;
            }

            $stmtEx = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
            $stmtEx->execute([$licence]);
            if ($stmtEx->fetchColumn()) {
                $pdo->prepare(
                    'UPDATE ja SET GradeFFTT=?, Actif=?, DateEnrichissementFFTT=NOW(),
                     Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                     WHERE Id_JA=?'
                )->execute([$grade, $actif, $cpFinal, $villeFinal, $idLaPoste, $licence]);
                $maj++;
            } else {
                $pdo->prepare(
                    'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, GradeFFTT, Actif, Id_Club,
                                     Defiscalisation, Nationale, DateEnrichissementFFTT,
                                     Id_LaPoste, Cp, Ville)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), ?, ?, ?)'
                )->execute([$licence, $nom, $prenom, $email, $grade, $grade, $actif, $idClub, $idLaPoste, $cpFinal, $villeFinal]);
                $nouveaux++;
            }
        }

        return $this->response->setJSON(['ok' => true, 'nouveaux' => $nouveaux, 'maj' => $maj]);
    }

    public function importCsvEbp(): ResponseInterface
    {

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $lignes = json_decode($this->request->getPost('lignes') ?? '[]', true);
        if (!is_array($lignes)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $ok     = 0;
        $echecs = [];

        $stmtSearch = $pdo->prepare(
            "SELECT Id_JA, Nom, Prenom FROM ja
             WHERE UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Nom)), '%')
               AND UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Prenom)), '%')"
        );
        $stmtUpdate = $pdo->prepare('UPDATE ja SET NumCompteEBP = ? WHERE Id_JA = ?');

        foreach ($lignes as $idx => $ligne) {
            $numEbp    = trim((string) ($ligne['num'] ?? ''));
            $nomPrenom = trim((string) ($ligne['nom'] ?? ''));
            $lineNo    = $idx + 1;

            if ($numEbp === '' || $nomPrenom === '') {
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'Ligne incomplète'];
                continue;
            }

            if (stripos($nomPrenom, 'fournisseur') === 0) {
                continue;
            }

            $stmtSearch->execute([$nomPrenom, $nomPrenom]);
            $found = $stmtSearch->fetchAll();

            if (count($found) === 0) {
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'JA introuvable en base'];
            } elseif (count($found) > 1) {
                $dups     = implode(', ', array_map(static fn ($r) => $r['Id_JA'], $found));
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => "Plusieurs JA trouvés ($dups) — non mis à jour"];
            } else {
                $stmtUpdate->execute([$numEbp, $found[0]['Id_JA']]);
                $ok++;
            }
        }

        return $this->response->setJSON(['ok' => true, 'maj' => $ok, 'echecs' => $echecs]);
    }

    /**
     * Non appelée par le JS de jugearbitre.php — portée à l'identique pour
     * parité (voir majLaposte()).
     */
    public function enrichirFftt(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $idJa = trim($this->request->getPost('id_ja') ?? '');
        if ($idJa === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'id_ja manquant.']);
        }

        $api = getFfttApi();
        $lic = $api->getLicenceB($idJa);
        if (!$lic) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Licence $idJa introuvable dans l'API FFTT."]);
        }

        $classement = isset($lic['point']) && $lic['point'] !== '' ? (int) $lic['point'] : null;
        $dateValid  = isset($lic['validation']) && $lic['validation'] !== '' ? (string) $lic['validation'] : null;
        $arb        = isset($lic['arb']) && $lic['arb'] !== '' ? trim((string) $lic['arb']) : null;
        $ja         = isset($lic['ja']) && $lic['ja'] !== '' ? trim((string) $lic['ja']) : null;
        $gradeFFTT  = $ja ?? $arb ?? null;

        // CP, Ville et Id_LaPoste volontairement exclus : les données FFTT sont moins fiables que la BDD locale
        $pdo->prepare(
            'UPDATE ja SET Classement=?, DateValidationFFTT=?, GradeFFTT=?, DateEnrichissementFFTT=NOW() WHERE Id_JA=?'
        )->execute([$classement, $dateValid, $gradeFFTT, $idJa]);

        return $this->response->setJSON([
            'ok'         => true,
            'classement' => $classement,
            'date_valid' => $dateValid,
            'grade_fftt' => $gradeFFTT,
            'nom_fftt'   => ($lic['nom'] ?? '') . ' ' . ($lic['prenom'] ?? ''),
            'club_fftt'  => $lic['nomclub'] ?? '',
        ]);
    }

    public function importerExcel(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé']);
        }

        $pdo = getPDO();
        $this->ensureFfttColumns($pdo);

        $file = $this->request->getFile('fichier');
        if ($file === null || !$file->isValid()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu (post_max_size = ' . ini_get('post_max_size') . ').']);
        }
        if (strtolower($file->getClientExtension()) !== 'xlsx') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
        }

        set_time_limit(180);

        try {
            $spreadsheet = IOFactory::load($file->getTempName());
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier illisible ou corrompu : ' . $e->getMessage()]);
        }
        $sheet  = $spreadsheet->getActiveSheet();
        $maxRow = $sheet->getHighestRow();

        // Charger tous les clubs pour enrichir nom_club
        $clubsMap = [];
        foreach ($pdo->query('SELECT Id_Club, Nom FROM Club')->fetchAll() as $c) {
            $clubsMap[$c['Id_Club']] = $c['Nom'];
        }

        $lignes = [];
        // Colonnes : A=Id_JA, B=Nom, C=Prénom, H=Id_Club, J=Grade,
        //            N=Actif, R=CodePostal, S=Ville, T=Email, U=Téléphone(alt), V=Téléphone
        for ($row = 3; $row <= $maxRow; $row++) {
            $grade = trim((string) $sheet->getCell('J' . $row)->getValue());
            if (strncasecmp($grade, 'JA', 2) !== 0) {
                continue;
            }

            $idJA     = trim((string) $sheet->getCell('A' . $row)->getValue());
            $nom      = trim((string) $sheet->getCell('B' . $row)->getValue());
            $prenom   = trim((string) $sheet->getCell('C' . $row)->getValue());
            $idClub   = trim((string) $sheet->getCell('H' . $row)->getValue());
            $actifRaw = trim((string) $sheet->getCell('N' . $row)->getValue());
            $cp       = trim((string) $sheet->getCell('R' . $row)->getValue());
            $ville    = trim((string) $sheet->getCell('S' . $row)->getValue());
            $email    = trim((string) $sheet->getCell('T' . $row)->getValue());
            $telU     = trim((string) $sheet->getCell('U' . $row)->getValue());
            $telV     = trim((string) $sheet->getCell('V' . $row)->getValue());

            if ($nom === '' && $prenom === '') {
                continue;
            }

            $tel   = $telV !== '' ? $telV : $telU;
            $actif = strtolower(trim($actifRaw)) === 'actif' ? 1 : 0;

            $lignes[] = [
                'id'         => $idJA !== '' ? (int) $idJA : 0,
                'nom'        => mb_strtoupper($nom, 'UTF-8'),
                'prenom'     => mb_convert_case($prenom, MB_CASE_TITLE, 'UTF-8'),
                'email'      => $email !== '' ? $email : null,
                'telephone'  => $this->formaterTelephone($tel !== '' ? $tel : null),
                'grade'      => $grade,
                'actif'      => $actif,
                'id_club'    => $idClub !== '' ? $idClub : null,
                'nom_club'   => $idClub !== '' ? ($clubsMap[$idClub] ?? '') : '',
                'id_laposte' => null, // résolu côté JS avec progression
                'cp'         => $cp,
                'ville'      => $ville,
            ];
        }

        $lignes = $this->deduplicateJA($lignes, 'nom', 'prenom', 'grade');

        return $this->response->setJSON(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
    }
}
