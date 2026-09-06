<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des équipes (EA94)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 65%; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'people-fill', 'phTitle' => 'Gestion des équipes', 'phCode' => 'EA94',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Équipes</span>
            <select id="sel-club" class="filter-ctl" style="width:auto;">
                <option value="">— Tous clubs —</option>
            </select>
            <select id="sel-departement" class="filter-ctl" style="width:auto;">
                <option value="">Tous</option>
            </select>
            <select id="sel-division" class="filter-ctl" style="width:180px;">
                <option value="">— Toutes divisions —</option>
            </select>
            <input type="search" id="search-nom" class="filter-ctl" placeholder="Nom…" style="width:260px;">
            <span id="lbl-count">0 / 0</span>
        </div>
        <div id="table-wrapper">
            <table id="tbl-equipes">
                <thead>
                    <tr>
                        <th data-col="0">Nom<span class="sort-icon"></span></th>
                        <th style="width:80px" data-col="1">Division<span class="sort-icon"></span></th>
                        <th data-col="2">Club<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="3">Id_Club<span class="sort-icon"></span></th>
                        <th style="width:75px" data-col="4">Dépt<span class="sort-icon"></span></th>
                        <th style="width:85px" data-col="5">Réengag.<span class="sort-icon"></span></th>
                        <th style="width:95px" data-col="6">Jour souh.<span class="sort-icon"></span></th>
                        <th style="width:85px" data-col="7">Souhait JA<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="8">Désid. saison<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="9" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une équipe dans la liste pour la modifier.</div>

        <div id="form-equipe" style="display:none;">
            <div class="mb-2">
                <span class="form-label d-block">Id_Equipe</span>
                <div class="form-readonly" id="txt-id"></div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-nom">Nom</label>
                <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
            </div>

            <div class="mb-2">
                <label class="form-label" for="edit-sel-division">Division</label>
                <select id="edit-sel-division" class="form-select form-select-sm"></select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="edit-sel-club">Club</label>
                <select id="edit-sel-club" class="form-select form-select-sm"></select>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label" for="sel-reengagement">Réengagement</label>
                <select id="sel-reengagement" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="O">Oui</option>
                    <option value="N">Non</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-jour-souhaite">Jour souhaité</label>
                <select id="sel-jour-souhaite" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="Samedi">Samedi</option>
                    <option value="Dimanche">Dimanche</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-souhait-ja">Souhait JA</label>
                <select id="sel-souhait-ja" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="CRA">CRA</option>
                    <option value="Club">Club</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-desiderata-saison">Saison désidérata</label>
                <input type="text" id="txt-desiderata-saison" class="form-control form-control-sm" maxlength="9" placeholder="2025-2026">
            </div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
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
const EQUIPE_BASE = '<?= site_url('gestion-equipes') ?>';
const DIVISION_NOMS = <?= json_encode($divisionNoms ?? [], JSON_UNESCAPED_UNICODE) ?>;
function libDivision(code) {
    const n = DIVISION_NOMS[code];
    return n ? code + ' — ' + n : code;
}
let equipes     = [];
let clubs       = [];
let divisions   = [];
let departements = [];
let currentId   = null;
let clubFiltre       = '';
let departementFiltre = '';
let divisionFiltre   = '';
let searchTerm        = '';
const sortState = { col: null, asc: true };

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function equipesFiltrees() {
    const term = searchTerm.toLowerCase();
    return equipes.filter(e => {
        if (clubFiltre && e.Id_Club !== clubFiltre) return false;
        if (departementFiltre && e.Departement !== departementFiltre) return false;
        if (divisionFiltre && e.Division !== divisionFiltre) return false;
        if (term && !String(e.Nom ?? '').toLowerCase().includes(term)) return false;
        return true;
    });
}

function chargerListe(selectId = null) {
    $.get(`${EQUIPE_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        equipes      = res.equipes;
        clubs        = res.clubs;
        divisions    = res.divisions;
        departements = res.departements;
        peuplerFiltres();
        peuplerSelectsFormulaire();
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function peuplerFiltres() {
    const clubsUtilises = new Map();
    equipes.forEach(e => clubsUtilises.set(e.Id_Club, e.NomClub));

    const $selClub = $('#sel-club');
    const valClub   = $selClub.val();
    $selClub.find('option:not(:first)').remove();
    [...clubsUtilises.entries()].sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(([id, nom]) => $selClub.append(new Option(nom, id)));
    $selClub.val(valClub);

    const $selDept = $('#sel-departement');
    const valDept   = $selDept.val();
    $selDept.find('option:not(:first)').remove();
    departements.forEach(d => $selDept.append(new Option(`${d.CodeDept} - ${d.nom}`, d.CodeDept)));
    $selDept.val(valDept);

    const $selDiv = $('#sel-division');
    const valDiv   = $selDiv.val();
    $selDiv.find('option:not(:first)').remove();
    divisions.forEach(d => $selDiv.append(new Option(libDivision(d), d)));
    $selDiv.val(valDiv);
}

function peuplerSelectsFormulaire() {
    const $selDiv = $('#edit-sel-division').empty();
    divisions.forEach(d => $selDiv.append(new Option(libDivision(d), d)));

    const $selClub = $('#edit-sel-club').empty();
    clubs.forEach(c => $selClub.append(new Option(c.Nom, c.Id_Club)));
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = equipesFiltrees();
    $('#lbl-count').text(`${affichees.length} / ${equipes.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="9" class="text-center text-muted py-3">Aucune équipe.</td></tr>');
        return;
    }

    affichees.forEach(e => {
        $('<tr>').attr('data-id', e.Id_Equipe).append(
            $('<td>').text(e.Nom ?? ''),
            $('<td>').text(e.Division ?? ''),
            $('<td>').text(e.NomClub ?? ''),
            $('<td>').text(e.Id_Club ?? ''),
            $('<td>').text(e.Departement ?? ''),
            $('<td>').text(e.ReEngagement ?? ''),
            $('<td>').text(e.JourSouhaite ?? ''),
            $('<td>').text(e.SouhaitJA ?? ''),
            $('<td>').text(e.DesiderataSaison ?? '')
        ).on('click', function () { selectionnerLigne($(this)); }).appendTo($body);
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
    const e  = equipes.find(x => x.Id_Equipe == id);
    if (!e) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-equipe').show();
    $('#txt-id').text(e.Id_Equipe ?? '');
    $('#txt-nom').val(e.Nom ?? '');
    $('#edit-sel-division').val(e.Division ?? '');
    $('#edit-sel-club').val(e.Id_Club ?? '');
    $('#sel-reengagement').val(e.ReEngagement ?? '');
    $('#sel-jour-souhaite').val(e.JourSouhaite ?? '');
    $('#sel-souhait-ja').val(e.SouhaitJA ?? '');
    $('#txt-desiderata-saison').val(e.DesiderataSaison ?? '');
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const payload = {
        nom:               $('#txt-nom').val().trim(),
        division:          $('#edit-sel-division').val(),
        id_club:           $('#edit-sel-club').val(),
        re_engagement:     $('#sel-reengagement').val(),
        jour_souhaite:     $('#sel-jour-souhaite').val(),
        souhait_ja:        $('#sel-souhait-ja').val(),
        desiderata_saison: $('#txt-desiderata-saison').val().trim(),
    };

    $.ajax({ url: `${EQUIPE_BASE}/${currentId}`, method: 'PUT', data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#sel-club').on('change', function () { clubFiltre = $(this).val(); renderListe(); });
$('#sel-departement').on('change', function () { departementFiltre = $(this).val(); renderListe(); });
$('#sel-division').on('change', function () { divisionFiltre = $(this).val(); renderListe(); });
$('#search-nom').on('input', function () { searchTerm = $(this).val().trim(); renderListe(); });

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-equipes thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
