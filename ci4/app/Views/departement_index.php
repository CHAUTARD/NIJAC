<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des départements (E013)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
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
        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        /* ── Toolbar ── */
        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
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
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        #toolbar .ts-pwd-warning:hover { color: #900; }
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
            cursor: pointer;
            user-select: none;
        }
        #tbl-depts thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-depts thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-depts thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-depts thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
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
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php (chemins relatifs internes
     incompatibles avec la profondeur d'URL de ci4/public/ — voir plan Phase 1). -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <span style="font-size:.78rem;font-weight:400;">
            <a href="<?= site_url('admin-menu') ?>" style="color:#cfe0ff;text-decoration:none;">Admin</a>
            <span class="mx-1" style="color:#cfe0ff;">&rsaquo;</span>
        </span>
        <i class="bi bi-geo-alt-fill me-2"></i>Départements
        <small class="ms-2" style="color:#cfe0ff;">(E013)</small>
    </div>
    <a href="<?= site_url('admin-menu') ?>" class="btn btn-sm py-0" style="flex-shrink:0;background:#fff;color:#1a3a6b;border:1px solid #fff;">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>

<!-- Toolbar : recopié de includes/toolbar.php -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= esc($nomComplet) ?><?= $departement ? ' (' . esc($departement) . ')' : '' ?>
    </span>
    <a class="ts-pwd-warning" href="<?= site_url('changer-mot-de-passe') ?>" id="lnk-chg-pwd" data-base="<?= site_url('changer-mot-de-passe') ?>">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

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
                        <th style="width:55px;" data-col="code">Code<span class="sort-icon"></span></th>
                        <th data-col="nom">Nom<span class="sort-icon"></span></th>
                        <th data-col="nom_region">Région<span class="sort-icon"></span></th>
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

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const DEPT_BASE = '<?= site_url('departement') ?>';
let currentCode = null;
let regions = [];
let tousLesDepts = [];
const sortState = { col: null, asc: true };

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
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
    if (sortState.col) {
        data.sort((a, b) => {
            const va = String(a[sortState.col] ?? '').toLowerCase();
            const vb = String(b[sortState.col] ?? '').toLowerCase();
            const cmp = va.localeCompare(vb, 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    }
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
    $.get(`${DEPT_BASE}/data`, function(res) {
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
    $.get(`${DEPT_BASE}/data/${code}`, function(res) {
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
    const isNew = currentCode === null;
    const payload = {
        code:        $('#txt-code').val().trim(),
        nom:         $('#txt-nom').val().trim(),
        code_region: $('#cbo-region').val(),
    };
    const url    = isNew ? DEPT_BASE : `${DEPT_BASE}/${currentCode}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function(res) {
        if (res.ok) { toast(res.msg); currentCode = res.code; chargerListe(res.code); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

$('#btn-supprimer').on('click', function() {
    if (!currentCode) return;
    const nom = $('#txt-nom').val();
    nijacConfirm(`Supprimer le département « ${nom} » (${currentCode}) ?`, function() {
        $.ajax({ url: `${DEPT_BASE}/${currentCode}`, method: 'DELETE', dataType: 'json' }).done(function(res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-depts thead th[data-col]', 'col', sortState, appliquerFiltre);
    $.get(`${DEPT_BASE}/regions`, function(res) {
        if (res.ok) { regions = res.data; peuplerRegions(''); peuplerFiltreRegion(); }
    }, 'json');
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
