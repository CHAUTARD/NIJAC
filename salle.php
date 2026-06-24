<?php
/**
 * NIJAC – Gestion des salles (E005)
 *
 * Référencement des salles de compétition avec leur adresse, code postal/ville
 * et rattachement à un club. Permet de désigner la salle principale d'un club.
 * Les coordonnées GPS sont récupérées via la table laposte.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur'])) {
    header('Location: index.php');
    exit;
}
$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $sql = 'SELECT s.Id_Salle, s.Nom, s.Adresse, s.Id_Laposte, s.Id_Club,
                           s.EstPrincipale, cl.Nom AS NomClub,
                           CONCAT(lp.CodePostal, \' \', lp.Nom) AS CpVille
                    FROM Salle s
                    LEFT JOIN Club    cl ON cl.Id_Club    = s.Id_Club
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte';
            $params = [];

            if (!$isAdmin) {
                // Nominateur : restreint à son département + ceux associés (configuration.php)
                $depts = getDepartementsAutorises($moi['id_departement'] ?? null);
                if (!$depts) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'data' => []]);
                    exit;
                }
                $deptPh = implode(',', array_fill(0, count($depts), '?'));
                $sql .= " WHERE LEFT(lp.CodePostal, 2) IN ($deptPh)";
                foreach ($depts as $d) $params[] = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            } elseif (isset($_POST['dept']) && $_POST['dept'] !== '') {
                // Administrateur avec filtre optionnel (département exact, sans association)
                $sql .= ' WHERE LEFT(lp.CodePostal, 2) = ?';
                $params[] = str_pad((string)$_POST['dept'], 2, '0', STR_PAD_LEFT);
            }
            $sql .= ' ORDER BY cl.Nom, s.Nom';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Max Id_Salle actuel ────────────────────────────────────────────
        if ($action === 'max_id') {
            $max = (int)$pdo->query('SELECT COALESCE(MAX(Id_Salle), 0) FROM Salle')->fetchColumn();
            ob_end_clean();
            echo json_encode(['ok' => true, 'max' => $max]);
            exit;
        }

        // ── Liste clubs pour le sélecteur ──────────────────────────────────
        if ($action === 'liste_clubs') {
            $rows = $pdo->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom')->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Actions réservées aux administrateurs ──────────────────────────
        if (!$isAdmin && in_array($action, ['importer_excel', 'sauvegarder', 'supprimer'], true)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'msg' => 'Accès refusé.']);
            exit;
        }

        // ── Importer Excel ─────────────────────────────────────────────────
        if ($action === 'importer_excel') {
            if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
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

            // Préparer le lookup LaPoste (CodePostal + Nom → Id_LaPoste)
            $stmtLaposte = $pdo->prepare(
                'SELECT Id_LaPoste FROM laposte WHERE CodePostal = ? AND Nom = ? LIMIT 1'
            );
            $stmtLaposteCp = $pdo->prepare(
                'SELECT Id_LaPoste FROM laposte WHERE CodePostal = ? LIMIT 1'
            );

            $lignes      = [];
            $clubsVus    = []; // suivi : premier club → salle principale
            // Colonnes : A=Id_Club, AN=Nom, AP+AQ=Adresse, AR=CodePostal, AS=Ville
            for ($row = 3; $row <= $maxRow; $row++) {
                $idClub  = trim((string)$sheet->getCell('A'  . $row)->getValue());
                $nom     = trim((string)$sheet->getCell('AN' . $row)->getValue());
                $adr1    = trim((string)$sheet->getCell('AP' . $row)->getValue());
                $adr2    = trim((string)$sheet->getCell('AQ' . $row)->getValue());
                $cp      = trim((string)$sheet->getCell('AR' . $row)->getValue());
                $ville   = trim((string)$sheet->getCell('AS' . $row)->getValue());

                // Pas d'enregistrement vide
                if ($nom === '') continue;

                // Concaténer adresse
                $adresse = trim($adr1 . ($adr1 !== '' && $adr2 !== '' ? ' ' : '') . $adr2) ?: null;

                // Résoudre Id_LaPoste
                $idLaposte = null;
                if ($cp !== '') {
                    $stmtLaposte->execute([$cp, mb_strtoupper($ville, 'UTF-8')]);
                    $found = $stmtLaposte->fetchColumn();
                    if ($found === false) {
                        $stmtLaposteCp->execute([$cp]);
                        $found = $stmtLaposteCp->fetchColumn();
                    }
                    $idLaposte = $found !== false ? (int)$found : null;
                }

                $idClubInt = $idClub !== '' ? (int)$idClub : null;

                // Première salle trouvée pour ce club = principale
                $estPrincipale = 0;
                if ($idClubInt !== null) {
                    if (!isset($clubsVus[$idClubInt])) {
                        $clubsVus[$idClubInt] = true;
                        $estPrincipale = 1;
                    }
                }

                $lignes[] = [
                    'id_salle'      => 0,
                    'nom'           => mb_strtoupper($nom, 'UTF-8'),
                    'adresse'       => $adresse,
                    'id_laposte'    => $idLaposte,
                    'cp_ville'      => trim("$cp $ville"),
                    'id_club'       => $idClubInt,
                    'nom_club'      => '',
                    'est_principale'=> $estPrincipale,
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

        // ── Sauvegarder (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'sauvegarder') {
            $lignes  = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $vider = !empty($_POST['vider']);
            if ($vider) {
                $pdo->exec('DELETE FROM Salle');
                $pdo->exec('ALTER TABLE Salle AUTO_INCREMENT = 1');
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

            $stmtInsert = $pdo->prepare(
                'INSERT INTO Salle (Nom, Adresse, Id_Laposte, Id_Club, EstPrincipale)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmtUpdate = $pdo->prepare(
                'UPDATE Salle SET Nom=?, Adresse=?, Id_Laposte=?, Id_Club=?, EstPrincipale=?
                 WHERE Id_Salle=?'
            );

            foreach ($lignes as $l) {
                $id            = (int)($l['id_salle']      ?? 0);
                $nom           = trim($l['nom']            ?? '');
                $adresse       = trim($l['adresse']        ?? '') ?: null;
                $idLaposte     = $l['id_laposte']  !== '' && $l['id_laposte'] !== null ? (int)$l['id_laposte'] : null;
                $idClub        = ($l['id_club'] ?? '') !== '' ? trim($l['id_club']) : null;
                $estPrincipale = !empty($l['est_principale']) ? 1 : 0;

                if ($nom === '') {
                    $erreurs[] = "Ligne id=$id : le nom est obligatoire.";
                    continue;
                }

                try {
                    if ($id === 0) {
                        $stmtInsert->execute([$nom, $adresse, $idLaposte, $idClub, $estPrincipale]);
                        $inserts++;
                    } else {
                        $stmtUpdate->execute([$nom, $adresse, $idLaposte, $idClub, $estPrincipale, $id]);
                        $updates++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "Ligne id=$id : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérée(s), $updates modifiée(s).";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            ob_end_clean();
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id invalide.']);
                exit;
            }
            $pdo->prepare('DELETE FROM Salle WHERE Id_Salle = ?')->execute([$id]);
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Salle supprimée.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] salle.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] salle.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet     = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement    = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin    = !empty($moi['change_login']);
$isAdminJs      = $isAdmin ? 'true' : 'false';
$deptUserJs     = json_encode($moi['id_departement'] ?? null);

$deptActifs = getDeptActifs();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Salles (E005)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="asset/css/nijac.css">

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

        #tbl-salles {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 800px;
        }

        #tbl-salles thead th {
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
        #tbl-salles thead th:hover { background: #d4dff0; }
        #tbl-salles thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-salles thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-salles thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-salles thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-salles tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-salles tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-salles tbody tr:hover   { background: #dce8f8; }
        #tbl-salles tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-salles tbody tr.new-row  { background: #fffbe6 !important; }
        #tbl-salles tbody td { border: 1px solid #e0e8f0; padding: 0; }

        /* Cellule éditable */
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

        td.col-id, td.col-nom-club { background: #f0f4fa; }
        td.col-id .cell-inner, td.col-nom-club .cell-inner { color: #6b7280; font-style: italic; }

        /* Checkbox principale */
        td.col-principale { text-align: center; vertical-align: middle; }
        td.col-principale input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }



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

<?php $pageIcon = 'bi-building-fill'; $pageTitle = 'Gestion des salles'; $pageCode = 'E005'; $backUrl = $isAdmin ? 'admin_menu.php' : 'Nominateur/menu.php'; require __DIR__ . '/includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
<?php if ($isAdmin): ?>
    <button class="menu-item" id="btn-importer">
        <i class="bi bi-file-earmark-arrow-up"></i>Importation Excel (xlsx)
    </button>
    <button class="menu-item" id="btn-ajouter">
        <i class="bi bi-plus-circle"></i>Ajouter
    </button>
    <button class="menu-item danger" id="btn-supprimer">
        <i class="bi bi-trash3"></i>Supprimer
    </button>
    <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.2rem .4rem;">
        <input type="checkbox" id="chk-vider"> Vider avant enregistrement
    </label>
    <button class="menu-item" id="btn-sauvegarder">
        <i class="bi bi-database-fill-up"></i>Enregistrer dans la Base de données
    </button>
    <input type="file" id="file-input" accept=".xlsx" style="display:none">
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
    <!-- Filtre département (admin uniquement) -->
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <span style="padding:.2rem .6rem; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; font-size:.82rem; color:#856404;">
        <i class="bi bi-eye-fill me-1"></i>Consultation — département <?= $departement ?>
    </span>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
<?php endif; ?>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-salles">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id_salle">N°<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:260px" data-field="adresse">Adresse<span class="sort-icon"></span></th>
                <th style="width:130px" data-field="id_laposte">CP / Ville<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="est_principale">Principale<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt.'; ?>

<!-- Toast -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const IS_ADMIN  = <?= $isAdminJs ?>;
const DEPT_USER = <?= $deptUserJs ?>;

let lignes     = [];
let clubs      = [];   // [{id_club, nom}]
let cellActive = null;
let rowActive  = null; // idx de la ligne sélectionnée
let sortField  = 'nom_club';
let sortDir    = 'asc';
let searchTerm = '';
let deptFiltre = IS_ADMIN ? '' : (DEPT_USER ?? '');

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
    let result = term
        ? lignes.filter(l =>
            String(l.id_salle       ?? '').toLowerCase().includes(term) ||
            String(l.nom            ?? '').toLowerCase().includes(term) ||
            String(l.adresse        ?? '').toLowerCase().includes(term) ||
            String(l.id_laposte     ?? '').toLowerCase().includes(term) ||
            String(l.id_club        ?? '').toLowerCase().includes(term) ||
            String(l.nom_club       ?? '').toLowerCase().includes(term))
        : [...lignes];

    const numFields = ['id_salle', 'id_laposte'];
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        if (sortField === 'est_principale') {
            return sortDir === 'asc' ? a.est_principale - b.est_principale : b.est_principale - a.est_principale;
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTri() {
    $('#tbl-salles thead th').each(function () {
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
        const msg = searchTerm ? 'Aucun résultat.' : 'Aucune salle.';
        $body.append(`<tr><td colspan="7" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} salle(s).` : 'Aucune salle enregistrée.');
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const isNew = !!l._nouveau;
        const $tr = $('<tr>').attr('data-idx', idx).toggleClass('new-row', isNew);

        $tr.append(makeTd(isNew ? '(nouveau)' : l.id_salle, idx, 'id_salle', true));
        $tr.append(makeTd(l.nom,        idx, 'nom',        false));
        $tr.append(makeTd(l.adresse,    idx, 'adresse',    false));
        // Affiche CP+Ville si importé, sinon Id_Laposte
        const cpAff = l.cp_ville || l.id_laposte || '';
        $tr.append(makeTd(cpAff, idx, 'id_laposte', false));
        $tr.append(makeTd(l.id_club  ?? '', idx, 'id_club',  true));
        $tr.append(makeTd(l.nom_club ?? '', idx, 'nom_club', true));
        $tr.append(makePrincipaleTd(l, idx));

        $tr.on('click', function () { selectionnerLigne(idx); });
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} salle(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    $('#lbl-count').text(`${lignes.length} salle(s)`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? `col-${field.replace('_','-')}` : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        $td.on('click', function (e) { e.stopPropagation(); selectionnerCellule($(this)); });
    }
    return $td;
}


function makePrincipaleTd(l, idx) {
    const $td  = $('<td class="col-principale">').attr('data-idx', idx).attr('data-field', 'est_principale');
    const $cb  = $('<input type="checkbox">').prop('checked', !!l.est_principale);
    $cb.on('change', function () {
        lignes[idx].est_principale = this.checked ? 1 : 0;
        setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
    });
    $td.append($cb);
    return $td;
}

// ── Sélection ─────────────────────────────────────────────────────────────────
function selectionnerLigne(idx) {
    rowActive = idx;
    $('#tbody-grille tr').removeClass('selected');
    $(`#tbody-grille tr[data-idx="${idx}"]`).addClass('selected');
}

function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    const idx = +$td.attr('data-idx');
    selectionnerLigne(idx);
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

// ── Clavier ───────────────────────────────────────────────────────────────────
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

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    const val   = $inner.text().trim();
    if (lignes[idx]) {
        lignes[idx][field] = val !== '' ? val : null;
    }
    setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
}

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
        url: 'salle.php', type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
        success(res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }

            // Récupérer le MAX actuel pour numéroter les futurs IDs
            $.post('salle.php', { action: 'max_id' }, function(r) {
                const base = r.ok ? r.max : 0;
                let seq = base;

                res.data.forEach(l => {
                    l._nouveau = true;        // marque ligne non encore en BDD
                    l.id_salle = ++seq;       // ID provisoire pour affichage
                    const c = clubs.find(c => c.id_club === l.id_club);
                    l.nom_club = c ? c.nom : '';
                });

                lignes = res.data;
                renderGrille();
                toast(`${res.count} salle(s) importée(s) depuis Excel.`);
                setStatus(`${res.count} salle(s) importée(s). IDs provisoires à partir de ${base + 1}. Vérifiez puis cliquez sur « Enregistrer ».`);
            }, 'json');
        },
        error() { spinner(false); toast("Erreur lors de l'import.", false); }
    });
    this.value = '';
});

// ── Charger ───────────────────────────────────────────────────────────────────
function chargerClubs() {
    return $.post('salle.php', { action: 'liste_clubs' }, function (res) {
        if (res.ok) clubs = res.data.map(r => ({ id_club: r.Id_Club, nom: r.Nom }));
    }, 'json');
}

function chargerListe() {
    spinner(true);
    chargerClubs().then(() => {
        $.post('salle.php', { action: 'liste', dept: deptFiltre }, function (res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            lignes = res.data.map(r => ({
                id_salle:       r.Id_Salle,
                nom:            r.Nom,
                adresse:        r.Adresse,
                id_laposte:     r.Id_Laposte,
                cp_ville:       r.CpVille ?? '',
                id_club:        r.Id_Club,
                nom_club:       r.NomClub ?? '',
                est_principale: r.EstPrincipale,
            }));
            renderGrille();
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
}


// ── Ajouter ───────────────────────────────────────────────────────────────────
$('#btn-ajouter').on('click', function () {
    lignes.push({
        id_salle: 0, nom: '', adresse: null,
        id_laposte: null, id_club: null, nom_club: '', est_principale: 0,
    });
    renderGrille();
    // Sélectionner la nouvelle ligne et activer le champ Nom
    const newIdx = lignes.length - 1;
    selectionnerLigne(newIdx);
    const $tr = $(`#tbody-grille tr[data-idx="${newIdx}"]`);
    $tr[0]?.scrollIntoView({ block: 'nearest' });
    const $nomTd = $tr.find('td[data-field="nom"]');
    selectionnerCellule($nomTd);
    $nomTd.find('.cell-inner').attr('contenteditable', 'true').trigger('focus');
});

// ── Supprimer ─────────────────────────────────────────────────────────────────
$('#btn-supprimer').on('click', function () {
    if (rowActive === null) { toast('Sélectionnez une ligne à supprimer.', false); return; }
    const l = lignes[rowActive];
    if (!l) return;

    const label = l.nom || '(nouvelle ligne)';
    if (!confirm(`Supprimer la salle « ${label} » ?`)) return;

    if (l.id_salle === 0) {
        lignes.splice(rowActive, 1);
        rowActive = null;
        renderGrille();
        return;
    }

    spinner(true);
    $.post('salle.php', { action: 'supprimer', id: l.id_salle }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) { rowActive = null; chargerListe(); }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-sauvegarder').on('click', function () {
    // Les lignes importées ont un id provisoire > 0 mais ne sont pas encore en BDD
    // On les distingue par le flag _nouveau posé à l'import
    const modifiees = lignes
        .filter(l => l.nom !== '' && l.nom !== null)
        .map(l => l._nouveau ? { ...l, id_salle: 0 } : l);
    if (!modifiees.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Enregistrer ${modifiees.length} salle(s) ?`)) return;

    const vider = $('#chk-vider').is(':checked');
    if (vider && !confirm('Vider toute la table Salle avant enregistrement ?\n\nCette opération est irréversible.')) return;

    spinner(true);
    $.post('salle.php', {
        action:  'sauvegarder',
        lignes:  JSON.stringify(modifiees),
        vider:   vider ? '1' : '0',
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-salles thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    if (sortField === f) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortField = f;
        sortDir   = 'asc';
    }
    renderGrille();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Filtre département (admin) ────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
