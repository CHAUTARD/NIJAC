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
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }
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
            width: 50%;
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
        #tbl-dates { width: 100%; font-size: .85rem; border-collapse: collapse; }
        #tbl-dates thead th {
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
        #tbl-dates thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-dates thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-dates thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-dates thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-dates tbody tr { cursor: pointer; border-bottom: 1px solid #e0e8f0; }
        #tbl-dates tbody tr:hover { background: #dce8f8; }
        #tbl-dates tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-dates tbody td { padding: .3rem .5rem; }
        #panel-form {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            background: #fff;
        }
        .form-label { font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: .2rem; }
        .form-control { font-size: .9rem; }
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
