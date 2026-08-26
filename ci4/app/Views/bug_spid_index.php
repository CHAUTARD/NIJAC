<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – BugSpid (E043)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 62%; }
        #tbl-bugspid td.col-statut .badge-traite  { background: #c6efce; color: #1a5c1a; }
        #tbl-bugspid td.col-statut .badge-atraiter { background: #fff3cd; color: #7a5b00; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'wrench-adjustable-circle', 'phTitle' => 'BugSpid — corrections de clubs dupliqués', 'phCode' => 'E043',
    'phCrumbLabel' => 'Admin BDD', 'phCrumbUrl' => site_url('db-admin'), 'phBackUrl' => site_url('db-admin'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => '', 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Corrections</span>
            <button type="button" class="btn btn-sm btn-light" id="btn-nouveau" title="Ajouter une ligne">
                <i class="bi bi-plus-lg"></i> Ajouter
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btn-executer-selection" title="Exécuter les lignes cochées">
                <i class="bi bi-play-fill"></i> Exécuter la sélection
            </button>
            <span id="lbl-count">0 / 0</span>
        </div>
        <div id="table-wrapper">
            <table id="tbl-bugspid">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="chk-tout"></th>
                        <th data-col="1">Description<span class="sort-icon"></span></th>
                        <th style="width:110px" data-col="2">Ancien Id_Club<span class="sort-icon"></span></th>
                        <th style="width:110px" data-col="3">Nouveau Id_Club<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="4">Statut<span class="sort-icon"></span></th>
                        <th style="width:140px" data-col="5">Date exécution<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une ligne dans la liste pour la modifier, ou cliquez sur « Ajouter ».</div>

        <div id="form-bugspid" style="display:none;">
            <div class="mb-2">
                <label class="form-label" for="txt-description">Description</label>
                <input type="text" id="txt-description" class="form-control form-control-sm" maxlength="255">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-ancien">Ancien Id_Club (fantôme)</label>
                <input type="text" id="txt-ancien" class="form-control form-control-sm" maxlength="20">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-nouveau">Nouveau Id_Club (FFTT réel)</label>
                <input type="text" id="txt-nouveau" class="form-control form-control-sm" maxlength="20">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-equipe-nom">EquipeNom à reporter (optionnel)</label>
                <input type="text" id="txt-equipe-nom" class="form-control form-control-sm" maxlength="100"
                       placeholder="Laisser vide pour ne pas toucher au EquipeNom du club cible">
            </div>

            <div class="form-readonly small text-muted mb-2" id="txt-resultat"></div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
                <button class="btn btn-sm btn-nouveau px-3" id="btn-annuler">Annuler</button>
                <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer"><i class="bi bi-trash me-1"></i>Supprimer</button>
            </div>

            <div id="form-status" class="mt-3 small fw-bold"></div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const BUGSPID_BASE = '<?= site_url('bug-spid') ?>';
let lignes = [];
let currentId = null;
const sortState = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectId = null) {
    $.get(`${BUGSPID_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data;
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    $('#lbl-count').text(`${lignes.length} ligne(s)`);
    $('#chk-tout').prop('checked', false);

    if (!lignes.length) {
        $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucune ligne.</td></tr>');
        return;
    }

    lignes.forEach(l => {
        const badge = l.Statut === 'Traite'
            ? '<span class="badge badge-traite">Traité</span>'
            : '<span class="badge badge-atraiter">À traiter</span>';
        $('<tr>').attr('data-id', l.Id_BugSpid).append(
            $('<td>').append($('<input type="checkbox" class="chk-ligne">').prop('disabled', l.Statut === 'Traite')),
            $('<td>').text(l.Description ?? ''),
            $('<td>').text(l.AncienIdClub ?? ''),
            $('<td>').text(l.NouveauIdClub ?? ''),
            $('<td>').addClass('col-statut').html(badge),
            $('<td>').text(l.DateExecution ?? '')
        ).on('click', function (e) {
            if ($(e.target).is('input[type="checkbox"]')) return;
            selectionnerLigne($(this));
        }).appendTo($body);
    });

    if (currentId) {
        const $tr = $(`#tbody-liste tr[data-id="${currentId}"]`);
        if ($tr.length) $tr.addClass('selected');
    }
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = +$tr.attr('data-id');
    const l  = lignes.find(x => x.Id_BugSpid == id);
    if (!l) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-bugspid').show();
    $('#txt-description').val(l.Description ?? '');
    $('#txt-ancien').val(l.AncienIdClub ?? '');
    $('#txt-nouveau').val(l.NouveauIdClub ?? '');
    $('#txt-equipe-nom').val(l.EquipeNom ?? '');
    $('#txt-resultat').text(l.Resultat ? `Résultat : ${l.Resultat}` : '');
    setStatus('');
}

$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#no-selection').hide();
    $('#form-bugspid').show();
    $('#txt-description, #txt-ancien, #txt-nouveau, #txt-equipe-nom').val('');
    $('#txt-resultat').text('');
    setStatus('');
});

$('#btn-annuler').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#form-bugspid').hide();
    $('#no-selection').show();
});

$('#btn-enregistrer').on('click', function () {
    const payload = {
        description:      $('#txt-description').val().trim(),
        ancien_id_club:    $('#txt-ancien').val().trim(),
        nouveau_id_club:   $('#txt-nouveau').val().trim(),
        equipe_nom:        $('#txt-equipe-nom').val().trim(),
    };

    const url    = currentId ? `${BUGSPID_BASE}/${currentId}` : BUGSPID_BASE;
    const method = currentId ? 'PUT' : 'POST';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId || res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    nijacConfirm('Supprimer cette ligne ?', function () {
        $.ajax({ url: `${BUGSPID_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) {
                toast(res.msg);
                currentId = null;
                $('#form-bugspid').hide();
                $('#no-selection').show();
                chargerListe();
            } else {
                toast(res.msg, false);
            }
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'danger' });
});

$('#chk-tout').on('change', function () {
    $('.chk-ligne:not(:disabled)').prop('checked', this.checked);
});

$('#btn-executer-selection').on('click', function () {
    const ids = $('.chk-ligne:checked').map(function () { return +$(this).closest('tr').attr('data-id'); }).get();
    if (!ids.length) { toast('Cochez au moins une ligne à exécuter.', false); return; }

    nijacConfirm(`Exécuter ${ids.length} ligne(s) de correction ?`, function () {
        $.ajax({ url: `${BUGSPID_BASE}/executer`, method: 'POST', data: { ids: JSON.stringify(ids) }, dataType: 'json' }).done(function (res) {
            if (!res.ok) { toast(res.msg, false); return; }
            const nbOk = res.resultats.filter(r => r.ok).length;
            const nbKo = res.resultats.length - nbOk;
            toast(`${nbOk} ligne(s) exécutée(s)` + (nbKo ? `, ${nbKo} en erreur.` : '.'), nbKo === 0);
            chargerListe();
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'question', title: 'Exécuter la sélection', confirmLabel: 'Exécuter' });
});

$(function () {
    nijacSortableTable('#tbl-bugspid thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
