<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des rôles (EA94)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 62%; }

        /* Bandeau de titre : strip clair — même style qu'EA88 / EA92 / EN29. */
        #menu-strip {
            --strip-bg: #f8fafc;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .4rem .75rem;
        }
        #menu-strip > .strip-titre { font-weight: 700; color: var(--nijac-blue); }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'person-badge-fill', 'phTitle' => 'Les rôles', 'phCode' => 'EA94',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu') . '#tab-tables', 'phBackUrl' => site_url('admin-menu') . '#tab-tables',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="menu-strip"><span class="strip-titre">Rôles des utilisateurs</span></div>
        <div id="table-wrapper">
            <table id="tbl-roles">
                <thead>
                    <tr>
                        <th class="th-pk" style="width:50px;" data-col="0">Id<span class="sort-icon"></span></th>
                        <th data-col="1">Nom<span class="sort-icon"></span></th>
                        <th data-col="2">Libellé<span class="sort-icon"></span></th>
                        <th style="width:70px;" data-col="3">Priorité<span class="sort-icon"></span></th>
                        <th data-col="4">Menu d'accueil<span class="sort-icon"></span></th>
                        <th style="width:70px;" data-col="5">Comptes<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label" for="txt-id">Id :</label>
                <input type="text" id="txt-id" class="form-control form-control-sm" readonly style="width:70px;" placeholder="auto">
            </div>
            <div class="col">
                <label class="form-label" for="txt-nom">Nom (valeur utilisée par le code) :</label>
                <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="30" placeholder="Nominateur">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-libelle">Libellé :</label>
            <input type="text" id="txt-libelle" class="form-control form-control-sm" maxlength="60" placeholder="Nominateur">
        </div>

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label" for="txt-priorite">Priorité :</label>
                <input type="number" id="txt-priorite" class="form-control form-control-sm" min="1" max="99" style="width:90px;" value="99">
            </div>
            <div class="col">
                <label class="form-label" for="txt-route">Route du menu d'accueil :</label>
                <input type="text" id="txt-route" class="form-control form-control-sm" maxlength="50" placeholder="nominateur-menu">
            </div>
        </div>

        <p class="small text-muted mb-2">
            Le nom ne peut plus être modifié après création (le code manipule ce nom) et les six rôles de référence ne
            sont pas supprimables. La priorité de « CRA Convoc » et de « CSR » est réappliquée à chaque passage d'EA98.
        </p>

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
const ROLE_BASE = '<?= site_url('role') ?>';
let currentId = null;
const sortState = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectId = null) {
    $.get(`${ROLE_BASE}/data`, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok) {
            $body.append($('<tr><td colspan="6" class="text-center text-danger py-3">').find('td').text(res.msg || 'Erreur.').end());
            return;
        }
        if (!res.data.length) {
            $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucun rôle.</td></tr>');
            return;
        }
        res.data.forEach(r => {
            $('<tr>').attr('data-id', r.Id_Role).append(
                $('<td>').html(`<code>${Number(r.Id_Role)}</code>`),
                $('<td>').text(r.Nom),
                $('<td>').text(r.Libelle),
                $('<td>').text(r.Priorite),
                $('<td>').text(r.RouteMenu),
                $('<td>').text(r.NbComptes)
            ).on('click', function () { selectionnerLigne($(this)); }).appendTo($body);
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
    $.get(`${ROLE_BASE}/data/${$tr.data('id')}`, function (res) {
        if (!res.ok) return;
        const r = res.data;
        currentId = r.Id_Role;
        $('#txt-id').val(r.Id_Role);
        $('#txt-nom').val(r.Nom).prop('readonly', true);
        $('#txt-libelle').val(r.Libelle);
        $('#txt-priorite').val(r.Priorite);
        $('#txt-route').val(r.RouteMenu);
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-nom').val('').prop('readonly', false).trigger('focus');
    $('#txt-libelle').val('');
    $('#txt-priorite').val(99);
    $('#txt-route').val('nominateur-menu');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const payload = {
        nom:        $('#txt-nom').val().trim(),
        libelle:    $('#txt-libelle').val().trim(),
        priorite:   $('#txt-priorite').val().trim(),
        route_menu: $('#txt-route').val().trim(),
    };
    $.ajax({ url: isNew ? ROLE_BASE : `${ROLE_BASE}/${currentId}`, method: isNew ? 'POST' : 'PUT', data: payload, dataType: 'json' })
        .done(function (res) {
            if (res.ok) { toast(res.msg); currentId = res.id; chargerListe(res.id); }
            else { toast(res.msg, false); setStatus(res.msg, false); }
        })
        .fail(() => toast('Erreur réseau.', false));
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    nijacConfirm(`Supprimer le rôle « ${$('#txt-nom').val()} » ?`, function () {
        $.ajax({ url: `${ROLE_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else { toast(res.msg, false); setStatus(res.msg, false); }
        });
    }, null, { type: 'danger' });
});

// ── Tri sur clic en-tête (nijac-sortable-table.js est chargé après ce script) ─
$(function () {
    nijacSortableTable('#tbl-roles thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
