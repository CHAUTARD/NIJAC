<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des départements (EA90)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }
        #panel-liste { width: 54%; }
        #tbl-depts td.col-limitrophe {
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: .78rem;
            color: #6b7280;
        }
        /* Valeur calculée (non stockée, non modifiable) : teal + italique */
        #tbl-depts td.col-limitrophe.col-calc,
        #tbl-depts th.th-calc,
        #txt-limitrophe-region { color: #0b7285; font-style: italic; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'geo-alt-fill', 'phTitle' => 'Départements', 'phCode' => 'EA90',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

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
                        <th data-col="Limitrophe">Limitrophes<span class="sort-icon"></span></th>
                        <th data-col="LimitropheRegion" class="th-calc">Limitrophes région<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
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

        <div class="mb-2">
            <label class="form-label" for="txt-limitrophe">Départements limitrophes :</label>
            <input type="text" id="txt-limitrophe" class="form-control form-control-sm" placeholder="28;60;72;78;80;95">
            <div class="form-text" style="font-size:.75rem;">Codes séparés par « ; ».</div>
        </div>

        <div class="mb-2">
            <span class="form-label d-block">Limitrophes de la région :</span>
            <div id="txt-limitrophe-region" class="form-text fw-bold" style="min-height:1rem;">&mdash;</div>
            <div class="form-text" style="font-size:.75rem;">Information calculée : sous-ensemble des limitrophes appartenant à la même région.</div>
        </div>

        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau"><i class="bi bi-plus-circle me-1"></i>Nouveau</button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled><i class="bi bi-trash3 me-1"></i>Supprimer</button>
        </div>

        <div id="form-status" class="mt-3 small fw-bold"></div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const DEPT_BASE = '<?= site_url('departement') ?>';
let currentCode = null;
let regions = [];
let tousLesDepts = [];
const sortState = { col: null, asc: true };

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
        $body.append('<tr><td colspan="5" class="text-center text-muted py-3">Aucun département.</td></tr>');
        return;
    }
    data.forEach(d => {
        $('<tr>').attr('data-code', d.code).append(
            $('<td>').html(`<code>${d.code}</code>`),
            $('<td>').text(d.nom),
            $('<td>').text(d.nom_region ?? ''),
            $('<td class="col-limitrophe">').text(d.Limitrophe ?? '').attr('title', d.Limitrophe ?? ''),
            $('<td class="col-limitrophe col-calc">').text(d.LimitropheRegion ?? '').attr('title', d.LimitropheRegion ?? '')
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
        $('#txt-limitrophe').val(d.Limitrophe ?? '');
        $('#txt-limitrophe-region').text(d.LimitropheRegion || '—');
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
    $('#txt-limitrophe').val('');
    $('#txt-limitrophe-region').text('—');
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
        limitrophe:  $('#txt-limitrophe').val().trim(),
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
