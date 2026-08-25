<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des divisions (E010)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">

    <style>
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }
        #panel-liste { width: 55%; }
        #txt-id { background: #f0f4fa; width: 100px; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'diagram-3-fill', 'phTitle' => 'Gestion des divisions', 'phCode' => 'E010',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Split -->
<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">Divisions</div>
        <div id="table-wrapper">
            <table id="tbl-divisions">
                <thead>
                    <tr>
                        <th style="width:80px" data-col="0">Division<span class="sort-icon"></span></th>
                        <th data-col="1">Nom<span class="sort-icon"></span></th>
                        <th style="width:50px" data-col="2">Ord<span class="sort-icon"></span></th>
                        <th style="width:50px;text-align:center">Couleur</th>
                        <th style="width:120px;text-align:center" data-col="4">Arbitrage CRA<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="mb-2">
            <label class="form-label">Id Division :</label>
            <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Division :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="num-ord">Ordre :</label>
            <input type="number" id="num-ord" class="form-control form-control-sm" min="1" max="300" value="1" style="width:120px">
        </div>
        <div class="mb-2">
            <label class="form-label" for="txt-nom-long">Nom :</label>
            <input type="text" id="txt-nom-long" class="form-control form-control-sm" maxlength="255">
        </div>

        <div class="mb-2">
            <label class="form-label" for="color-couleur">Couleur :</label>
            <input type="color" id="color-couleur" class="form-control form-control-sm" value="#1565c0" style="width:60px;padding:.2rem">
        </div>

        <div class="mb-3">
            <label class="form-label">Arbitrage CRA :</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="arbitrage" id="radio-oblig" value="1" checked>
                    <label class="form-check-label" for="radio-oblig">
                        <span class="badge" style="background:#1565c0">Obligatoire</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="arbitrage" id="radio-demande" value="0">
                    <label class="form-check-label" for="radio-demande">
                        <span class="badge" style="background:#e65100">Sur demande du club</span>
                    </label>
                </div>
            </div>
            <div class="form-text">Indique si un JA doit être désigné automatiquement pour chaque rencontre.</div>
        </div>

        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau">
                <i class="bi bi-plus-circle me-1"></i>Nouveau
            </button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled>
                <i class="bi bi-trash3 me-1"></i>Supprimer
            </button>
        </div>

        <div id="form-status" class="mt-3 small fw-bold"></div>

    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const DIVISION_BASE = '<?= site_url('division') ?>';
let currentId = null;
const sortState = { col: '2', asc: true }; // 2 = colonne Ord, tri par défaut (voir DivisionController::data())

function setStatus(msg, ok = true) {
    $('#form-status').text(msg)
        .removeClass('text-danger text-success')
        .addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectId = null) {
    $.get(`${DIVISION_BASE}/data`, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="5" class="text-center text-muted py-3">Aucune division.</td></tr>');
            return;
        }
        res.data.forEach(d => {
            const arb = +d.ArbitrageCRA === 1
                ? '<span class="badge" style="background:#1565c0;font-size:.75rem">Obligatoire</span>'
                : '<span class="badge" style="background:#e65100;font-size:.75rem">Sur demande</span>';
            const couleur = `<span style="display:inline-block;width:16px;height:16px;border-radius:3px;border:1px solid #999;background:${d.Color || '#1565c0'}"></span>`;
            const $tr = $('<tr>')
                .attr('data-id', d.Division)
                .append(
                    $('<td>').text(d.Division),
                    $('<td>').text(d.Nom),
                    $('<td class="text-center">').text(d.Ord),
                    $('<td class="text-center">').html(couleur),
                    $('<td class="text-center">').html(arb)
                )
                .on('click', function () { selectionnerLigne($(this)); });
            $body.append($tr);
        });

        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json');
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = $tr.data('id');
    $.get(`${DIVISION_BASE}/data/${id}`, function (res) {
        if (!res.ok) return;
        const d = res.data;
        currentId = d.Division;
        $('#txt-id').val(currentId);
        $('#txt-nom').val(d.Division);
        $('#num-ord').val(d.Ord);
        $('#txt-nom-long').val(d.Nom);
        $('#color-couleur').val(d.Color || '#1565c0');
        $('input[name="arbitrage"]').filter(`[value="${+d.ArbitrageCRA}"]`).prop('checked', true);
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-nom').val('').trigger('focus');
    $('#num-ord').val(1);
    $('#txt-nom-long').val('');
    $('#color-couleur').val('#1565c0');
    $('#radio-oblig').prop('checked', true);
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const payload = {
        nom:                   $('#txt-nom').val().trim(),
        ord:                   $('#num-ord').val(),
        nom_long:              $('#txt-nom-long').val().trim(),
        color:                 $('#color-couleur').val(),
        arbitrage_obligatoire: $('input[name="arbitrage"]:checked').val(),
    };
    const url    = isNew ? DIVISION_BASE : `${DIVISION_BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const nom = $('#txt-nom').val();
    nijacConfirm(`Supprimer la division « ${nom} » ?`, function () {
        $.ajax({ url: `${DIVISION_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-divisions thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
