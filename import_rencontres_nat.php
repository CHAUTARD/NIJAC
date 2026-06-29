<?php
/**
 * NIJAC – Import Rencontres Nationales (E017)
 *
 * Charge les équipes nationales depuis l'API FFTT (N1M, N2M, N3M, N1F, N2F),
 * permet d'associer chaque équipe à un département et un club normand,
 * puis importe les rencontres où le receveur est un club de la région.
 */
session_start();
require __DIR__ . '/includes/admin_required.php';
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';

// ── Migration table ───────────────────────────────────────────────────────────
try {
    $pdo0 = getPDO();
    $pdo0->exec("
        CREATE TABLE IF NOT EXISTS equipe_nationale (
            Id_EquipeNat INT              AUTO_INCREMENT PRIMARY KEY,
            Nom          VARCHAR(200)     NOT NULL,
            id_division  INT      NOT NULL,
            Poule        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            Rang         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            CodeDept     VARCHAR(3)       NULL,
            Id_Club      CHAR(8)          NULL,
            Id_Equipe    INT              NULL,
            UNIQUE KEY uq_nom_div (Nom(150), id_division)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $cols = array_column($pdo0->query('SHOW COLUMNS FROM equipe_nationale')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('CodeDept', $cols))
        $pdo0->exec("ALTER TABLE equipe_nationale ADD COLUMN CodeDept VARCHAR(3) NULL AFTER Rang");
} catch (PDOException $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Retourne [code => Id_Division] depuis la table division (ex: ['N1M' => 3, 'N2M' => 5]). */
function getDivIdMap(PDO $pdo): array
{
    $rows = $pdo->query("SELECT Id_Division, Division FROM division")->fetchAll(PDO::FETCH_ASSOC);
    $map  = [];
    foreach ($rows as $r) $map[$r['Division']] = (int)$r['Id_Division'];
    return $map;
}

/** Normalise une réponse FFTT en tableau indexé. */
function ffttItemsNat(array $data, string $key): array
{
    $items = $data[$key] ?? [];
    if (empty($items)) return [];
    return isset($items[0]) ? $items : [$items];
}

/** Retourne les cx_poule réels d'une division depuis action=poule. */
function getCxPoules(object $api, string $divFftt): array
{
    $r     = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
    $items = ffttItemsNat($r, 'poule');
    $cx    = [];
    foreach ($items as $i => $p) {
        $lienRaw = $p['lien'] ?? '';
        $lienStr = is_array($lienRaw) ? '' : (string)$lienRaw;
        if ($lienStr !== '') {
            parse_str(html_entity_decode($lienStr), $lp);
            if (!empty($lp['cx_poule'])) { $cx[$i + 1] = $lp['cx_poule']; continue; }
        }
        $cx[$i + 1] = null; // cx_poule absent → fallback global
    }
    return $cx; // [numPoule => cx_poule|null]
}

/** Retourne les rencontres d'une division (par poule ou global). */
function getRencontresDivision(object $api, string $divFftt): array
{
    $cx = getCxPoules($api, $divFftt);
    $rencontres = [];
    $hasAllCx   = count(array_filter($cx)) === count($cx) && count($cx) > 0;

    if ($hasAllCx) {
        foreach ($cx as $num => $cxPoule) {
            $r = $api->request('xml_result_equ', ['D1' => $divFftt, 'cx_poule' => $cxPoule, 'auto' => '1', 'type' => 'E']);
            foreach (ffttItemsNat($r, 'tour') as $rc) {
                $rc['_poule_num'] = $num;
                $rencontres[] = $rc;
            }
        }
    } else {
        $r = $api->request('xml_result_equ', ['D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
        $rencontres = ffttItemsNat($r, 'tour');
    }
    return $rencontres;
}

/** Retourne [idEpreuve => [divisions FFTT Nationales]] depuis l'API. */
function getDivisionsNationales(object $api, string $orgId): array
{
    $epRep  = $api->request('xml_epreuve', ['organisme' => $orgId, 'type' => 'E']);
    $result = [];
    foreach (ffttItemsNat($epRep, 'epreuve') as $ep) {
        $intitule = (string)($ep['intitule'] ?? $ep['libelle'] ?? '');
        if (!preg_match('/nationale/i', $intitule)) continue;
        $idEp = (string)($ep['idepreuve'] ?? $ep['ident'] ?? '');
        if ($idEp === '') continue;
        $divRep = $api->request('xml_division', ['organisme' => $orgId, 'epreuve' => $idEp, 'type' => 'E']);
        foreach (ffttItemsNat($divRep, 'division') as $div) {
            $lib   = (string)($div['libelle'] ?? '');
            $idDiv = (string)($div['iddivision'] ?? $div['ident'] ?? '');
            if ($idDiv === '') continue;
            // Ne garder que les divisions N1M, N2M, N3M, N1F, N2F, N1D, N2D
            if (!preg_match('/nationale\s*(\d+)\s*(messieurs?|dames?|hommes?|f[eé]minin)/i', $lib, $m)) continue;
            $num  = $m[1];
            $sexe = preg_match('/dame|féminin|feminin/i', $m[2]) ? 'F' : 'M';
            $code = "N{$num}{$sexe}";
            $result[$idEp][] = ['iddivision' => $idDiv, 'libelle' => $lib, 'code' => $code];
        }
    }
    return $result;
}

/** Retourne l'ID organisme de la ligue depuis la config ou l'API. */
function getOrgId(object $api): string
{
    $stored = getConfig('fftt_organisme_id', '');
    if ($stored !== '') return $stored;
    $region = mb_strtolower(getConfig('region', 'Normandie'), 'UTF-8');
    $rep    = $api->request('xml_organisme', ['type' => 'L']);
    foreach (ffttItemsNat($rep, 'organisme') as $org) {
        $lib = mb_strtolower((string)($org['libelle'] ?? ''), 'UTF-8');
        if (str_contains($lib, $region)) {
            $id = (string)($org['id'] ?? $org['ident'] ?? $org['idorga'] ?? '');
            if ($id !== '') return $id;
        }
    }
    return '';
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);
    $pdo = getPDO();

    try {
        // ── Vider le cache divisions nationales (avant un nouveau scan) ─────
        if ($action === 'reset_nat_cache') {
            unset($_SESSION['nat_div_ids']);
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Lister tous les clubs normands ───────────────────────────────────
        if ($action === 'lister_clubs_region') {
            set_time_limit(120);
            $api   = getFfttApi();
            $depts = getDeptActifs();
            $clubs = [];
            foreach ($depts as $dept) {
                $list = $api->getClubsDepartement($dept['code']);
                foreach ($list as $c) {
                    $num = trim((string)($c['numero'] ?? $c['numclu'] ?? ''));
                    $nom = trim((string)($c['nom'] ?? ''));
                    if ($num === '') continue;
                    $clubs[] = ['numclu' => $num, 'nom' => $nom, 'dept' => (string)$dept['code']];
                }
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => $clubs]);
            exit;
        }

        // ── Debug : voir brut xml_equipe d'un club ───────────────────────────
        if ($action === 'debug_club') {
            $numclu = trim($_GET['numclu'] ?? '');
            if ($numclu === '') { ob_end_clean(); echo json_encode(['ok'=>false,'err'=>'numclu manquant']); exit; }
            $api     = getFfttApi();
            $equipes = $api->getEquipesClub($numclu);
            $raw     = mb_convert_encoding((string)$api->lastRaw(), 'UTF-8', 'UTF-8');
            $payload = ['ok' => true, 'equipes' => $equipes, 'raw' => $raw];
            $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $json = json_encode(['ok' => false, 'err' => 'json_encode échoué : ' . json_last_error_msg()]);
            }
            ob_end_clean();
            echo $json;
            exit;
        }

        // ── Scanner les équipes nationales d'un club ─────────────────────────
        if ($action === 'scanner_club') {
            $numclu  = trim($_POST['numclu'] ?? '');
            $dept    = trim($_POST['dept'] ?? '');
            $nomClub = mb_substr(trim($_POST['nom'] ?? ''), 0, 100);
            if ($numclu === '') { ob_end_clean(); echo json_encode(['ok' => false, 'err' => 'numclu manquant']); exit; }

            // Divisions nationales actives (phase à venir) — cachées en session pour éviter
            // un appel API par club (plusieurs centaines de clubs à scanner)
            if (empty($_SESSION['nat_div_ids'])) {
                $apiOrg = getFfttApi();
                $orgId  = getOrgId($apiOrg);
                $_SESSION['nat_div_ids'] = [];
                if ($orgId !== '') {
                    $divMap = getDivisionsNationales($apiOrg, $orgId);
                    foreach ($divMap as $divs)
                        foreach ($divs as $div) $_SESSION['nat_div_ids'][$div['iddivision']] = $div['code'];
                }
            }
            $phaseFiltre = trim($_POST['phase'] ?? ''); // "1", "2" ou "" = toutes

            $api      = getFfttApi();
            $equipes  = $api->getEquipesClub($numclu);
            $divIdMap = getDivIdMap($pdo);

            $stmtClubIns = $pdo->prepare("INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)");
            $stmtNatIns  = $pdo->prepare("
                INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang, CodeDept, Id_Club)
                VALUES (?,?,?,0,?,?)
                ON DUPLICATE KEY UPDATE CodeDept=VALUES(CodeDept), Id_Club=VALUES(Id_Club)
            ");

            $nationales = [];
            $nonMatch   = [];

            foreach ($equipes as $eq) {
                $lib  = trim((string)($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                if ($lib === '') continue;
                $ndiv = trim((string)($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? ''));

                // Détection par nom de division (seule méthode fiable — D1 est un ID local incompatible)
                $divCode = null;
                if (preg_match('/\b(N[1-9][MFD])\b/i', $ndiv, $m))
                    $divCode = strtoupper($m[1]);
                elseif (preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $ndiv, $m))
                    $divCode = 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
                elseif (preg_match('/\bpro\s*([ab])\b/i', $ndiv, $m))
                    $divCode = 'PRO' . strtoupper($m[1]);

                if (!$divCode) {
                    if ($ndiv !== '')
                        $nonMatch[] = ['lib' => $lib, 'raison' => 'non reconnu', 'ndiv' => $ndiv];
                    continue;
                }

                // Filtre de phase : "Phase X" dans ndivision
                if ($phaseFiltre !== '' && !preg_match('/\bPhase\s+' . preg_quote($phaseFiltre, '/') . '\b/i', $ndiv)) {
                    $nonMatch[] = ['lib' => $lib, 'raison' => 'autre phase', 'ndiv' => $ndiv];
                    continue;
                }

                // Extraire la poule depuis ndivision
                $poule = 0;
                if (preg_match('/Poule\s+(\d+)/i', $ndiv, $mp)) $poule = (int)$mp[1];

                $idDiv = $divIdMap[$divCode] ?? null;
                if (!$idDiv) {
                    $nonMatch[] = ['lib' => $lib, 'raison' => "division $divCode absente en BDD", 'ndiv' => $ndiv];
                    continue;
                }
                $stmtClubIns->execute([$numclu, $nomClub]);
                $stmtNatIns->execute([mb_substr($lib, 0, 200), $idDiv, $poule, $dept, $numclu]);
                $isNew = (bool)$pdo->lastInsertId();
                $nationales[] = ['lib' => $lib, 'div' => $divCode, 'new' => $isNew];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'nationales' => $nationales, 'non_match' => $nonMatch]);
            exit;
        }

        // ── Charger équipes nationales depuis FFTT ───────────────────────────
        if ($action === 'charger_depuis_api') {
            set_time_limit(300);
            $api   = getFfttApi();
            $orgId = getOrgId($api);
            if ($orgId === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'err' => 'Organisme ligue introuvable.']);
                exit;
            }

            $divMap   = getDivisionsNationales($api, $orgId);
            $divIdMap = getDivIdMap($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang)
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE Poule = VALUES(Poule)
            ");

            $stats = ['divisions' => 0, 'equipes' => 0, 'erreurs' => []];

            foreach ($divMap as $idEp => $divisions) {
                foreach ($divisions as $div) {
                    $divCode = $div['code'];
                    $divFftt = $div['iddivision'];
                    $idDiv   = $divIdMap[$divCode] ?? null;
                    if (!$idDiv) { $stats['erreurs'][] = "Division $divCode absente en BDD."; continue; }
                    $stats['divisions']++;

                    try {
                        $rencontres = getRencontresDivision($api, $divFftt);
                        $equipesDep = [];
                        foreach ($rencontres as $rc) {
                            $libelle  = trim((string)($rc['libelle'] ?? ''));
                            $pouleNum = isset($rc['_poule_num']) ? (int)$rc['_poule_num'] : 0;
                            if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) $pouleNum = (int)$m[1];

                            foreach (['equa' => $rc['equa'] ?? '', 'equb' => $rc['equb'] ?? ''] as $nom) {
                                $nom = mb_substr(trim((string)$nom), 0, 200);
                                if ($nom === '') continue;
                                $key = $divCode . '|' . $pouleNum . '|' . $nom;
                                if (isset($equipesDep[$key])) continue;
                                $equipesDep[$key] = true;
                                $stmt->execute([$nom, $idDiv, $pouleNum]);
                                if ($pdo->lastInsertId()) $stats['equipes']++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $stats['erreurs'][] = "{$divCode} ({$divFftt}) : " . $e->getMessage();
                    }
                }
            }

            // Auto-propagation CodeDept/Id_Club par nom de base
            $pdo->exec("
                UPDATE equipe_nationale en_cible
                JOIN equipe_nationale en_source
                    ON en_source.Id_Club IS NOT NULL
                    AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                      = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
                SET en_cible.Id_Club  = en_source.Id_Club,
                    en_cible.CodeDept = en_source.CodeDept
                WHERE en_cible.Id_Club IS NULL
            ");

            ob_end_clean();
            echo json_encode(['ok' => true, 'stats' => $stats, 'org_id' => $orgId]);
            exit;
        }

        // ── Liste des équipes ────────────────────────────────────────────────
        if ($action === 'liste_equipes') {
            $rows = $pdo->query("
                SELECT en.Id_EquipeNat, en.Nom, d.Division AS id_division, en.Poule, en.Rang,
                       en.CodeDept, en.Id_Club,
                       (SELECT c.Nom FROM club c WHERE c.Id_Club = en.Id_Club LIMIT 1) AS NomClub
                FROM equipe_nationale en
                LEFT JOIN division d ON d.Id_Division = en.id_division
                ORDER BY d.Division, en.Poule, en.Nom
            ")->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean();
            echo json_encode(['ok' => true, 'equipes' => $rows]);
            exit;
        }

        // ── Recherche clubs ──────────────────────────────────────────────────
        if ($action === 'recherche_club') {
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) { ob_end_clean(); echo json_encode(['ok' => true, 'clubs' => []]); exit; }
            $stmt = $pdo->prepare("SELECT Id_Club, Nom FROM club WHERE Nom LIKE ? ORDER BY Nom LIMIT 25");
            $stmt->execute(['%' . $q . '%']);
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── Sauvegarder association ──────────────────────────────────────────
        if ($action === 'sauvegarder_assoc') {
            $idEn    = (int)($_POST['id_equipe_nat'] ?? 0);
            $codeDept = trim($_POST['code_dept'] ?? '') ?: null;
            $idClub  = ($_POST['id_club'] ?? '') !== '' ? trim($_POST['id_club']) : null;
            if ($idEn <= 0) { ob_end_clean(); echo json_encode(['ok' => false, 'err' => 'Id invalide.']); exit; }
            $pdo->prepare("UPDATE equipe_nationale SET CodeDept=?, Id_Club=? WHERE Id_EquipeNat=?")
                ->execute([$codeDept, $idClub, $idEn]);
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Assigner clubs Hors Région ───────────────────────────────────────
        if ($action === 'assigner_hors_region') {
            $deptsNorm    = array_map('strval', array_column(getDeptActifs(), 'code'));
            $placeholders = implode(',', array_fill(0, count($deptsNorm), '?'));

            $stmt = $pdo->prepare("
                SELECT en.CodeDept,
                       TRIM(REGEXP_REPLACE(MIN(en.Nom), '\\\\s+[0-9]+\$', '')) AS NomBase
                FROM equipe_nationale en
                WHERE en.CodeDept IS NOT NULL
                  AND en.Id_Club IS NULL
                  AND en.CodeDept NOT IN ($placeholders)
                GROUP BY en.CodeDept
            ");
            $stmt->execute($deptsNorm);
            $depts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $crees = 0; $assoc = 0;
            $stmtIns = $pdo->prepare("INSERT INTO club (Id_Club, Nom) VALUES (?,?) ON DUPLICATE KEY UPDATE Nom=VALUES(Nom)");
            $stmtUpd = $pdo->prepare("UPDATE equipe_nationale SET Id_Club=? WHERE CodeDept=? AND Id_Club IS NULL");
            foreach ($depts as $d) {
                $idClub = 'HR' . str_pad($d['CodeDept'], 3, '0', STR_PAD_LEFT);
                $stmtIns->execute([$idClub, mb_substr('Hors Région - ' . $d['NomBase'], 0, 100)]);
                if ($stmtIns->rowCount() === 1) $crees++;
                $stmtUpd->execute([$idClub, $d['CodeDept']]);
                $assoc += $stmtUpd->rowCount();
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs_crees' => $crees, 'equipes_assignees' => $assoc]);
            exit;
        }

        // ── Importer rencontres (receveur = club normand) ────────────────────
        if ($action === 'importer_rencontres') {
            set_time_limit(300);
            $api   = getFfttApi();
            $orgId = getOrgId($api);
            if ($orgId === '') { ob_end_clean(); echo json_encode(['ok' => false, 'err' => 'Organisme introuvable.']); exit; }

            $deptsNorm = array_map('strval', array_column(getDeptActifs(), 'code'));

            // Map [Division][Nom] → {Id_Club, CodeDept}
            $enRows = $pdo->query("
                SELECT d.Division AS DivCode, en.Nom, en.Id_Club, en.CodeDept
                FROM equipe_nationale en
                LEFT JOIN division d ON d.Id_Division = en.id_division
                WHERE en.Id_Club IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);
            $enMap  = [];
            foreach ($enRows as $r) $enMap[$r['DivCode']][$r['Nom']] = $r;

            // Map divCode → Id_Division NIJAC + ArbitrageObligatoire
            $divRows = $pdo->query("SELECT Id_Division, Division, ArbitrageObligatoire FROM division")->fetchAll(PDO::FETCH_ASSOC);
            $divNijac = [];
            foreach ($divRows as $r) $divNijac[$r['Division']] = $r;

            $stmtClubIns   = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtClubByNom = $pdo->prepare('SELECT Id_Club FROM club WHERE Nom=? LIMIT 1');
            $stmtClubById  = $pdo->prepare('SELECT Id_Club FROM club WHERE Id_Club=? LIMIT 1');
            $stmtEqChk     = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Id_Division=? LIMIT 1');
            $stmtEqIns     = $pdo->prepare('INSERT INTO equipe (Nom, Id_Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk     = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns     = $pdo->prepare('INSERT INTO rencontre (Date,Heure,Id_Division,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire) VALUES (?,?,?,?,?,?,?,?,?)');

            $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'ignores' => 0, 'erreurs' => [], 'log' => []];

            $divMap = getDivisionsNationales($api, $orgId);

            foreach ($divMap as $idEp => $divisions) {
                foreach ($divisions as $div) {
                    $divCode = $div['code'];
                    $divFftt = $div['iddivision'];
                    $nijac   = $divNijac[$divCode] ?? null;
                    if (!$nijac) { $stats['erreurs'][] = "Division $divCode non mappée en NIJAC."; continue; }
                    $idDivNijac = (int)$nijac['Id_Division'];
                    $arbitrage  = (int)$nijac['ArbitrageObligatoire'];

                    try {
                        $rencontres = getRencontresDivision($api, $divFftt);
                    } catch (\Throwable $e) {
                        $stats['erreurs'][] = "$divCode : " . $e->getMessage();
                        continue;
                    }

                    foreach ($rencontres as $rc) {
                        $libDom  = mb_substr(trim((string)($rc['equa'] ?? '')), 0, 200);
                        $libExt  = mb_substr(trim((string)($rc['equb'] ?? '')), 0, 200);
                        $dateStr = trim((string)($rc['dateprevue'] ?? ''));
                        $libelle = trim((string)($rc['libelle'] ?? ''));

                        $pouleNum = isset($rc['_poule_num']) ? (int)$rc['_poule_num'] : 0;
                        $journee  = 0;
                        if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) $pouleNum = (int)$m[1];
                        if (preg_match('/tour\s+n[°o]?\s*(\d+)/i', $libelle, $m)) $journee = (int)$m[1];

                        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateStr, $dm)) {
                            $stats['ignores']++; continue;
                        }
                        $date = "{$dm[3]}-{$dm[2]}-{$dm[1]}";

                        // Seules les rencontres où le RECEVEUR est de la région
                        $domEntry = $enMap[$divCode][$libDom] ?? null;
                        if (!$domEntry || !in_array((string)$domEntry['CodeDept'], $deptsNorm)) {
                            $stats['ignores']++; continue;
                        }
                        $extEntry = $enMap[$divCode][$libExt] ?? null;
                        if (!$extEntry) { $stats['ignores']++; continue; }

                        // Résoudre clubs
                        foreach ([['dom', &$domEntry['Id_Club'], mb_substr(preg_replace('/\s+\d+$/', '', $libDom), 0, 100)],
                                  ['ext', &$extEntry['Id_Club'], mb_substr(preg_replace('/\s+\d+$/', '', $libExt), 0, 100)]] as [, &$idVar, $nomClub]) {
                            $idClub8 = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClub), 0, 8)) ?: 'UNKNOWN1';
                            $stmtClubIns->execute([$idClub8, $nomClub]);
                            if (!$pdo->lastInsertId()) {
                                $stmtClubById->execute([$idVar]);
                                if (!$stmtClubById->fetchColumn()) {
                                    $stmtClubByNom->execute([$nomClub]);
                                    $found = $stmtClubByNom->fetchColumn();
                                    if ($found) $idVar = $found;
                                }
                            }
                        }
                        unset($idVar);

                        // Équipes
                        $idDom = $idExt = null;
                        foreach ([[$libDom, $domEntry['Id_Club'], 'idDom'], [$libExt, $extEntry['Id_Club'], 'idExt']] as [$lib, $club, $var]) {
                            $stmtEqChk->execute([$lib, $idDivNijac]);
                            $$var = $stmtEqChk->fetchColumn() ?: null;
                            if (!$$var) {
                                $stmtEqIns->execute([$lib, $idDivNijac, $club]);
                                $$var = (int)$pdo->lastInsertId() ?: null;
                                if ($$var) {
                                    $stats['equipes_creees']++;
                                    $stats['log'][] = ['type' => 'equipe', 'val' => $lib];
                                }
                            }
                        }
                        if (!$idDom || !$idExt) { $stats['erreurs'][] = "Équipe non créée : $libDom / $libExt"; continue; }

                        $stmtRcChk->execute([$date, $idDom, $idExt]);
                        if ($stmtRcChk->fetchColumn()) { $stats['doublons']++; continue; }

                        $stmtRcIns->execute([$date, '00:00:00', $idDivNijac, $pouleNum, $idDom, $idExt, 1, $journee, $arbitrage]);
                        $stats['rencontres_creees']++;
                        $stats['log'][] = ['type' => 'rencontre', 'val' => "$divCode P$pouleNum J$journee — $libDom vs $libExt ($date)"];
                    }
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] E017 PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'err' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] E017 : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'err' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$u           = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$deptsNorm   = array_column(getDeptActifs(), 'code');
$regionNom     = getConfig('region', 'Région');
$regionGentile = getRegionGentile();
try {
    $allDepts = getPDO()->query("SELECT code, nom FROM departement ORDER BY CAST(code AS UNSIGNED)")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $allDepts = []; }

// Récapitulatif equipe_nationale par département normand
$recapDepts = [];
try {
    $pdo0    = getPDO();
    $deptsNormActifs = array_map('strval', $deptsNorm);
    if ($deptsNormActifs) {
        $ph   = implode(',', array_fill(0, count($deptsNormActifs), '?'));
        $rows = $pdo0->prepare("
            SELECT CodeDept, COUNT(*) AS nb
            FROM equipe_nationale
            WHERE CodeDept IN ($ph)
            GROUP BY CodeDept
        ");
        $rows->execute($deptsNormActifs);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r)
            $recapDepts[$r['CodeDept']] = (int)$r['nb'];
    }
} catch (PDOException $e) { $recapDepts = []; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Rencontres Nationales (E017)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .badge-div { display:inline-block; font-size:.75rem; font-weight:700; padding:.15rem .45rem; border-radius:4px; white-space:nowrap; }
        .bdiv-N1M { background:#1a3a6b; color:#fff; }
        .bdiv-N2M { background:#2563eb; color:#fff; }
        .bdiv-N3M { background:#60a5fa; color:#1e3a5f; }
        .bdiv-N1F,.bdiv-N1D { background:#7c3aed; color:#fff; }
        .bdiv-N2F,.bdiv-N2D { background:#a78bfa; color:#3b1264; }
        #tbl-assoc { width:100%; border-collapse:collapse; font-size:.84rem; }
        #tbl-assoc thead th { background:#e8eef7; border:1px solid #c8d4e8; padding:.3rem .5rem; position:sticky; top:0; z-index:1; white-space:nowrap; }
        #tbl-assoc tbody tr { border-bottom:1px solid #e8eef7; }
        #tbl-assoc tbody tr:hover { background:#f0f6ff; }
        #tbl-assoc tbody td { padding:.3rem .5rem; vertical-align:middle; }
        tr.assoc-ok td { background:#f0fdf4 !important; }
        tr.assoc-ok:hover td { background:#dcfce7 !important; }
        .club-search-wrap { position:relative; display:flex; gap:.35rem; align-items:center; }
        .club-display { flex:1; padding:.2rem .5rem; border:1px solid #c8d4e8; border-radius:4px; background:#f8faff; font-size:.83rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; color:#374151; max-width:280px; }
        .club-display.empty { color:#9ca3af; font-style:italic; }
        .club-popup { position:absolute; top:100%; left:0; z-index:999; background:#fff; border:1px solid #c8d4e8; border-radius:6px; box-shadow:0 6px 20px rgba(0,0,0,.12); min-width:280px; max-width:400px; max-height:260px; overflow-y:auto; display:none; }
        .club-popup.open { display:block; }
        .club-popup-input { width:100%; padding:.35rem .5rem; border:none; border-bottom:1px solid #e0e8f0; font-size:.83rem; outline:none; }
        .club-result { padding:.3rem .6rem; cursor:pointer; font-size:.82rem; border-bottom:1px solid #f0f4fa; }
        .club-result:hover { background:#eef3fb; }
        .stat-card { display:inline-block; text-align:center; min-width:100px; background:#fff; border:2px solid #d0d8e8; border-radius:8px; padding:.4rem .7rem; margin-right:.5rem; margin-bottom:.5rem; }
        .stat-card .sv { font-size:1.5rem; font-weight:700; color:var(--nijac-blue); }
        .stat-card .sl { font-size:.72rem; color:#6b7280; }
        .log-detail { font-size:.75rem; padding:.3rem .5rem .3rem 1.5rem; color:#374151; background:#f9fafb; border-left:3px solid #e5e7eb; }
        #spinner { display:none; position:fixed; inset:0; background:rgba(255,255,255,.5); z-index:9999; align-items:center; justify-content:center; }
        #spinner.show { display:flex; }
    </style>
</head>
<body>

<?php $pageIcon='bi-trophy-fill'; $pageTitle='Rencontres Nationales'; $pageCode='E017'; $backUrl='admin_menu.php'; require __DIR__.'/includes/page_header.php'; ?>
<?php require __DIR__.'/includes/toolbar.php'; ?>

<div id="spinner"><div class="spinner-border text-primary" style="width:3rem;height:3rem;"></div></div>

<div class="container-fluid p-3">

    <!-- Récapitulatif equipe_nationale par département -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-bar-chart-fill me-2"></i>Équipes nationales enregistrées par département</div>
        <div class="card-body py-2">
            <?php
            $totalRecap = array_sum($recapDepts);
            if ($totalRecap === 0): ?>
                <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Aucune équipe nationale enregistrée. Lancez le scan ci-dessous.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php foreach ($deptsNorm as $code):
                    $code = (string)$code;
                    $nb   = $recapDepts[$code] ?? 0;
                    $nom  = $allDepts[$code] ?? $code;
                    $cls  = $nb > 0 ? 'border-success text-success' : 'border-secondary text-muted';
                ?>
                    <div class="stat-card <?= $cls ?>" style="min-width:110px; border-color:inherit;">
                        <div class="sv" style="font-size:1.4rem;"><?= $nb ?></div>
                        <div class="sl"><?= htmlspecialchars($nom) ?> (<?= htmlspecialchars($code) ?>)</div>
                    </div>
                <?php endforeach; ?>
                    <div class="stat-card" style="min-width:90px; border-color:#1a3a6b; color:#1a3a6b;">
                        <div class="sv" style="font-size:1.4rem;"><?= $totalRecap ?></div>
                        <div class="sl">Total région</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Étape 0 : scanner les clubs de la région -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-search me-2"></i>Rechercher les équipes nationales des clubs de <?= htmlspecialchars($regionNom) ?></div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Parcourt tous les clubs de la région (<?= htmlspecialchars($regionNom) ?>) via l'API FFTT et détecte ceux ayant une équipe
                en division nationale (N1M, N2M, N3M, N1F, N2F…). Les équipes trouvées sont automatiquement
                ajoutées à la table <code>equipe_nationale</code> avec leur club d'origine.
            </p>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <button id="btn-scanner" class="btn btn-warning">
                    <i class="bi bi-binoculars me-1"></i>Scanner les clubs de <?= htmlspecialchars($regionNom) ?>
                </button>
                <span id="scan-status" class="text-muted small"></span>
                <div class="ms-auto d-flex align-items-center gap-1" title="Tester un club précis pour voir les champs retournés par l'API">
                    <input type="text" id="dbg-numclu" class="form-control form-control-sm" style="width:110px;" placeholder="n° club">
                    <button id="btn-debug-club" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-bug me-1"></i>Debug xml_equipe
                    </button>
                </div>
            </div>
            <!-- Barre de progression -->
            <div id="scan-progress-wrap" style="display:none;" class="mb-2">
                <div class="d-flex justify-content-between mb-1" style="font-size:.78rem; color:#6b7280;">
                    <span id="scan-label">Initialisation…</span>
                    <span id="scan-counter"></span>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="scan-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                         role="progressbar" style="width:0%"></div>
                </div>
            </div>
            <!-- Journal des opérations -->
            <div id="scan-log" style="display:none; max-height:340px; overflow-y:auto;
                 background:#0f172a; color:#e2e8f0; border-radius:6px; padding:.6rem .85rem;
                 font-family:monospace; font-size:.76rem; line-height:1.6;">
            </div>
        </div>
    </div>

    <!-- Étape 1 : charger depuis FFTT -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-cloud-download me-2"></i>1. Charger les équipes nationales depuis l'API FFTT</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Interroge l'API FFTT pour récupérer toutes les équipes des divisions nationales (N1M, N2M, N3M, N1F, N2F)
                et les insère dans la table <code>equipe_nationale</code>.
            </p>
            <button id="btn-charger" class="btn btn-primary">
                <i class="bi bi-cloud-download me-1"></i>Charger depuis FFTT
            </button>
            <div id="res-charger" class="mt-2"></div>
        </div>
    </div>

    <!-- Étape 2 : association équipes → clubs -->
    <div id="section-assoc" style="display:none;" class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>2. Association équipes → département / club de <?= htmlspecialchars($regionNom) ?></div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span id="lbl-assoc-count" class="text-muted" style="font-size:.82rem;"></span>
                <select id="sel-div-filtre" class="form-select form-select-sm w-auto ms-2">
                    <option value="">Toutes les divisions</option>
                    <option value="N1M">N1M</option><option value="N2M">N2M</option><option value="N3M">N3M</option>
                    <option value="N1F">N1F</option><option value="N2F">N2F</option>
                </select>
                <label class="mb-0" style="font-size:.82rem;">
                    <input type="checkbox" id="chk-sans-dept" class="form-check-input me-1">Sans département
                </label>
            </div>
            <div style="max-height:55vh; overflow:auto; border:1px solid #d0d8e8; border-radius:6px;">
                <table id="tbl-assoc">
                    <thead><tr>
                        <th style="width:55px;">Division</th>
                        <th style="width:35px;">P.</th>
                        <th>Nom équipe nationale</th>
                        <th style="width:70px; text-align:center;">Dépt</th>
                        <th style="width:300px;">Club (<?= htmlspecialchars($regionNom) ?>)</th>
                        <th style="width:55px; text-align:center;">Sauver</th>
                    </tr></thead>
                    <tbody id="tbody-assoc">
                        <tr><td colspan="6" class="text-center text-muted py-3">Chargez les équipes depuis l'API FFTT.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 d-flex gap-2 flex-wrap">
                <button id="btn-hors-region" class="btn btn-outline-secondary" disabled>
                    <i class="bi bi-geo-alt me-1"></i>Assigner Hors Région
                </button>
            </div>
        </div>
    </div>

    <!-- Étape 3 : import rencontres -->
    <div id="section-import" style="display:none;" class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-calendar2-check me-2"></i>3. Importer les rencontres (receveur = club de <?= htmlspecialchars($regionNom) ?>)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Importe depuis l'API FFTT toutes les rencontres nationales où <strong>l'équipe à domicile</strong>
                est un club de la région. L'adversaire peut être <?= htmlspecialchars($regionGentile) ?> ou hors région.
            </p>
            <button id="btn-importer" class="btn btn-success" disabled>
                <i class="bi bi-cloud-upload me-1"></i>Importer les rencontres
            </button>
            <div id="res-import" class="mt-2"></div>
        </div>
    </div>

</div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/nijac-toast.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let equipes = [];
const DEPTS_NORM = <?= json_encode(array_map('strval', $deptsNorm)) ?>;
const ALL_DEPTS  = <?= json_encode((object)$allDepts) ?>;
const REGION_NOM     = <?= json_encode($regionNom) ?>;
const REGION_GENTILE = <?= json_encode($regionGentile) ?>;

function spinner(v) { $('#spinner').toggleClass('show', v); }
function isNorm(d)  { return d && DEPTS_NORM.includes(String(d)); }
function deptName(d){ return (d && ALL_DEPTS[d]) ? ALL_DEPTS[d] : (d || ''); }
function esc(s)     { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function deptBadge(dept) {
    if (!dept) return '<span style="font-size:.7rem;color:#9ca3af;">—</span>';
    if (isNorm(dept)) return `<span style="font-size:.68rem;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:3px;padding:.05rem .3rem;" title="${esc(deptName(dept))}">NOR</span>`;
    return `<span style="font-size:.68rem;font-weight:600;color:#6b7280;background:#f3f4f6;border:1px solid #d1d5db;border-radius:3px;padding:.05rem .3rem;" title="${esc(deptName(dept))}">${esc(dept)}</span>`;
}

// ── 1. Charger depuis FFTT ────────────────────────────────────────────────────
$('#btn-charger').on('click', async function () {
    $(this).prop('disabled', true);
    spinner(true);
    $('#res-charger').html('<em class="text-muted small">Interrogation de l\'API FFTT (peut prendre 30–60 s)…</em>');
    try {
        const r = await $.post('import_rencontres_nat.php', {action:'charger_depuis_api'}, null, 'json');
        spinner(false);
        if (!r.ok) { $('#res-charger').html(`<div class="alert alert-danger py-2">${esc(r.err)}</div>`); return; }
        const s = r.stats;
        let html = `<div class="alert alert-success py-2">
            <i class="bi bi-check-circle me-1"></i>
            <strong>${s.divisions} division(s)</strong> traitées —
            <strong>${s.equipes} équipe(s)</strong> nouvelles insérées.`;
        if (s.erreurs?.length) html += `<br><span class="text-warning">${s.erreurs.length} erreur(s) : ${s.erreurs.map(e=>esc(e)).join(', ')}</span>`;
        html += '</div>';
        $('#res-charger').html(html);
        chargerEquipes();
    } catch(e) {
        spinner(false);
        $('#res-charger').html('<div class="alert alert-danger py-2">Erreur réseau.</div>');
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 2. Table associations ─────────────────────────────────────────────────────
function chargerEquipes() {
    $.getJSON('import_rencontres_nat.php?action=liste_equipes', function(r) {
        if (!r.ok) { nijacToast('Erreur chargement équipes.', 'danger'); return; }
        equipes = r.equipes;
        $('#section-assoc, #section-import').show();
        $('#btn-hors-region, #btn-importer').prop('disabled', false);
        renderAssoc();
    });
}

function renderAssoc() {
    const divF = $('#sel-div-filtre').val();
    const sans = $('#chk-sans-dept').is(':checked');
    let data = [...equipes];
    if (divF) data = data.filter(e => e.id_division === divF);
    if (sans) data = data.filter(e => !e.CodeDept);

    const total    = equipes.length;
    const avecDept = equipes.filter(e => e.CodeDept).length;
    const normands = equipes.filter(e => isNorm(e.CodeDept)).length;
    const avecClub = equipes.filter(e => e.Id_Club).length;
    $('#lbl-assoc-count').text(`${avecDept}/${total} avec dépt — ${normands} ${REGION_GENTILE} — ${avecClub} avec club`);

    const $body = $('#tbody-assoc').empty();
    if (!data.length) { $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucune équipe.</td></tr>'); return; }

    data.forEach(e => {
        const norm   = isNorm(e.CodeDept);
        const haClub = !!e.Id_Club;
        const rowOk  = e.CodeDept && (!norm || haClub);
        const clubCell = norm ? `
            <div class="club-search-wrap" data-id="${e.Id_EquipeNat}">
                <div class="club-display ${haClub?'':'empty'}">${haClub ? esc(e.NomClub)+` <small class="text-muted">(#${e.Id_Club})</small>` : '— non associé —'}</div>
                <button class="btn btn-xs btn-outline-secondary btn-changer-club py-0 px-1" style="font-size:.76rem;"><i class="bi bi-search"></i></button>
                ${haClub ? `<button class="btn btn-xs btn-outline-danger btn-effacer-club py-0 px-1" style="font-size:.76rem;"><i class="bi bi-x"></i></button>` : ''}
                <div class="club-popup"><input class="club-popup-input" placeholder="Chercher…" autocomplete="off"><div class="club-results"></div></div>
            </div>` : `<span class="text-muted" style="font-size:.78rem;">${esc(deptName(e.CodeDept))}</span>`;

        const $tr = $('<tr>').addClass(rowOk?'assoc-ok':'').attr('data-id', e.Id_EquipeNat);
        $tr.html(`
            <td><span class="badge-div bdiv-${esc(e.id_division)}">${esc(e.id_division)}</span></td>
            <td style="text-align:center;">${e.Poule||'—'}</td>
            <td style="font-weight:600;">${esc(e.Nom)}</td>
            <td style="text-align:center;">
                <input type="text" class="input-dept form-control form-control-sm text-center px-1" value="${esc(e.CodeDept??'')}" placeholder="76" maxlength="3" style="width:58px;display:inline-block;" data-id="${e.Id_EquipeNat}">
                <span class="dept-badge ms-1" data-id="${e.Id_EquipeNat}">${deptBadge(e.CodeDept)}</span>
            </td>
            <td>${clubCell}</td>
            <td style="text-align:center;">
                <button class="btn btn-sm btn-success btn-sauver py-0" data-id="${e.Id_EquipeNat}" data-dept="${esc(e.CodeDept??'')}" data-club="${e.Id_Club??''}"><i class="bi bi-floppy"></i></button>
            </td>`);
        $body.append($tr);
    });
}

$(document).on('input', '.input-dept', function() {
    const idEn = +$(this).attr('data-id');
    const dept = $(this).val().trim().toUpperCase();
    const norm = isNorm(dept);
    $(this).closest('tr').find(`.dept-badge[data-id="${idEn}"]`).html(deptBadge(dept||null));
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.CodeDept = dept||null; if (!norm) { eq.Id_Club=null; eq.NomClub=null; } }
    const $clubCell = $(this).closest('tr').find('td').eq(4);
    if (!norm) {
        $clubCell.html(`<span class="text-muted" style="font-size:.78rem;">${esc(deptName(dept))}</span>`);
        $(this).closest('tr').find('.btn-sauver').attr('data-club','');
    } else if (!$clubCell.find('.club-search-wrap').length) {
        $clubCell.html(`<div class="club-search-wrap" data-id="${idEn}"><div class="club-display empty">— non associé —</div><button class="btn btn-xs btn-outline-secondary btn-changer-club py-0 px-1" style="font-size:.76rem;"><i class="bi bi-search"></i></button><div class="club-popup"><input class="club-popup-input" placeholder="Chercher…" autocomplete="off"><div class="club-results"></div></div></div>`);
    }
});

let searchTimer = null;
$(document).on('input', '.club-popup-input', function() {
    const q = $(this).val().trim();
    const $res = $(this).siblings('.club-results').empty();
    clearTimeout(searchTimer);
    if (q.length < 2) return;
    searchTimer = setTimeout(() => {
        $.getJSON('import_rencontres_nat.php?action=recherche_club', {q}, function(r) {
            $res.empty();
            if (!r.ok || !r.clubs.length) { $res.html('<div class="club-result text-muted">Aucun club.</div>'); return; }
            r.clubs.forEach(c => $('<div class="club-result">').html(`<strong>${esc(c.Nom)}</strong> <small class="text-muted">#${c.Id_Club}</small>`).attr({'data-id-club':c.Id_Club,'data-nom-club':c.Nom}).appendTo($res));
        });
    }, 280);
});

$(document).on('click', '.btn-changer-club', function(e) {
    e.stopPropagation();
    $('.club-popup').removeClass('open');
    $(this).closest('.club-search-wrap').find('.club-popup').addClass('open').find('.club-popup-input').val('').trigger('focus');
    $(this).closest('.club-search-wrap').find('.club-results').empty();
});
$(document).on('click', function() { $('.club-popup').removeClass('open'); });
$(document).on('click', '.club-popup', e => e.stopPropagation());

$(document).on('click', '.club-result', function() {
    const $wrap = $(this).closest('.club-search-wrap');
    const idEn  = +$wrap.attr('data-id');
    const idClub = $(this).attr('data-id-club');
    const nom    = $(this).attr('data-nom-club');
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club=idClub; eq.NomClub=nom; }
    $wrap.find('.club-display').removeClass('empty').html(esc(nom)+` <small class="text-muted">(#${idClub})</small>`);
    $wrap.closest('tr').find('.btn-sauver').attr('data-club', idClub);
    $wrap.find('.club-popup').removeClass('open');
});

$(document).on('click', '.btn-effacer-club', function() {
    const $wrap = $(this).closest('.club-search-wrap');
    const idEn  = +$wrap.attr('data-id');
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club=null; eq.NomClub=null; }
    $wrap.closest('tr').find('.btn-sauver').attr('data-club','');
    renderAssoc();
});

$(document).on('click', '.btn-sauver', function() {
    const $btn  = $(this);
    const idEn  = +$btn.attr('data-id');
    const dept  = $btn.closest('tr').find('.input-dept').val().trim();
    const norm  = isNorm(dept);
    const idClub = norm ? $btn.attr('data-club') : '';
    $btn.prop('disabled', true);
    $.post('import_rencontres_nat.php?action=sauvegarder_assoc', {id_equipe_nat:idEn, code_dept:dept, id_club:idClub}, function(r) {
        $btn.prop('disabled', false);
        if (r.ok) { const eq=equipes.find(e=>+e.Id_EquipeNat===idEn); if(eq){eq.CodeDept=dept||null; if(!norm){eq.Id_Club=null;eq.NomClub=null;}} renderAssoc(); }
        else nijacToast('Erreur : ' + r.err, 'danger');
    }, 'json').fail(() => { $btn.prop('disabled', false); nijacToast('Erreur réseau.', 'danger'); });
});

$('#sel-div-filtre, #chk-sans-dept').on('change', renderAssoc);

$('#btn-hors-region').on('click', function() {
    $(this).prop('disabled', true);
    $.post('import_rencontres_nat.php?action=assigner_hors_region', {}, function(r) {
        $('#btn-hors-region').prop('disabled', false);
        if (!r.ok) { nijacToast('Erreur Hors Région : '+(r.err??''), 'danger'); return; }
        if (r.clubs_crees > 0 || r.equipes_assignees > 0)
            nijacToast(`Hors Région : ${r.clubs_crees} club(s) créé(s), ${r.equipes_assignees} équipe(s) assignée(s).`, 'success');
        chargerEquipes();
    }, 'json').fail(() => nijacToast('Erreur réseau.', 'danger'));
});

// ── 3. Import rencontres ──────────────────────────────────────────────────────
$('#btn-importer').on('click', async function() {
    const conf = await new Promise(resolve => nijacConfirm(
        `Importer les rencontres nationales (receveur = club ${REGION_GENTILE}) ?\nLes doublons seront ignorés.`,
        () => resolve(true), () => resolve(false)
    ));
    if (!conf) return;

    $(this).prop('disabled', true);
    spinner(true);
    $('#res-import').html('<em class="text-muted small">Import en cours…</em>');
    try {
        const r = await $.post('import_rencontres_nat.php', {action:'importer_rencontres'}, null, 'json');
        spinner(false);
        if (!r.ok) { $('#res-import').html(`<div class="alert alert-danger py-2">${esc(r.err)}</div>`); return; }
        const s = r.stats;
        let html = `<div class="d-flex flex-wrap gap-2 mb-2">
            <div class="stat-card"><div class="sv text-success">${s.rencontres_creees}</div><div class="sl">Rencontres créées</div></div>
            <div class="stat-card"><div class="sv text-primary">${s.equipes_creees}</div><div class="sl">Équipes créées</div></div>
            <div class="stat-card"><div class="sv text-secondary">${s.doublons}</div><div class="sl">Doublons</div></div>
            <div class="stat-card"><div class="sv text-muted">${s.ignores}</div><div class="sl">Ignorées</div></div>
        </div>`;
        if (s.erreurs?.length) {
            html += '<div class="alert alert-warning py-2"><strong>Avertissements :</strong><ul class="mb-0">';
            s.erreurs.forEach(e => html += `<li>${esc(e)}</li>`);
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Import terminé sans erreur.</div>';
        }
        if (s.log?.length) {
            const icons = {rencontre:'bi-calendar-check text-success', equipe:'bi-people-fill text-primary'};
            const rows = s.log.map(l => `<div><i class="bi ${icons[l.type]??'bi-dot'}" style="font-size:.7rem;"></i> ${esc(l.val)}</div>`).join('');
            html += `<div class="log-detail mt-1">${rows}</div>`;
        }
        $('#res-import').html(html);
    } catch(e) {
        spinner(false);
        $('#res-import').html('<div class="alert alert-danger py-2">Erreur réseau.</div>');
    } finally {
        $(this).prop('disabled', false);
    }
});

// Charger les équipes existantes au démarrage si table non vide
$.getJSON('import_rencontres_nat.php?action=liste_equipes', function(r) {
    if (r.ok && r.equipes.length) { equipes=r.equipes; $('#section-assoc,#section-import').show(); $('#btn-hors-region,#btn-importer').prop('disabled', false); renderAssoc(); }
});

// ── 0b. Debug xml_equipe ──────────────────────────────────────────────────────
$('#btn-debug-club').on('click', async function () {
    const numclu = $('#dbg-numclu').val().trim();
    if (!numclu) { nijacToast('Saisissez un numéro de club.', 'warning'); return; }
    $(this).prop('disabled', true);
    $('#scan-log').show().empty();
    $('#scan-progress-wrap').hide();

    function logLine(html) {
        const $log = $('#scan-log');
        $log.append('<div>' + html + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    try {
        const r = await $.getJSON('import_rencontres_nat.php?action=debug_club&numclu=' + encodeURIComponent(numclu));
        if (!r.ok) { logLine(`<span style="color:#f87171;">Erreur : ${esc(r.err)}</span>`); return; }
        logLine(`<span style="color:#94a3b8;">${r.equipes.length} équipe(s) retournée(s) par xml_equipe pour ${esc(numclu)} :</span>`);
        r.equipes.forEach((eq, i) => {
            logLine(`<span style="color:#e2e8f0;"><strong>#${i+1}</strong> ${JSON.stringify(eq)}</span>`);
        });
        if (!r.equipes.length) {
            logLine(`<span style="color:#facc15;">XML brut :</span>`);
            logLine(`<span style="color:#94a3b8;">${esc(r.raw)}</span>`);
        }
    } catch(e) {
        const detail = e && e.status ? `HTTP ${e.status} — ${esc((e.responseText || '(corps vide)').substring(0,500))}` : esc(String(e));
        logLine(`<span style="color:#f87171;">Erreur réseau : ${detail}</span>`);
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 0. Scanner les clubs normands ─────────────────────────────────────────────
$('#btn-scanner').on('click', async function () {
    const $btn = $(this).prop('disabled', true);
    $('#scan-log').show().empty();
    $('#scan-progress-wrap').show();
    $('#scan-bar').css('width', '0%').removeClass('bg-success').addClass('bg-warning progress-bar-animated');
    $('#scan-label').text('Récupération de la liste des clubs…');
    $('#scan-counter').text('');
    $('#scan-status').text('');

    function logLine(html) {
        const $log = $('#scan-log');
        $log.append('<div>' + html + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    try {
        // Vider le cache session des divisions nationales (pour forcer le rechargement)
        await $.post('import_rencontres_nat.php', {action: 'reset_nat_cache'}, null, 'json');

        // Étape 1 : lister tous les clubs
        const rClubs = await $.getJSON('import_rencontres_nat.php?action=lister_clubs_region');
        if (!rClubs.ok) { logLine('<span style="color:#f87171;">Erreur : ' + esc(rClubs.err??'') + '</span>'); return; }

        const clubs  = rClubs.clubs;
        const total  = clubs.length;
        let traites  = 0, trouves = 0, doublons = 0, erreurs = 0;

        logLine(`<span style="color:#94a3b8;">${total} club(s) à analyser…</span>`);
        $('#scan-label').text('Analyse en cours…');

        // Étape 2 : scanner club par club
        for (const club of clubs) {
            traites++;
            const pct = Math.round(traites / total * 100);
            $('#scan-bar').css('width', pct + '%');
            $('#scan-counter').text(`${traites} / ${total}`);
            $('#scan-label').text(esc(club.nom) + ' (' + club.dept + ')');

            try {
                const r = await $.post('import_rencontres_nat.php', {
                    action: 'scanner_club',
                    numclu: club.numclu,
                    dept:   club.dept,
                    nom:    club.nom,
                }, null, 'json');

                if (!r.ok) {
                    erreurs++;
                    logLine(`<span style="color:#f87171;">✗ ${esc(club.nom)} — ${esc(r.err??'')}</span>`);
                    continue;
                }

                if (r.nationales && r.nationales.length) {
                    r.nationales.forEach(n => {
                        const isNew = n.new;
                        const icon  = isNew ? '⊕' : '↺';
                        const color = isNew ? '#4ade80' : '#facc15';
                        if (isNew) trouves++; else doublons++;
                        logLine(`<span style="color:${color};">${icon} <strong style="color:#e2e8f0;">${esc(club.nom)}</strong> — <span style="color:#93c5fd;">${esc(n.div)}</span> — ${esc(n.lib)}</span>`);
                    });
                } else {
                    logLine(`<span style="color:#475569;">— ${esc(club.nom)} — Pas d'équipe Nationale</span>`);
                }
            } catch(e) {
                erreurs++;
                const detail = e && e.status ? `HTTP ${e.status} — ${esc((e.responseText || '(corps vide)').substring(0,500))}` : esc(String(e));
                logLine(`<span style="color:#f87171;">✗ ${esc(club.nom)} — ${detail}</span>`);
            }
        }

        // Terminé
        $('#scan-bar').css('width','100%').removeClass('bg-warning progress-bar-animated').addClass('bg-success');
        $('#scan-label').text('Scan terminé.');
        $('#scan-counter').text(`${traites} / ${total}`);
        const msg = `${trouves} équipe(s) nouvelle(s), ${doublons} déjà connue(s)${erreurs ? ', ' + erreurs + ' erreur(s)' : ''}.`;
        $('#scan-status').html(`<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>${esc(msg)}</span>`);
        logLine(`<span style="color:#94a3b8; border-top:1px solid #334155; display:block; margin-top:.4rem; padding-top:.4rem;">Terminé — ${msg}</span>`);

        // Rafraîchir la table d'association
        if (trouves > 0) {
            $.getJSON('import_rencontres_nat.php?action=liste_equipes', function(r2) {
                if (r2.ok && r2.equipes.length) {
                    equipes = r2.equipes;
                    $('#section-assoc,#section-import').show();
                    $('#btn-hors-region,#btn-importer').prop('disabled', false);
                    renderAssoc();
                }
            });
        }
    } catch(e) {
        logLine(`<span style="color:#f87171;">Erreur inattendue : ${esc(String(e))}</span>`);
    } finally {
        $btn.prop('disabled', false);
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
