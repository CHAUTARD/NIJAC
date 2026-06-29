<?php
/**
 * NIJAC – Gestion des départements (E013)
 */
session_start();
require __DIR__ . '/includes/admin_required.php';
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        if ($action === 'liste') {
            $rows = $pdo->query("
                SELECT d.code, d.nom, d.code_region, r.nom AS nom_region
                FROM departement d
                LEFT JOIN region r ON r.code = d.code_region
                ORDER BY CAST(d.code AS UNSIGNED)
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'charger') {
            $code = trim($_GET['code'] ?? '');
            $stmt = $pdo->prepare("
                SELECT d.code, d.nom, d.code_region, r.nom AS nom_region
                FROM departement d
                LEFT JOIN region r ON r.code = d.code_region
                WHERE d.code = ?
            ");
            $stmt->execute([$code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']);
            exit;
        }

        if ($action === 'liste_regions') {
            $rows = $pdo->query("SELECT code, nom FROM region ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'enregistrer') {
            $code       = trim($_POST['code']        ?? '');
            $nom        = trim($_POST['nom']         ?? '');
            $codeRegion = trim($_POST['code_region'] ?? '') ?: null;
            $isNew      = (int)($_POST['is_new']     ?? 0);
            if ($code === '' || $nom === '') { echo json_encode(['ok' => false, 'msg' => 'Code et nom obligatoires.']); exit; }
            if ($isNew) {
                $pdo->prepare("INSERT INTO departement (code, nom, code_region) VALUES (?,?,?)")
                    ->execute([$code, $nom, $codeRegion]);
                echo json_encode(['ok' => true, 'msg' => 'Département créé.', 'code' => $code]);
            } else {
                $pdo->prepare("UPDATE departement SET nom=?, code_region=? WHERE code=?")
                    ->execute([$nom, $codeRegion, $code]);
                echo json_encode(['ok' => true, 'msg' => 'Département mis à jour.', 'code' => $code]);
            }
            exit;
        }

        if ($action === 'supprimer') {
            $code = trim($_POST['code'] ?? '');
            if ($code === '') { echo json_encode(['ok' => false, 'msg' => 'Code manquant.']); exit; }
            $pdo->prepare("DELETE FROM departement WHERE code=?")->execute([$code]);
            echo json_encode(['ok' => true, 'msg' => 'Département supprimé.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] departement.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ───────────────────────────────────────────────────────────────
$moi         = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? ''));
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Gestion des départements (E013)</title>
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
        #split-container { display: flex; flex: 1; overflow: hidden; }
        #panel-liste {
            width: 54%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
        }
        #liste-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
        }
        #table-wrapper { flex: 1; overflow-y: auto; }
        #tbl-depts { width: 100%; font-size: .85rem; border-collapse: collapse; }
        #tbl-depts thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        #tbl-depts tbody tr { cursor: pointer; border-bottom: 1px solid #e0e8f0; }
        #tbl-depts tbody tr:hover { background: #dce8f8; }
        #tbl-depts tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-depts tbody td { padding: .3rem .5rem; }
        #panel-form {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            background: #fff;
        }
        .form-label { font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: .2rem; }
        .form-control, .form-select { font-size: .9rem; }
        #panel-boutons { display: flex; gap: .6rem; margin-top: 1.25rem; }
        .btn-nouveau     { background:#fff; border:1px solid #aaa; }
        .btn-enregistrer { background:#c6efce; border:1px solid #82c88e; font-weight:600; }
        .btn-supprimer   { background:#ffc7ce; border:1px solid #e09090; font-weight:600; }
        .btn-nouveau:hover     { background:#e8e8e8; }
        .btn-enregistrer:hover { background:#a8dfb0; }
        .btn-supprimer:hover   { background:#f0a0a8; }
        .btn-supprimer:disabled { opacity:.5; cursor:not-allowed; }
        #toast-container { position:fixed; bottom:1rem; right:1rem; z-index:9999; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-geo-alt-fill'; $pageTitle = 'Départements'; $pageCode = 'E013'; $backUrl = 'admin_menu.php'; require __DIR__ . '/includes/page_header.php'; ?>
<?php require __DIR__ . '/includes/toolbar.php'; ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">Départements</div>
        <div id="filtre-bar" style="padding:.35rem .5rem; background:#f0f4fa; border-bottom:1px solid #c8d4e8; display:flex; gap:.4rem; align-items:center; flex-shrink:0;">
            <input type="search" id="filtre-texte" class="form-control form-control-sm" placeholder="Code ou nom…" style="width:130px;">
            <select id="filtre-region" class="form-select form-select-sm" style="flex:1; min-width:0;">
                <option value="">Toutes les régions</option>
            </select>
            <button id="btn-filtre-reset" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Effacer les filtres"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="table-wrapper">
            <table id="tbl-depts">
                <thead>
                    <tr>
                        <th style="width:55px;">Code</th>
                        <th>Nom</th>
                        <th>Région</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="3" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label">Code :</label>
                <input type="text" id="txt-code" class="form-control form-control-sm" maxlength="3" style="width:70px;" placeholder="76">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Nom :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="255" placeholder="Seine-Maritime">
        </div>

        <div class="mb-2">
            <label class="form-label" for="cbo-region">Région :</label>
            <select id="cbo-region" class="form-select form-select-sm" style="max-width:320px">
                <option value="">— Aucune —</option>
            </select>
        </div>

        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau"><i class="bi bi-plus-circle me-1"></i>Nouveau</button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled><i class="bi bi-trash3 me-1"></i>Supprimer</button>
        </div>

        <div id="form-status" class="mt-3 small fw-bold"></div>
    </div>
</div>

<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script src="asset/js/nijac-toast.js"></script>
<script>
'use strict';
let currentCode = null;
let regions = [];
let tousLesDepts = [];

function toast(msg, ok = true) {
    const id = 'toast-' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(`<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show" role="alert"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    setTimeout(() => $(`#${id}`).remove(), 3500);
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function peuplerRegions(selectedCode) {
    const $sel = $('#cbo-region').empty().append('<option value="">— Aucune —</option>');
    regions.forEach(r => $sel.append(`<option value="${r.code}" ${r.code === selectedCode ? 'selected' : ''}>${r.nom}</option>`));
}

function peuplerFiltreRegion() {
    const $sel = $('#filtre-region').empty().append('<option value="">Toutes les régions</option>');
    regions.forEach(r => $sel.append(`<option value="${r.code}">${r.nom}</option>`));
}

function appliquerFiltre() {
    const texte  = $('#filtre-texte').val().trim().toLowerCase();
    const region = $('#filtre-region').val();
    const $body  = $('#tbody-liste').empty();
    const data   = tousLesDepts.filter(d =>
        (!texte  || d.code.toLowerCase().includes(texte) || d.nom.toLowerCase().includes(texte)) &&
        (!region || d.code_region === region)
    );
    if (!data.length) {
        $body.append('<tr><td colspan="3" class="text-center text-muted py-3">Aucun département.</td></tr>');
        return;
    }
    data.forEach(d => {
        $('<tr>').attr('data-code', d.code).append(
            $('<td>').html(`<code>${d.code}</code>`),
            $('<td>').text(d.nom),
            $('<td>').text(d.nom_region ?? '')
        ).on('click', function() { selectionnerLigne($(this)); }).appendTo($body);
    });
}

function chargerListe(selectCode = null) {
    $.post('departement.php', {action: 'liste'}, function(res) {
        if (!res.ok) return;
        tousLesDepts = res.data;
        appliquerFiltre();
        if (selectCode) {
            const $tr = $(`#tbody-liste tr[data-code="${selectCode}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json');
}

$('#filtre-texte, #filtre-region').on('input change', appliquerFiltre);
$('#btn-filtre-reset').on('click', function() {
    $('#filtre-texte').val('');
    $('#filtre-region').val('');
    appliquerFiltre();
});

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const code = $tr.data('code');
    $.get('departement.php', {action: 'charger', code}, function(res) {
        if (!res.ok) return;
        const d = res.data;
        currentCode = d.code;
        $('#txt-code').val(d.code).prop('readonly', true);
        $('#txt-nom').val(d.nom);
        peuplerRegions(d.code_region ?? '');
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function() {
    currentCode = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-code').val('').prop('readonly', false).trigger('focus');
    $('#txt-nom').val('');
    peuplerRegions('');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function() {
    const data = {
        action:      'enregistrer',
        code:        $('#txt-code').val().trim(),
        nom:         $('#txt-nom').val().trim(),
        code_region: $('#cbo-region').val(),
        is_new:      currentCode === null ? 1 : 0,
    };
    $.post('departement.php', data, function(res) {
        if (res.ok) { toast(res.msg); currentCode = res.code; chargerListe(res.code); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }, 'json');
});

$('#btn-supprimer').on('click', function() {
    if (!currentCode) return;
    const nom = $('#txt-nom').val();
    nijacConfirm(`Supprimer le département « ${nom} » (${currentCode}) ?`, function() {
        $.post('departement.php', {action: 'supprimer', code: currentCode}, function(res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        }, 'json');
    }, null, {type: 'danger'});
});

$(function() {
    $.post('departement.php', {action: 'liste_regions'}, function(res) {
        if (res.ok) { regions = res.data; peuplerRegions(''); peuplerFiltreRegion(); }
    }, 'json');
    chargerListe();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
