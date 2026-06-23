<?php
/**
 * NIJAC – Import Rencontres Nationales (E017)
 *
 * Importe les rencontres des divisions nationales (N1M, N2M, N3M, N1D, N2D)
 * depuis un fichier Excel FFTT à 6 feuilles.
 * Gère la correspondance équipe nationale → club via la table equipe_nationale.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-22
 */
session_start();
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// ── Création / migration de la table equipe_nationale ─────────────────────────
try {
    $pdo0 = getPDO();
    $pdo0->exec("
        CREATE TABLE IF NOT EXISTS equipe_nationale (
            Id_EquipeNat INT              AUTO_INCREMENT PRIMARY KEY,
            Nom          VARCHAR(200)     NOT NULL,
            Division     VARCHAR(10)      NOT NULL,
            Poule        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            Rang         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            CodeDept     CHAR(2)          NULL,
            Id_Club      INT              NULL,
            Id_Equipe    INT              NULL,
            UNIQUE KEY uq_nom_div (Nom(150), Division)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Migration : ajouter CodeDept si la table existait déjà sans cette colonne
    $cols = array_column($pdo0->query('SHOW COLUMNS FROM equipe_nationale')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('CodeDept', $cols)) {
        $pdo0->exec("ALTER TABLE equipe_nationale ADD COLUMN CodeDept CHAR(2) NULL AFTER Rang");
    }
} catch (PDOException $e) {}

// ── Helpers de parsing ────────────────────────────────────────────────────────
const MOIS_NAT = [
    'janv'=>'01','fevr'=>'02','mars'=>'03','avr'=>'04','mai'=>'05',
    'juin'=>'06','juil'=>'07','aout'=>'08','sept'=>'09','oct'=>'10','nov'=>'11','dec'=>'12',
];

function parseDateNat(string $s, int $anneeDepart): ?string
{
    $n = mb_strtolower(strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','û'=>'u']));
    if (!preg_match('/(\d{1,2})-([a-z]+)/', $n, $m)) return null;
    $mo = MOIS_NAT[$m[2]] ?? null;
    if (!$mo) return null;
    $annee = ((int)$mo >= 9) ? $anneeDepart : $anneeDepart + 1;
    return $annee . '-' . $mo . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
}

function detectDivNat(string $cell): ?string
{
    $v = strtoupper(preg_replace('/\s+/', ' ', trim($cell)));
    if (preg_match('/NATIONALE\s+(\d+)\s+(MESSIEURS|DAMES)/i', $v, $m)) {
        return 'N' . $m[1] . (strtoupper($m[2]) === 'DAMES' ? 'D' : 'M');
    }
    return null;
}

/**
 * Parse le fichier xlsx et retourne journées + poules.
 * Gère les lignes vides entre en-tête de poule et données.
 */
function parseNatExcel(\PhpOffice\PhpSpreadsheet\Spreadsheet $sp): array
{
    $result = ['saison' => '', 'journees' => [], 'pools' => []];

    // Valeur brute de cellule : supprime les ".0" des entiers retournés par PhpSpreadsheet
    $cellInt = static function (mixed $v): string {
        $s = trim((string)$v);
        return is_numeric($s) && floor((float)$s) == (float)$s && !str_contains($s, '.') === false
            ? (string)(int)(float)$s
            : $s;
    };

    // ── Feuille 1 : calendrier ──────────────────────────────────────────────
    // Valeurs brutes (formatData=false) pour éviter les "1.00" sur les numéros de journée
    $rows1 = $sp->getSheet(0)->toArray(null, false, false, false);
    $anneeDepart = 2026;
    foreach ($rows1 as $row) {
        foreach ($row as $cell) {
            if (preg_match('/(\d{4})\s*\/\s*(\d{4})/', (string)$cell, $m)) {
                $result['saison'] = $m[1] . '/' . $m[2];
                $anneeDepart = (int)$m[1];
                break 2;
            }
        }
    }
    foreach ($rows1 as $row) {
        $c0 = trim((string)($row[0] ?? ''));
        $rawDate = $row[1] ?? null;
        $c2 = trim((string)($row[2] ?? ''));  // col C : "Ordre des Rencontres"
        // Accepte "1", "1.0", ou entier PHP (is_numeric + valeur entière positive)
        if (!is_numeric($c0) || (int)(float)$c0 < 1 || $rawDate === null || $rawDate === '' || $c2 === '') continue;
        // Colonne B : date sérielle Excel (float > 1000) ou chaîne texte "12-sept"
        if (is_numeric($rawDate) && (float)$rawDate > 1000) {
            $dt   = ExcelDate::excelToDateTimeObject((float)$rawDate);
            $date = $dt->format('Y-m-d');
        } else {
            $date = parseDateNat(trim((string)$rawDate), $anneeDepart);
        }
        $matchs = [];
        foreach (explode('/', $c2) as $part) {
            if (preg_match('/(\d+)-(\d+)/', trim($part), $pm)) {
                $matchs[] = [(int)$pm[1], (int)$pm[2]];
            }
        }
        if ($date && !empty($matchs)) {
            $result['journees'][] = ['journee' => (int)(float)$c0, 'date' => $date, 'matchs' => $matchs];
        }
    }

    // ── Feuilles 2–6 : poules ───────────────────────────────────────────────
    $defaultDiv = [1 => 'N1M', 2 => 'N2M', 3 => 'N3M', 4 => 'N3M'];
    for ($s = 1; $s < $sp->getSheetCount(); $s++) {
        $rows       = $sp->getSheet($s)->toArray(null, false, false, false);
        $currentDiv = $defaultDiv[$s] ?? null;

        foreach ($rows as $ri => $row) {
            // Détecter changement de division (utile pour feuille 6)
            foreach ($row as $cell) {
                $div = detectDivNat((string)$cell);
                if ($div) { $currentDiv = $div; break; }
            }

            // Détecter les en-têtes "POULE N" sur la ligne
            foreach ($row as $ci => $cell) {
                $v = str_replace("\n", " ", trim((string)$cell));
                if (!preg_match('/^POULE\s+(\d+)$/i', $v, $pm)) continue;

                $pouleNum = (int)$pm[1];
                $teams    = [];
                $found    = 0;
                // Lire jusqu'à 8 équipes en sautant les lignes vides
                for ($r = $ri + 1; $r <= $ri + 12 && $found < 8; $r++) {
                    if (!isset($rows[$r])) break;
                    $rank = trim((string)($rows[$r][$ci]     ?? ''));
                    $name = trim((string)($rows[$r][$ci + 1] ?? ''));
                    if ($rank === '' && $name === '') continue; // ligne vide entre header et données
                    if (!is_numeric($rank) || (int)(float)$rank < 1 || $name === '') break;
                    $teams[] = ['rang' => (int)(float)$rank, 'nom' => $name];
                    $found++;
                }

                if ($found >= 2 && $currentDiv) {
                    $result['pools'][] = [
                        'division' => $currentDiv,
                        'poule'    => $pouleNum,
                        'equipes'  => $teams,
                    ];
                }
            }
        }
    }

    return $result;
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    $action = $_GET['action'];
    $pdo    = getPDO();

    try {
        // ── Liste des fichiers xlsx ──────────────────────────────────────────
        if ($action === 'liste_fichiers') {
            $dossier  = __DIR__ . '/Importation/Rencontres/';
            $fichiers = [];
            foreach (glob($dossier . '*.xlsx') as $f) {
                $fichiers[] = basename($f);
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'fichiers' => $fichiers]);
            exit;
        }

        // ── Analyser + peupler equipe_nationale ─────────────────────────────
        if ($action === 'analyser') {
            $nom     = basename($_POST['fichier'] ?? '');
            $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
            if (!file_exists($fichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'err' => 'Fichier introuvable.']);
                exit;
            }
            $sp   = IOFactory::load($fichier);
            $data = parseNatExcel($sp);

            $stmt = $pdo->prepare("
                INSERT INTO equipe_nationale (Nom, Division, Poule, Rang)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE Poule = VALUES(Poule), Rang = VALUES(Rang)
            ");
            foreach ($data['pools'] as $pool) {
                foreach ($pool['equipes'] as $eq) {
                    $stmt->execute([$eq['nom'], $pool['division'], $pool['poule'], $eq['rang']]);
                }
            }

            // Auto-association : pour chaque équipe sans club, chercher une équipe déjà
            // associée dont le nom de base (sans chiffre final) est identique.
            // Ex : "FONTENAY USTT 2" → base "FONTENAY USTT" → copie club de "FONTENAY USTT 1"
            $pdo->exec("
                UPDATE equipe_nationale en_cible
                JOIN equipe_nationale en_source
                    ON en_source.Id_Club IS NOT NULL
                    AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                      = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
                SET en_cible.Id_Club   = en_source.Id_Club,
                    en_cible.CodeDept  = en_source.CodeDept
                WHERE en_cible.Id_Club IS NULL
            ");
            $autoAssoc = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

            // Propagation CodeDept seul : si une équipe du même nom de base a un CodeDept
            // renseigné (même sans club), l'appliquer aux équipes du même groupe sans CodeDept.
            $pdo->exec("
                UPDATE equipe_nationale en_cible
                JOIN equipe_nationale en_source
                    ON en_source.CodeDept IS NOT NULL
                    AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                      = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
                SET en_cible.CodeDept = en_source.CodeDept
                WHERE en_cible.CodeDept IS NULL
            ");

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $data, 'auto_assoc' => (int)$autoAssoc]);
            exit;
        }

        // ── Liste des associations ───────────────────────────────────────────
        if ($action === 'liste_equipes') {
            $rows = $pdo->query("
                SELECT en.Id_EquipeNat, en.Nom, en.Division, en.Poule, en.Rang,
                       en.CodeDept, en.Id_Club,
                       (SELECT c.Nom FROM Club c WHERE c.Id_Club = en.Id_Club LIMIT 1) AS NomClub
                FROM equipe_nationale en
                ORDER BY en.Division, en.Poule, en.Rang
            ")->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean();
            echo json_encode(['ok' => true, 'equipes' => $rows]);
            exit;
        }

        // ── Recherche clubs ──────────────────────────────────────────────────
        if ($action === 'recherche_club') {
            $q    = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) { ob_end_clean(); echo json_encode(['ok' => true, 'clubs' => []]); exit; }
            $stmt = $pdo->prepare("SELECT Id_Club, Nom FROM Club WHERE Nom LIKE ? ORDER BY Nom LIMIT 25");
            $stmt->execute(['%' . $q . '%']);
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── Sauvegarder une association ──────────────────────────────────────
        if ($action === 'sauvegarder_assoc') {
            $idEn    = (int)($_POST['id_equipe_nat'] ?? 0);
            $codeDept = trim($_POST['code_dept'] ?? '') ?: null;
            $idClub  = ($_POST['id_club'] ?? '') !== '' ? (int)$_POST['id_club'] : null;
            if ($idEn <= 0) { ob_end_clean(); echo json_encode(['ok' => false, 'err' => 'Id invalide.']); exit; }
            $pdo->prepare("UPDATE equipe_nationale SET CodeDept = ?, Id_Club = ? WHERE Id_EquipeNat = ?")
                ->execute([$codeDept, $idClub, $idEn]);
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Assigner clubs Hors Région aux équipes non-normandes sans club ──────
        if ($action === 'assigner_hors_region') {
            $deptsNormPHP = array_map('strval', array_column(getDeptActifs(), 'code'));
            $placeholders = implode(',', array_fill(0, count($deptsNormPHP), '?'));

            // Un club par département (Id = 99000 + dept), nommé d'après le nom de base de l'équipe
            $stmt = $pdo->prepare("
                SELECT en.CodeDept,
                       TRIM(REGEXP_REPLACE(MIN(en.Nom), '\\\\s+[0-9]+\$', '')) AS NomBase
                FROM equipe_nationale en
                WHERE en.CodeDept IS NOT NULL
                  AND en.Id_Club IS NULL
                  AND en.CodeDept NOT IN ($placeholders)
                GROUP BY en.CodeDept
            ");
            $stmt->execute($deptsNormPHP);
            $depts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $crees = 0;
            $assoc = 0;
            $stmtIns = $pdo->prepare(
                "INSERT INTO Club (Id_Club, Nom) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE Nom = VALUES(Nom)"
            );
            $stmtUpd = $pdo->prepare(
                "UPDATE equipe_nationale SET Id_Club = ?
                 WHERE CodeDept = ? AND Id_Club IS NULL"
            );
            foreach ($depts as $d) {
                $idClub  = 99000 + (int)$d['CodeDept'];
                $nomClub = 'Hors Région - ' . $d['NomBase'];
                $stmtIns->execute([$idClub, $nomClub]);
                if ($stmtIns->rowCount() === 1) $crees++;
                $stmtUpd->execute([$idClub, $d['CodeDept']]);
                $assoc += $stmtUpd->rowCount();
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs_crees' => $crees, 'equipes_assignees' => $assoc]);
            exit;
        }

        // ── Import en base ───────────────────────────────────────────────────
        if ($action === 'importer') {
            $nom     = basename($_POST['fichier'] ?? '');
            $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
            if (!file_exists($fichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'err' => 'Fichier introuvable.']);
                exit;
            }
            $sp   = IOFactory::load($fichier);
            $data = parseNatExcel($sp);

            // Charger associations Nom+Division → Id_Club
            $assocRows = $pdo->query("
                SELECT Nom, Division, Poule, Rang, Id_Club
                FROM equipe_nationale WHERE Id_Club IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);
            $assocMap = [];
            foreach ($assocRows as $r) {
                $assocMap[$r['Division']][$r['Poule']][$r['Rang']] = (int)$r['Id_Club'];
            }

            // Charger divisions : essai code exact (N1M, N2M…)
            $divRows = $pdo->query("SELECT Id_Division, Division FROM division")->fetchAll();
            $divMap  = [];
            foreach ($divRows as $r) { $divMap[$r['Division']] = (int)$r['Id_Division']; }

            $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'sans_club' => 0, 'erreurs' => []];

            $stmtEqChk = $pdo->prepare("SELECT Id_Equipe FROM equipe WHERE Nom = ? AND Id_Division = ? LIMIT 1");
            $stmtEqIns = $pdo->prepare("INSERT IGNORE INTO equipe (Nom, Id_Division, Id_Club) VALUES (?,?,?)");
            $stmtRcChk = $pdo->prepare("SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1");
            $stmtRcIns = $pdo->prepare("
                INSERT INTO rencontre (Date, Heure, Id_Division, Poule, Id_EquipeDom, Id_EquipeExt, Phase, Journee, ArbitrageObligatoire)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $stmtArb = $pdo->prepare("SELECT ArbitrageObligatoire FROM division WHERE Id_Division = ?");

            foreach ($data['pools'] as $pool) {
                $div      = $pool['division'];
                $pouleNum = $pool['poule'];
                $idDiv    = $divMap[$div] ?? null;
                if (!$idDiv) {
                    $stats['erreurs'][] = "Division $div introuvable en base (vérifiez la table division).";
                    continue;
                }
                $stmtArb->execute([$idDiv]);
                $arbitrage = (int)($stmtArb->fetchColumn() ?? 0);

                // Construire map rang → Id_Equipe
                $equipeMap = [];
                foreach ($pool['equipes'] as $eq) {
                    $rang   = $eq['rang'];
                    $idClub = $assocMap[$div][$pouleNum][$rang] ?? null;
                    if (!$idClub) { $stats['sans_club']++; continue; }

                    $stmtEqChk->execute([$eq['nom'], $idDiv]);
                    $existing = $stmtEqChk->fetchColumn();
                    if ($existing) {
                        $equipeMap[$rang] = (int)$existing;
                    } else {
                        $stmtEqIns->execute([$eq['nom'], $idDiv, $idClub]);
                        $equipeMap[$rang] = (int)$pdo->lastInsertId();
                        $stats['equipes_creees']++;
                    }
                }

                foreach ($data['journees'] as $j) {
                    foreach ($j['matchs'] as [$rangDom, $rangExt]) {
                        $idDom = $equipeMap[$rangDom] ?? null;
                        $idExt = $equipeMap[$rangExt] ?? null;
                        if (!$idDom || !$idExt) continue;
                        $stmtRcChk->execute([$j['date'], $idDom, $idExt]);
                        if ($stmtRcChk->fetchColumn()) { $stats['doublons']++; continue; }
                        $stmtRcIns->execute([
                            $j['date'], '14:00:00', $idDiv, $pouleNum,
                            $idDom, $idExt, 1, $j['journee'], $arbitrage,
                        ]);
                        $stats['rencontres_creees']++;
                    }
                }
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            exit;
        }

        // ── Remplir rencontres à domicile des clubs normands ─────────────────
        if ($action === 'remplir_rencontres') {
            $nom     = basename($_POST['fichier'] ?? '');
            $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
            if (!file_exists($fichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'err' => 'Fichier introuvable.']);
                exit;
            }
            $sp   = IOFactory::load($fichier);
            $data = parseNatExcel($sp);

            $deptsNorm = array_map('strval', array_column(getDeptActifs(), 'code'));

            // Map [Division][Poule][Rang] → {Id_Club, CodeDept, Nom}
            $enRows = $pdo->query("
                SELECT Division, Poule, Rang, Id_Club, CodeDept, Nom
                FROM equipe_nationale WHERE Id_Club IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);
            $enMap = [];
            foreach ($enRows as $r) {
                $enMap[$r['Division']][$r['Poule']][$r['Rang']] = $r;
            }

            $divRows = $pdo->query("SELECT Id_Division, Division FROM division")->fetchAll();
            $divMap  = [];
            foreach ($divRows as $r) { $divMap[$r['Division']] = (int)$r['Id_Division']; }

            $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'ignores' => 0, 'erreurs' => []];

            $stmtEqChk = $pdo->prepare("SELECT Id_Equipe FROM equipe WHERE Nom = ? AND Id_Division = ? LIMIT 1");
            $stmtEqIns = $pdo->prepare("INSERT IGNORE INTO equipe (Nom, Id_Division, Id_Club) VALUES (?,?,?)");
            $stmtRcChk = $pdo->prepare("SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1");
            $stmtRcIns = $pdo->prepare("
                INSERT INTO rencontre (Date, Heure, Id_Division, Poule, Id_EquipeDom, Id_EquipeExt, Phase, Journee, ArbitrageObligatoire)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $stmtArb = $pdo->prepare("SELECT ArbitrageObligatoire FROM division WHERE Id_Division = ?");

            foreach ($data['pools'] as $pool) {
                $div      = $pool['division'];
                $pouleNum = $pool['poule'];
                $idDiv    = $divMap[$div] ?? null;
                if (!$idDiv) {
                    $stats['erreurs'][] = "Division $div introuvable.";
                    continue;
                }
                $stmtArb->execute([$idDiv]);
                $arbitrage = (int)($stmtArb->fetchColumn() ?? 0);

                foreach ($data['journees'] as $j) {
                    foreach ($j['matchs'] as [$rangDom, $rangExt]) {
                        $domEntry = $enMap[$div][$pouleNum][$rangDom] ?? null;
                        $extEntry = $enMap[$div][$pouleNum][$rangExt] ?? null;

                        // Ignorer si l'équipe à domicile n'est pas normande
                        if (!$domEntry || !in_array($domEntry['CodeDept'], $deptsNorm)) {
                            $stats['ignores']++;
                            continue;
                        }
                        if (!$extEntry) { $stats['ignores']++; continue; }

                        // Créer ou récupérer l'équipe à domicile
                        $stmtEqChk->execute([$domEntry['Nom'], $idDiv]);
                        $idDom = $stmtEqChk->fetchColumn();
                        if (!$idDom) {
                            $stmtEqIns->execute([$domEntry['Nom'], $idDiv, $domEntry['Id_Club']]);
                            $idDom = (int)$pdo->lastInsertId();
                            $stats['equipes_creees']++;
                        }

                        // Créer ou récupérer l'équipe extérieure
                        $stmtEqChk->execute([$extEntry['Nom'], $idDiv]);
                        $idExt = $stmtEqChk->fetchColumn();
                        if (!$idExt) {
                            $stmtEqIns->execute([$extEntry['Nom'], $idDiv, $extEntry['Id_Club']]);
                            $idExt = (int)$pdo->lastInsertId();
                            $stats['equipes_creees']++;
                        }

                        // Créer la rencontre
                        $stmtRcChk->execute([$j['date'], $idDom, $idExt]);
                        if ($stmtRcChk->fetchColumn()) { $stats['doublons']++; continue; }
                        $stmtRcIns->execute([
                            $j['date'], '14:00:00', $idDiv, $pouleNum,
                            $idDom, $idExt, 1, $j['journee'], $arbitrage,
                        ]);
                        $stats['rencontres_creees']++;
                    }
                }
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'stats' => $stats]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] import_rencontres_nat.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'err' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] import_rencontres_nat.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'err' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'err' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$u           = $_SESSION['utilisateur'];
$isAdmin     = !empty($u['is_admin']);
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$deptsNorm   = array_column(getDeptActifs(), 'code'); // codes départements Normandie actifs
// Tous les départements (code → nom) pour l'affichage dans le tableau
try {
    $allDepts = getPDO()->query("SELECT code, nom FROM departement ORDER BY CAST(code AS UNSIGNED)")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $allDepts = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Import Rencontres Nationales (E017)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; }

        #page-header {
            background: var(--nijac-blue); color: #fff;
            padding: .5rem 1.25rem; font-size: .9rem; font-weight: 600; flex-shrink: 0;
        }
        #content { padding: 1.25rem; }

        /* ── Sélection fichier ── */
        .file-zone {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.25rem;
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        }

        /* ── Badges division ── */
        .badge-div {
            display: inline-block; font-size: .75rem; font-weight: 700;
            padding: .15rem .45rem; border-radius: 4px; white-space: nowrap;
        }
        .bdiv-N1M { background: #1a3a6b; color: #fff; }
        .bdiv-N2M { background: #2563eb; color: #fff; }
        .bdiv-N3M { background: #60a5fa; color: #1e3a5f; }
        .bdiv-N1D { background: #7c3aed; color: #fff; }
        .bdiv-N2D { background: #a78bfa; color: #3b1264; }

        /* ── Table associations ── */
        #tbl-assoc { width: 100%; border-collapse: collapse; font-size: .84rem; }
        #tbl-assoc thead th {
            background: #e8eef7; border: 1px solid #c8d4e8;
            padding: .3rem .5rem; position: sticky; top: 0; z-index: 1; white-space: nowrap;
        }
        #tbl-assoc tbody tr { border-bottom: 1px solid #e8eef7; }
        #tbl-assoc tbody tr:hover { background: #f0f6ff; }
        #tbl-assoc tbody td { padding: .3rem .5rem; vertical-align: middle; }
        tr.assoc-ok td { background: #f0fdf4 !important; }
        tr.assoc-ok:hover td { background: #dcfce7 !important; }

        /* ── Recherche club inline ── */
        .club-search-wrap { position: relative; display: flex; gap: .35rem; align-items: center; }
        .club-display {
            flex: 1; padding: .2rem .5rem; border: 1px solid #c8d4e8;
            border-radius: 4px; background: #f8faff; font-size: .83rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;
            color: #374151; max-width: 280px;
        }
        .club-display.empty { color: #9ca3af; font-style: italic; }

        /* ── Popup résultats club ── */
        .club-popup {
            position: absolute; top: 100%; left: 0; z-index: 999;
            background: #fff; border: 1px solid #c8d4e8; border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0,0,0,.12); min-width: 280px; max-width: 400px;
            max-height: 260px; overflow-y: auto; display: none;
        }
        .club-popup.open { display: block; }
        .club-popup-input {
            width: 100%; padding: .35rem .5rem; border: none; border-bottom: 1px solid #e0e8f0;
            font-size: .83rem; outline: none;
        }
        .club-result {
            padding: .3rem .6rem; cursor: pointer; font-size: .82rem;
            border-bottom: 1px solid #f0f4fa;
        }
        .club-result:hover { background: #eef3fb; }
        .club-result strong { color: #1a3a6b; }

        /* ── Stat badges ── */
        .stat-card {
            display: inline-block; text-align: center; min-width: 100px;
            background: #fff; border: 2px solid #d0d8e8; border-radius: 8px;
            padding: .4rem .7rem; margin-right: .5rem; margin-bottom: .5rem;
        }
        .stat-card .sv { font-size: 1.5rem; font-weight: 700; color: var(--nijac-blue); }
        .stat-card .sl { font-size: .72rem; color: #6b7280; }

        /* ── Calendrier ── */
        .journee-card {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 6px; padding: .6rem .85rem; margin-bottom: .5rem;
        }
        .journee-card .jdate { font-weight: 700; color: var(--nijac-blue); }

        /* ── Spinner overlay ── */
        #spinner {
            display: none; position: fixed; inset: 0;
            background: rgba(255,255,255,.5); z-index: 9999;
            align-items: center; justify-content: center;
        }
        #spinner.show { display: flex; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-trophy-fill'; $pageTitle = 'Import Rencontres Nationales'; $pageCode = 'E017'; $backUrl = 'admin_menu.php'; require __DIR__ . '/includes/page_header.php'; ?>
<?php require __DIR__ . '/includes/toolbar.php'; ?>

<div id="spinner"><div class="spinner-border text-primary" style="width:3rem;height:3rem;"></div></div>

<!-- Modal recherche club -->
<div class="modal fade" id="modalClub" tabindex="-1" aria-labelledby="modalClubLabel" aria-hidden="true">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="modalClubLabel"><i class="bi bi-search me-2"></i>Recherche club</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <div class="mb-2 text-muted" style="font-size:.82rem;">
          Équipe : <strong id="modal-nom-equipe"></strong>
        </div>
        <input type="text" id="modal-club-input" class="form-control form-control-sm"
               placeholder="Saisir le nom du club…" autocomplete="off">
        <div id="modal-club-results" class="mt-2" style="max-height:300px; overflow-y:auto;"></div>
      </div>
    </div>
  </div>
</div>

<div id="content">

    <!-- Sélection fichier -->
    <div class="file-zone">
        <label class="fw-bold mb-0" style="white-space:nowrap;">
            <i class="bi bi-file-earmark-excel text-success me-1"></i>Fichier xlsx :
        </label>
        <select id="sel-fichier" class="form-select form-select-sm w-auto">
            <option value="">— Chargement… —</option>
        </select>
        <button id="btn-analyser" class="btn btn-sm btn-primary" disabled>
            <i class="bi bi-search me-1"></i>Analyser
        </button>
        <span id="analyse-status" class="text-muted" style="font-size:.83rem;"></span>
    </div>

    <!-- Calendrier (affiché après analyse) -->
    <div id="section-calendrier" style="display:none; margin-bottom:1.25rem;">
        <div class="d-flex align-items-center gap-2 mb-2">
            <h6 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Calendrier analysé</h6>
            <span id="lbl-saison" class="badge bg-secondary"></span>
            <button class="btn btn-sm btn-outline-secondary" id="btn-toggle-cal" style="font-size:.78rem;">
                <i class="bi bi-chevron-down"></i> Afficher
            </button>
        </div>
        <div id="cal-content" style="display:none;"></div>
    </div>

    <!-- Table associations -->
    <div id="section-assoc" style="display:none;">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
            <h6 class="mb-0"><i class="bi bi-link-45deg me-1"></i>Association équipes → clubs</h6>
            <span id="lbl-assoc-count" class="text-muted" style="font-size:.82rem;"></span>
            <!-- Filtre division -->
            <select id="sel-div-filtre" class="form-select form-select-sm w-auto ms-2">
                <option value="">Toutes les divisions</option>
                <option value="N1M">N1M</option>
                <option value="N2M">N2M</option>
                <option value="N3M">N3M</option>
                <option value="N1D">N1D</option>
                <option value="N2D">N2D</option>
            </select>
            <label class="ms-2 mb-0" style="font-size:.82rem;">
                <input type="checkbox" id="chk-sans-club" class="form-check-input me-1">
                Sans département seulement
            </label>
        </div>

        <div style="max-height:55vh; overflow:auto; border:1px solid #d0d8e8; border-radius:6px;">
            <table id="tbl-assoc">
                <thead>
                    <tr>
                        <th style="width:55px;">Division</th>
                        <th style="width:40px;">Poule</th>
                        <th style="width:35px;">Rang</th>
                        <th>Nom de l'équipe nationale</th>
                        <th style="width:65px; text-align:center;">Dépt</th>
                        <th style="width:300px;">Club (Normandie)</th>
                        <th style="width:60px; text-align:center;">Sauver</th>
                    </tr>
                </thead>
                <tbody id="tbody-assoc">
                    <tr><td colspan="6" class="text-center text-muted py-3">Analysez un fichier pour afficher les équipes.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Import -->
        <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
            <button id="btn-hors-region" class="btn btn-outline-secondary" disabled title="Crée un club virtuel « Hors Région » par département non-normand et l'assigne aux équipes sans club">
                <i class="bi bi-geo-alt me-1"></i>Assigner Hors Région
            </button>
            <button id="btn-remplir" class="btn btn-primary" disabled title="Crée les équipes et rencontres pour chaque match à domicile d'un club normand">
                <i class="bi bi-calendar2-check me-1"></i>Remplir les rencontres
            </button>
            <button id="btn-importer" class="btn btn-success" disabled>
                <i class="bi bi-cloud-upload me-1"></i>Importer en base
            </button>
            <span id="import-warn" class="text-warning" style="font-size:.83rem; display:none;">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Des équipes n'ont pas de club associé — leurs rencontres ne seront pas importées.
            </span>
        </div>
        <div id="import-result" class="mt-2"></div>
    </div>

</div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let equipes = [];
let fichierEnCours = null;
const DEPTS_NORMANDIE = <?= json_encode(array_map('strval', $deptsNorm)) ?>;
const ALL_DEPTS = <?= json_encode((object)$allDepts) ?>; // { "14": "Calvados", "27": "Eure", … }

function spinner(show) { $('#spinner').toggleClass('show', show); }

function toast(msg, ok = true) {
    const id = 't' + Date.now();
    $('#content').prepend(
        `<div id="${id}" class="alert alert-${ok ? 'success' : 'danger'} alert-dismissible py-2 mb-2">
            ${msg}
            <button class="btn-close" data-bs-dismiss="alert"></button>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 4000);
}

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Chargement liste fichiers ──────────────────────────────────────────────────
$.getJSON('import_rencontres_nat.php?action=liste_fichiers', function (r) {
    const $sel = $('#sel-fichier').empty();
    if (!r.ok || !r.fichiers.length) {
        $sel.append('<option value="">— Aucun fichier xlsx disponible —</option>');
        return;
    }
    $sel.append('<option value="">— Choisir un fichier —</option>');
    r.fichiers.forEach(f => $sel.append(`<option value="${esc(f)}">${esc(f)}</option>`));
    $('#btn-analyser').prop('disabled', false);
});

$('#sel-fichier').on('change', function () {
    $('#btn-analyser').prop('disabled', !this.value);
});

// ── Analyser ──────────────────────────────────────────────────────────────────
$('#btn-analyser').on('click', function () {
    const fichier = $('#sel-fichier').val();
    if (!fichier) return;
    fichierEnCours = fichier;
    spinner(true);
    console.log('[Analyser] Envoi requête pour fichier :', fichier);
    $.post('import_rencontres_nat.php?action=analyser', { fichier }, function (r) {
        spinner(false);
        console.log('[Analyser] Réponse reçue :', r);
        if (!r.ok) { toast('Erreur analyse : ' + r.err, false); return; }

        console.log('[Calendrier] Saison :', r.data.saison);
        console.log('[Calendrier] Journées (' + r.data.journees.length + ') :', r.data.journees);
        console.log('[Calendrier] Poules (' + r.data.pools.length + ') :', r.data.pools);
        console.log('[Analyser] Auto-associations :', r.auto_assoc);

        $('#lbl-saison').text('Saison ' + r.data.saison);
        afficherCalendrier(r.data.journees);
        $('#section-calendrier').show();
        $('#cal-content').hide();
        $('#btn-toggle-cal').html('<i class="bi bi-chevron-down"></i> Afficher');

        assignerHorsRegion(() => chargerEquipes());
        let statusMsg = `✓ ${r.data.pools.length} poule(s), ${r.data.journees.length} journée(s).`;
        if (r.auto_assoc > 0) statusMsg += ` ${r.auto_assoc} association(s) copiée(s) automatiquement.`;
        $('#analyse-status').text(statusMsg);
    }, 'json').fail((xhr, status, err) => {
        spinner(false);
        console.error('[Analyser] Erreur réseau :', status, err, xhr.status, xhr.responseText);
        toast('Erreur réseau.', false);
    });
});

// ── Calendrier ────────────────────────────────────────────────────────────────
function fmtDate(iso) {
    // "YYYY-MM-DD" → "JJ/MM/YYYY"
    const p = iso.split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
}

function afficherCalendrier(journees) {
    console.log('[afficherCalendrier] Entrée, journees.length =', journees.length);
    if (!journees.length) {
        console.warn('[afficherCalendrier] Aucune journée — vérifiez le parsing Excel');
        $('#cal-content').html('<p class="text-muted">Aucune journée détectée.</p>');
        return;
    }
    // Ligne 1 : en-têtes J1 + date
    let th1 = '', th2 = '';
    journees.forEach(j => {
        console.log('[afficherCalendrier] Journée', j.journee, 'date =', j.date, 'matchs =', j.matchs);
        const matchs = j.matchs.map(m => `${m[0]}-${m[1]}`).join(' / ');
        th1 += `<th style="text-align:center; white-space:nowrap; padding:.25rem .5rem;">
                    J${j.journee}<br>
                    <span style="font-weight:400; font-size:.78rem;">${fmtDate(j.date)}</span>
                </th>`;
        th2 += `<td style="text-align:center; font-size:.8rem; color:#4b5563; white-space:nowrap; padding:.2rem .5rem;">${matchs}</td>`;
    });
    const html = `
        <div style="overflow-x:auto;">
          <table style="border-collapse:collapse; border:1px solid #d0d8e8; font-size:.84rem; background:#fff; border-radius:6px; overflow:hidden;">
            <thead>
              <tr style="background:#e8eef7; color:#1a3a6b;">${th1}</tr>
            </thead>
            <tbody>
              <tr>${th2}</tr>
            </tbody>
          </table>
        </div>`;
    $('#cal-content').html(html);
}

$('#btn-toggle-cal').on('click', function () {
    const $c = $('#cal-content');
    const open = $c.is(':visible');
    $c.toggle(!open);
    $(this).html(open ? '<i class="bi bi-chevron-down"></i> Afficher' : '<i class="bi bi-chevron-up"></i> Masquer');
});

// ── Table associations ────────────────────────────────────────────────────────
function chargerEquipes() {
    $.getJSON('import_rencontres_nat.php?action=liste_equipes', function (r) {
        if (!r.ok) { toast('Erreur chargement équipes.', false); return; }
        equipes = r.equipes;
        $('#section-assoc').show();
        renderAssoc();
    });
}

function isNormandie(dept) {
    return dept && DEPTS_NORMANDIE.includes(String(dept));
}

function deptName(dept) {
    return (dept && ALL_DEPTS[dept]) ? ALL_DEPTS[dept] : (dept || '');
}

function deptBadge(dept) {
    if (!dept) return '<span style="font-size:.7rem;color:#9ca3af;">—</span>';
    const nom = deptName(dept);
    if (isNormandie(dept)) {
        return `<span style="font-size:.68rem;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:3px;padding:.05rem .3rem;" title="${esc(nom)}">NOR</span>`;
    }
    return `<span style="font-size:.68rem;font-weight:600;color:#6b7280;background:#f3f4f6;border:1px solid #d1d5db;border-radius:3px;padding:.05rem .3rem;" title="${esc(nom)}">${esc(dept)}</span>`;
}

function renderAssoc() {
    const divFiltre     = $('#sel-div-filtre').val();
    const sansSeulement = $('#chk-sans-club').is(':checked');

    let data = [...equipes];
    if (divFiltre)     data = data.filter(e => e.Division === divFiltre);
    if (sansSeulement) data = data.filter(e => !e.CodeDept);

    const total    = equipes.length;
    const avecDept = equipes.filter(e => e.CodeDept).length;
    const normands = equipes.filter(e => isNormandie(e.CodeDept)).length;
    const avecClub = equipes.filter(e => e.Id_Club).length;

    $('#lbl-assoc-count').text(
        `${avecDept} / ${total} équipes avec dépt — dont ${normands} normand(e)s, ${avecClub} avec club`
    );
    $('#import-warn').toggle(normands > avecClub);
    $('#btn-importer').prop('disabled', !fichierEnCours);
    $('#btn-hors-region').prop('disabled', !fichierEnCours);
    $('#btn-remplir').prop('disabled', !fichierEnCours);

    const $body = $('#tbody-assoc').empty();
    if (!data.length) {
        $body.append('<tr><td colspan="7" class="text-center text-muted py-3">Aucune équipe.</td></tr>');
        return;
    }

    data.forEach(e => {
        const norm    = isNormandie(e.CodeDept);
        const haClub  = !!e.Id_Club;
        const rowOk   = e.CodeDept && (!norm || haClub);
        const clubTxt = haClub ? esc(e.NomClub) + ` <small class="text-muted">(#${e.Id_Club})</small>` : '';

        // Cellule club : visible uniquement si dépt Normandie
        const clubCell = norm ? `
            <div class="club-search-wrap" data-id="${e.Id_EquipeNat}">
                <div class="club-display ${haClub ? '' : 'empty'}" title="${haClub ? esc(e.NomClub ?? '') : ''}">
                    ${haClub ? clubTxt : '— non associé —'}
                </div>
                <button class="btn btn-xs btn-outline-secondary btn-changer-club py-0 px-1" style="font-size:.76rem;">
                    <i class="bi bi-search"></i>
                </button>
                ${haClub ? `<button class="btn btn-xs btn-outline-danger btn-effacer-club py-0 px-1" style="font-size:.76rem;" title="Supprimer"><i class="bi bi-x"></i></button>` : ''}
                <div class="club-popup">
                    <input class="club-popup-input" placeholder="Chercher un club…" autocomplete="off">
                    <div class="club-results"></div>
                </div>
            </div>` : `<span class="text-muted" style="font-size:.78rem;">${esc(deptName(e.CodeDept))}</span>`;

        const $tr = $('<tr>').addClass(rowOk ? 'assoc-ok' : '').attr('data-id', e.Id_EquipeNat);
        $tr.html(`
            <td><span class="badge-div bdiv-${esc(e.Division)}">${esc(e.Division)}</span></td>
            <td style="text-align:center;">${e.Poule}</td>
            <td style="text-align:center;">${e.Rang}</td>
            <td style="font-weight:600;">
                ${esc(e.Nom)}
                <button class="btn-search-club btn btn-xs btn-outline-primary ms-1 py-0 px-1"
                        style="font-size:.7rem; vertical-align:middle;"
                        data-nom="${esc(e.Nom)}"
                        title="Rechercher le club">
                    <i class="bi bi-search"></i>
                </button>
            </td>
            <td style="text-align:center; white-space:nowrap;">
                <input type="text" class="input-dept form-control form-control-sm text-center px-1"
                       value="${esc(e.CodeDept ?? '')}"
                       placeholder="ex: 76"
                       maxlength="2"
                       style="width:58px; display:inline-block;"
                       data-id="${e.Id_EquipeNat}">
                <span class="dept-badge ms-1" data-id="${e.Id_EquipeNat}">${deptBadge(e.CodeDept)}</span>
            </td>
            <td>${clubCell}</td>
            <td style="text-align:center;">
                <button class="btn btn-sm btn-success btn-sauver py-0"
                        data-id="${e.Id_EquipeNat}"
                        data-dept="${esc(e.CodeDept ?? '')}"
                        data-club="${e.Id_Club ?? ''}">
                    <i class="bi bi-floppy"></i>
                </button>
            </td>
        `);
        $body.append($tr);
    });
}

// ── Saisie département → badge + cellule club en temps réel ──────────────────
$(document).on('input', '.input-dept', function () {
    const idEn = +$(this).attr('data-id');
    const dept = $(this).val().trim().toUpperCase();
    const $tr  = $(this).closest('tr');
    const norm = isNormandie(dept);

    // Badge indicateur
    $tr.find(`.dept-badge[data-id="${idEn}"]`).html(deptBadge(dept || null));

    // Mettre à jour le cache
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) {
        eq.CodeDept = dept || null;
        if (!norm) { eq.Id_Club = null; eq.NomClub = null; }
    }

    // Cellule club : basculer hors Normandie ↔ recherche club
    const $clubCell = $tr.find('td').eq(5);
    if (!norm) {
        $clubCell.html(`<span class="text-muted" style="font-size:.78rem;">${esc(deptName(dept))}</span>`);
        $tr.find('.btn-sauver').attr('data-club', '');
    } else if (!$clubCell.find('.club-search-wrap').length) {
        // Afficher la cellule club Normandie (vide pour l'instant)
        $clubCell.html(`
            <div class="club-search-wrap" data-id="${idEn}">
                <div class="club-display empty">— non associé —</div>
                <button class="btn btn-xs btn-outline-secondary btn-changer-club py-0 px-1" style="font-size:.76rem;">
                    <i class="bi bi-search"></i>
                </button>
                <div class="club-popup">
                    <input class="club-popup-input" placeholder="Chercher un club…" autocomplete="off">
                    <div class="club-results"></div>
                </div>
            </div>`);
    }
});

// ── Bouton loupe : ouvre le modal de recherche club ──────────────────────────
let modalTargetId = null;
const bsModalClub = new bootstrap.Modal(document.getElementById('modalClub'));

$(document).on('click', '.btn-search-club', function (e) {
    e.stopPropagation();
    const nom  = $(this).attr('data-nom') || '';
    const base = nom.replace(/\s+\d+$/, '').trim();
    window.open('https://www.google.com/search?q=' + encodeURIComponent(base + ' tennis de table club'), '_blank');
});

// Recherche dans le modal
let modalSearchTimer = null;
$('#modal-club-input').on('input', function () {
    const q = $(this).val().trim();
    const $res = $('#modal-club-results').empty();
    clearTimeout(modalSearchTimer);
    if (q.length < 2) return;
    modalSearchTimer = setTimeout(() => {
        $.getJSON('import_rencontres_nat.php?action=recherche_club', { q }, function (r) {
            $res.empty();
            if (!r.ok || !r.clubs.length) {
                $res.html('<p class="text-muted mb-0 py-1" style="font-size:.83rem;">Aucun club trouvé.</p>');
                return;
            }
            r.clubs.forEach(c => {
                $('<div>')
                    .addClass('p-2 border-bottom club-modal-item')
                    .css({ cursor: 'pointer', fontSize: '.84rem' })
                    .html(`<strong>${esc(c.Nom)}</strong> <small class="text-muted">#${c.Id_Club}</small>`)
                    .attr('data-id-club', c.Id_Club)
                    .attr('data-nom-club', c.Nom)
                    .on('mouseenter', function () { $(this).css('background', '#eef3fb'); })
                    .on('mouseleave', function () { $(this).css('background', ''); })
                    .appendTo($res);
            });
        });
    }, 280);
});

// Sélection d'un club dans le modal
$(document).on('click', '.club-modal-item', function () {
    if (!modalTargetId) return;
    const idClub = +$(this).attr('data-id-club');
    const nom    = $(this).attr('data-nom-club');
    // Mettre à jour le cache
    const eq = equipes.find(e => +e.Id_EquipeNat === modalTargetId);
    if (eq) { eq.Id_Club = idClub; eq.NomClub = nom; }
    // Mettre à jour le bouton Sauver de la ligne
    $(`#tbody-assoc tr[data-id="${modalTargetId}"] .btn-sauver`).attr('data-club', idClub);
    bsModalClub.hide();
    renderAssoc();
});

// ── Ouverture popup recherche club ────────────────────────────────────────────
$(document).on('click', '.btn-changer-club', function (e) {
    e.stopPropagation();
    $('.club-popup').removeClass('open');
    const $wrap = $(this).closest('.club-search-wrap');
    const $pop  = $wrap.find('.club-popup').addClass('open');
    $pop.find('.club-popup-input').val('').trigger('focus');
    $pop.find('.club-results').empty();
});

$(document).on('click', function () { $('.club-popup').removeClass('open'); });
$(document).on('click', '.club-popup', e => e.stopPropagation());

// ── Recherche dans le popup ───────────────────────────────────────────────────
let searchTimer = null;
$(document).on('input', '.club-popup-input', function () {
    const q     = $(this).val().trim();
    const $res  = $(this).siblings('.club-results').empty();
    clearTimeout(searchTimer);
    if (q.length < 2) return;
    searchTimer = setTimeout(() => {
        $.getJSON('import_rencontres_nat.php?action=recherche_club', { q }, function (r) {
            $res.empty();
            if (!r.ok || !r.clubs.length) {
                $res.html('<div class="club-result text-muted">Aucun club trouvé.</div>');
                return;
            }
            r.clubs.forEach(c => {
                $('<div class="club-result">')
                    .html(`<strong>${esc(c.Nom)}</strong> <small class="text-muted">#${c.Id_Club}</small>`)
                    .attr('data-id-club', c.Id_Club)
                    .attr('data-nom-club', c.Nom)
                    .appendTo($res);
            });
        });
    }, 280);
});

// ── Sélection d'un club dans le popup ────────────────────────────────────────
$(document).on('click', '.club-result', function () {
    const $wrap  = $(this).closest('.club-search-wrap');
    const idEn   = +$wrap.attr('data-id');
    const idClub = +$(this).attr('data-id-club');
    const nom    = $(this).attr('data-nom-club');

    // Mettre à jour le cache local
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club = idClub; eq.NomClub = nom; }

    // Mettre à jour l'affichage
    const $tr = $wrap.closest('tr');
    $wrap.find('.club-display')
        .removeClass('empty')
        .html(esc(nom) + ` <small class="text-muted">(#${idClub})</small>`)
        .attr('title', nom);
    $tr.find('.btn-sauver').attr('data-club', idClub);
    $wrap.find('.club-popup').removeClass('open');
});

// ── Effacer une association ───────────────────────────────────────────────────
$(document).on('click', '.btn-effacer-club', function () {
    const $wrap = $(this).closest('.club-search-wrap');
    const idEn  = +$wrap.attr('data-id');
    const eq    = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club = null; eq.NomClub = null; }
    $wrap.closest('tr').find('.btn-sauver').attr('data-club', '');
    renderAssoc();
});

// ── Sauvegarde d'une ligne ────────────────────────────────────────────────────
$(document).on('click', '.btn-sauver', function () {
    const $btn   = $(this);
    const $tr    = $btn.closest('tr');
    const idEn   = +$btn.attr('data-id');
    const dept   = $tr.find('.input-dept').val().trim();
    const norm   = isNormandie(dept);
    const idClub = norm ? $btn.attr('data-club') : '';

    // Mettre à jour le cache local
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) {
        eq.CodeDept = dept || null;
        if (!norm) { eq.Id_Club = null; eq.NomClub = null; }
    }

    $btn.prop('disabled', true);
    $.post('import_rencontres_nat.php?action=sauvegarder_assoc', {
        id_equipe_nat: idEn,
        code_dept: dept,
        id_club: idClub,
    }, function (r) {
        $btn.prop('disabled', false);
        if (r.ok) {
            renderAssoc();
        } else {
            toast('Erreur : ' + r.err, false);
        }
    }, 'json').fail(() => { $btn.prop('disabled', false); toast('Erreur réseau.', false); });
});

// ── Filtres ───────────────────────────────────────────────────────────────────
$('#sel-div-filtre, #chk-sans-club').on('change', renderAssoc);

// ── Hors Région ───────────────────────────────────────────────────────────────
function assignerHorsRegion(callback) {
    $.post('import_rencontres_nat.php?action=assigner_hors_region', {}, function (r) {
        if (!r.ok) { toast('Erreur Hors Région : ' + (r.err ?? ''), false); }
        else if (r.clubs_crees > 0 || r.equipes_assignees > 0) {
            toast(`Hors Région : ${r.clubs_crees} club(s) créé(s), ${r.equipes_assignees} équipe(s) assignée(s).`);
        }
        if (callback) callback();
    }, 'json').fail(() => toast('Erreur réseau (Hors Région).', false));
}

$('#btn-hors-region').on('click', function () {
    $(this).prop('disabled', true);
    assignerHorsRegion(() => {
        chargerEquipes();
        $('#btn-hors-region').prop('disabled', false);
    });
});

// ── Remplir les rencontres ────────────────────────────────────────────────────
$('#btn-remplir').on('click', function () {
    if (!fichierEnCours) return;
    if (!confirm('Créer les équipes et rencontres à domicile des clubs normands ?\n(Les doublons seront ignorés.)')) return;
    spinner(true);
    $.post('import_rencontres_nat.php?action=remplir_rencontres', { fichier: fichierEnCours }, function (r) {
        spinner(false);
        if (!r.ok) { toast('Erreur : ' + r.err, false); return; }
        const s = r.stats;
        let html = `
            <div class="d-flex flex-wrap gap-2 mb-2">
                <div class="stat-card"><div class="sv text-success">${s.equipes_creees}</div><div class="sl">Équipes créées</div></div>
                <div class="stat-card"><div class="sv text-primary">${s.rencontres_creees}</div><div class="sl">Rencontres créées</div></div>
                <div class="stat-card"><div class="sv text-secondary">${s.doublons}</div><div class="sl">Doublons ignorés</div></div>
                <div class="stat-card"><div class="sv text-muted">${s.ignores}</div><div class="sl">Hors région (ignorés)</div></div>
            </div>`;
        if (s.erreurs && s.erreurs.length) {
            html += '<div class="alert alert-warning py-2"><strong>Avertissements :</strong><ul class="mb-0">';
            s.erreurs.forEach(e => html += `<li>${esc(e)}</li>`);
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success py-2">Rencontres remplies sans erreur.</div>';
        }
        $('#import-result').html(html);
    }, 'json').fail(() => { spinner(false); toast('Erreur serveur.', false); });
});

// ── Import en base ────────────────────────────────────────────────────────────
$('#btn-importer').on('click', function () {
    if (!fichierEnCours) return;
    if (!confirm('Importer les rencontres nationales en base ?\n(Les doublons seront ignorés.)')) return;
    spinner(true);
    $.post('import_rencontres_nat.php?action=importer', { fichier: fichierEnCours }, function (r) {
        spinner(false);
        if (!r.ok) { toast('Erreur import : ' + r.err, false); return; }
        const s = r.stats;
        let html = `
            <div class="d-flex flex-wrap gap-2 mb-2">
                <div class="stat-card"><div class="sv text-success">${s.equipes_creees}</div><div class="sl">Équipes créées</div></div>
                <div class="stat-card"><div class="sv text-primary">${s.rencontres_creees}</div><div class="sl">Rencontres créées</div></div>
                <div class="stat-card"><div class="sv text-secondary">${s.doublons}</div><div class="sl">Doublons ignorés</div></div>
                <div class="stat-card"><div class="sv text-warning">${s.sans_club}</div><div class="sl">Sans association</div></div>
            </div>`;
        if (s.erreurs && s.erreurs.length) {
            html += '<div class="alert alert-warning py-2"><strong>Avertissements :</strong><ul class="mb-0">';
            s.erreurs.forEach(e => html += `<li>${esc(e)}</li>`);
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success py-2">Import terminé sans erreur.</div>';
        }
        $('#import-result').html(html);
    }, 'json').fail(() => { spinner(false); toast('Erreur serveur.', false); });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
