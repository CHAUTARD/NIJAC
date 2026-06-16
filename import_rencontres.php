<?php
/**
 * NIJAC – Import des rencontres (E011)
 *
 * Importe les rencontres de la saison depuis des fichiers Excel (format FFTT).
 * Les fichiers sont déposés dans le dossier Importation/ et traités pour
 * alimenter la table rencontre en base. Les doublons sont ignorés et
 * les données manquantes (salles, clubs) sont signalées.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ─── Contrainte UNIQUE sur equipe (une seule fois) ────────────────────────
try {
    getPDO()->exec("ALTER TABLE equipe ADD UNIQUE KEY uq_equipe_nom_div (Nom(80), Id_Division)");
} catch (PDOException $e) { /* déjà existe */ }


// ─── Fonctions de parsing ──────────────────────────────────────────────────

/**
 * Convertit une chaîne de date (formats variés) en "YYYY-MM-DD".
 * Formats attendus :
 *   - "Dimanche 21/09/2025 09H00"
 *   - "28-sept-2025"
 *   - "12-oct-2025"
 *   - "11-janv-2026"
 */
function parseDate(string $s): ?string
{
    static $mois = [
        'janv' => '01', 'fevr' => '02', 'mars' => '03', 'avr'  => '04',
        'mai'  => '05', 'juin' => '06', 'juil' => '07', 'aout' => '08',
        'sept' => '09', 'oct'  => '10', 'nov'  => '11', 'dec'  => '12',
    ];
    // dd/mm/yyyy
    if (preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    // dd-mon-yyyy (French, accents supprimés)
    $n = mb_strtolower(strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','û'=>'u','î'=>'i','ô'=>'o']));
    if (preg_match('/(\d{1,2})-([a-z]+)-(\d{4})/', $n, $m)) {
        $num = $mois[$m[2]] ?? null;
        if ($num) {
            return $m[3] . '-' . $num . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
    }
    return null;
}

/** Extrait HH:MM:SS depuis "09H00" */
function parseHeure(string $s): ?string
{
    if (preg_match('/(\d{2})H(\d{2})/i', $s, $m)) {
        return $m[1] . ':' . $m[2] . ':00';
    }
    return null;
}

/** Retourne le numéro de phase depuis "1ere", "2eme", "2" … */
function parsePhase(string $s): int
{
    if (preg_match('/(\d+)/', $s, $m)) return (int)$m[1];
    return 1;
}

/** Retourne Id_Division depuis code division + catégorie */
function getDivisionId(string $div, string $cat): ?int
{
    static $mapM = ['R1'=>3,'R2'=>2,'R3'=>1,'R4'=>10,'PN'=>4,'N3'=>5,'N2'=>6,'N1'=>7];
    static $mapF = ['R1'=>8,'PN'=>9];
    $div = strtoupper(trim($div));
    $catNorm = mb_strtolower(strtr($cat, ['É'=>'E','È'=>'E','é'=>'e','è'=>'e']));
    $female = str_contains($catNorm, 'fem') || str_contains($catNorm, 'dame');
    $map = $female ? $mapF : $mapM;
    return $map[$div] ?? null;
}

/**
 * Parse une feuille XLS et retourne les données structurées.
 * @return array{saison:string, categorie:string, secteur:string, division:string,
 *               poule:int, phase:int, id_division:int|null,
 *               clubs:array, rencontres:array}
 */
function parseSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
{
    $rows = $sheet->toArray(null, true, true, false);
    $result = [
        'saison' => '', 'categorie' => '', 'secteur' => '',
        'division' => '', 'poule' => 0, 'phase' => 1,
        'id_division' => null, 'clubs' => [], 'rencontres' => [],
    ];

    // ── 1. En-tête ─────────────────────────────────────────────────────────
    // Saison dans les premières lignes (pattern xxxx/xxxx)
    foreach (array_slice($rows, 0, 12) as $row) {
        foreach ($row as $cell) {
            if (preg_match('/(\d{4}\/\d{4})/', (string)$cell, $m)) {
                $result['saison'] = $m[1];
                break 2;
            }
        }
    }

    // ── Recherche robuste de Division/Poule/Phase/Catégorie/Secteur ────────
    // Stratégie : balayer les lignes 3-12, collecter toutes les valeurs non vides
    // en tenant compte des labels (DIVISION, POULE, PHASE) et des patterns directs.
    foreach (array_slice($rows, 3, 10) as $row) {
        // Valeurs non vides de la ligne, dans l'ordre
        $vals = array_values(array_filter(
            array_map(fn($v) => trim((string)$v), $row),
            fn($v) => $v !== ''
        ));
        for ($i = 0; $i < count($vals); $i++) {
            $v = $vals[$i];
            $vu = strtoupper($v);
            $next = $vals[$i + 1] ?? '';

            // Catégorie
            // Catégorie (normalisation accent pour Féminines/Féminins)
            $vn = mb_strtolower(strtr($v, ['É'=>'E','È'=>'E','Ê'=>'E','é'=>'e','è'=>'e','ê'=>'e']));
            if (preg_match('/^(messieurs|feminines?|dames?)$/', $vn) && $result['categorie'] === '') {
                $result['categorie'] = $v;
            }
            // Secteur (ex "27-76", "14-50-61")
            if (preg_match('/^\d{2}(-\d{2})+$/', $v) && $result['secteur'] === '') {
                $result['secteur'] = $v;
            }
            // Division — soit après le label "DIVISION", soit seule (R1-R4, PN, N1-N3)
            if ($vu === 'DIVISION') {
                if (preg_match('/^(R[1-4]|PN[MF]?|N[1-3]|PR)$/i', $next)) {
                    $result['division'] = strtoupper($next);
                    $i++; // consomme la valeur suivante
                    // Poule juste après ?
                    $afterDiv = $vals[$i + 1] ?? '';
                    // skip si c'est le label "POULE"
                    if (strtoupper($afterDiv) === 'POULE') {
                        $i++;
                        $afterDiv = $vals[$i + 1] ?? '';
                    }
                    if (ctype_digit($afterDiv) && $result['poule'] === 0) {
                        $result['poule'] = (int)$afterDiv;
                        $i++;
                    }
                }
            } elseif (preg_match('/^(R[1-4]|PN[MF]?|N[1-3])$/i', $v) && $result['division'] === '') {
                $result['division'] = strtoupper($v);
                // Poule juste après (chiffre seul) ?
                if (ctype_digit($next) && $result['poule'] === 0) {
                    $result['poule'] = (int)$next;
                    $i++;
                }
            }
            // Poule après label "POULE"
            if ($vu === 'POULE' && ctype_digit($next) && $result['poule'] === 0) {
                $result['poule'] = (int)$next;
                $i++;
            }
            // Phase : "1ere", "2eme", ou après label "PHASE"
            if ($vu === 'PHASE' && $next !== '' && $result['phase'] === 1) {
                $result['phase'] = parsePhase($next);
                $i++;
            } elseif (preg_match('/^\d+(ere|eme|[eè]re|[eè]me)$/i', $v)) {
                $result['phase'] = parsePhase($v);
            }
        }
        // Stopper dès qu'on a tout
        if ($result['division'] !== '' && $result['poule'] > 0 && $result['categorie'] !== '') break;
    }

    $result['id_division'] = getDivisionId($result['division'], $result['categorie']);

    // ── 2. Clubs ────────────────────────────────────────────────────────────
    // Le N° Club (6-9 chiffres) peut se trouver dans n'importe quelle colonne.
    // On scanne toute la ligne pour le détecter, puis on prend la valeur non vide suivante comme nom.
    foreach ($rows as $row) {
        foreach ($row as $j => $cell) {
            $numClub = trim((string)$cell);
            if (!preg_match('/^\d{6,9}$/', $numClub)) continue;
            // Chercher le nom : première cellule non vide après le N°Club
            $nom = '';
            for ($k = $j + 1; $k < count($row); $k++) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '' && !preg_match('/^\d+$/', $v)) { $nom = $v; break; }
            }
            if ($nom === '') break;
            // Chercher email et téléphone dans les cellules suivantes
            $tel = ''; $email = '';
            foreach (array_slice($row, $j + 1) as $c) {
                $cv = trim((string)$c);
                if ($email === '' && preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $cv, $me))
                    $email = $me[1];
                if ($tel === '' && preg_match('/^(\d[\d\s\-\.]{8,13}\d)$/', $cv, $mt))
                    $tel = preg_replace('/\s/', '', $mt[1]);
            }
            $result['clubs'][$nom] = [
                'id_club' => (int)$numClub,
                'nom'     => $nom,
                'tel'     => $tel,
                'email'   => $email,
            ];
            break; // une seule détection par ligne
        }
    }

    // ── 3. Rencontres ───────────────────────────────────────────────────────
    // Ligne journée : contient "Journée N" dans n'importe quelle colonne
    // Ligne rencontre : contient " Contre " dans n'importe quelle colonne ;
    //                   la date/heure est toujours en col[0]
    $journeeCourante = 0;
    foreach ($rows as $row) {
        $c0 = trim((string)($row[0] ?? ''));

        // Détection du n° de journée (scan toute la ligne)
        // Normalisation accent pour éviter les problèmes d'encodage UTF-8 sans flag /u
        $foundJournee = false;
        foreach ($row as $cell) {
            $cellN = strtr((string)$cell, ['é'=>'e','è'=>'e','ê'=>'e','É'=>'E','È'=>'E']);
            if (preg_match('/Journee\s+(\d+)/i', $cellN, $mj)) {
                $journeeCourante = (int)$mj[1];
                $foundJournee = true;
                break;
            }
        }
        if ($foundJournee) continue;

        // Ligne de rencontre : chercher " Contre " dans n'importe quelle colonne
        $cellContre = null;
        foreach ($row as $cell) {
            if (stripos((string)$cell, ' Contre ') !== false) {
                $cellContre = trim((string)$cell);
                break;
            }
        }
        if ($cellContre !== null) {
            $date  = parseDate($c0);
            $heure = parseHeure($c0) ?? '09:00:00';
            $parts = preg_split('/\s+Contre\s+/i', $cellContre, 2);
            if (count($parts) === 2 && $date) {
                $result['rencontres'][] = [
                    'journee'   => $journeeCourante,
                    'date'      => $date,
                    'heure'     => $heure,
                    'equipe_dom' => trim($parts[0]),
                    'equipe_ext' => trim($parts[1]),
                ];
            }
        }
    }

    return $result;
}

// ─── ACTIONS AJAX ──────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    ob_start();
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    $action = $_GET['action'];

    // ── Ajout d'un ou plusieurs fichiers ────────────────────────────────────
    if ($action === 'upload') {
        $dossier = __DIR__ . '/Importation/Rencontres/';
        $resultats = [];

        if (empty($_FILES['fichiers'])) {
            echo json_encode(['ok' => false, 'err' => 'Aucun fichier reçu.']);
            exit;
        }

        $noms  = $_FILES['fichiers']['name'];
        $tmps  = $_FILES['fichiers']['tmp_name'];
        $errs  = $_FILES['fichiers']['error'];

        foreach ($noms as $i => $nomOriginal) {
            $nom = basename($nomOriginal);

            if ($errs[$i] !== UPLOAD_ERR_OK) {
                $resultats[] = ['nom' => $nom, 'ok' => false, 'msg' => 'Erreur de téléversement.'];
                continue;
            }
            if (strtolower(pathinfo($nom, PATHINFO_EXTENSION)) !== 'xls') {
                $resultats[] = ['nom' => $nom, 'ok' => false, 'msg' => 'Seuls les fichiers .xls sont acceptés.'];
                continue;
            }

            $cible = $dossier . $nom;
            if (!move_uploaded_file($tmps[$i], $cible)) {
                $resultats[] = ['nom' => $nom, 'ok' => false, 'msg' => 'Échec de l\'enregistrement sur le serveur.'];
                continue;
            }
            $resultats[] = ['nom' => $nom, 'ok' => true];
        }

        echo json_encode(['ok' => true, 'resultats' => $resultats]);
        exit;
    }

    // ── Suppression d'un fichier ────────────────────────────────────────────
    if ($action === 'supprimer') {
        $nom = basename($_POST['fichier'] ?? '');
        $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
        if ($nom === '' || !file_exists($fichier)) {
            echo json_encode(['ok' => false, 'err' => 'Fichier introuvable']);
            exit;
        }
        if (!unlink($fichier)) {
            echo json_encode(['ok' => false, 'err' => 'Impossible de supprimer le fichier.']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Liste des fichiers XLS disponibles ─────────────────────────────────
    if ($action === 'liste') {
        $dossier = __DIR__ . '/Importation/Rencontres/';
        $pdo     = getPDO();
        $stmtExiste = $pdo->prepare(
            'SELECT 1 FROM rencontre r
             JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom AND ed.Id_Division = ?
             JOIN equipe ee ON ee.Id_Equipe = r.Id_EquipeExt
             WHERE r.Date = ? AND ed.Nom = ? AND ee.Nom = ? LIMIT 1'
        );

        $fichiers = [];
        foreach (glob($dossier . '*.xls') as $f) {
            $nom = basename($f);
            $sp  = IOFactory::load($f);

            // Récupère la première rencontre du fichier (toutes feuilles confondues)
            $premiere = null;
            for ($s = 0; $s < $sp->getSheetCount() && !$premiere; $s++) {
                $data = parseSheet($sp->getSheet($s));
                if (!$data['id_division'] || empty($data['rencontres'])) continue;
                $r = $data['rencontres'][0];
                $premiere = [
                    'id_division' => $data['id_division'],
                    'date'        => $r['date'],
                    'dom'         => $r['equipe_dom'],
                    'ext'         => $r['equipe_ext'],
                ];
            }

            $importe = false;
            if ($premiere) {
                $stmtExiste->execute([$premiere['id_division'], $premiere['date'], $premiere['dom'], $premiere['ext']]);
                $importe = (bool)$stmtExiste->fetchColumn();
            }

            $fichiers[] = [
                'nom'      => $nom,
                'feuilles' => $sp->getSheetCount(),
                'importe'  => $importe,
            ];
        }
        echo json_encode(['ok' => true, 'fichiers' => $fichiers]);
        exit;
    }

    // ── Aperçu d'un fichier ────────────────────────────────────────────────
    if ($action === 'apercu') {
        $nom = basename($_GET['fichier'] ?? '');
        $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
        if (!file_exists($fichier)) {
            echo json_encode(['ok' => false, 'err' => 'Fichier introuvable']);
            exit;
        }
        $sp = IOFactory::load($fichier);
        $poules = [];
        for ($s = 0; $s < $sp->getSheetCount(); $s++) {
            $poules[] = parseSheet($sp->getSheet($s));
        }
        echo json_encode(['ok' => true, 'poules' => $poules]);
        exit;
    }

    // ── Import en base ─────────────────────────────────────────────────────
    if ($action === 'importer') {
        $nom = basename($_POST['fichier'] ?? '');
        $fichier = __DIR__ . '/Importation/Rencontres/' . $nom;
        if (!file_exists($fichier)) {
            echo json_encode(['ok' => false, 'err' => 'Fichier introuvable']);
            exit;
        }
        $sp = IOFactory::load($fichier);
        $pdo = getPDO();
        $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'erreurs' => []];

        $stmtEqCheck = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom = ? AND Id_Division = ?');
        $stmtEqIns   = $pdo->prepare('INSERT IGNORE INTO equipe (Nom, Id_Division, Id_Club) VALUES (?,?,?)');
        $stmtRencCheck = $pdo->prepare(
            'SELECT Id_Rencontre FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=?'
        );
        $stmtRencIns = $pdo->prepare(
            'INSERT INTO rencontre (Date, Heure, Id_Division, Poule, Id_EquipeDom, Id_EquipeExt,
                                    Phase, Saison, Journee, ArbitrageObligatoire)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        // Cache ArbitrageObligatoire par division
        $stmtArbitrage = $pdo->prepare('SELECT ArbitrageObligatoire FROM division WHERE Id_Division = ?');
        $cacheArbitrage = [];

        for ($s = 0; $s < $sp->getSheetCount(); $s++) {
            $data = parseSheet($sp->getSheet($s));
            if (!$data['id_division']) {
                $stats['erreurs'][] = "Feuille " . ($s+1) . " : division introuvable ({$data['division']})";
                continue;
            }

            // Récupérer ArbitrageObligatoire de la division (avec cache)
            $idDiv = $data['id_division'];
            if (!isset($cacheArbitrage[$idDiv])) {
                $stmtArbitrage->execute([$idDiv]);
                $cacheArbitrage[$idDiv] = (int)($stmtArbitrage->fetchColumn() ?? 1);
            }
            $arbitrageObligatoire = $cacheArbitrage[$idDiv];

            // Créer les équipes
            $equipeMap = []; // nom → Id_Equipe
            foreach ($data['clubs'] as $club) {
                $stmtEqCheck->execute([$club['nom'], $idDiv]);
                $existing = $stmtEqCheck->fetchColumn();
                if ($existing) {
                    $equipeMap[$club['nom']] = (int)$existing;
                } else {
                    $stmtEqIns->execute([$club['nom'], $idDiv, $club['id_club']]);
                    $equipeMap[$club['nom']] = (int)$pdo->lastInsertId();
                    $stats['equipes_creees']++;
                }
            }

            // Créer les rencontres
            foreach ($data['rencontres'] as $r) {
                $idDom = $equipeMap[$r['equipe_dom']] ?? null;
                $idExt = $equipeMap[$r['equipe_ext']] ?? null;
                if (!$idDom || !$idExt) {
                    $stats['erreurs'][] = "Équipe inconnue : \"{$r['equipe_dom']}\" ou \"{$r['equipe_ext']}\"";
                    continue;
                }
                $stmtRencCheck->execute([$r['date'], $idDom, $idExt]);
                if ($stmtRencCheck->fetchColumn()) {
                    $stats['doublons']++;
                    continue;
                }
                $stmtRencIns->execute([
                    $r['date'], $r['heure'], $idDiv, $data['poule'],
                    $idDom, $idExt, $data['phase'], $data['saison'], $r['journee'],
                    $arbitrageObligatoire
                ]);
                $stats['rencontres_creees']++;
            }
        }

        echo json_encode(['ok' => true, 'stats' => $stats]);
        exit;
    }

    echo json_encode(['ok' => false, 'err' => 'Action inconnue']);
    exit;
}

$u           = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$isAdmin     = !empty($u['is_admin']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Import Rencontres (E011)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }

        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; }


        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        #content { padding: 1.25rem; }
        .fichier-card {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 8px; padding: .85rem 1rem; margin-bottom: .65rem;
            display: flex; align-items: center; gap: .75rem;
        }
        .fichier-card .fc-nom { flex: 1; font-weight: 600; font-size: .95rem; }
        .fichier-card .fc-badge {
            background: #1a3a6b; color: #fff;
            border-radius: 12px; padding: .15rem .55rem; font-size: .78rem;
        }
        .fichier-card.fc-importe {
            background: #f0f7f1; border-color: #9fcdab;
        }
        .fichier-card .fc-importe-badge {
            background: #1a7f4b; color: #fff;
            border-radius: 12px; padding: .15rem .55rem; font-size: .76rem;
            white-space: nowrap;
        }
        /* Aperçu */
        #apercu-zone { display: none; margin-top: 1rem; }
        .poule-header {
            background: #1a3a6b; color: #fff;
            padding: .4rem .75rem; border-radius: 6px 6px 0 0;
            font-weight: 700; font-size: .88rem; margin-top: .75rem;
        }
        .poule-body {
            border: 1px solid #b0bcd0; border-top: none;
            border-radius: 0 0 6px 6px; padding: .5rem;
            background: #fff; margin-bottom: .5rem;
        }
        .poule-clubs { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .4rem; }
        .club-pill {
            background: #e8f0fe; border: 1px solid #c5d0ec;
            border-radius: 12px; padding: .1rem .55rem; font-size: .8rem;
        }
        table.tbl-renc { width: 100%; border-collapse: collapse; font-size: .82rem; }
        table.tbl-renc th { background: #e8eef7; padding: .2rem .4rem; text-align: left; }
        table.tbl-renc td { padding: .18rem .4rem; border-bottom: 1px solid #eee; }
        /* Résultat import */
        #result-zone { display: none; margin-top: 1rem; }
        .stat-box {
            display: inline-block; text-align: center;
            min-width: 110px; background: #fff;
            border: 2px solid #b0bcd0; border-radius: 8px;
            padding: .5rem .75rem; margin-right: .5rem; margin-bottom: .5rem;
        }
        .stat-box .sv { font-size: 1.6rem; font-weight: 700; color: #1a3a6b; }
        .stat-box .sl { font-size: .75rem; color: #555; }
        /* Zone d'ajout de fichiers */
        #dropzone {
            border: 2px dashed #b0bcd0; border-radius: 8px;
            padding: 1rem; text-align: center; color: #6b7280;
            background: #fff; margin-bottom: 1rem; cursor: pointer;
            transition: background .15s, border-color .15s;
        }
        #dropzone:hover, #dropzone.dz-over { background: #eef3fb; border-color: #1a3a6b; }
        .fc-btn-supprimer {
            color: #b02a37; background: none; border: none;
            font-size: 1.05rem; line-height: 1; padding: .15rem .3rem;
        }
        .fc-btn-supprimer:hover { color: #fff; background: #dc3545; border-radius: 4px; }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Import des rencontres (XLS)
    <small class="opacity-75 ms-2">(E011)</small>
    <a href="<?= $isAdmin ? 'admin_menu.php' : 'Nominateur/menu.php' ?>" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<div id="content">

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <h5 class="mb-0">Fichiers disponibles</h5>
        <button class="btn btn-sm btn-outline-primary" id="btn-refresh">
            <i class="bi bi-arrow-clockwise"></i> Actualiser
        </button>
        <span class="text-muted" style="font-size:.82rem;">
            <i class="bi bi-folder2-open me-1"></i>
<?php
                $cheminComplet = str_replace('\\', '/', __DIR__ . '/Importation/Rencontres/');
                $posNijac      = strripos($cheminComplet, '/nijac/');
                $cheminAffiche = $posNijac !== false ? substr($cheminComplet, $posNijac + 1) : $cheminComplet;
            ?>
            <code><?= htmlspecialchars($cheminAffiche) ?></code>
            &mdash; fichiers <code>*.xls</code>
        </span>
    </div>
    <!-- Zone d'ajout de fichiers -->
    <div id="dropzone">
        <i class="bi bi-cloud-arrow-up fs-3 d-block mb-1"></i>
        Cliquez ou déposez ici des fichiers <code>.xls</code> à ajouter
        <input type="file" id="input-upload" accept=".xls" multiple hidden>
    </div>
    <div id="upload-status" class="mb-3"></div>

    <p class="text-muted mb-3" style="font-size:.82rem;">
        <i class="bi bi-info-circle me-1"></i>
        Les fichiers PDF de base ont été convertis en Excel grâce à
        <a href="https://www.pdfgear.com/fr/" target="_blank" rel="noopener">PDFGear</a>.
    </p>

    <div id="liste-fichiers">
        <div class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Chargement…</div>
    </div>

    <!-- Aperçu -->
    <div id="apercu-zone">
        <hr>
        <div class="d-flex align-items-center gap-2 mb-2">
            <h6 class="mb-0" id="apercu-titre">Aperçu</h6>
            <button class="btn btn-success btn-sm" id="btn-importer">
                <i class="bi bi-cloud-upload me-1"></i>Importer en base
            </button>
            <span id="apercu-spinner" class="spinner-border spinner-border-sm text-success d-none"></span>
        </div>
        <div id="apercu-content"></div>
    </div>

    <!-- Résultat -->
    <div id="result-zone">
        <hr>
        <h6>Résultat de l'import</h6>
        <div id="result-content"></div>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let fichierEnCours = null;

// ── Chargement de la liste ─────────────────────────────────────────────────
function chargerListe() {
    $('#liste-fichiers').html('<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Chargement…</span>');
    $.getJSON('import_rencontres.php?action=liste', function (r) {
        if (!r.ok) { $('#liste-fichiers').html('<div class="text-danger">Erreur chargement</div>'); return; }
        if (!r.fichiers.length) {
            $('#liste-fichiers').html('<div class="text-muted">Aucun fichier XLS trouvé dans Importation/Rencontres/</div>');
            return;
        }
        let html = '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;">';
        r.fichiers.forEach(function (f) {
            const importeBadge = f.importe
                ? `<span class="fc-importe-badge">
                       <i class="bi bi-check-circle-fill me-1"></i>Déjà importé
                   </span>`
                : '';
            html += `<div class="fichier-card${f.importe ? ' fc-importe' : ''}">
                <i class="bi bi-file-earmark-excel text-success fs-4"></i>
                <span class="fc-nom">${f.nom}</span>
                <span class="fc-badge">${f.feuilles} poule${f.feuilles > 1 ? 's' : ''}</span>
                ${importeBadge}
                <button class="btn btn-sm btn-outline-primary btn-apercu" data-nom="${f.nom}">
                    <i class="bi bi-eye me-1"></i>Aperçu
                </button>
                <button class="fc-btn-supprimer btn-supprimer-fichier" data-nom="${f.nom}" title="Supprimer le fichier">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>`;
        });
        html += '</div>';
        $('#liste-fichiers').html(html);
    });
}

// ── Ajout de fichiers ─────────────────────────────────────────────────────
$('#dropzone').on('click', () => $('#input-upload').trigger('click'));
$('#dropzone').on('dragover', function (e) { e.preventDefault(); $(this).addClass('dz-over'); });
$('#dropzone').on('dragleave', function () { $(this).removeClass('dz-over'); });
$('#dropzone').on('drop', function (e) {
    e.preventDefault();
    $(this).removeClass('dz-over');
    televerser(e.originalEvent.dataTransfer.files);
});
$('#input-upload').on('change', function () {
    televerser(this.files);
    this.value = '';
});

function televerser(fileList) {
    if (!fileList || !fileList.length) return;
    const fd = new FormData();
    for (const f of fileList) fd.append('fichiers[]', f);

    $('#upload-status').html('<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Envoi en cours…</span>');

    $.ajax({
        url: 'import_rencontres.php?action=upload',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
    }).done(function (r) {
        if (!r.ok) { $('#upload-status').html('<div class="text-danger">' + (r.err || 'Erreur') + '</div>'); return; }
        let html = '';
        r.resultats.forEach(function (res) {
            html += res.ok
                ? `<div class="text-success"><i class="bi bi-check-circle me-1"></i>${res.nom} ajouté.</div>`
                : `<div class="text-danger"><i class="bi bi-x-circle me-1"></i>${res.nom} — ${res.msg}</div>`;
        });
        $('#upload-status').html(html);
        chargerListe();
    }).fail(function () {
        $('#upload-status').html('<div class="text-danger">Erreur réseau lors de l\'envoi.</div>');
    });
}

// ── Suppression d'un fichier ─────────────────────────────────────────────
$(document).on('click', '.btn-supprimer-fichier', function () {
    const nom = $(this).data('nom');
    if (!confirm('Supprimer le fichier "' + nom + '" ?\n(Cette action est irréversible.)')) return;

    $.post('import_rencontres.php?action=supprimer', { fichier: nom }, function (r) {
        if (!r.ok) { toast_err(r.err || 'Erreur lors de la suppression.'); return; }
        chargerListe();
    }, 'json').fail(function () {
        toast_err('Erreur réseau lors de la suppression.');
    });
});

function toast_err(msg) {
    $('#upload-status').html('<div class="text-danger"><i class="bi bi-x-circle me-1"></i>' + msg + '</div>');
}

// ── Aperçu ─────────────────────────────────────────────────────────────────
$(document).on('click', '.btn-apercu', function () {
    fichierEnCours = $(this).data('nom');
    $('#apercu-zone').hide();
    $('#result-zone').hide();
    $('#apercu-titre').text('Aperçu — ' + fichierEnCours);
    $('#apercu-content').html('<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Analyse…</span>');
    $('#apercu-zone').show();
    $('html, body').animate({ scrollTop: $('#apercu-zone').offset().top - 10 }, 200);

    $.getJSON('import_rencontres.php?action=apercu&fichier=' + encodeURIComponent(fichierEnCours), function (r) {
        if (!r.ok) { $('#apercu-content').html('<div class="text-danger">Erreur : ' + r.err + '</div>'); return; }
        let html = '';
        r.poules.forEach(function (p, i) {
            const divLabel = p.id_division ? `Division ID ${p.id_division}` : '<span class="text-danger">Division inconnue !</span>';
            html += `<div class="poule-header">
                Feuille ${i+1} — ${p.categorie} ${p.division} Poule ${p.poule} Phase ${p.phase}
                — ${p.saison} — Secteur ${p.secteur} — ${divLabel}
            </div>
            <div class="poule-body">
                <div class="poule-clubs">`;
            Object.values(p.clubs).forEach(function (c) {
                html += `<span class="club-pill" title="N°${c.id_club}">${c.nom}</span>`;
            });
            html += `</div>
                <strong>${p.rencontres.length} rencontres</strong>
                <table class="tbl-renc mt-1">
                    <tr><th>J.</th><th>Date</th><th>Heure</th><th>Domicile</th><th>Extérieur</th></tr>`;
            p.rencontres.forEach(function (r) {
                html += `<tr>
                    <td>${r.journee}</td>
                    <td>${r.date}</td>
                    <td>${r.heure}</td>
                    <td>${r.equipe_dom}</td>
                    <td>${r.equipe_ext}</td>
                </tr>`;
            });
            html += `</table></div>`;
        });
        $('#apercu-content').html(html);
    });
});

// ── Import ─────────────────────────────────────────────────────────────────
$('#btn-importer').on('click', function () {
    if (!fichierEnCours) return;
    if (!confirm('Importer "' + fichierEnCours + '" en base de données ?\n(Les doublons seront ignorés.)')) return;
    $('#apercu-spinner').removeClass('d-none');
    $('#btn-importer').prop('disabled', true);

    $.post('import_rencontres.php?action=importer', { fichier: fichierEnCours }, function (r) {
        $('#apercu-spinner').addClass('d-none');
        $('#btn-importer').prop('disabled', false);
        $('#result-zone').show();
        $('html, body').animate({ scrollTop: $('#result-zone').offset().top - 10 }, 200);

        if (!r.ok) {
            $('#result-content').html('<div class="alert alert-danger">' + r.err + '</div>');
            return;
        }
        const s = r.stats;
        let html = `
            <div class="mb-2">
                <div class="stat-box"><div class="sv text-success">${s.equipes_creees}</div><div class="sl">Équipes créées</div></div>
                <div class="stat-box"><div class="sv text-primary">${s.rencontres_creees}</div><div class="sl">Rencontres créées</div></div>
                <div class="stat-box"><div class="sv text-secondary">${s.doublons}</div><div class="sl">Doublons ignorés</div></div>
            </div>`;
        if (s.erreurs && s.erreurs.length) {
            html += '<div class="alert alert-warning mt-2"><strong>Avertissements :</strong><ul class="mb-0">';
            s.erreurs.forEach(e => html += '<li>' + e + '</li>');
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success">Import terminé sans erreur.</div>';
        }
        $('#result-content').html(html);
        chargerListe();
    }, 'json').fail(function () {
        $('#apercu-spinner').addClass('d-none');
        $('#btn-importer').prop('disabled', false);
        $('#result-content').html('<div class="alert alert-danger">Erreur serveur.</div>');
        $('#result-zone').show();
    });
});

$('#btn-refresh').on('click', chargerListe);

// Init
chargerListe();
</script>
</body>
</html>
