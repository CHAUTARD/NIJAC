<?php
/**
 * NIJAC – Gestion des Juges-Arbitres (E007)
 *
 * Importe et gère la liste des Juges-Arbitres (JA) depuis un fichier Excel FFTT.
 * Permet de visualiser, modifier et activer/désactiver les fiches JA.
 * Normalise automatiquement les noms de ville et enrichit les données
 * (code postal, ville, coordonnées GPS) depuis la table laposte.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 * Déplacé dans Nominateur/ : 2026-06-12
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Sécurité ──────────────────────────────────────────────────────────────────
$authRedirect = '../index.php';
require __DIR__ . '/../includes/auth_required.php';

$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Formater un numéro de téléphone ───────────────────────────────────────────
require_once __DIR__ . '/../config/helpers.php';

/** Rang du grade : J3=3, J2=2, JA1=1 — plus c'est haut, plus c'est prioritaire */
function gradeRank(string $grade): int
{
    if (preg_match('/3/', $grade)) return 3;
    if (preg_match('/2/', $grade)) return 2;
    return 1;
}

/** Déduplique un tableau de JA (clé Nom+Prénom) en gardant le grade le plus haut */
function deduplicateJA(array $rows, string $nomKey, string $prenomKey, string $gradeKey): array
{
    $byPerson = [];
    foreach ($rows as $r) {
        $key = mb_strtoupper($r[$nomKey] . '|' . $r[$prenomKey]);
        if (!isset($byPerson[$key]) || gradeRank((string)$r[$gradeKey]) > gradeRank((string)$byPerson[$key][$gradeKey])) {
            $byPerson[$key] = $r;
        }
    }
    return array_values($byPerson);
}

function formaterTelephone(?string $tel): ?string
{
    if ($tel === null || $tel === '') return null;
    $t = preg_replace('/\D/', '', $tel); // garder uniquement les chiffres
    if (strlen($t) === 10) {
        return implode('.', str_split($t, 2));
    }
    return $tel;
}

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    // Actions réservées aux administrateurs
    $actionsAdmin = ['importer_excel', 'sauvegarder', 'supprimer', 'maj_laposte', 'enrichir_fftt', 'get_clubs_dept', 'import_fftt_club', 'scan_fftt_club', 'import_fftt_selected'];
    if (in_array($action, $actionsAdmin) && !$isAdmin) {
        echo json_encode(['ok' => false, 'err' => 'Accès refusé']);
        exit;
    }

    try {
        $pdo = getPDO();

        // ── Auto-migration : colonnes enrichissement FFTT ─────────────────
        $colsJa = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(), 'Field');
        $ffttCols = [
            'Classement'              => 'INT NULL DEFAULT NULL',
            'DateValidationFFTT'      => 'VARCHAR(10) NULL DEFAULT NULL',
            'GradeFFTT'               => "VARCHAR(20) NULL DEFAULT NULL COMMENT 'Grade arbitrage retourné par xml_licence_b'",
            'DateEnrichissementFFTT'  => 'DATETIME NULL DEFAULT NULL',
            'Cp'                      => 'VARCHAR(10) NULL DEFAULT NULL',
            'Ville'                   => 'VARCHAR(100) NULL DEFAULT NULL',
        ];
        foreach ($ffttCols as $col => $def) {
            if (!in_array($col, $colsJa)) $pdo->exec("ALTER TABLE ja ADD COLUMN $col $def");
        }

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $dept = isset($_POST['dept']) && $_POST['dept'] !== '' ? $_POST['dept'] : null;

            $deptPad   = $dept !== null ? str_pad((string)$dept, 2, '0', STR_PAD_LEFT) : null;
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
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $rows = deduplicateJA($rows, 'Nom', 'Prenom', 'Grade');

            // Normalisation des clés pour éviter les problèmes de casse PDO
            $rows = array_map(function($r) {
                // Retrouver les clés indépendamment de la casse
                $find = function($row, $key) {
                    if (isset($row[$key])) return $row[$key];
                    $keyLow = strtolower($key);
                    foreach ($row as $k => $v) {
                        if (strtolower($k) === $keyLow) return $v;
                    }
                    return null;
                };
                return [
                    'Id_JA'          => $find($r, 'Id_JA'),
                    'Nom'            => $find($r, 'Nom'),
                    'Prenom'         => $find($r, 'Prenom'),
                    'Email'          => $find($r, 'Email'),
                    'Telephone'      => $find($r, 'Telephone'),
                    'Grade'          => $find($r, 'Grade'),
                    'Actif'          => $find($r, 'Actif'),
                    'Id_Club'        => $find($r, 'Id_Club'),
                    'Id_LaPoste'     => $find($r, 'Id_LaPoste'),
                    'NumCompteEBP'   => $find($r, 'NumCompteEBP'),
                    'Defiscalisation'=> $find($r, 'Defiscalisation'),
                    'Nationale'      => $find($r, 'Nationale'),
                    'NbDispo'        => $find($r, 'NbDispo'),
                    'NomClub'                 => $find($r, 'NomClub'),
                    'CP'                      => $find($r, 'CodePostalJA'),
                    'Ville'                   => $find($r, 'VilleJA'),
                    'Classement'              => $find($r, 'Classement'),
                    'DateValidationFFTT'      => $find($r, 'DateValidationFFTT'),
                    'GradeFFTT'               => $find($r, 'GradeFFTT'),
                    'DateEnrichissementFFTT'  => $find($r, 'DateEnrichissementFFTT'),
                ];
            }, $rows);

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Clubs filtrés par département ─────────────────────────────────
        if ($action === 'clubs_par_dept') {
            $dept = trim($_POST['dept'] ?? '');
            if ($dept === '') {
                $stmt = $pdo->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom');
            } else {
                $deptPad = str_pad($dept, 2, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare(
                    'SELECT cl.Id_Club, cl.Nom
                     FROM Club cl
                     JOIN Salle s  ON s.Id_Club   = cl.Id_Club AND s.EstPrincipale = 1
                     JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                     WHERE LEFT(lp.CodePostal, 2) = ?
                     ORDER BY cl.Nom'
                );
                $stmt->execute([$deptPad]);
            }
            $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => $clubs]);
            exit;
        }

        // ── Mise à jour immédiate Id_LaPoste ───────────────────────────────
        if ($action === 'maj_laposte') {
            $idJA      = (int)($_POST['id_ja'] ?? 0);
            $idLaPoste = ($_POST['id_laposte'] ?? '') !== '' ? (int)$_POST['id_laposte'] : null;
            $cp        = ($_POST['cp']    ?? '') !== '' ? trim($_POST['cp'])    : null;
            $ville     = ($_POST['ville'] ?? '') !== '' ? trim($_POST['ville']) : null;
            if ($idJA <= 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id_JA invalide.']);
                exit;
            }
            $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
                ->execute([$idLaPoste, $cp, $ville, $idJA]);
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Mettre à jour la BDD (UPSERT) ─────────────────────────────────
        if ($action === 'maj_bdd') {
            $lignes = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

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

            foreach ($lignes as $l) {
                $id      = (int)($l['id']              ?? 0);
                $nom     = trim($l['nom']              ?? '');
                $prenom  = trim($l['prenom']           ?? '');
                $email   = $l['email']   !== '' && $l['email']   !== null ? $l['email']   : null;
                $tel     = formaterTelephone($l['telephone'] !== '' && $l['telephone'] !== null ? $l['telephone'] : null);
                $grade   = trim($l['grade']            ?? '');
                $actif     = !empty($l['actif']) ? 1 : 0;
                $defisc    = !empty($l['defiscalisation']) ? 1 : 0;
                $nationale = !empty($l['nationale']) ? 1 : 0;
                $idClub  = ($l['id_club'] ?? '') !== '' ? trim($l['id_club']) : null;
                $idLap   = $l['id_laposte'] !== '' && $l['id_laposte'] !== null ? (int)$l['id_laposte'] : null;
                $cpteEbp = $l['num_compte_ebp'] !== '' && $l['num_compte_ebp'] !== null ? trim($l['num_compte_ebp']) : null;
                // Cp/Ville sont NOT NULL en base : chaîne vide plutôt que null si inconnu
                $cp      = trim((string)($l['cp']    ?? ''));
                $ville   = trim((string)($l['ville'] ?? ''));

                if ($nom === '') continue;

                try {
                    if ($id > 0) {
                        $stmtCheck->execute([$id]);
                        if ((int)$stmtCheck->fetchColumn() > 0) {
                            $stmtUpdate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $id]);
                            $updates++;
                        } else {
                            $stmtInsert->execute([$id, $nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville]);
                            $inserts++;
                        }
                    } else {
                        // Pas d'Id_JA → INSERT auto-increment
                        $pdo->prepare(
                            'INSERT INTO ja (Nom, Prenom, Email, Telephone, Grade, Actif,
                                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                                             Cp, Ville)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville]);
                        $inserts++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "$nom $prenom : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            ob_end_clean();
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

        // ── Récupérer la liste des clubs d'un département ─────────────────
        if ($action === 'get_clubs_dept') {
            $dep = trim($_POST['dep'] ?? '');
            if ($dep === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Département manquant.']); exit; }
            $api   = getFfttApi();
            $clubs = $api->getClubsDepartement($dep);
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => array_map(fn($c) => [
                'numero' => $c['numero'] ?? $c['numclu'] ?? '',
                'nom'    => $c['nom']    ?? $c['nomclub'] ?? '',
            ], $clubs)]);
            exit;
        }

        // ── Importer les JAs d'un club via l'API FFTT ──────────────────────
        if ($action === 'import_fftt_club') {
            $numClub = trim($_POST['num_club'] ?? '');
            if ($numClub === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Numéro de club manquant.']); exit; }

            set_time_limit(180);
            $api     = getFfttApi();
            $membres = $api->getLicenciesClub($numClub);
            $trouves = [];
            $erreurs = 0;
            $erreursMsgs = [];

            foreach ($membres as $m) {
                $licence = trim((string)($m['licence'] ?? ''));
                if ($licence === '') continue;

                try {
                    // 1 retry avec pause de 600 ms si l'API throttle
                    $lb = null;
                    for ($tentative = 0; $tentative < 2; $tentative++) {
                        try {
                            $lb = $api->getLicenceB($licence);
                            break;
                        } catch (RuntimeException $e) {
                            if ($tentative === 0) {
                                usleep(600_000);
                            } else {
                                throw $e;
                            }
                        }
                    }
                    if (!$lb) continue;

                    $ja  = trim((string)($lb['ja']  ?? ''));
                    $arb = trim((string)($lb['arb'] ?? ''));
                    if ($ja === '' && $arb === '') continue;

                    $grade = $ja ?: $arb;
                    $nom   = mb_strtoupper(trim((string)($lb['nom']    ?? '')), 'UTF-8');
                    $prenom = trim((string)($lb['prenom'] ?? ''));
                    $email  = trim((string)($lb['email']  ?? ''));
                    $idClub = trim((string)($lb['numclub'] ?? $numClub));

                    // Seuls JA1, JA2, JA3 — les AR sont exclus
                    if (!preg_match('/^JA[123]$/i', $grade)) continue;

                    $gradeNorm = strtoupper($grade);

                    // Actif = saison couverte par la licence encore en cours (1er juillet → 30 juin)
                    $dateValidStr = trim((string)($lb['validation'] ?? ''));
                    $actif = 0;
                    if ($dateValidStr !== '') {
                        if (preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $dateValidStr, $mv)) {
                            $validated = new DateTime($mv[3] . '-' . $mv[2] . '-' . str_pad($mv[1], 2, '0', STR_PAD_LEFT));
                            // La saison se termine le 30 juin : si validée juil-déc → fin = juin N+1, sinon fin = juin N
                            $seasonEndYear = (int)$validated->format('n') >= 7
                                ? (int)$validated->format('Y') + 1
                                : (int)$validated->format('Y');
                            $actif = new DateTime('today') <= new DateTime($seasonEndYear . '-06-30') ? 1 : 0;
                        }
                    }

                    // Résolution CP / Ville / Id_LaPoste depuis les données FFTT
                    $cpFFTT    = trim((string)($lb['cp']    ?? ''));
                    $villeFFTT = normaliserVille((string)($lb['ville'] ?? ''));
                    $idLaPoste  = null;
                    $cpFinal    = $cpFFTT !== '' ? $cpFFTT : '';   // '' si l'API ne fournit pas de CP
                    $villeFinal = $villeFFTT;
                    if ($cpFFTT !== '') {
                        $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                        $stmtLap->execute([$cpFFTT]);
                        $lap = $stmtLap->fetch(PDO::FETCH_ASSOC);
                        if ($lap) {
                            $idLaPoste  = $lap['Id_LaPoste'];
                            $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string)($lap['Nom'] ?? ''));
                        }
                    }

                    // Upsert dans ja (Id_JA = numéro de licence)
                    $exists = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                    $exists->execute([$licence]);
                    if ($exists->fetchColumn()) {
                        // Mise à jour du grade FFTT, Actif, + CP/Ville si encore vides
                        $pdo->prepare(
                            'UPDATE ja SET GradeFFTT=?, Actif=?, DateEnrichissementFFTT=NOW(),
                             Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                             WHERE Id_JA=?'
                        )->execute([$gradeNorm, $actif, $cpFinal, $villeFinal, $idLaPoste, $licence]);
                        $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'mis_a_jour'];
                    } else {
                        // Insertion d'un nouveau JA
                        $pdo->prepare(
                            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, GradeFFTT, Actif, Id_Club,
                                             Defiscalisation, Nationale, DateEnrichissementFFTT,
                                             Id_LaPoste, Cp, Ville)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), ?, ?, ?)'
                        )->execute([$licence, $nom, $prenom, $email ?: null, $gradeNorm, $gradeNorm, $actif, $idClub,
                                    $idLaPoste, $cpFinal, $villeFinal]);
                        $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'nouveau'];
                    }
                } catch (RuntimeException $e) {
                    $erreurs++;
                    $msg = $e->getMessage();
                    error_log("[NIJAC] import_fftt_club $numClub licence=$licence : $msg");
                    if (count($erreursMsgs) < 10) {
                        $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                    }
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
            exit;
        }

        // ── Scanner un club sans écriture en base (pour sélection manuelle) ──
        if ($action === 'scan_fftt_club') {
            $numClub = trim($_POST['num_club'] ?? '');
            if ($numClub === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Numéro de club manquant.']); exit; }

            set_time_limit(180);
            $api     = getFfttApi();
            $membres = $api->getLicenciesClub($numClub);
            $trouves = [];
            $erreurs = 0;
            $erreursMsgs = [];

            foreach ($membres as $m) {
                $licence = trim((string)($m['licence'] ?? ''));
                if ($licence === '') continue;

                try {
                    $lb = null;
                    for ($tentative = 0; $tentative < 2; $tentative++) {
                        try {
                            $lb = $api->getLicenceB($licence);
                            break;
                        } catch (RuntimeException $e) {
                            if ($tentative === 0) { usleep(600_000); } else { throw $e; }
                        }
                    }
                    if (!$lb) continue;

                    $ja  = trim((string)($lb['ja']  ?? ''));
                    $arb = trim((string)($lb['arb'] ?? ''));
                    if ($ja === '' && $arb === '') continue;
                    $grade = $ja ?: $arb;
                    if (!preg_match('/^JA[123]$/i', $grade)) continue;

                    $gradeNorm  = strtoupper($grade);
                    $nom        = mb_strtoupper(trim((string)($lb['nom']    ?? '')), 'UTF-8');
                    $prenom     = trim((string)($lb['prenom'] ?? ''));
                    $email      = trim((string)($lb['email']  ?? ''));
                    $idClub     = trim((string)($lb['numclub'] ?? $numClub));

                    $dateValidStr = trim((string)($lb['validation'] ?? ''));
                    $actif = 0;
                    if ($dateValidStr !== '' && preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $dateValidStr, $mv)) {
                        $validated = new DateTime($mv[3] . '-' . $mv[2] . '-' . str_pad($mv[1], 2, '0', STR_PAD_LEFT));
                        $seasonEndYear = (int)$validated->format('n') >= 7
                            ? (int)$validated->format('Y') + 1
                            : (int)$validated->format('Y');
                        $actif = new DateTime('today') <= new DateTime($seasonEndYear . '-06-30') ? 1 : 0;
                    }

                    $cpFFTT    = trim((string)($lb['cp']    ?? ''));
                    $villeFFTT = normaliserVille((string)($lb['ville'] ?? ''));
                    $idLaPoste  = null;
                    $cpFinal    = $cpFFTT;
                    $villeFinal = $villeFFTT;
                    if ($cpFFTT !== '') {
                        $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                        $stmtLap->execute([$cpFFTT]);
                        $lap = $stmtLap->fetch(PDO::FETCH_ASSOC);
                        if ($lap) {
                            $idLaPoste  = $lap['Id_LaPoste'];
                            $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string)($lap['Nom'] ?? ''));
                        }
                    }

                    $stmtEx = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                    $stmtEx->execute([$licence]);
                    $enBase = (bool)$stmtEx->fetchColumn();

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
                } catch (RuntimeException $e) {
                    $erreurs++;
                    $msg = $e->getMessage();
                    error_log("[NIJAC] scan_fftt_club $numClub licence=$licence : $msg");
                    if (count($erreursMsgs) < 10) {
                        $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                    }
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
            exit;
        }

        // ── Importer les JAs sélectionnés (après scan limitrophe) ─────────────
        if ($action === 'import_fftt_selected') {
            $licences = json_decode($_POST['licences'] ?? '[]', true);
            if (!is_array($licences)) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Données invalides.']); exit; }

            $nouveaux = 0;
            $maj      = 0;

            foreach ($licences as $ja) {
                $licence    = trim((string)($ja['licence']    ?? ''));
                $nom        = trim((string)($ja['nom']        ?? ''));
                $prenom     = trim((string)($ja['prenom']     ?? ''));
                $email      = trim((string)($ja['email']      ?? '')) ?: null;
                $grade      = trim((string)($ja['grade']      ?? ''));
                $actif      = (int)($ja['actif']              ?? 0);
                $idClub     = trim((string)($ja['id_club']    ?? ''));
                $idLaPoste  = $ja['id_laposte'] ?? null;
                $cpFinal    = trim((string)($ja['cp']         ?? ''));
                $villeFinal = trim((string)($ja['ville']      ?? ''));

                if ($licence === '' || $grade === '') continue;

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

            ob_end_clean();
            echo json_encode(['ok' => true, 'nouveaux' => $nouveaux, 'maj' => $maj]);
            exit;
        }

        // ── Import CSV numéros de compte EBP ──────────────────────────────
        if ($action === 'import_csv_ebp') {
            $lignes    = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Données invalides.']); exit; }

            $ok       = 0;
            $echecs   = [];

            $stmtSearch = $pdo->prepare(
                "SELECT Id_JA, Nom, Prenom FROM ja
                 WHERE UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Nom)), '%')
                   AND UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Prenom)), '%')"
            );
            $stmtUpdate = $pdo->prepare("UPDATE ja SET NumCompteEBP = ? WHERE Id_JA = ?");

            foreach ($lignes as $idx => $ligne) {
                $numEbp    = trim((string)($ligne['num'] ?? ''));
                $nomPrenom = trim((string)($ligne['nom'] ?? ''));
                $lineNo    = $idx + 1;

                if ($numEbp === '' || $nomPrenom === '') {
                    $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'Ligne incomplète'];
                    continue;
                }

                if (stripos($nomPrenom, 'fournisseur') === 0) continue;

                $stmtSearch->execute([$nomPrenom, $nomPrenom]);
                $found = $stmtSearch->fetchAll();

                if (count($found) === 0) {
                    $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'JA introuvable en base'];
                } elseif (count($found) > 1) {
                    $dups = implode(', ', array_map(fn($r) => $r['Id_JA'], $found));
                    $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => "Plusieurs JA trouvés ($dups) — non mis à jour"];
                } else {
                    $stmtUpdate->execute([$numEbp, $found[0]['Id_JA']]);
                    $ok++;
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'maj' => $ok, 'echecs' => $echecs]);
            exit;
        }

        // ── Enrichir un JA via l'API FFTT ─────────────────────────────────
        if ($action === 'enrichir_fftt') {
            $idJa = trim($_POST['id_ja'] ?? '');
            if ($idJa === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'id_ja manquant.']);
                exit;
            }
            $api = getFfttApi();
            $lic = $api->getLicenceB($idJa);
            if (!$lic) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => "Licence $idJa introuvable dans l'API FFTT."]);
                exit;
            }
            $classement = isset($lic['point']) && $lic['point'] !== '' ? (int)$lic['point'] : null;
            $dateValid  = isset($lic['validation']) && $lic['validation'] !== '' ? (string)$lic['validation'] : null;
            // Grades d'arbitrage : champs 'arb' et 'ja' retournés par xml_licence_b
            $arb = isset($lic['arb'])  && $lic['arb']  !== '' ? trim((string)$lic['arb'])  : null;
            $ja  = isset($lic['ja'])   && $lic['ja']   !== '' ? trim((string)$lic['ja'])   : null;
            $gradeFFTT = $ja ?? $arb ?? null;
            // CP, Ville et Id_LaPoste volontairement exclus : les données FFTT sont moins fiables que la BDD locale
            $pdo->prepare(
                'UPDATE ja SET Classement=?, DateValidationFFTT=?, GradeFFTT=?, DateEnrichissementFFTT=NOW() WHERE Id_JA=?'
            )->execute([$classement, $dateValid, $gradeFFTT, $idJa]);
            ob_end_clean();
            echo json_encode([
                'ok'         => true,
                'classement' => $classement,
                'date_valid' => $dateValid,
                'grade_fftt' => $gradeFFTT,
                'nom_fftt'   => ($lic['nom']    ?? '') . ' ' . ($lic['prenom'] ?? ''),
                'club_fftt'  => $lic['nomclub'] ?? '',
            ]);
            exit;
        }

        // ── Importer depuis fichier XLSX FFTT (102_*.xlsx) ───────────────────
        if ($action === 'importer_excel') {
            if (empty($_FILES['fichier'])) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Aucun fichier reçu (post_max_size = ' . ini_get('post_max_size') . ').']);
                exit;
            }
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite formulaire).',
                UPLOAD_ERR_PARTIAL    => 'Transfert incomplet.',
                UPLOAD_ERR_NO_FILE    => 'Aucun fichier sélectionné.',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
                UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier temporaire.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqué par une extension PHP.',
            ];
            if ($_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                $code = $_FILES['fichier']['error'];
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => $uploadErrors[$code] ?? "Erreur upload (code $code)."]);
                exit;
            }
            if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
                exit;
            }

            $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            // Charger tous les clubs pour enrichir nom_club
            $clubsMap = [];
            foreach ($pdo->query('SELECT Id_Club, Nom FROM Club')->fetchAll() as $c) {
                $clubsMap[$c['Id_Club']] = $c['Nom'];
            }

            $lignes = [];
            // Colonnes : A=Id_JA, B=Nom, C=Prénom, H=Id_Club, J=Grade,
            //            N=Actif, R=CodePostal, S=Ville, T=Email, U=Téléphone(alt), V=Téléphone
            for ($row = 3; $row <= $maxRow; $row++) {
                $grade = trim((string)$sheet->getCell('J' . $row)->getValue());
                if (strncasecmp($grade, 'JA', 2) !== 0) continue;

                $idJA    = trim((string)$sheet->getCell('A' . $row)->getValue());
                $nom     = trim((string)$sheet->getCell('B' . $row)->getValue());
                $prenom  = trim((string)$sheet->getCell('C' . $row)->getValue());
                $idClub  = trim((string)$sheet->getCell('H' . $row)->getValue());
                $actifRaw= trim((string)$sheet->getCell('N' . $row)->getValue());
                $cp      = trim((string)$sheet->getCell('R' . $row)->getValue());
                $ville   = trim((string)$sheet->getCell('S' . $row)->getValue());
                $email   = trim((string)$sheet->getCell('T' . $row)->getValue());
                $telU    = trim((string)$sheet->getCell('U' . $row)->getValue());
                $telV    = trim((string)$sheet->getCell('V' . $row)->getValue());

                if ($nom === '' && $prenom === '') continue;

                $tel   = $telV !== '' ? $telV : $telU;
                $actif = strtolower(trim($actifRaw)) === 'actif' ? 1 : 0;

                $lignes[] = [
                    'id'         => $idJA !== '' ? (int)$idJA : 0,
                    'nom'        => mb_strtoupper($nom, 'UTF-8'),
                    'prenom'     => mb_convert_case($prenom, MB_CASE_TITLE, 'UTF-8'),
                    'email'      => $email !== '' ? $email : null,
                    'telephone'  => formaterTelephone($tel !== '' ? $tel : null),
                    'grade'      => $grade,
                    'actif'      => $actif,
                    'id_club'    => $idClub !== '' ? $idClub : null,
                    'nom_club'   => $idClub !== '' ? ($clubsMap[$idClub] ?? '') : '',
                    'id_laposte' => null,   // résolu côté JS avec progression
                    'cp'         => $cp,
                    'ville'      => $ville,
                ];
            }

            $lignes = deduplicateJA($lignes, 'nom', 'prenom', 'grade');
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] jugearbitre.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] jugearbitre.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$isAdminJs   = $isAdmin ? 'true' : 'false';
$deptUserJs  = $isAdmin ? "''" : "'" . addslashes($moi['id_departement'] ?? '') . "'";
$deptActifs       = getDeptActifs();
$deptLimitrophes  = getDepartementsLimitrophes();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Juges-Arbitres (E007)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../asset/css/nijac.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 260px;
        }

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Grille ── */
        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-ja {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 1000px;
        }

        #tbl-ja thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
            user-select: none;
        }
        #tbl-ja thead th:hover { background: #d4dff0; }
        #tbl-ja thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-ja thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-ja thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-ja thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-ja tbody tr:hover   { background: #dce8f8; }
        #tbl-ja tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-ja tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .45rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }
        .cell-inner[contenteditable="true"] {
            background: #fffbe6;
            outline: 2px solid #f0a000;
            outline-offset: -2px;
        }

        td.col-readonly { background: #f0f4fa; }
        td.col-readonly .cell-inner { color: #6b7280; font-style: italic; }

        /* Actif badge */
        .badge-actif     { background: #d1fae5; color: #065f46; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-inactif   { background: #fee2e2; color: #991b1b; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-defisc    { background: #dbeafe; color: #1e40af; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-no-defisc { background: #f3f4f6; color: #6b7280; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }

        /* ── Label compteur ── */
        #lbl-count {
            margin-left: .75rem;
            padding: .2rem .6rem;
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            font-size: .82rem;
            color: #1a3a6b;
            font-weight: 600;
        }


        /* ── Toggle Tous / Actifs ── */
        #toggle-actif {
            display: inline-flex;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            overflow: hidden;
            font-size: .82rem;
        }
        #toggle-actif button {
            padding: .2rem .65rem;
            border: none;
            background: #fff;
            cursor: pointer;
            color: #374151;
            transition: background .15s, color .15s;
        }
        #toggle-actif button:hover { background: #e8eef7; }
        #toggle-actif button.active {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
        }
        #btn-erreurs-cp { color: #92400e; }
        #btn-erreurs-cp:hover { background: #fef3c7; }
        #btn-erreurs-cp.active { background: #d97706; color: #fff; }

        /* ── Menu déroulant style Windows ── */
        .win-menu-wrap { position: relative; }
        .win-menu-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .25rem .75rem;
            background: #fff;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            font-size: .83rem;
            cursor: pointer;
            white-space: nowrap;
            color: #212529;
            user-select: none;
        }
        .win-menu-btn:hover,
        .win-menu-btn.open { background: #e8eef7; border-color: #a0b4d0; }
        .win-menu-btn i.caret { font-size: .7rem; margin-left: .15rem; }
        .win-menu-drop {
            display: none;
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            min-width: 230px;
            background: #fff;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            box-shadow: 2px 4px 12px rgba(0,0,0,.15);
            z-index: 9000;
            padding: .25rem 0;
        }
        .win-menu-drop.open { display: block; }
        .win-menu-drop .drop-item {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .35rem 1rem;
            font-size: .84rem;
            color: #212529;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
        }
        .win-menu-drop .drop-item:hover { background: #e8eef7; color: #1a3a6b; }
        .win-menu-drop .drop-item i { font-size: 1rem; width: 1.1rem; text-align: center; }
        .win-menu-drop .drop-sep { border: none; border-top: 1px solid #dee2e6; margin: .25rem 0; }
        .win-menu-drop .drop-item.green { color: #1a6b2b; font-weight: 600; }
        .win-menu-drop .drop-item.green:hover { background: #d1fae5; }

        /* ── Spinner ── */
        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.3);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #spinner.show { display: flex; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-person-badge-fill'; $pageTitle = 'Gestion des Juges-Arbitres'; $pageCode = 'E007'; $backUrl = 'menu.php'; require __DIR__ . '/../includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Input file caché pour import XLSX JA -->
<input type="file" id="file-input" accept=".xlsx" style="display:none">
<input type="file" id="file-input-ebp" accept=".csv,text/csv" style="display:none">

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <!-- Menu déroulant style Windows -->
    <div class="win-menu-wrap">
        <button class="win-menu-btn" id="win-menu-trigger">
            <i class="bi bi-grid-3x3-gap-fill"></i>Actions
            <i class="bi bi-chevron-down caret"></i>
        </button>
        <div class="win-menu-drop" id="win-menu-drop">
            <button class="drop-item" id="btn-maj-bdd">
                <i class="bi bi-database-fill-up"></i>Mettre à jour la Base de données
            </button>
            <hr class="drop-sep">
            <button class="drop-item" id="btn-import-fftt-dept" data-bs-toggle="modal" data-bs-target="#modal-import-fftt">
                <i class="bi bi-cloud-arrow-down-fill"></i>Importer JA1/JA2/JA3 depuis FFTT (par département)
            </button>
            <button class="drop-item" id="btn-importer">
                <i class="bi bi-file-earmark-spreadsheet"></i>Importer depuis fichier FFTT (102_*.xlsx)
            </button>
            <hr class="drop-sep">
            <button class="drop-item" id="btn-import-ebp">
                <i class="bi bi-file-earmark-text"></i>Importer numéros de compte EBP (CSV)
            </button>
            <hr class="drop-sep">
            <button class="drop-item green" id="btn-nouveau-ja">
                <i class="bi bi-person-plus-fill"></i>Nouveau JA
            </button>
            <a class="drop-item" href="https://www.dcode.fr/code-postal" target="_blank">
                <i class="bi bi-globe2"></i>dCode code-postal
            </a>
        </div>
    </div>
    <span id="lbl-count">0 JA</span>
    <div id="toggle-actif" style="margin-left:.5rem">
        <button id="btn-tous">Tous</button>
        <button id="btn-actifs"       class="active">Actifs seulement</button>
        <button id="btn-erreurs-cp">⚠ Erreurs CP/Ville</button>
    </div>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
        <?php if ($deptLimitrophes): ?>
        <option disabled>── Limitrophes ──</option>
        <?php foreach ($deptLimitrophes as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?> (<?= htmlspecialchars($d['region']) ?>)</option>
        <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-ja">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id">N° JA<span class="sort-icon"></span></th>
                <th style="width:55px"  data-field="grade">Grade<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:140px" data-field="prenom">Prénom<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="email">Email<span class="sort-icon"></span></th>
                <th style="width:120px" data-field="telephone">Téléphone<span class="sort-icon"></span></th>
                <th style="width:65px"  data-field="actif">Actif<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="defiscalisation">Défiscalisation<span class="sort-icon"></span></th>
                <th style="width:85px"  data-field="nationale">Nationale<span class="sort-icon"></span></th>
                <th style="width:110px" data-field="num_compte_ebp">Cpte EBP<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="cp">CP<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:75px"  class="no-sort">Lien dispo</th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="16" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt. — Cliquez sur une cellule puis F2 pour modifier.'; ?>

<!-- Modale rapport import EBP -->
<div class="modal fade" id="modal-import-ebp" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a3a6b;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-text me-1"></i>Import numéros de compte EBP</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="ebp-resume" class="mb-2" style="font-size:.9rem;"></div>
        <div id="ebp-echecs-bloc" style="display:none;">
          <div class="fw-semibold text-danger mb-1" style="font-size:.85rem;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>Lignes non traitées :
          </div>
          <table class="table table-sm table-bordered mb-2" style="font-size:.82rem;">
            <thead class="table-danger">
              <tr><th>#</th><th>Nom / Prénom (CSV)</th><th>Raison</th></tr>
            </thead>
            <tbody id="ebp-echecs-body"></tbody>
          </table>
          <button class="btn btn-sm btn-outline-secondary" id="btn-ebp-telecharger">
            <i class="bi bi-download me-1"></i>Télécharger le rapport CSV
          </button>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- Modale progression import Excel -->
<div class="modal fade" id="modal-import-excel" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#198754;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import XLSX — Juges-Arbitres</h6>
      </div>
      <div class="modal-body pb-2">
        <div class="mb-3">
          <div class="d-flex justify-content-between small mb-1">
            <span id="xlsx-progress-label">Envoi du fichier…</span>
            <span id="xlsx-progress-pct">0 %</span>
          </div>
          <div class="progress" style="height:20px;">
            <div id="xlsx-progress-bar"
                 class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 style="width:0%;transition:width .3s ease;"></div>
          </div>
        </div>
        <div id="xlsx-log"
             style="max-height:260px;overflow-y:auto;font-size:.78rem;
                    background:#f8fafc;border:1px solid #e0e8f0;
                    border-radius:4px;padding:.5rem;font-family:monospace;"></div>
      </div>
      <div class="modal-footer py-2" id="xlsx-footer" style="display:none;">
        <button type="button" class="btn btn-success btn-sm" id="btn-xlsx-ok" data-bs-dismiss="modal">
          <i class="bi bi-check-lg me-1"></i>OK — afficher la grille
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modale CP / Ville -->
<!-- Modale Import FFTT par département -->
<div class="modal fade" id="modal-import-fftt" tabindex="-1" aria-labelledby="modal-import-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-import-fftt-titre"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Importer les JA1 / JA2 / JA3 depuis l'API FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btn-fermer-import-fftt"></button>
      </div>
      <div class="modal-body">

        <!-- Étape 1 : choix département -->
        <div id="import-fftt-step1">
          <p class="text-muted small mb-3">
            Sélectionnez un département. L'import parcourt tous les clubs,
            vérifie chaque licencié via <code>xml_licence_b</code> et insère ou met à jour
            les JA1/JA2/JA3 trouvés. Les AR sont exclus. <strong>CP et Ville ne sont pas écrasés.</strong>
          </p>
          <div class="input-group mb-3" style="max-width:380px">
            <label class="input-group-text" for="import-fftt-dept"><i class="bi bi-map me-1"></i>Département</label>
            <select id="import-fftt-dept" class="form-select">
              <option value="">— Choisir —</option>
              <?php foreach ($deptActifs as $d): ?>
              <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
              <?php endforeach; ?>
              <?php if ($deptLimitrophes): ?>
              <option disabled>── Limitrophes ──</option>
              <?php foreach ($deptLimitrophes as $d): ?>
              <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?> (<?= htmlspecialchars($d['region']) ?>)</option>
              <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-lancer-import-fftt">
            <i class="bi bi-play-fill me-1"></i>Lancer l'import
          </button>
        </div>

        <!-- Étape 2 : progression -->
        <div id="import-fftt-step2" style="display:none;">
          <div class="d-flex align-items-center gap-2 mb-2 px-2 py-1 rounded" style="background:#e8f0fe;border:1px solid #c5d5f8;">
            <i class="bi bi-geo-alt-fill text-primary"></i>
            <span class="small fw-bold text-primary">Département en cours d'import :</span>
            <span id="import-fftt-dept-label" class="small fw-semibold text-dark"></span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span id="import-fftt-label" class="fw-semibold small text-primary">Récupération des clubs…</span>
            <span id="import-fftt-pct" class="small text-muted">0 %</span>
          </div>
          <div class="progress mb-3" style="height:18px;">
            <div id="import-fftt-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 style="width:0%" role="progressbar"></div>
          </div>
          <div class="row text-center mb-3">
            <div class="col-3">
              <div class="fw-bold fs-5 text-success" id="cnt-nouveaux">0</div>
              <div class="small text-muted" id="cnt-nouveaux-label">Nouveaux JAs</div>
            </div>
            <div class="col-3" id="cnt-maj-col">
              <div class="fw-bold fs-5 text-info" id="cnt-maj">0</div>
              <div class="small text-muted">Mis à jour</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-secondary" id="cnt-membres">0</div>
              <div class="small text-muted">Membres vérifiés</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-warning" id="cnt-erreurs">0</div>
              <div class="small text-muted">Erreurs API</div>
            </div>
          </div>
          <!-- Log des JAs trouvés -->
          <div id="import-fftt-log" style="max-height:200px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

        <!-- Étape 2b : sélection des JA (départements limitrophes uniquement) -->
        <div id="import-fftt-step2b" style="display:none;">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <div>
              <span class="fw-bold text-primary fs-6">Sélectionnez les JA à importer</span>
              <span class="text-muted small ms-2" id="import-fftt-2b-sous-titre"></span>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-secondary btn-sm" id="btn-2b-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
              <button class="btn btn-outline-secondary btn-sm" id="btn-2b-tout-decocher"><i class="bi bi-square me-1"></i>Tout décocher</button>
            </div>
          </div>
          <div id="import-fftt-2b-list" style="max-height:360px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:.5rem .75rem;"></div>
          <div id="import-fftt-2b-erreurs" class="text-danger small mt-1" style="display:none;"></div>
          <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
            <button class="btn btn-success" id="btn-2b-valider">
              <i class="bi bi-check-lg me-1"></i>Valider l'import
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btn-2b-retour">
              <i class="bi bi-arrow-left me-1"></i>Retour
            </button>
            <span class="text-muted small" id="import-fftt-2b-nb-sel"></span>
          </div>
        </div>

        <!-- Étape 3 : résumé final -->
        <div id="import-fftt-step3" style="display:none;">
          <div class="alert alert-success mb-3" id="import-fftt-resume"></div>
          <div id="import-fftt-log-final" style="max-height:250px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- Modale Nouveau JA -->
<div class="modal fade" id="modal-nouveau-ja" tabindex="-1" aria-labelledby="modal-nouveau-ja-titre" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#1a3a6b;color:#fff;">
        <h5 class="modal-title" id="modal-nouveau-ja-titre"><i class="bi bi-person-plus-fill me-2"></i>Créer un nouveau Juge-Arbitre</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-nouveau-ja" novalidate>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Grade <span class="text-danger">*</span></label>
              <select class="form-select form-select-sm" id="nja-grade" required>
                <option value="">— Choisir —</option>
                <option value="JA1">JA1</option>
                <option value="JA2">JA2</option>
                <option value="JA3">JA3</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm text-uppercase" id="nja-nom" required placeholder="NOM">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="nja-prenom" required placeholder="Prénom">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" class="form-control form-control-sm" id="nja-email" placeholder="adresse@email.fr">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Téléphone</label>
              <input type="text" class="form-control form-control-sm" id="nja-telephone" placeholder="06.12.34.56.78">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Département / Club</label>
              <div class="input-group input-group-sm">
                <select class="form-select" id="nja-dept" style="max-width:200px">
                  <option value="">— Tous —</option>
                  <?php foreach ($deptActifs as $d): ?>
                  <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select" id="nja-id-club">
                  <option value="">— Sélectionnez d'abord un département —</option>
                </select>
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Code postal / Ville</label>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="nja-cp" placeholder="76000" maxlength="10" style="max-width:90px">
                <input type="text" class="form-control text-uppercase" id="nja-ville" placeholder="ROUEN">
              </div>
              <div id="nja-laposte-msg" class="form-text" style="min-height:1.2em;"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">N° Compte EBP</label>
              <input type="text" class="form-control form-control-sm" id="nja-cpte-ebp" placeholder="">
            </div>
            <div class="col-12">
              <div class="d-flex gap-4 mt-1">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-actif" checked>
                  <label class="form-check-label" for="nja-actif">Actif</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-defisc">
                  <label class="form-check-label" for="nja-defisc">Défiscalisation</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-nationale">
                  <label class="form-check-label" for="nja-nationale">Nationale</label>
                </div>
              </div>
            </div>
          </div>
        </form>
        <!-- Zone suggestions laposte -->
        <div id="nja-suggestions" class="mt-2" style="display:none;">
          <div class="fw-semibold text-primary mb-1">Plusieurs communes trouvées — choisissez :</div>
          <div id="nja-suggestions-list" class="d-flex flex-wrap gap-1"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-enregistrer-ja">
          <i class="bi bi-check-lg me-1"></i>Créer le JA
        </button>
      </div>
    </div>
  </div>
</div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let lignes     = [];
let filtreActif   = true;   // false = tous, true = actifs seulement
let filtreErreursCp = false; // true = uniquement les lignes sans id_laposte
let cellActive = null;
const sortState = { col: 'nom', asc: true };
let searchTerm = '';
const isAdmin  = <?= $isAdminJs ?>;
let deptFiltre = <?= $deptUserJs ?>; // nominateur : filtré sur son dept

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let source = [...lignes];
    if (filtreActif)     source = source.filter(l => l.actif === 1);
    if (filtreErreursCp) source = source.filter(l => l.id_laposte == null);
    let result = term
        ? source.filter(l =>
            String(l.id              ?? '').toLowerCase().includes(term) ||
            String(l.nom             ?? '').toLowerCase().includes(term) ||
            String(l.prenom          ?? '').toLowerCase().includes(term) ||
            String(l.email           ?? '').toLowerCase().includes(term) ||
            String(l.telephone       ?? '').toLowerCase().includes(term) ||
            String(l.grade           ?? '').toLowerCase().includes(term) ||
            String(l.id_club         ?? '').toLowerCase().includes(term) ||
            String(l.nom_club        ?? '').toLowerCase().includes(term) ||
            String(l.cp              ?? '').toLowerCase().includes(term) ||
            String(l.ville           ?? '').toLowerCase().includes(term))
        : source;

    const numFields = ['id'];
    const sortField = sortState.col, sortDir = sortState.asc ? 'asc' : 'desc';
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        if (sortField === 'actif') {
            return sortDir === 'asc' ? a.actif - b.actif : b.actif - a.actif;
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    refreshTriEntetes();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun juge-arbitre.';
        $body.append(`<tr><td colspan="16" class="text-center text-muted py-3">${msg}</td></tr>`);
    } else {
        affichees.forEach(l => {
            const idx  = l._idx;          // index stable, indépendant du filtre/tri
            const $tr  = $('<tr>').attr('data-idx', idx);
            const actifHtml = l.actif
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const defiscHtml = l.defiscalisation
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const nationaleHtml = l.nationale
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';

            $tr.append(makeTd(l.id,              idx, 'id',              true));
            $tr.append(makeTd(l.grade,            idx, 'grade',           false));
            $tr.append(makeTd(l.nom,              idx, 'nom',             false));
            $tr.append(makeTd(l.prenom,           idx, 'prenom',          false));
            $tr.append(makeTd(l.email,            idx, 'email',           false));
            $tr.append(makeTd(l.telephone,        idx, 'telephone',       false));
            $tr.append(makeTdHtml(actifHtml,      idx, 'actif'));
            $tr.append(makeTd(l.id_club,          idx, 'id_club',         true));
            $tr.append(makeTd(l.nom_club,         idx, 'nom_club',        true));
            $tr.append(makeTdHtml(defiscHtml,     idx, 'defiscalisation'));
            $tr.append(makeTdHtml(nationaleHtml,   idx, 'nationale'));
            $tr.append(makeTd(l.num_compte_ebp,    idx, 'num_compte_ebp', false));
            $tr.append(makeCpTd(l,    idx));
            $tr.append(makeVilleTd(l, idx));
            // Bouton lien disponibilité
            const $tdLien = $('<td>').css({textAlign:'center', verticalAlign:'middle', padding:'.2rem'});
            const dispoCls   = l.nb_dispo > 0 ? 'btn-success'         : 'btn-outline-secondary';
            const dispoTitle = l.nb_dispo > 0 ? `Disponibilités saisies (${l.nb_dispo} journée(s))` : 'Aucune disponibilité saisie — cliquez pour ouvrir';
            const adrTitle = l.id_laposte ? 'Envoyer la demande de mise à jour d\'adresse' : 'Adresse manquante — envoyer la demande';
            $tdLien.html(`<button class="btn btn-sm ${dispoCls} btn-lien-dispo" data-id="${l.id}" title="${dispoTitle}"><i class="bi bi-calendar2-check"></i></button>`
                + ` <button class="btn btn-sm ${l.id_laposte ? 'btn-outline-secondary' : 'btn-warning'} btn-lien-adresse" data-id="${l.id}" data-nom="${escHtml(l.prenom + ' ' + l.nom)}" data-email="${escHtml(l.email ?? '')}" title="${adrTitle}"><i class="bi bi-geo-alt"></i></button>`);
            $tr.append($tdLien);
            $body.append($tr);
        });
    }

    const info = searchTerm
        ? `${affichees.length} résultat(s) sur ${lignes.length}`
        : `${lignes.length} JA`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    const nbActifs    = lignes.filter(l => l.actif === 1).length;
    const nbErreursCp = lignes.filter(l => l.id_laposte == null).length;
    let   lblTxt = `${lignes.length} JA dont ${nbActifs} actif${nbActifs > 1 ? 's' : ''}`;
    if (nbErreursCp > 0) lblTxt += ` — ⚠ ${nbErreursCp} erreur${nbErreursCp > 1 ? 's' : ''} CP`;
    $('#lbl-count').text(lblTxt);
}

const CHAMPS_NUMERIQUES = [];

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? 'col-readonly' : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        if (CHAMPS_NUMERIQUES.includes(field)) {
            // Clic direct → édition immédiate pour les champs numériques
            $td.on('click', function (e) {
                e.stopPropagation();
                selectionnerCellule($(this));
                const $inner = $(this).find('.cell-inner');
                if ($inner.attr('contenteditable') === 'false') {
                    $inner.attr('contenteditable', 'true').trigger('focus');
                    const range = document.createRange();
                    range.selectNodeContents($inner[0]);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                }
            });
        } else {
            $td.on('click', function (e) { e.stopPropagation(); selectionnerCellule($(this)); });
        }
    }
    return $td;
}

function makeTdHtml(html, idx, field) {
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', field)
                         .css({textAlign: 'center', verticalAlign: 'middle', padding: '.2rem'});
    $td.html(html);
    // Clic → bascule la valeur booléenne du champ
    $td.on('click', function () {
        lignes[idx][field] = lignes[idx][field] ? 0 : 1;
        renderGrille();
        setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
    });
    return $td;
}

// ── Cellules CP / Ville (lecture seule — modification via bouton adresse) ────
function makeCpTd(l, idx) {
    const trouve = l.id_laposte != null;
    const bg     = trouve ? '#d1fae5' : (l.cp ? '#fee2e2' : '');
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', 'cp').css({ background: bg });
    $('<div class="cell-inner">').text(l.cp ?? '').appendTo($td);
    return $td;
}

function makeVilleTd(l, idx) {
    const trouve = l.id_laposte != null;
    const bg     = trouve ? '#d1fae5' : (l.ville ? '#fee2e2' : '');
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', 'ville').css({ background: bg });
    $('<div class="cell-inner">').text(l.ville ?? '').appendTo($td);
    return $td;
}


// ── Sélection / Edition ───────────────────────────────────────────────────────
function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    $td.closest('tr').addClass('selected');
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

$(document).on('keydown', function (e) {
    if (!cellActive) return;
    const $inner = cellActive.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    } else if (e.key === 'Escape') {
        const idx   = +cellActive.attr('data-idx');
        const field = cellActive.attr('data-field');
        $inner.text(lignes[idx]?.[field] ?? '').attr('contenteditable', 'false');
        setStatus('Modification annulée.');
    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        validerCellule($inner, cellActive);
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

// Bloquer la saisie non numérique dans Distance
$(document).on('keypress', '.cell-inner[contenteditable="true"]', function (e) {
    const field = $(this).closest('td').attr('data-field');
    if (CHAMPS_NUMERIQUES.includes(field)) {
        if (!/[0-9]/.test(e.key)) { e.preventDefault(); }
    }
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    let   val   = $inner.text().trim();

    // Validation numérique pour Distance
    if (CHAMPS_NUMERIQUES.includes(field)) {
        const n = parseInt(val, 10);
        if (val !== '' && (isNaN(n) || n < 0)) {
            toast('Valeur numérique entière ≥ 0 attendue.', false);
            $inner.text(lignes[idx]?.[field] ?? '');  // restaurer
            return;
        }
        val = val !== '' ? n : null;
    }

    if (lignes[idx]) lignes[idx][field] = val !== '' ? val : null;

    setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
}

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.post('jugearbitre.php', { action: 'liste', dept: deptFiltre }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map((r, i) => ({
            _idx:            i,
            id:              r.Id_JA,
            nom:             r.Nom,
            prenom:          r.Prenom,
            email:           r.Email,
            telephone:       r.Telephone,
            grade:           r.Grade,
            actif:           +r.Actif,
            id_club:         r.Id_Club,
            nom_club:        r.NomClub ?? '',
            id_laposte:       r.Id_LaPoste,
            num_compte_ebp:   r.NumCompteEBP ?? '',
            defiscalisation:  +r.Defiscalisation,
            nationale:        +r.Nationale,
            nb_dispo:               +r.NbDispo,
            cp:                     r.CP    ?? '',
            ville:                  r.Ville ?? '',
            classement:               r.Classement ?? null,
            date_validation_fftt:     r.DateValidationFFTT ?? null,
            grade_fftt:               r.GradeFFTT ?? null,
            date_enrichissement_fftt: r.DateEnrichissementFFTT ?? null,
        }));
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Menu déroulant Windows ────────────────────────────────────────────────────
$('#win-menu-trigger').on('click', function (e) {
    e.stopPropagation();
    const open = $('#win-menu-drop').toggleClass('open').hasClass('open');
    $(this).toggleClass('open', open);
});
$(document).on('click', function () {
    $('#win-menu-drop').removeClass('open');
    $('#win-menu-trigger').removeClass('open');
});
$('#win-menu-drop').on('click', '.drop-item', function () {
    $('#win-menu-drop').removeClass('open');
    $('#win-menu-trigger').removeClass('open');
});

// ── Mettre à jour la BDD ──────────────────────────────────────────────────────
$('#btn-maj-bdd').on('click', function () {
    if (!lignes.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Mettre à jour la base de données avec ${lignes.length} JA ?`)) return;

    spinner(true);
    $.post('jugearbitre.php', {
        action: 'maj_bdd',
        lignes: JSON.stringify(lignes),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Import FFTT par département ───────────────────────────────────────────────
const DEPT_ACTIFS_CODES = <?= json_encode(array_map('strval', array_column($deptActifs, 'code'))) ?>;
let importFfttEnCours = false;

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
let _scanJAs = []; // JAs trouvés lors du scan limitrophe

$('#modal-import-fftt').on('hidden.bs.modal', function () {
    if (!importFfttEnCours) resetImportFftt();
});

function resetImportFftt() {
    $('#import-fftt-step1').show();
    $('#import-fftt-step2, #import-fftt-step2b, #import-fftt-step3').hide();
    $('#import-fftt-dept').val('');
    $('#import-fftt-bar').css('width', '0%');
    $('#import-fftt-label').text('Récupération des clubs…');
    $('#import-fftt-pct').text('0 %');
    $('#cnt-nouveaux-label').text('Nouveaux JAs');
    $('#cnt-maj-col').show();
    ['cnt-nouveaux','cnt-maj','cnt-membres','cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#import-fftt-log, #import-fftt-log-final').empty();
    $('#import-fftt-2b-list').empty();
    importFfttEnCours = false;
    _scanJAs = [];
}

// ── Utilitaire : lancer la progression (scan ou import direct) ────────────────
function lancerProgressionClubs(dep, depText, modeScan) {
    importFfttEnCours = true;
    $('#import-fftt-step1').hide();
    $('#import-fftt-step2').show();
    $('#btn-fermer-import-fftt').prop('disabled', true);
    $('#import-fftt-dept-label').text(depText);

    if (modeScan) {
        $('#cnt-nouveaux-label').text('JAs trouvés');
        $('#cnt-maj-col').hide();
    }

    let totalNouveaux = 0, totalMaj = 0, totalMembres = 0, totalErreurs = 0;
    const logLines = [];
    _scanJAs = [];

    $.post('jugearbitre.php', { action: 'get_clubs_dept', dep }, function (res) {
        if (!res.ok) {
            nijacToast('Erreur : ' + res.msg, 'danger');
            resetImportFftt();
            $('#btn-fermer-import-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs.filter(c => c.numero);
        const total = clubs.length;
        let done = 0;

        $('#import-fftt-label').text(`0 / ${total} clubs traités…`);

        const action = modeScan ? 'scan_fftt_club' : 'import_fftt_club';

        function traiterClub() {
            if (done >= total) {
                importFfttEnCours = false;
                $('#btn-fermer-import-fftt').prop('disabled', false);
                $('#import-fftt-step2').hide();

                if (modeScan) {
                    // Afficher l'étape de sélection
                    afficherSelectionJAs(_scanJAs, totalMembres, totalErreurs, logLines);
                } else {
                    $('#import-fftt-step3').show();
                    $('#import-fftt-resume').html(
                        `<i class="bi bi-check-circle-fill me-2"></i>` +
                        `Import terminé — <strong>${totalNouveaux}</strong> nouveau(x) JA, ` +
                        `<strong>${totalMaj}</strong> mis à jour, ` +
                        `<strong>${totalMembres}</strong> membres vérifiés` +
                        (totalErreurs ? `, <strong>${totalErreurs}</strong> erreur(s) API` : '') + '.'
                    );
                    $('#import-fftt-log-final').html(logLines.join(''));
                    chargerListe();
                }
                return;
            }

            const club = clubs[done];
            const pct  = Math.round(done / total * 100);
            $('#import-fftt-bar').css('width', pct + '%');
            $('#import-fftt-pct').text(pct + ' %');
            $('#import-fftt-label').text(`${done + 1} / ${total} — ${club.nom}`);

            $.post('jugearbitre.php', { action, num_club: club.numero }, function (r) {
                if (r.ok) {
                    totalMembres += r.total_membres;
                    totalErreurs += r.erreurs;
                    if (r.erreurs_msgs && r.erreurs_msgs.length) {
                        r.erreurs_msgs.forEach(msg => {
                            const line = `<div class="text-danger">⚠ ${msg}</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                        });
                    }
                    r.trouves.forEach(ja => {
                        if (modeScan) {
                            _scanJAs.push(ja);
                            totalNouveaux = _scanJAs.length;
                            const cls  = ja.en_base ? 'text-secondary' : 'text-success';
                            const lbl  = ja.en_base ? '≡ EN BASE' : '✚ NOUVEAU';
                            const line = `<div class="${cls}">[${lbl}] ${ja.grade} — ${ja.nom} ${ja.prenom} (${ja.licence})</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                        } else {
                            const cls   = ja.statut === 'nouveau' ? 'text-success' : 'text-info';
                            const label = ja.statut === 'nouveau' ? '✚ NOUVEAU' : '↻ MAJ';
                            const line  = `<div class="${cls}">[${label}] ${ja.grade} — ${ja.nom} ${ja.prenom} (${ja.licence})</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                            if (ja.statut === 'nouveau') totalNouveaux++; else totalMaj++;
                        }
                    });
                    $('#cnt-nouveaux').text(totalNouveaux);
                    if (!modeScan) $('#cnt-maj').text(totalMaj);
                    $('#cnt-membres').text(totalMembres);
                    $('#cnt-erreurs').text(totalErreurs);
                }
                done++;
                traiterClub();
            }, 'json').fail(() => {
                totalErreurs++;
                $('#cnt-erreurs').text(totalErreurs);
                done++;
                traiterClub();
            });
        }

        traiterClub();
    }, 'json').fail(() => {
        nijacToast('Erreur réseau lors de la récupération des clubs.', 'danger');
        resetImportFftt();
        $('#btn-fermer-import-fftt').prop('disabled', false);
    });
}

// ── Afficher l'écran de sélection des JAs (limitrophes) ──────────────────────
function afficherSelectionJAs(jas, totalMembres, totalErreurs, logLines) {
    const $list = $('#import-fftt-2b-list').empty();

    if (!jas.length) {
        $list.html('<div class="text-muted text-center py-4"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA trouvé dans ce département.</div>');
    } else {
        const gradeBg = { JA1: 'primary', JA2: 'success', JA3: 'warning text-dark' };
        jas.forEach((ja, i) => {
            const bg    = gradeBg[ja.grade] || 'secondary';
            const lieu  = [ja.cp, ja.ville].filter(Boolean).join(' ');
            const badge = ja.en_base
                ? '<span class="badge bg-secondary ms-auto" style="flex-shrink:0">Déjà en base</span>'
                : '<span class="badge bg-success ms-auto" style="flex-shrink:0">Nouveau</span>';
            $list.append(`
                <div class="d-flex align-items-center gap-2 py-1 border-bottom">
                    <input type="checkbox" class="form-check-input ja-sel-check" data-idx="${i}" ${!ja.en_base ? 'checked' : ''} style="flex-shrink:0;width:1.1em;height:1.1em">
                    <span class="badge bg-${bg}" style="flex-shrink:0">${ja.grade}</span>
                    <span class="fw-semibold small" style="min-width:0">${escHtml(ja.prenom)} ${escHtml(ja.nom)}</span>
                    <span class="text-muted small text-truncate">${escHtml(lieu)}</span>
                    ${badge}
                </div>
            `);
        });
    }

    const sousTitre = `${jas.length} JA(s) trouvé(s) — ${totalMembres} membres vérifiés` +
        (totalErreurs ? ` — <span class="text-danger">${totalErreurs} erreur(s)</span>` : '');
    $('#import-fftt-2b-sous-titre').html(sousTitre);
    $('#import-fftt-2b-erreurs').hide();
    majNbSel();

    $('#import-fftt-step2b').show();
}

function majNbSel() {
    const n = $('.ja-sel-check:checked').length;
    $('#import-fftt-2b-nb-sel').text(n > 0 ? `${n} JA(s) sélectionné(s)` : 'Aucune sélection');
}

$(document).on('change', '.ja-sel-check', majNbSel);

$('#btn-2b-tout-cocher').on('click', function () {
    $('.ja-sel-check').prop('checked', true);
    majNbSel();
});
$('#btn-2b-tout-decocher').on('click', function () {
    $('.ja-sel-check').prop('checked', false);
    majNbSel();
});

$('#btn-2b-retour').on('click', function () {
    $('#import-fftt-step2b').hide();
    resetImportFftt();
});

$('#btn-2b-valider').on('click', function () {
    const selected = [];
    $('.ja-sel-check:checked').each(function () {
        const idx = parseInt($(this).data('idx'), 10);
        if (!isNaN(idx) && _scanJAs[idx]) selected.push(_scanJAs[idx]);
    });
    if (!selected.length) {
        nijacToast('Cochez au moins un JA à importer.', 'warning');
        return;
    }

    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Import…');

    $.post('jugearbitre.php', { action: 'import_fftt_selected', licences: JSON.stringify(selected) }, function (r) {
        $('#btn-2b-valider').prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Valider l\'import');
        if (!r.ok) { nijacToast('Erreur : ' + r.msg, 'danger'); return; }
        $('#import-fftt-step2b').hide();
        $('#import-fftt-step3').show();
        $('#import-fftt-resume').html(
            `<i class="bi bi-check-circle-fill me-2"></i>` +
            `Import terminé — <strong>${r.nouveaux}</strong> nouveau(x) JA, ` +
            `<strong>${r.maj}</strong> mis à jour.`
        );
        $('#import-fftt-log-final').empty();
        chargerListe();
    }, 'json').fail(() => {
        $('#btn-2b-valider').prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Valider l\'import');
        nijacToast('Erreur réseau lors de l\'import.', 'danger');
    });
});

$('#btn-lancer-import-fftt').on('click', function () {
    const dep = $('#import-fftt-dept').val();
    if (!dep) { nijacToast('Sélectionnez un département.', 'warning'); return; }

    const depText  = $('#import-fftt-dept option:selected').text().trim();
    const modeScan = !DEPT_ACTIFS_CODES.includes(dep);
    lancerProgressionClubs(dep, depText, modeScan);
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page (includes/footer.php),
// donc pas encore défini si on l'appelait ici de façon synchrone.
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-ja thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Toggles filtres ───────────────────────────────────────────────────────────
$('#btn-tous').on('click', function () {
    // Réinitialise tous les filtres
    filtreActif = false; filtreErreursCp = false;
    $('#btn-tous').addClass('active');
    $('#btn-actifs, #btn-erreurs-cp').removeClass('active');
    renderGrille();
});
$('#btn-actifs').on('click', function () {
    filtreActif = !filtreActif;
    $(this).toggleClass('active', filtreActif);
    // Si aucun filtre actif, revenir à "Tous"
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});
$('#btn-erreurs-cp').on('click', function () {
    filtreErreursCp = !filtreErreursCp;
    $(this).toggleClass('active', filtreErreursCp);
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});

// ── Bouton "Ouvrir la page de disponibilité du JA" ───────────────────────────
$(document).on('click', '.btn-lien-dispo', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    $.getJSON('disponibilite_ja.php', { action: 'token', id: id }, function (r) {
        if (!r.ok) { alert('Erreur lors de la génération du lien.'); return; }
        window.open(r.url, '_blank');
    });
});

// ── Bouton "Envoyer la demande de mise à jour d'adresse" ─────────────────────
$(document).on('click', '.btn-lien-adresse', function (e) {
    e.stopPropagation();
    const id    = $(this).data('id');
    const nom   = $(this).data('nom')   || `JA #${id}`;
    const email = $(this).data('email') || '';

    if (!email) {
        nijacToast(`${nom} n'a pas d'adresse email — impossible d'envoyer le message.`, 'warning');
        return;
    }

    nijacConfirm(
        `Envoyer le message de demande d'adresse à <strong>${nom}</strong> ?`,
        function () {
            $.post('adresse_ja.php', { action: 'envoyer_demande_adresse', id_ja: id }, function (r) {
                if (r.ok) {
                    nijacToast(`Message envoyé à ${r.nom}.`, 'success');
                    if (r.url) window.open(r.url, '_blank');
                } else {
                    nijacToast('Erreur : ' + (r.err || 'inconnue'), 'danger');
                }
            }, 'json').fail(function () {
                nijacToast('Erreur réseau.', 'danger');
            });
        },
        null,
        { type: 'question', confirmLabel: 'Envoyer', cancelLabel: 'Annuler' }
    );
});

// ── Modale Nouveau JA ─────────────────────────────────────────────────────────
let njaIdLaPoste = null;

$('#btn-nouveau-ja').on('click', function () {
    // Réinitialiser le formulaire
    $('#form-nouveau-ja')[0].reset();
    $('#nja-actif').prop('checked', true);
    njaIdLaPoste = null;
    $('#nja-laposte-msg').text('').css('color', '');
    $('#nja-suggestions').hide();
    $('#nja-suggestions-list').empty();
    $('#nja-id-club').html('<option value="">— Sélectionnez d\'abord un département —</option>').prop('disabled', false);
    new bootstrap.Modal('#modal-nouveau-ja').show();
});

// Recherche laposte dans la modale
function njaRechercherLaPoste() {
    const cp    = $('#nja-cp').val().trim();
    const ville = $('#nja-ville').val().trim();
    if (cp === '' && ville === '') { njaIdLaPoste = null; return; }

    $.post('../ajax/laposte.php', { action: 'recherche_laposte', cp, ville }, function (res) {
        $('#nja-suggestions').hide();
        $('#nja-suggestions-list').empty();
        if (!res.ok && !res.multi) {
            njaIdLaPoste = null;
            $('#nja-laposte-msg').text('Commune non trouvée.').css('color', '#c00');
            return;
        }
        if (res.multi) {
            $('#nja-laposte-msg').text('').css('color', '');
            const $list = $('#nja-suggestions-list').empty();
            res.suggestions.forEach(s => {
                $('<button>').addClass('btn btn-sm btn-outline-primary')
                    .text(`${s.cp} ${s.ville}`)
                    .on('click', function () {
                        njaIdLaPoste = s.id_laposte;
                        $('#nja-cp').val(s.cp);
                        $('#nja-ville').val(s.ville);
                        $('#nja-laposte-msg').text(`✓ ${s.cp} ${s.ville}`).css('color', '#065f46');
                        $('#nja-suggestions').hide();
                    })
                    .appendTo($list);
            });
            $('#nja-suggestions').show();
            return;
        }
        njaIdLaPoste = res.id_laposte;
        $('#nja-cp').val(res.cp);
        $('#nja-ville').val(res.ville);
        $('#nja-laposte-msg').text(`✓ ${res.cp} ${res.ville}`).css('color', '#065f46');
    }, 'json').fail(() => { njaIdLaPoste = null; $('#nja-laposte-msg').text('Erreur réseau.').css('color', '#c00'); });
}

// Chargement des clubs selon le département sélectionné
function njaChargerClubs(dept) {
    const $sel = $('#nja-id-club');
    $sel.html('<option value="">Chargement…</option>').prop('disabled', true);
    $.post('jugearbitre.php', { action: 'clubs_par_dept', dept }, function (res) {
        $sel.prop('disabled', false);
        if (!res.ok || !res.clubs.length) {
            $sel.html('<option value="">— Aucun club trouvé —</option>');
            return;
        }
        let opts = '<option value="">— Choisir un club —</option>';
        res.clubs.forEach(c => {
            opts += `<option value="${c.Id_Club}">${c.Id_Club} — ${$('<span>').text(c.Nom).html()}</option>`;
        });
        $sel.html(opts);
    }, 'json').fail(() => {
        $sel.prop('disabled', false).html('<option value="">— Erreur chargement —</option>');
    });
}

$('#nja-dept').on('change', function () {
    njaChargerClubs($(this).val());
});

$('#nja-cp, #nja-ville').on('blur', function () { njaRechercherLaPoste(); });
$('#nja-nom').on('input', function () { $(this).val($(this).val().toUpperCase()); });

// Enregistrer
$('#btn-enregistrer-ja').on('click', function () {
    const grade  = $('#nja-grade').val().trim();
    const nom    = $('#nja-nom').val().trim().toUpperCase();
    const prenom = $('#nja-prenom').val().trim();

    if (!grade || !nom || !prenom) {
        toast('Grade, Nom et Prénom sont obligatoires.', false);
        return;
    }

    const record = {
        id:              0,
        grade,
        nom,
        prenom,
        email:           $('#nja-email').val().trim() || null,
        telephone:       $('#nja-telephone').val().trim() || null,
        id_club:         $('#nja-id-club').val() || null,
        cp:              $('#nja-cp').val().trim() || null,
        ville:           $('#nja-ville').val().trim() || null,
        id_laposte:      njaIdLaPoste,
        num_compte_ebp:  $('#nja-cpte-ebp').val().trim() || null,
        actif:           $('#nja-actif').is(':checked') ? 1 : 0,
        defiscalisation: $('#nja-defisc').is(':checked') ? 1 : 0,
        nationale:       $('#nja-nationale').is(':checked') ? 1 : 0,
    };

    spinner(true);
    $.post('jugearbitre.php', { action: 'maj_bdd', lignes: JSON.stringify([record]) }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) {
            bootstrap.Modal.getInstance('#modal-nouveau-ja')?.hide();
            chargerListe();
        }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Import CSV numéros de compte EBP ─────────────────────────────────────────
$('#btn-import-ebp').on('click', () => $('#file-input-ebp').val('').trigger('click'));

$('#file-input-ebp').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        const text = e.target.result;
        // Parsing CSV : séparateur virgule, ignorer lignes vides
        const lignes = text.split(/\r?\n/)
            .map(l => l.trim())
            .filter(Boolean)
            .map((l, i) => {
                const idx = l.indexOf(';');
                if (idx === -1) return { num: l.trim(), nom: '', _src: l };
                return {
                    num: l.substring(0, idx).trim(),
                    nom: l.substring(idx + 1).trim().replace(/^"|"$/g, ''),
                    _src: l,
                };
            });

        if (!lignes.length) {
            nijacToast('Fichier CSV vide ou illisible.', 'warning');
            return;
        }

        spinner(true);
        $.post('jugearbitre.php', { action: 'import_csv_ebp', lignes: JSON.stringify(lignes) }, function (r) {
            spinner(false);
            if (!r.ok) { nijacToast('Erreur : ' + (r.msg || 'inconnue'), 'danger'); return; }

            // Résumé
            $('#ebp-resume').html(
                `<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>${r.maj} compte(s) mis à jour.</span>` +
                (r.echecs.length ? ` &nbsp;<span class="text-danger">${r.echecs.length} ligne(s) non traitée(s).</span>` : '')
            );

            // Tableau des échecs
            const $tbody = $('#ebp-echecs-body').empty();
            if (r.echecs.length) {
                r.echecs.forEach(ec => {
                    $tbody.append(`<tr><td>${ec.ligne}</td><td>${$('<span>').text(ec.texte).html()}</td><td class="text-danger">${$('<span>').text(ec.raison).html()}</td></tr>`);
                });
                $('#ebp-echecs-bloc').show();

                // Rapport CSV téléchargeable
                $('#btn-ebp-telecharger').off('click').on('click', function () {
                    const csv = 'Ligne,Nom / Prénom,Raison\n' +
                        r.echecs.map(ec => `${ec.ligne},"${ec.texte.replace(/"/g, '""')}","${ec.raison.replace(/"/g, '""')}"`).join('\n');
                    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
                    const url  = URL.createObjectURL(blob);
                    const a    = document.createElement('a');
                    a.href     = url;
                    a.download = 'rapport_import_ebp.csv';
                    a.click();
                    URL.revokeObjectURL(url);
                });
            } else {
                $('#ebp-echecs-bloc').hide();
            }

            new bootstrap.Modal(document.getElementById('modal-import-ebp')).show();
            if (r.maj > 0) chargerListe();
        }, 'json').fail(() => { spinner(false); nijacToast('Erreur réseau.', 'danger'); });
    };
    reader.readAsText(file, 'UTF-8');
});

// ── Importer Excel (102_*.xlsx) ───────────────────────────────────────────────
$('#btn-importer').on('click', () => $('#file-input').val('').trigger('click'));

(function () {
    let _modalImportExcel = null;
    function getModalImportExcel() {
        if (!_modalImportExcel) _modalImportExcel = new bootstrap.Modal(document.getElementById('modal-import-excel'));
        return _modalImportExcel;
    }

    function xlsxLog(html, cls) {
        const $d = $('<div>').html(html);
        if (cls) $d.addClass(cls);
        $('#xlsx-log').append($d).scrollTop(9999);
    }

    function xlsxProgress(pct, label) {
        $('#xlsx-progress-bar').css('width', pct + '%');
        $('#xlsx-progress-pct').text(pct + ' %');
        if (label !== undefined) $('#xlsx-progress-label').text(label);
    }

    $('#file-input').on('change', function () {
        const file = this.files[0];
        if (!file) return;

        // Réinitialiser la modale
        $('#xlsx-log').empty();
        $('#xlsx-footer').hide();
        $('#xlsx-progress-bar')
            .css('width', '0%')
            .removeClass('bg-danger')
            .addClass('bg-success progress-bar-animated progress-bar-striped');
        xlsxProgress(5, 'Envoi du fichier…');
        getModalImportExcel().show();
        xlsxLog(`<i class="bi bi-cloud-upload"></i> Envoi de <strong>${file.name}</strong> (${(file.size/1024).toFixed(0)} Ko)…`);

        const fd = new FormData();
        fd.append('action',  'importer_excel');
        fd.append('fichier', file);

        $.ajax({
            url: 'jugearbitre.php', type: 'POST',
            data: fd, processData: false, contentType: false, dataType: 'json',

            xhr() {
                const xhr = $.ajaxSettings.xhr();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        const pct = Math.round(e.loaded / e.total * 30);
                        xlsxProgress(5 + pct, pct < 30 ? 'Envoi du fichier…' : 'Analyse du fichier XLSX…');
                    }
                });
                return xhr;
            },

            success(res) {
                if (!res.ok) {
                    xlsxLog(`<i class="bi bi-x-circle-fill text-danger"></i> ${res.msg}`, 'text-danger fw-semibold');
                    xlsxProgress(100, 'Erreur');
                    $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                    $('#xlsx-footer').show();
                    return;
                }

                const rows = res.data;
                xlsxProgress(40, `${rows.length} JA trouvés — résolution des communes…`);
                xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${rows.length}</strong> Juge(s)-Arbitre(s) trouvé(s) dans le fichier.`);

                // Initialiser le tableau de lignes (id_laposte = null pour l'instant)
                lignes = rows.map((r, i) => Object.assign({}, r, {
                    _idx:            i,
                    actif:           +r.actif,
                    defiscalisation: r.defiscalisation != null ? +r.defiscalisation : 0,
                    nationale:       r.nationale       != null ? +r.nationale       : 0,
                    cp:              r.cp    ?? '',
                    ville:           r.ville ?? '',
                    id_laposte:      null,
                }));

                // Collecter les paires CP/Ville uniques à résoudre
                const pairesUniques = {};
                lignes.forEach(l => {
                    if (l.cp || l.ville) {
                        const cle = (l.cp || '') + '|' + (l.ville || '');
                        if (!pairesUniques[cle]) pairesUniques[cle] = { cp: l.cp, ville: l.ville, id_laposte: null, resolved: false };
                    }
                });
                const paires = Object.entries(pairesUniques); // [[cle, obj], ...]
                const total  = paires.length;

                if (total === 0) {
                    finaliserImportExcel(0, 0, 0);
                    return;
                }

                xlsxLog(`<i class="bi bi-geo-alt"></i> Résolution de <strong>${total}</strong> commune(s) unique(s)…`);
                let done = 0, resolues = 0, multiples = 0, inconnues = 0;

                function resoudreProchaine() {
                    if (done >= total) {
                        // Appliquer les résultats sur les lignes
                        lignes.forEach(l => {
                            const cle = (l.cp || '') + '|' + (l.ville || '');
                            const r = pairesUniques[cle];
                            if (r && r.resolved) {
                                l.id_laposte = r.id_laposte;
                                l.cp         = r.cp;
                                l.ville      = r.ville;
                            }
                        });
                        finaliserImportExcel(resolues, multiples, inconnues);
                        return;
                    }

                    const pct = 40 + Math.round(done / total * 55);
                    xlsxProgress(pct, `Communes : ${done + 1} / ${total}`);

                    const [cle, obj] = paires[done];
                    $.post('../ajax/laposte.php',
                        { action: 'recherche_laposte', cp: obj.cp, ville: obj.ville },
                        function (r) {
                            if (r.ok && !r.multi) {
                                obj.id_laposte = r.id_laposte;
                                obj.cp         = r.cp;
                                obj.ville      = r.ville;
                                obj.resolved   = true;
                                resolues++;
                            } else if (r.multi) {
                                // Plusieurs communes — laisser null, l'utilisateur corrigera via la modale
                                obj.resolved = true;
                                multiples++;
                                xlsxLog(`<span class="text-warning">⚠ Plusieurs communes pour CP <strong>${obj.cp}</strong> — à préciser dans la grille.</span>`);
                            } else {
                                inconnues++;
                                xlsxLog(`<span class="text-danger">✗ Commune non trouvée : ${obj.cp} ${obj.ville}</span>`);
                            }
                            done++;
                            resoudreProchaine();
                        }, 'json'
                    ).fail(() => { inconnues++; done++; resoudreProchaine(); });
                }

                resoudreProchaine();
            },

            error() {
                xlsxLog('<i class="bi bi-x-circle-fill text-danger"></i> Erreur réseau lors de l\'import.', 'fw-semibold');
                xlsxProgress(100, 'Erreur réseau');
                $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                $('#xlsx-footer').show();
            }
        });

        function finaliserImportExcel(resolues, multiples, inconnues) {
            xlsxLog(`<hr class="my-1">`);
            xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${resolues}</strong> commune(s) résolue(s).`);
            if (multiples) xlsxLog(`<span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> <strong>${multiples}</strong> CP avec plusieurs communes — à préciser (clic CP/Ville dans la grille).</span>`);
            if (inconnues) xlsxLog(`<span class="text-danger"><i class="bi bi-x-circle-fill"></i> <strong>${inconnues}</strong> commune(s) introuvable(s) — à corriger dans la grille.</span>`);
            xlsxLog(`<hr class="my-1"><i class="bi bi-database-fill-up text-primary"></i> Enregistrement en base de données…`);
            xlsxProgress(98, 'Enregistrement en base…');

            $.post('jugearbitre.php', { action: 'maj_bdd', lignes: JSON.stringify(lignes) }, function (res) {
                xlsxProgress(100, 'Terminé');
                $('#xlsx-progress-bar').removeClass('progress-bar-animated progress-bar-striped');
                if (res.ok) {
                    xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${res.msg}</strong>`);
                } else {
                    xlsxLog(`<span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> ${res.msg}</span>`);
                }
                xlsxLog(`<strong class="text-primary">Cliquez « OK » pour afficher la grille à jour.</strong>`);
                renderGrille();
                setStatus(`Import terminé. ${res.msg}`);
                $('#xlsx-footer').show();
                chargerListe();
            }, 'json').fail(() => {
                xlsxProgress(100, 'Erreur réseau');
                xlsxLog(`<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Erreur réseau lors de l'enregistrement.</span>`);
                $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                xlsxLog(`<strong>Cliquez « OK » pour afficher la grille — utilisez « Mettre à jour la BDD » pour réessayer.</strong>`);
                renderGrille();
                $('#xlsx-footer').show();
            });
        }

        $('#btn-xlsx-ok').off('click').on('click', function () {
            nijacToast(`${lignes.length} JA importé(s) depuis Excel.`);
        });
    });
}());

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
