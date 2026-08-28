<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des régions (EA88)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 60%; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'map-fill', 'phTitle' => 'Régions', 'phCode' => 'EA88',
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
