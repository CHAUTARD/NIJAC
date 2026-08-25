<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Calendrier championnat régional (E014)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 50%; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar2-check-fill', 'phTitle' => 'Calendrier championnat régional', 'phCode' => 'E014',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">Dates de championnat régional</div>
        <div id="table-wrapper">
            <table id="tbl-dates">
                <thead>
                    <tr>
                        <th data-col="0">Date<span class="sort-icon"></span></th>
                        <th data-col="1">Horaire<span class="sort-icon"></span></th>
                        <th data-col="2">Commentaire<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="3" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="mb-2">
            <label class="form-label" for="txt-date">Date :</label>
            <input type="date" id="txt-date" class="form-control form-control-sm" style="max-width:220px;">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-heure">Horaire :</label>
            <input type="time" id="txt-heure" class="form-control form-control-sm" style="max-width:220px;">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-commentaire">Commentaire :</label>
            <textarea id="txt-commentaire" class="form-control form-control-sm" rows="2" maxlength="255"
                      placeholder="Ex : Dimanche 09h00 : départements 27 et 76 — Dimanche 14h00 : départements 14, 50, 61"></textarea>
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
const BASE = '<?= site_url('competition-regionale') ?>';
let currentId = null;
const sortState = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

const JOURS_FR = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

function formaterDateFr(iso) {
    const [y, m, d] = iso.split('-');
    const jour = JOURS_FR[new Date(`${iso}T00:00:00`).getDay()];
    return `${jour} ${d}/${m}/${y}`;
}

function chargerListe(selectId = null) {
    $.get(`${BASE}/data`, function(res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="3" class="text-center text-muted py-3">Aucune date.</td></tr>');
            return;
        }
        res.data.forEach(r => {
            $('<tr>').attr('data-id', r.Id_CompetitionRegionale).attr('data-date', r.Date).append(
                $('<td>').text(formaterDateFr(r.Date)),
                $('<td>').text(r.Heure.substring(0, 5)),
                $('<td>').text(r.Commentaire ?? '')
            ).on('click', function() { selectionnerLigne($(this)); }).appendTo($body);
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
    $.get(`${BASE}/data/${id}`, function(res) {
        if (!res.ok) return;
        const r = res.data;
        currentId = r.Id_CompetitionRegionale;
        $('#txt-date').val(r.Date);
        $('#txt-heure').val(r.Heure.substring(0, 5));
        $('#txt-commentaire').val(r.Commentaire ?? '');
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function() {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-date').val('').trigger('focus');
    $('#txt-heure').val('');
    $('#txt-commentaire').val('');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function() {
    const isNew = currentId === null;
    const payload = {
        date:        $('#txt-date').val(),
        heure:       $('#txt-heure').val(),
        commentaire: $('#txt-commentaire').val(),
    };
    const url    = isNew ? BASE : `${BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function(res) {
        if (res.ok) { toast(res.msg); currentId = res.id; chargerListe(res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

$('#btn-supprimer').on('click', function() {
    if (!currentId) return;
    const date = $('#txt-date').val();
    nijacConfirm(`Supprimer la date du ${formaterDateFr(date)} ?`, function() {
        $.ajax({ url: `${BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function(res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// Colonne Date : tri par data-date (ISO, sortable en l'état) plutôt que par le texte affiché
// ("Samedi 19/09/2026") qui trierait alphabétiquement par nom de jour.
function trierListe() {
    if (sortState.col !== '0') {
        nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc);
        return;
    }
    const tbody = document.querySelector('#tbody-liste');
    const rows = Array.from(tbody.children).filter(tr => tr.dataset.id);
    rows.sort((a, b) => (sortState.asc ? 1 : -1) * a.dataset.date.localeCompare(b.dataset.date));
    rows.forEach(tr => tbody.appendChild(tr));
}

$(function () {
    nijacSortableTable('#tbl-dates thead th[data-col]', 'col', sortState, trierListe);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
