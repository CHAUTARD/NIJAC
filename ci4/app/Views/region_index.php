<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des régions (E012)</title>
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
        #split-container { display: flex; flex: 1; overflow: hidden; }
        #panel-liste {
            width: 60%;
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
        #tbl-regions { width: 100%; font-size: .85rem; border-collapse: collapse; }
        #tbl-regions thead th {
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
        #tbl-regions thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-regions thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-regions thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-regions thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-regions tbody tr { cursor: pointer; border-bottom: 1px solid #e0e8f0; }
        #tbl-regions tbody tr:hover { background: #dce8f8; }
        #tbl-regions tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-regions tbody td { padding: .3rem .5rem; }
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
        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'map-fill', 'phTitle' => 'Régions', 'phCode' => 'E012',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">Régions</div>
        <div id="table-wrapper">
            <table id="tbl-regions">
                <thead>
                    <tr>
                        <th style="width:55px;" data-col="0">Code<span class="sort-icon"></span></th>
                        <th data-col="1">Nom<span class="sort-icon"></span></th>
                        <th data-col="2">Gentilé<span class="sort-icon"></span></th>
                        <th data-col="3">Chef-lieu<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="4" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label">Code :</label>
                <input type="text" id="txt-code" class="form-control form-control-sm" maxlength="2" style="width:70px;" placeholder="28">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Nom :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="255" placeholder="Normandie">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-gentile">Gentilé :</label>
            <input type="text" id="txt-gentile" class="form-control form-control-sm" maxlength="100" placeholder="Normand(e)">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-chef-lieu">Chef-lieu :</label>
            <input type="text" id="txt-chef-lieu" class="form-control form-control-sm" maxlength="255" placeholder="Rouen">
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
const REGION_BASE = '<?= site_url('region') ?>';
let currentCode = null;
const sortState = { col: null, asc: true };

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectCode = null) {
    $.get(`${REGION_BASE}/data`, function(res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="4" class="text-center text-muted py-3">Aucune région.</td></tr>');
            return;
        }
        res.data.forEach(r => {
            $('<tr>').attr('data-code', r.code).append(
                $('<td>').html(`<code>${r.code}</code>`),
                $('<td>').text(r.nom),
                $('<td>').text(r.Gentile ?? ''),
                $('<td>').text(r.chef_lieu ?? '')
            ).on('click', function() { selectionnerLigne($(this)); }).appendTo($body);
        });
        if (selectCode) {
            const $tr = $(`#tbody-liste tr[data-code="${selectCode}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json');
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const code = $tr.data('code');
    $.get(`${REGION_BASE}/data/${code}`, function(res) {
        if (!res.ok) return;
        const r = res.data;
        currentCode = r.code;
        $('#txt-code').val(r.code).prop('readonly', true);
        $('#txt-nom').val(r.nom);
        $('#txt-gentile').val(r.Gentile ?? '');
        $('#txt-chef-lieu').val(r.chef_lieu ?? '');
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function() {
    currentCode = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-code').val('').prop('readonly', false).trigger('focus');
    $('#txt-nom, #txt-gentile, #txt-chef-lieu').val('');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function() {
    const isNew = currentCode === null;
    const payload = {
        code:      $('#txt-code').val().trim(),
        nom:       $('#txt-nom').val().trim(),
        gentile:   $('#txt-gentile').val().trim(),
        chef_lieu: $('#txt-chef-lieu').val().trim(),
    };
    const url    = isNew ? REGION_BASE : `${REGION_BASE}/${currentCode}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function(res) {
        if (res.ok) { toast(res.msg); currentCode = res.code; chargerListe(res.code); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

$('#btn-supprimer').on('click', function() {
    if (!currentCode) return;
    const nom = $('#txt-nom').val();
    nijacConfirm(`Supprimer la région « ${nom} » (${currentCode}) ?`, function() {
        $.ajax({ url: `${REGION_BASE}/${currentCode}`, method: 'DELETE', dataType: 'json' }).done(function(res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-regions thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
