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

// ── Sécurité ──────────────────────────────────────────────────────────────────
$authRedirect = '../index.php';
require __DIR__ . '/../includes/auth_required.php';

$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Formater un numéro de téléphone ───────────────────────────────────────────
// Normalise un nom de ville : majuscules, tirets et apostrophes → espace, espaces multiples réduits
function normaliserVille(string $ville): string
{
    $v = mb_strtoupper(trim($ville), 'UTF-8');
    $v = str_replace(['-', "'", "\u{2019}"], ' ', $v); // tiret, apostrophe droite et typographique → espace
    $v = preg_replace('/\s+/', ' ', $v);               // espaces multiples → un seul
    return trim($v);
}

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
    $actionsAdmin = ['importer_excel', 'sauvegarder', 'supprimer', 'maj_laposte', 'enrichir_fftt', 'get_clubs_dept', 'import_fftt_club'];
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
                        (SELECT cl.Nom FROM Club cl WHERE cl.Id_Club = j.Id_Club LIMIT 1) AS NomClub,
                        (SELECT lp.CodePostal FROM laposte lp WHERE lp.Id_LaPoste = j.Id_LaPoste LIMIT 1) AS CodePostalJA,
                        (SELECT lp.Nom        FROM laposte lp WHERE lp.Id_LaPoste = j.Id_LaPoste LIMIT 1) AS VilleJA,
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

        // ── Recherche laposte par CP et/ou Ville ───────────────────────────
        if ($action === 'recherche_laposte') {
            $cp    = trim($_POST['cp']    ?? '');
            $ville = normaliserVille($_POST['ville'] ?? '');

            if ($cp === '' && $ville === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'CP et Ville vides.']);
                exit;
            }

            // 1) Correspondance exacte CP + Ville
            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? AND UPPER(REPLACE(REPLACE(Nom, '-', ' '), '''', ' ')) = ? LIMIT 1");
                $stmt->execute([$cp, $ville]);
                $row = $stmt->fetch();
                if ($row) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                    exit;
                }
            }

            // 2) CP seul → toutes les villes correspondantes
            if ($cp !== '') {
                $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
                $stmt->execute([$cp]);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                    exit;
                }
                if (count($rows) > 1) {
                    $sugg = array_map(function($r) { return ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']]; }, $rows);
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                    exit;
                }
            }

            // 3) Ville seule → correspondance partielle
            if ($ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE UPPER(REPLACE(REPLACE(Nom, '-', ' '), '''', ' ')) LIKE ? ORDER BY CodePostal, Nom LIMIT 20");
                $stmt->execute([$ville . '%']);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                    exit;
                }
                if (count($rows) > 1) {
                    $sugg = array_map(function($r) { return ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']]; }, $rows);
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                    exit;
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => false, 'msg' => 'Aucune commune trouvée.']);
            exit;
        }

        // ── Importer Excel ─────────────────────────────────────────────────
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

            if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'PhpSpreadsheet non installé sur ce serveur. Contactez l\'administrateur.']);
                exit;
            }
            require_once __DIR__ . '/../vendor/autoload.php';
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            // Charger tous les clubs pour enrichir nom_club
            $clubsMap = [];
            foreach ($pdo->query('SELECT Id_Club, Nom FROM Club')->fetchAll() as $c) {
                $clubsMap[$c['Id_Club']] = $c['Nom'];
            }

            // Charger la table laposte : clé = "CP|VILLE_NORMALISEE" → Id_LaPoste
            $laposteMap = [];
            foreach ($pdo->query('SELECT Id_LaPoste, CodePostal, Nom FROM laposte')->fetchAll() as $lp) {
                $cle = trim($lp['CodePostal']) . '|' . normaliserVille($lp['Nom']);
                $laposteMap[$cle] = $lp['Id_LaPoste'];
            }

            $lignes = [];
            // Colonnes : A=Id_JA, B=Nom, C=Prénom, H=Id_Club, J=Grade,
            //            N=Actif, R=CodePostal, S=Ville, T=Email, U=Téléphone(alt), V=Téléphone
            for ($row = 3; $row <= $maxRow; $row++) {
                $grade = trim((string)$sheet->getCell('J' . $row)->getValue());

                // Filtrer : ne garder que les grades commençant par "JA"
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

                // Téléphone : V en priorité, sinon U
                $tel = $telV !== '' ? $telV : $telU;

                // Actif : "Actif" → 1, tout autre valeur → 0
                $actif = (strtolower(trim($actifRaw)) === 'actif') ? 1 : 0;

                // Recherche Id_LaPoste par CP + Ville (normalisation majuscules)
                $idLaPoste = null;
                if ($cp !== '' && $ville !== '') {
                    $cle = $cp . '|' . normaliserVille($ville);
                    $idLaPoste = $laposteMap[$cle] ?? null;
                }

                $lignes[] = [
                    'id'              => $idJA !== '' ? (int)$idJA : 0,
                    'nom'             => mb_strtoupper($nom, 'UTF-8'),
                    'prenom'          => mb_convert_case($prenom, MB_CASE_TITLE, 'UTF-8'),
                    'email'           => $email !== '' ? $email : null,
                    'telephone'       => formaterTelephone($tel !== '' ? $tel : null),
                    'grade'           => $grade,
                    'actif'           => $actif,
                    'id_club'         => $idClub !== '' ? $idClub : null,
                    'nom_club'        => $idClub !== '' ? ($clubsMap[$idClub] ?? '') : '',
                    'id_laposte'      => $idLaPoste,
                    'cp'              => $cp,
                    'ville'           => $ville,
                ];
            }

            $lignes = deduplicateJA($lignes, 'nom', 'prenom', 'grade');
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
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
            if ($idJA <= 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id_JA invalide.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE ja SET Id_LaPoste = ? WHERE Id_JA = ?');
            $stmt->execute([$idLaPoste, $idJA]);
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
                                 Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtUpdate = $pdo->prepare(
                'UPDATE ja SET Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                               Actif=?, Id_Club=?, Id_LaPoste=?,
                               Defiscalisation=?, Nationale=?, NumCompteEBP=?
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

                if ($nom === '') continue;

                try {
                    if ($id > 0) {
                        $stmtCheck->execute([$id]);
                        if ((int)$stmtCheck->fetchColumn() > 0) {
                            $stmtUpdate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $id]);
                            $updates++;
                        } else {
                            $stmtInsert->execute([$id, $nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp]);
                            $inserts++;
                        }
                    } else {
                        // Pas d'Id_JA → INSERT auto-increment
                        $pdo->prepare(
                            'INSERT INTO ja (Nom, Prenom, Email, Telephone, Grade, Actif,
                                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp]);
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

            foreach ($membres as $m) {
                $licence = trim((string)($m['licence'] ?? ''));
                if ($licence === '') continue;

                try {
                    $lb  = $api->getLicenceB($licence);
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

                    // Upsert dans ja (Id_JA = numéro de licence)
                    $exists = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                    $exists->execute([$licence]);
                    if ($exists->fetchColumn()) {
                        // Mise à jour du grade FFTT seulement — CP/Ville préservés
                        $pdo->prepare(
                            'UPDATE ja SET GradeFFTT=?, DateEnrichissementFFTT=NOW() WHERE Id_JA=?'
                        )->execute([$gradeNorm, $licence]);
                        $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'mis_a_jour'];
                    } else {
                        // Insertion d'un nouveau JA
                        $pdo->prepare(
                            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, GradeFFTT, Actif, Id_Club, Defiscalisation, Nationale, DateEnrichissementFFTT)
                             VALUES (?, ?, ?, ?, ?, ?, 1, ?, 0, 0, NOW())'
                        )->execute([$licence, $nom, $prenom, $email ?: null, $gradeNorm, $gradeNorm, $idClub]);
                        $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'nouveau'];
                    }
                } catch (RuntimeException) {
                    $erreurs++;
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs]);
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

        /* ── Toast ── */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }

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

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <?php if ($isAdmin): ?>
    <!-- Menu déroulant style Windows -->
    <div class="win-menu-wrap">
        <button class="win-menu-btn" id="win-menu-trigger">
            <i class="bi bi-grid-3x3-gap-fill"></i>Actions
            <i class="bi bi-chevron-down caret"></i>
        </button>
        <div class="win-menu-drop" id="win-menu-drop">
            <button class="drop-item" id="btn-importer">
                <i class="bi bi-file-earmark-arrow-up"></i>Importer Excel 102_*.xlsx
            </button>
            <button class="drop-item" id="btn-maj-bdd">
                <i class="bi bi-database-fill-up"></i>Mettre à jour la Base de données
            </button>
            <hr class="drop-sep">
            <button class="drop-item" id="btn-enrichir-fftt">
                <i class="bi bi-cloud-download"></i>Enrichir via FFTT (liste visible)
            </button>
            <button class="drop-item" id="btn-import-fftt-dept" data-bs-toggle="modal" data-bs-target="#modal-import-fftt">
                <i class="bi bi-cloud-arrow-down-fill"></i>Importer JA1/JA2/JA3 depuis FFTT (par département)
            </button>
            <hr class="drop-sep">
            <button class="drop-item green" id="btn-nouveau-ja">
                <i class="bi bi-person-plus-fill"></i>Nouveau JA
            </button>
            <hr class="drop-sep">
            <a class="drop-item" href="https://www.dcode.fr/code-postal" target="_blank">
                <i class="bi bi-globe2"></i>dCode code-postal
            </a>
        </div>
    </div>
    <input type="file" id="file-input" accept=".xlsx" style="display:none">
    <?php endif; ?>
    <span id="lbl-count">0 JA</span>
    <div id="toggle-actif" style="margin-left:.5rem">
        <button id="btn-tous">Tous</button>
        <button id="btn-actifs"       class="active">Actifs seulement</button>
        <button id="btn-erreurs-cp">⚠ Erreurs CP/Ville</button>
    </div>
    <span style="flex:1"></span>
    <?php if ($isAdmin): ?>
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
    <?php endif; ?>
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
                <th style="width:110px" class="no-sort">FFTT</th>
                <th style="width:75px"  class="no-sort">Lien dispo</th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="17" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt. — Cliquez sur une cellule puis F2 pour modifier.'; ?>

<!-- Toast -->
<div id="toast-container"></div>

<!-- Modale Import FFTT par département -->
<div class="modal fade" id="modal-import-fftt" tabindex="-1" aria-labelledby="modal-import-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
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
              <div class="small text-muted">Nouveaux JAs</div>
            </div>
            <div class="col-3">
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
    <script src="../asset/js/nijac-csrf.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let lignes     = [];
let filtreActif   = true;   // false = tous, true = actifs seulement
let filtreErreursCp = false; // true = uniquement les lignes sans id_laposte
let cellActive = null;
let sortField  = 'nom';
let sortDir    = 'asc';
let searchTerm = '';
const isAdmin  = <?= $isAdminJs ?>;
let deptFiltre = <?= $deptUserJs ?>; // nominateur : filtré sur son dept

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    const id  = 't' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 4000);
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

function majEnteteTri() {
    $('#tbl-ja thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f === sortField) $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    majEnteteTri();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun juge-arbitre.';
        $body.append(`<tr><td colspan="17" class="text-center text-muted py-3">${msg}</td></tr>`);
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
            $tr.append(makeTdLaPoste(l.cp,         idx, 'cp'));
            $tr.append(makeTdLaPoste(l.ville,     idx, 'ville'));
            // Colonne FFTT
            const $tdFftt = $('<td>').css({textAlign:'center', verticalAlign:'middle', padding:'.2rem', whiteSpace:'nowrap'});
            if (l.grade_fftt || l.classement || l.date_validation_fftt) {
                const grade = l.grade_fftt          ? `<span class="badge bg-success me-1">${l.grade_fftt}</span>` : '';
                const pts   = l.classement          ? `<span class="badge bg-info text-dark me-1">${l.classement}</span>` : '';
                const valid = l.date_validation_fftt ? `<br><small class="text-muted">${l.date_validation_fftt}</small>` : '';
                $tdFftt.html(`${grade}${pts}${valid}`);
            }
            if (isAdmin) {
                const $btnRefresh = $('<button>')
                    .addClass('btn btn-sm btn-outline-primary btn-enrichir-fftt ms-1')
                    .attr({'data-id': l.id, 'data-idx': idx, title: 'Enrichir via FFTT'})
                    .html('<i class="bi bi-cloud-download"></i>');
                $tdFftt.append($btnRefresh);
            }
            $tr.append($tdFftt);

            // Bouton lien disponibilité
            const $tdLien = $('<td>').css({textAlign:'center', verticalAlign:'middle', padding:'.2rem'});
            const dispoCls   = l.nb_dispo > 0 ? 'btn-success'         : 'btn-outline-secondary';
            const dispoTitle = l.nb_dispo > 0 ? `Disponibilités saisies (${l.nb_dispo} journée(s))` : 'Aucune disponibilité saisie — cliquez pour ouvrir';
            $tdLien.html(`<button class="btn btn-sm ${dispoCls} btn-lien-dispo" data-id="${l.id}" title="${dispoTitle}"><i class="bi bi-calendar2-check"></i></button>`);
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

// ── Cellule CP / Ville avec code couleur laposte ──────────────────────────────
function makeTdLaPoste(val, idx, field) {
    const l      = lignes[idx];
    const trouve = l ? l.id_laposte != null : false;
    const vide   = !val || String(val).trim() === '';
    const bg     = trouve ? '#d1fae5' : '#fee2e2';
    const $td = $('<td>')
        .attr('data-idx', idx)
        .attr('data-field', field)
        .css({ background: bg });
    const affichage = vide && !trouve ? '—' : (val ?? '');
    const $div = $('<div class="cell-inner">').text(affichage).attr('contenteditable', 'false');
    $td.append($div);

    // Clic direct → édition immédiate
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
    return $td;
}

// ── Sauvegarde immédiate Id_LaPoste en base ───────────────────────────────────
function sauvegarderLaPoste(idx) {
    const l = lignes[idx];
    if (!l || !(+l.id > 0)) {
        // Ligne pas encore en base : informer seulement
        setStatus('Commune trouvée. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
        return;
    }
    $.post('jugearbitre.php', { action: 'maj_laposte', id_ja: l.id, id_laposte: l.id_laposte ?? '', cp: l.cp ?? '', ville: l.ville ?? '' }, function (res) {
        if (res.ok) {
            setStatus(`Commune mise à jour en base pour ${l.nom} ${l.prenom}.`);
            toast(`Commune sauvegardée : ${l.cp} ${l.ville}.`);
        } else {
            toast(`Erreur sauvegarde : ${res.msg}`, false);
        }
    }, 'json').fail(() => toast('Erreur réseau lors de la sauvegarde.', false));
}

// ── Recherche laposte après édition CP ou Ville ───────────────────────────────
function rechercherLaPoste(idx) {
    const cp    = String(lignes[idx].cp    ?? '').trim();
    const ville = String(lignes[idx].ville ?? '').trim();
    if (cp === '' && ville === '') return;

    $.post('jugearbitre.php', { action: 'recherche_laposte', cp, ville }, function (res) {
        if (!res.ok && !res.multi) {
            // Non trouvé : effacer id_laposte, garder les valeurs saisies, fond rouge
            lignes[idx].id_laposte = null;
            renderGrille();
            toast(`Commune non trouvée (${cp} ${ville}).`, false);
            return;
        }
        if (res.multi) {
            afficherSuggestions(idx, res.suggestions);
            return;
        }
        // Trouvé : mettre à jour cp, ville, id_laposte
        lignes[idx].id_laposte = res.id_laposte;
        lignes[idx].cp         = res.cp;
        lignes[idx].ville      = res.ville;
        renderGrille();
        // Sauvegarde immédiate si le JA existe déjà en base (id > 0)
        sauvegarderLaPoste(idx);
    }, 'json').fail(() => toast('Erreur réseau lors de la recherche laposte.', false));
}

// ── Popup de suggestions ──────────────────────────────────────────────────────
function afficherSuggestions(idx, suggestions) {
    $('#laposte-popup').remove();
    const $pop = $('<div id="laposte-popup">').css({
        position: 'fixed', top: '50%', left: '50%',
        transform: 'translate(-50%,-50%)',
        background: '#fff', border: '1px solid #c8d4e8',
        borderRadius: '8px', boxShadow: '0 8px 32px rgba(0,0,0,.18)',
        zIndex: 9999, minWidth: '280px', maxWidth: '400px',
        maxHeight: '60vh', overflowY: 'auto', padding: '1rem'
    });
    $pop.append('<div style="font-weight:700;margin-bottom:.5rem;color:#1a3a6b">Plusieurs communes trouvées — choisissez :</div>');
    suggestions.forEach(s => {
        $('<button>').text(`${s.cp}  ${s.ville}`)
            .css({ display: 'block', width: '100%', textAlign: 'left', padding: '.35rem .6rem',
                   margin: '.15rem 0', border: '1px solid #c8d4e8', borderRadius: '4px',
                   background: '#f8faff', cursor: 'pointer', fontSize: '.88rem' })
            .on('click', function () {
                lignes[idx].id_laposte = s.id_laposte;
                lignes[idx].cp         = s.cp;
                lignes[idx].ville      = s.ville;
                $('#laposte-popup').remove();
                renderGrille();
                sauvegarderLaPoste(idx);
            })
            .appendTo($pop);
    });
    $('<button>').text('Annuler')
        .css({ marginTop: '.5rem', padding: '.25rem .8rem', background: '#e5e7eb',
               border: 'none', borderRadius: '4px', cursor: 'pointer' })
        .on('click', () => $('#laposte-popup').remove())
        .appendTo($pop);
    $('body').append($pop);
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

    // Si CP ou Ville modifié → relancer la recherche laposte
    if (['cp', 'ville'].includes(field)) {
        rechercherLaPoste(idx);
        return;
    }
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

// ── Importer Excel ────────────────────────────────────────────────────────────
$('#btn-importer').on('click', () => $('#file-input').trigger('click'));

$('#file-input').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('action',  'importer_excel');
    fd.append('fichier', file);

    spinner(true);
    $.ajax({
        url: 'jugearbitre.php', type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
        success(res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            // Les clés PHP sont déjà en minuscules (snake_case) côté import
            lignes = res.data.map((r, i) => Object.assign(r, {
                _idx:           i,
                actif:          +r.actif,
                defiscalisation: r.defiscalisation != null ? +r.defiscalisation : 0,
                nationale:       r.nationale != null ? +r.nationale : 0,
                cp:             r.cp    ?? '',
                ville:          r.ville ?? '',
            }));
            renderGrille();
            toast(`${res.count} JA importé(s) depuis Excel (filtre grade JA*).`);
            setStatus(`${res.count} JA importé(s). Vérifiez les données puis cliquez sur « Mettre à jour la BDD ».`);
        },
        error() { spinner(false); toast("Erreur lors de l'import.", false); }
    });
    this.value = '';
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

// ── Enrichir un JA via FFTT (bouton par ligne) ───────────────────────────────
function enrichirJaFftt(idJa, idx) {
    $.post('jugearbitre.php', { action: 'enrichir_fftt', id_ja: idJa }, function (res) {
        if (!res.ok) { toast('FFTT : ' + res.msg, false); return; }
        lignes[idx].classement               = res.classement;
        lignes[idx].date_validation_fftt     = res.date_valid;
        lignes[idx].grade_fftt               = res.grade_fftt;
        lignes[idx].date_enrichissement_fftt = new Date().toISOString();
        renderGrille();
        const grade = res.grade_fftt ? ` [${res.grade_fftt}]` : '';
        const pts   = res.classement ? ` — ${res.classement} pts` : '';
        const dt    = res.date_valid ? `, validité ${res.date_valid}` : '';
        toast(`FFTT : ${res.nom_fftt}${grade}${pts}${dt}`);
    }, 'json').fail(() => toast('Erreur réseau FFTT.', false));
}

$(document).on('click', '.btn-enrichir-fftt', function () {
    const id  = $(this).data('id');
    const idx = $(this).data('idx');
    enrichirJaFftt(id, idx);
});

// ── Enrichir tous les JA visibles via FFTT ────────────────────────────────────
$('#btn-enrichir-fftt').on('click', function () {
    const visibles = lignesFiltreesTriees();
    if (!visibles.length) { toast('Aucun JA visible.', false); return; }
    if (!confirm(`Enrichir ${visibles.length} JA via l'API FFTT ? (${visibles.length} appels réseau)`)) return;

    let done = 0;
    let errors = 0;

    function next() {
        if (done + errors >= visibles.length) {
            toast(`FFTT : ${done} enrichi(s)${errors ? ', ' + errors + ' erreur(s)' : ''}.`, errors === 0);
            spinner(false);
            return;
        }
        const l = visibles[done + errors];
        $.post('jugearbitre.php', { action: 'enrichir_fftt', id_ja: l.id }, function (res) {
            if (res.ok) {
                lignes[l._idx].classement               = res.classement;
                lignes[l._idx].date_validation_fftt     = res.date_valid;
                lignes[l._idx].grade_fftt               = res.grade_fftt;
                lignes[l._idx].date_enrichissement_fftt = new Date().toISOString();
                done++;
            } else {
                errors++;
            }
            setStatus(`Enrichissement FFTT : ${done + errors}/${visibles.length}…`);
            next();
        }, 'json').fail(() => { errors++; next(); });
    }

    spinner(true);
    next();
});

// ── Import FFTT par département ───────────────────────────────────────────────
let importFfttEnCours = false;

$('#modal-import-fftt').on('hidden.bs.modal', function () {
    if (!importFfttEnCours) resetImportFftt();
});

function resetImportFftt() {
    $('#import-fftt-step1').show();
    $('#import-fftt-step2, #import-fftt-step3').hide();
    $('#import-fftt-dept').val('');
    $('#import-fftt-bar').css('width', '0%');
    $('#import-fftt-label').text('Récupération des clubs…');
    $('#import-fftt-pct').text('0 %');
    ['cnt-nouveaux','cnt-maj','cnt-membres','cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#import-fftt-log, #import-fftt-log-final').empty();
    importFfttEnCours = false;
}

$('#btn-lancer-import-fftt').on('click', function () {
    const dep = $('#import-fftt-dept').val();
    if (!dep) { nijacToast('Sélectionnez un département.', 'warning'); return; }

    importFfttEnCours = true;
    $('#import-fftt-step1').hide();
    $('#import-fftt-step2').show();
    $('#btn-fermer-import-fftt').prop('disabled', true);

    let totalNouveaux = 0, totalMaj = 0, totalMembres = 0, totalErreurs = 0;
    const logLines = [];

    // Étape 1 : récupérer la liste des clubs
    $.post('jugearbitre.php', { action: 'get_clubs_dept', dep }, function (res) {
        if (!res.ok) {
            nijacToast('Erreur : ' + res.msg, 'danger');
            resetImportFftt();
            $('#import-fftt-step1').show();
            $('#import-fftt-step2').hide();
            $('#btn-fermer-import-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs.filter(c => c.numero);
        const total = clubs.length;
        let done = 0;

        $('#import-fftt-label').text(`0 / ${total} clubs traités…`);

        function traiterClub() {
            if (done >= total) {
                // Terminé
                importFfttEnCours = false;
                $('#btn-fermer-import-fftt').prop('disabled', false);
                $('#import-fftt-step2').hide();
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
                return;
            }

            const club = clubs[done];
            const pct  = Math.round(done / total * 100);
            $('#import-fftt-bar').css('width', pct + '%');
            $('#import-fftt-pct').text(pct + ' %');
            $('#import-fftt-label').text(`${done + 1} / ${total} — ${club.nom}`);

            $.post('jugearbitre.php', { action: 'import_fftt_club', num_club: club.numero }, function (r) {
                if (r.ok) {
                    totalMembres += r.total_membres;
                    totalErreurs += r.erreurs;
                    r.trouves.forEach(ja => {
                        const cls   = ja.statut === 'nouveau' ? 'text-success' : 'text-info';
                        const label = ja.statut === 'nouveau' ? '✚ NOUVEAU' : '↻ MAJ';
                        const line  = `<div class="${cls}">[${label}] ${ja.grade} — ${ja.nom} ${ja.prenom} (${ja.licence})</div>`;
                        logLines.push(line);
                        $('#import-fftt-log').append(line).scrollTop(9999);
                        if (ja.statut === 'nouveau') totalNouveaux++;
                        else totalMaj++;
                    });
                    $('#cnt-nouveaux').text(totalNouveaux);
                    $('#cnt-maj').text(totalMaj);
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
        $('#import-fftt-step1').show();
        $('#import-fftt-step2').hide();
        $('#btn-fermer-import-fftt').prop('disabled', false);
    });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-ja thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    sortDir   = sortField === f ? (sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
    sortField = f;
    renderGrille();
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

    $.post('jugearbitre.php', { action: 'recherche_laposte', cp, ville }, function (res) {
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

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
