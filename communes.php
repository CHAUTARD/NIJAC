<?php
/**
 * NIJAC – Gestion des communes / La Poste (E006)
 *
 * Référentiel des codes postaux et communes (table laposte) avec coordonnées
 * GPS (latitude / longitude). Utilisé pour calculer les distances domicile-salle
 * lors de la nomination des JA et pour alimenter les sélecteurs d'adresse.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getPDO();

        // ── Charger la liste (recherche + pagination) ──────────────────────
        if ($action === 'liste') {
            $q      = trim($_GET['q'] ?? $_POST['q'] ?? '');
            $offset = max(0, (int)($_GET['offset'] ?? $_POST['offset'] ?? 0));
            $limit  = 500;

            if ($q !== '') {
                $like  = '%' . $q . '%';
                $total = (int)$pdo->prepare(
                    'SELECT COUNT(*) FROM laposte WHERE CodePostal LIKE ? OR Nom LIKE ?'
                )->execute([$like, $like]) && false ?: (function() use ($pdo, $like) {
                    $s = $pdo->prepare('SELECT COUNT(*) FROM laposte WHERE CodePostal LIKE ? OR Nom LIKE ?');
                    $s->execute([$like, $like]);
                    return (int)$s->fetchColumn();
                })();
                $stmt = $pdo->prepare(
                    'SELECT Id_LaPoste, Nom, CodePostal, Latitude, Longitude, Id_Departement
                     FROM laposte WHERE CodePostal LIKE ? OR Nom LIKE ?
                     ORDER BY CodePostal, Nom LIMIT ? OFFSET ?'
                );
                $stmt->execute([$like, $like, $limit, $offset]);
            } else {
                $countStmt = $pdo->query('SELECT COUNT(*) FROM laposte');
                $total     = (int)$countStmt->fetchColumn();
                $stmt      = $pdo->prepare(
                    'SELECT Id_LaPoste, Nom, CodePostal, Latitude, Longitude, Id_Departement
                     FROM laposte ORDER BY CodePostal, Nom LIMIT ? OFFSET ?'
                );
                $stmt->execute([$limit, $offset]);
            }

            $rows = $stmt->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
            exit;
        }

        // ── Importer CSV ───────────────────────────────────────────────────
        if ($action === 'importer_csv') {
            if (empty($_FILES['fichier'])) {
                // post_max_size dépassé : $_FILES est vide mais $_SERVER contient la taille
                $postMax = ini_get('post_max_size');
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => "Aucun fichier reçu. Vérifiez que post_max_size ($postMax) n'est pas dépassé."]);
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
                $msg  = $uploadErrors[$code] ?? "Erreur upload inconnue (code $code).";
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => $msg]);
                exit;
            }

            $ext = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
            $extsAutorisees = ['csv', '001', '002', '003', '004', '005'];
            if (!in_array($ext, $extsAutorisees, true)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => "Extension « .$ext » non acceptée."]);
                exit;
            }

            $vider     = !empty($_POST['vider']);
            $hasHeader = !isset($_POST['has_header']) || !empty($_POST['has_header']);

            $fh = fopen($_FILES['fichier']['tmp_name'], 'r');
            if ($fh === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
                exit;
            }

            // Détection du séparateur (virgule ou point-virgule)
            $firstLine = fgets($fh);
            rewind($fh);
            $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

            if ($hasHeader) {
                // Lire et analyser l'en-tête
                $header = fgetcsv($fh, 0, $sep);
                if ($header === false) {
                    fclose($fh);
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => 'Fichier vide ou illisible.']);
                    exit;
                }
                $header = array_map('trim', $header);

                $required = ['zip_code', 'label', 'latitude', 'longitude'];
                $colIdx   = array_flip($header);
                foreach ($required as $col) {
                    if (!isset($colIdx[$col])) {
                        fclose($fh);
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'msg' => "Colonne manquante : « $col »."]);
                        exit;
                    }
                }
                $iInsee = $colIdx['insee_code'] ?? 0;
                $iZip   = $colIdx['zip_code'];
                $iLabel = $colIdx['label'];
                $iLat   = $colIdx['latitude'];
                $iLon   = $colIdx['longitude'];
                $iDept  = $colIdx['department_number'] ?? null;
            } else {
                // insee_code,city_code,zip_code,label,latitude,longitude,department_name,department_number,...
                $iInsee = 0;
                $iZip   = 2;
                $iLabel = 3;
                $iLat   = 4;
                $iLon   = 5;
                $iDept  = 7;
            }

            // $deptAutorises = ['14', '27', '50', '61', '76'];

            set_time_limit(300);

            $pdo->beginTransaction();
            try {
                if ($vider) {
                    $pdo->exec('DELETE FROM laposte');
                }

                $stmtIns = $pdo->prepare(
                    'INSERT INTO laposte (Id_LaPoste, Nom, CodePostal, Latitude, Longitude, Id_Departement)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmtUpd = $pdo->prepare(
                    'UPDATE laposte SET Nom=?, CodePostal=?, Latitude=?, Longitude=?, Id_Departement=?
                     WHERE Id_LaPoste=?'
                );
                $stmtChk = $pdo->prepare(
                    'SELECT Id_LaPoste FROM laposte WHERE Id_LaPoste=? LIMIT 1'
                );

                $inserts  = 0;
                $updates  = 0;
                $ignores  = 0;
                $erreurs  = [];

                while (($row = fgetcsv($fh, 0, $sep)) !== false) {
                    if (count($row) <= $iLabel) { $ignores++; continue; }

                    $insee   = (int)trim($row[$iInsee] ?? '0');
                    $cp      = trim($row[$iZip]       ?? '');
                    $nom     = mb_strtoupper(trim($row[$iLabel] ?? ''), 'UTF-8');
                    $lat     = (float)str_replace(',', '.', trim($row[$iLat] ?? '0'));
                    $lon     = (float)str_replace(',', '.', trim($row[$iLon] ?? '0'));
                    $deptVal = $iDept !== null && isset($row[$iDept])
                               ? ltrim(trim($row[$iDept]), '0')
                               : null;
                    $dept    = $deptVal !== null && $deptVal !== '' ? (int)$deptVal : null;

                    if ($insee === 0 || $cp === '' || $nom === '') { $ignores++; continue; }

                    /* Filtrer uniquement les départements autorisés
                    // Si colonne absente ($deptVal === null) → on laisse passer
                    if ($deptVal !== null && !in_array($deptVal, $deptAutorises, true)) {
                        $ignores++;
                        continue;
                    }
                    */

                    if (!$vider) {
                        $stmtChk->execute([$insee]);
                        $existing = $stmtChk->fetchColumn();
                        if ($existing !== false) {
                            $stmtUpd->execute([$nom, $cp, $lat, $lon, $dept, $insee]);
                            $updates++;
                            continue;
                        }
                    }

                    try {
                        $stmtIns->execute([$insee, $nom, $cp, $lat, $lon, $dept]);
                        $inserts++;
                    } catch (PDOException $ex) {
                        $erreurs[] = "$cp $nom (insee=$insee) : " . $ex->getMessage();
                        if (count($erreurs) >= 10) { $ignores++; continue; }
                    }
                }

                $pdo->commit();
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }

            fclose($fh);

            $msg = "$inserts insérée(s), $updates mise(s) à jour, $ignores ignorée(s).";
            if ($erreurs) $msg .= ' Premières erreurs : ' . implode(' | ', array_slice($erreurs, 0, 3));
            ob_end_clean();
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg, 'inserts' => $inserts, 'updates' => $updates, 'ignores' => $ignores]);
            exit;
        }

        // ── Exporter CSV ───────────────────────────────────────────────────
        if ($action === 'exporter_csv') {
            ob_end_clean();

            $rows = $pdo->query(
                'SELECT Id_LaPoste, Nom, CodePostal, Latitude, Longitude, Id_Departement
                 FROM laposte ORDER BY CodePostal, Nom'
            )->fetchAll();

            $filename = 'communes_export_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store');

            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel
            fwrite($out, "\xEF\xBB\xBF");
            // En-tête identique au format d'import
            fputcsv($out, ['insee_code','city_code','zip_code','label','latitude','longitude',
                           'department_name','department_number','region_name','region_geojson_name']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['Id_LaPoste'],   // insee_code
                    '',                  // city_code (non stocké)
                    $r['CodePostal'],
                    $r['Nom'],
                    $r['Latitude'],
                    $r['Longitude'],
                    '',                  // department_name (non stocké)
                    $r['Id_Departement'] ?? '',
                    '',                  // region_name (non stocké)
                    '',                  // region_geojson_name (non stocké)
                ]);
            }
            fclose($out);
            exit;
        }

        // ── Compter ────────────────────────────────────────────────────────
        if ($action === 'compter') {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM laposte')->fetchColumn();
            ob_end_clean();
            echo json_encode(['ok' => true, 'count' => $n]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] communes.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] communes.php : ' . $e->getMessage());
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Communes / La Poste (E006)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

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

        /* ── Toolbar ── */
        #toolbar {
            background: #c0ffff;
            border-bottom: 1px solid #90cccc;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            flex-shrink: 0;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center; gap: .35rem;
            color: #c00; font-weight: 700;
            cursor: pointer; text-decoration: underline dotted;
        }
        #toolbar .ts-screen-id {
            font-size: .78rem; font-weight: 700;
            color: #1a3a6b; background: #ddeeff;
            padding: .1rem .45rem; border-radius: 4px;
            border: 1px solid #99bbdd; letter-spacing: .03em;
        }

        /* ── MenuStrip ── */
        #menu-strip {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: .25rem .75rem;
            display: flex;
            align-items: center;
            gap: .25rem;
            flex-shrink: 0;
        }
        .menu-item {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .25rem .75rem;
            font-size: .85rem;
            border: 1px solid transparent;
            border-radius: 4px;
            background: none;
            cursor: pointer;
            white-space: nowrap;
            color: #212529;
        }
        .menu-item:hover { background: #e8eef7; border-color: #c8d4e8; }
        .menu-item img { width: 18px; height: 18px; object-fit: contain; }

        /* ── Option import ── */
        #import-options {
            display: none;
            align-items: center;
            gap: .5rem;
            padding: .15rem .5rem;
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 4px;
            font-size: .82rem;
        }
        #import-options.show { display: inline-flex; }

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 250px;
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

        #tbl-communes {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 600px;
        }

        #tbl-communes thead th {
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
        #tbl-communes thead th:hover { background: #d4dff0; }
        #tbl-communes thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-communes thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-communes thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-communes thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-communes tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-communes tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-communes tbody tr:hover { background: #dce8f8; }

        #tbl-communes tbody td {
            border: 1px solid #e0e8f0;
            padding: .28rem .5rem;
            white-space: nowrap;
        }
        td.col-id { color: #6b7280; font-style: italic; background: #f0f4fa; }

        /* ── Pagination ── */
        #pagination-bar {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .25rem 1rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .82rem;
            flex-shrink: 0;
        }
        #pagination-bar button {
            padding: .15rem .5rem;
            font-size: .82rem;
            border: 1px solid #c8d4e8;
            border-radius: 3px;
            background: #fff;
            cursor: pointer;
        }
        #pagination-bar button:disabled { opacity: .4; cursor: default; }
        #pagination-bar button:not(:disabled):hover { background: #e8eef7; }

        /* ── Barre d'état ── */
        #status-bar {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            color: #374151;
            flex-shrink: 0;
            min-height: 26px;
        }

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

        /* ── Barre de progression import ── */
        #import-progress {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #import-progress.show { display: flex; }
        #import-progress .box {
            background: #fff;
            border-radius: 8px;
            padding: 2rem 2.5rem;
            min-width: 340px;
            text-align: center;
        }
        #import-progress .box p { margin: .5rem 0 1rem; font-size: .9rem; color: #374151; }
    </style>
</head>
<body>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- Overlay import -->
<div id="import-progress">
    <div class="box">
        <div class="spinner-border text-primary mb-2" style="width:2.5rem;height:2.5rem;"></div>
        <p id="import-msg">Import en cours, veuillez patienter…</p>
        <div class="progress mb-2" style="height:8px;">
            <div id="progress-bar-file" class="progress-bar bg-primary" style="width:0%;transition:width .3s;"></div>
        </div>
        <small id="import-detail" class="text-muted"></small>
    </div>
</div>

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-importer">
        <img src="img/Importer_32.png" alt="">Importation CSV
    </button>
    <button class="menu-item" id="btn-exporter">
        <img src="img/Exporter_32.png" alt="">Exportation CSV
    </button>
    <div id="import-options">
        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
            <input type="checkbox" id="chk-vider"> Vider la table avant import
        </label>
        <button class="menu-item" id="btn-confirmer-import" style="background:#d4edda;border-color:#c3e6cb;">
            <i class="bi bi-check-lg"></i> Confirmer
        </button>
        <button class="menu-item" id="btn-annuler-import" style="background:#f8d7da;border-color:#f5c6cb;">
            <i class="bi bi-x-lg"></i> Annuler
        </button>
    </div>
    <input type="file" id="file-input" accept=".csv,.001,.002,.003,.004,.005" multiple style="display:none">
    <span style="flex:1"></span>
    <input type="search" id="search-input" placeholder="🔍 Code postal ou commune…">
</div>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-mailbox2 me-2"></i>Gestion des communes (La Poste)
    <small class="opacity-75 ms-2">(E006)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-communes">
        <thead>
            <tr>
                <th style="width:70px"  data-field="Id_LaPoste">N°<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="CodePostal">Code postal<span class="sort-icon"></span></th>
                <th style="width:280px" data-field="Nom">Commune<span class="sort-icon"></span></th>
                <th style="width:110px" data-field="Latitude">Latitude<span class="sort-icon"></span></th>
                <th style="width:110px" data-field="Longitude">Longitude<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="Id_Departement">Dép.<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div id="pagination-bar">
    <button id="btn-prev" disabled>&#8592; Précédent</button>
    <span id="page-info">—</span>
    <button id="btn-next" disabled>Suivant &#8594;</button>
</div>

<!-- Barre d'état -->
<div id="status-bar">Prêt.</div>

<!-- Toast -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let lignes      = [];
let totalRows   = 0;
let currentOffset = 0;
const PAGE_SIZE   = 500;
let sortField   = 'CodePostal';
let sortDir     = 'asc';
let searchTerm  = '';
let searchTimer = null;
let fichiersCSV = [];    // Files en attente de confirmation

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }
function importProgress(show, msg) {
    $('#import-progress').toggleClass('show', show);
    if (msg) $('#import-msg').text(msg);
}

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
    setTimeout(() => $(`#${id}`).remove(), 5000);
}

// ── Tri local (sur la page courante) ─────────────────────────────────────────
function lignesTriees() {
    const numFields = ['Id_LaPoste', 'Id_Departement', 'Latitude', 'Longitude'];
    return [...lignes].sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
}

function majEnteteTri() {
    $('#tbl-communes thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f === sortField) $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    majEnteteTri();

    const affichees = lignesTriees();

    if (!affichees.length) {
        $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucun résultat.</td></tr>');
        return;
    }

    affichees.forEach(r => {
        $body.append(
            `<tr>
                <td class="col-id">${String(r.Id_LaPoste).padStart(5, '0')}</td>
                <td>${r.CodePostal ?? ''}</td>
                <td>${r.Nom ?? ''}</td>
                <td>${r.Latitude ?? ''}</td>
                <td>${r.Longitude ?? ''}</td>
                <td>${r.Id_Departement ?? ''}</td>
             </tr>`
        );
    });
}

function majPagination() {
    const debut = currentOffset + 1;
    const fin   = Math.min(currentOffset + lignes.length, totalRows);
    const total = totalRows.toLocaleString('fr-FR');

    $('#page-info').text(`${debut.toLocaleString('fr-FR')}–${fin.toLocaleString('fr-FR')} sur ${total}`);
    $('#btn-prev').prop('disabled', currentOffset === 0);
    $('#btn-next').prop('disabled', currentOffset + PAGE_SIZE >= totalRows);

    const info = searchTerm
        ? `${totalRows.toLocaleString('fr-FR')} résultat(s) pour « ${searchTerm} »`
        : `${totalRows.toLocaleString('fr-FR')} commune(s) en base`;
    setStatus(info + `. Page ${Math.floor(currentOffset / PAGE_SIZE) + 1} / ${Math.ceil(totalRows / PAGE_SIZE) || 1}.`);
}

// ── Charger ───────────────────────────────────────────────────────────────────
function chargerListe(offset = 0) {
    spinner(true);
    currentOffset = offset;
    $.post('communes.php', { action: 'liste', q: searchTerm, offset }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes    = res.data;
        totalRows = res.total;
        renderGrille();
        majPagination();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-communes thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    sortField = (sortField === f) ? sortField : f;
    sortDir   = (sortField === f && sortDir === 'asc') ? 'desc' : (sortField !== f ? 'asc' : sortDir === 'asc' ? 'desc' : 'asc');
    sortField = f;
    renderGrille();
    majEnteteTri();
});

// ── Recherche (debounce 400 ms, serveur) ──────────────────────────────────────
$('#search-input').on('input', function () {
    clearTimeout(searchTimer);
    const val = $(this).val().trim();
    searchTimer = setTimeout(() => {
        searchTerm = val;
        chargerListe(0);
    }, 400);
});

// ── Pagination ────────────────────────────────────────────────────────────────
$('#btn-prev').on('click', () => chargerListe(Math.max(0, currentOffset - PAGE_SIZE)));
$('#btn-next').on('click', () => chargerListe(currentOffset + PAGE_SIZE));

// ── Export CSV ────────────────────────────────────────────────────────────────
$('#btn-exporter').on('click', function () {
    window.location = 'communes.php?action=exporter_csv';
});

// ── Import CSV — étape 1 : sélection fichier ──────────────────────────────────
$('#btn-importer').on('click', () => $('#file-input').trigger('click'));

$('#file-input').on('change', function () {
    if (!this.files.length) return;
    // Trier par nom pour garantir l'ordre .001 → .002 → .003
    fichiersCSV = Array.from(this.files).sort((a, b) => a.name.localeCompare(b.name));
    const noms  = fichiersCSV.map(f => f.name).join(', ');
    $('#import-options').addClass('show');
    setStatus(`${fichiersCSV.length} fichier(s) sélectionné(s) : ${noms}`);
    this.value = '';
});

$('#btn-annuler-import').on('click', function () {
    fichiersCSV = [];
    $('#import-options').removeClass('show');
    setStatus('Import annulé.');
});

// ── Import CSV — étape 2 : confirmation + envoi séquentiel ────────────────────
$('#btn-confirmer-import').on('click', function () {
    if (!fichiersCSV.length) return;

    const vider = $('#chk-vider').is(':checked');
    if (vider && !confirm('Vider toute la table laposte avant import ?\n\nCette opération est irréversible.')) return;

    $('#import-options').removeClass('show');

    const files   = [...fichiersCSV];
    fichiersCSV   = [];
    let idx       = 0;
    let totalIns  = 0;
    let totalUpd  = 0;
    const erreurs = [];

    importProgress(true);

    function envoyerFichier() {
        if (idx >= files.length) {
            importProgress(false);
            const msg = `Import terminé : ${totalIns} insérée(s), ${totalUpd} mise(s) à jour.`
                      + (erreurs.length ? ' Erreurs : ' + erreurs.join(' | ') : '');
            toast(msg, !erreurs.length);
            chargerListe(0);
            return;
        }

        const file      = files[idx];
        const isFirst   = idx === 0;
        const pct       = Math.round((idx / files.length) * 100);

        $('#progress-bar-file').css('width', pct + '%');
        $('#import-msg').text(`Fichier ${idx + 1} / ${files.length} : ${file.name}`);
        $('#import-detail').text('Envoi en cours…');

        const fd = new FormData();
        fd.append('action',     'importer_csv');
        fd.append('fichier',    file);
        fd.append('has_header', '1');
        if (isFirst && vider) fd.append('vider', '1');

        $.ajax({
            url: 'communes.php', type: 'POST',
            data: fd, processData: false, contentType: false, dataType: 'json',
            timeout: 300000,
            success(res) {
                if (res.ok) {
                    totalIns += res.inserts ?? 0;
                    totalUpd += res.updates ?? 0;
                    const ign = res.ignores ?? 0;
                    $('#import-detail').text(`✔ ${res.inserts ?? 0} insérée(s), ${res.updates ?? 0} mise(s) à jour, ${ign} ignorée(s)`);
                } else {
                    erreurs.push(`${file.name} : ${res.msg}`);
                    $('#import-detail').text(`✖ ${res.msg}`);
                }
                idx++;
                setTimeout(envoyerFichier, 200);
            },
            error(xhr) {
                const msg = xhr.responseJSON?.msg ?? 'Erreur réseau.';
                erreurs.push(`${file.name} : ${msg}`);
                idx++;
                setTimeout(envoyerFichier, 200);
            }
        });
    }

    envoyerFichier();
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    // Corriger le gestionnaire de tri (réécriture propre)
    $('#tbl-communes thead th[data-field]').off('click').on('click', function () {
        const f = $(this).data('field');
        if (sortField === f) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortField = f;
            sortDir   = 'asc';
        }
        renderGrille();
        majEnteteTri();
    });

    chargerListe(0);
});
</script>
</body>
</html>
