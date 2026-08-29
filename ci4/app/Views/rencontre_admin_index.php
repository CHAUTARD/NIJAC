<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des rencontres (EA95)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 68%; }
        .cell-equipe:hover { text-decoration: underline; cursor: pointer; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar3', 'phTitle' => 'Gestion des rencontres', 'phCode' => 'EA95',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Rencontres</span>
            <input type="search" id="search-equipe" placeholder="🔍 Équipe…" style="width:220px;">
            <select id="sel-dept" class="form-select form-select-sm" style="width:170px;">
                <option value="">— Département —</option>
                <?php foreach ($deptActifs as $d): ?>
                <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="sel-division" class="form-select form-select-sm" style="width:130px;">
                <option value="">— Division —</option>
            </select>
            <select id="sel-poule" class="form-select form-select-sm" style="width:110px;">
                <option value="">— Poule —</option>
            </select>
            <select id="sel-journee" class="form-select form-select-sm" style="width:120px;">
                <option value="">— Journée —</option>
            </select>
            <select id="sel-date" class="form-select form-select-sm" style="width:auto;">
                <option value="">— Date —</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-warning" id="btn-doublons" title="N'afficher que les rencontres en doublon : même affiche (domicile / extérieur / phase), quelles que soient la date, l'heure ou la journée">
                <i class="bi bi-files"></i> Doublons
            </button>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>
            <span id="lbl-count">0 / 0</span>
        </div>
        <div id="table-wrapper">
            <table id="tbl-rencontres">
                <thead>
                    <tr>
                        <th style="width:140px" data-col="0">Date<span class="sort-icon"></span></th>
                        <th style="width:60px" data-col="1">Heure<span class="sort-icon"></span></th>
                        <th style="width:55px" data-col="2">Poule<span class="sort-icon"></span></th>
                        <th style="width:65px" data-col="3">Journée<span class="sort-icon"></span></th>
                        <th style="width:70px" data-col="4">Division<span class="sort-icon"></span></th>
                        <th data-col="5">Domicile<span class="sort-icon"></span></th>
                        <th data-col="6">Extérieur<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une rencontre dans la liste pour la modifier.</div>

        <div id="form-rencontre" style="display:none;">
            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <span class="form-label d-block">Id_Rencontre</span>
                    <div class="form-readonly" id="txt-id"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Rencontre</span>
                    <div class="form-readonly">
                        <span id="txt-dom"></span> / <span id="txt-ext"></span>
                    </div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Division</span>
                    <div class="form-readonly" id="txt-division"></div>
                </div>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label" for="txt-date">Date</label>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="btn-date-moins"><i class="bi bi-dash-lg"></i></button>
                    <input type="date" id="txt-date" class="form-control form-control-sm">
                    <button type="button" class="btn btn-outline-secondary" id="btn-date-plus"><i class="bi bi-plus-lg"></i></button>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-heure">Heure</label>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary" id="btn-heure-moins"><i class="bi bi-dash-lg"></i></button>
                    <select id="sel-heure" class="form-select form-select-sm">
                        <option value="09:00">9h00</option>
                        <option value="14:00">14h00</option>
                        <option value="16:00">16h00</option>
                    </select>
                    <button type="button" class="btn btn-outline-secondary" id="btn-heure-plus"><i class="bi bi-plus-lg"></i></button>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-poule">Poule</label>
                <input type="number" id="txt-poule" class="form-control form-control-sm" min="0" step="1">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-journee">Journée</label>
                <input type="number" id="txt-journee" class="form-control form-control-sm" min="0" step="1">
            </div>

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
const RENCONTRE_BASE = '<?= site_url('gestion-rencontres') ?>';
const DIVISION_NOMS = <?= json_encode($divisionNoms ?? [], JSON_UNESCAPED_UNICODE) ?>;
function libDivision(code) {
    const n = DIVISION_NOMS[code];
    return n ? code + ' — ' + n : code;
}
let rencontres = [];
let currentId  = null;
let searchEquipe  = '';
let deptFiltre = '';
let divisionFiltre = '';
let pouleFiltre   = '';
let journeeFiltre = '';
let dateFiltre    = '';
let doublonsIds   = null;   // null = filtre inactif ; sinon tableau d'Id_Rencontre (chaînes)
const sortState = { col: null, asc: true };

const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

/** "YYYY-MM-DD" → "Samedi 19/09/2026". Construction via composants locaux (pas de décalage UTC). */
function formatDateAvecJour(dateStr) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.substring(0, 10).split('-').map(Number);
    const jour = JOURS_SEMAINE[new Date(y, m - 1, d).getDay()];
    return `${jour} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

/* Calcule la luminosité d'une couleur hex et retourne '#fff' ou '#111' selon le contraste */
function textColorFor(hex) {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0,2), 16);
    const g = parseInt(c.substring(2,4), 16);
    const b = parseInt(c.substring(4,6), 16);
    const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return lum > 0.55 ? '#111' : '#fff';
}

// Format Id_Club : 0[9][dept 2 chiffres][4 chiffres] — ex. 09760442 → '76'
function deptDeClub(idClub) {
    return /^\d{8}$/.test(idClub ?? '') ? idClub.substring(2, 4) : '';
}

function macaronDivision(division, color) {
    const bg = color && /^#[0-9a-fA-F]{6}$/.test(color) ? color : '#1a3a6b';
    return $('<span class="badge">').text(division ?? '').css({ background: bg, color: textColorFor(bg) });
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function rencontresFiltrees() {
    const equipe = searchEquipe.toLowerCase();
    return rencontres.filter(r => {
        if (equipe
            && !String(r.NomDom ?? '').toLowerCase().includes(equipe)
            && !String(r.NomExt ?? '').toLowerCase().includes(equipe)) return false;
        if (deptFiltre && deptDeClub(r.IdClubDom) !== deptFiltre) return false;
        if (divisionFiltre && String(r.Division ?? '') !== divisionFiltre) return false;
        if (pouleFiltre && String(r.Poule ?? '') !== pouleFiltre) return false;
        if (journeeFiltre && String(r.Journee ?? '') !== journeeFiltre) return false;
        if (dateFiltre && (r.Date ?? '').substring(0, 10) !== dateFiltre) return false;
        if (doublonsIds && !doublonsIds.includes(String(r.Id_Rencontre))) return false;
        return true;
    });
}

function chargerListe(selectId = null) {
    $.get(`${RENCONTRE_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        rencontres = res.rencontres;
        peuplerFiltres();
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function peuplerFiltres() {
    const divisions = [...new Set(rencontres.map(r => r.Division).filter(Boolean))].sort();
    const $selDivision = $('#sel-division');
    const valDivision   = $selDivision.val();
    $selDivision.find('option:not(:first)').remove();
    divisions.forEach(d => $selDivision.append(new Option(libDivision(d), d)));
    $selDivision.val(valDivision);

    const poules = [...new Set(rencontres.map(r => r.Poule).filter(p => p !== null))].sort((a, b) => a - b);
    const $selPoule = $('#sel-poule');
    const valPoule   = $selPoule.val();
    $selPoule.find('option:not(:first)').remove();
    poules.forEach(p => $selPoule.append(new Option(p, p)));
    $selPoule.val(valPoule);

    const journees = [...new Set(rencontres.map(r => r.Journee).filter(j => j !== null))].sort((a, b) => a - b);
    const $selJournee = $('#sel-journee');
    const valJournee   = $selJournee.val();
    $selJournee.find('option:not(:first)').remove();
    journees.forEach(j => $selJournee.append(new Option(j, j)));
    $selJournee.val(valJournee);

    const dates = [...new Set(rencontres.map(r => (r.Date ?? '').substring(0, 10)).filter(Boolean))].sort();
    const $selDate = $('#sel-date');
    const valDate   = $selDate.val();
    $selDate.find('option:not(:first)').remove();
    dates.forEach(d => $selDate.append(new Option(formatDateAvecJour(d), d)));
    $selDate.val(valDate);
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = rencontresFiltrees();
    $('#lbl-count').text(`${affichees.length} / ${rencontres.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="7" class="text-center text-muted py-3">Aucune rencontre.</td></tr>');
        return;
    }

    affichees.forEach(r => {
        const date = formatDateAvecJour(r.Date);
        const heure = (r.Heure ?? '').substring(0, 5);
        const $tdDom = $('<td>').addClass('cell-equipe').text(r.NomDom ?? '')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomDom, r.Id_Rencontre); });
        const $tdExt = $('<td>').addClass('cell-equipe').text(r.NomExt ?? '—')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomExt, r.Id_Rencontre); });
        $('<tr>').attr('data-id', r.Id_Rencontre).append(
            $('<td>').text(date),
            $('<td>').text(heure),
            $('<td>').text(r.Poule ?? ''),
            $('<td>').text(r.Journee ?? ''),
            $('<td>').append(macaronDivision(r.Division, r.DivisionColor)),
            $tdDom,
            $tdExt
        ).on('click', function () { selectionnerLigne($(this)); }).appendTo($body);
    });

    if (currentId) {
        const $tr = $(`#tbody-liste tr[data-id="${currentId}"]`);
        if ($tr.length) $tr.addClass('selected');
    }
}

function filtrerParEquipe(nom, id) {
    if (!nom) return;
    searchEquipe = nom;
    $('#search-equipe').val(nom);
    renderListe();
    const $tr = $(`#tbody-liste tr[data-id="${id}"]`);
    if ($tr.length) selectionnerLigne($tr);
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = +$tr.attr('data-id');
    const r  = rencontres.find(x => x.Id_Rencontre == id);
    if (!r) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-rencontre').show();
    $('#txt-id').text(r.Id_Rencontre ?? '');
    $('#txt-dom').text(r.NomDom ?? '');
    $('#txt-ext').text(r.NomExt ?? '—');
    $('#txt-division').empty().append(macaronDivision(r.Division, r.DivisionColor));
    $('#txt-date').val(r.Date ? r.Date.substring(0, 10) : '');
    $('#sel-heure').val((r.Heure ?? '').substring(0, 5));
    $('#txt-poule').val(r.Poule ?? '');
    $('#txt-journee').val(r.Journee ?? '');
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const payload = {
        date:    $('#txt-date').val(),
        heure:   $('#sel-heure').val(),
        poule:   $('#txt-poule').val(),
        journee: $('#txt-journee').val(),
    };

    $.ajax({ url: `${RENCONTRE_BASE}/${currentId}`, method: 'PUT', data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#btn-annuler').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#form-rencontre').hide();
    $('#no-selection').show();
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const libelle = `${$('#txt-dom').text()} / ${$('#txt-ext').text()}`;
    nijacConfirm(`Supprimer la rencontre ${libelle} ?`, function () {
        $.ajax({ url: `${RENCONTRE_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) {
                toast(res.msg);
                currentId = null;
                $('#form-rencontre').hide();
                $('#no-selection').show();
                chargerListe();
            } else {
                toast(res.msg, false);
            }
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'danger' });
});

$('#search-equipe').on('input', function () { searchEquipe = $(this).val().trim(); renderListe(); });
$('#sel-dept').on('change', function () { deptFiltre = $(this).val(); renderListe(); });
$('#sel-division').on('change', function () { divisionFiltre = $(this).val(); renderListe(); });
$('#sel-poule').on('change', function () { pouleFiltre = $(this).val(); renderListe(); });
$('#sel-journee').on('change', function () { journeeFiltre = $(this).val(); renderListe(); });
$('#sel-date').on('change', function () { dateFiltre = $(this).val(); renderListe(); });

function decalerHeure(delta) {
    const $sel = $('#sel-heure');
    const idx = $sel.prop('selectedIndex') + delta;
    if (idx >= 0 && idx < $sel.find('option').length) {
        $sel.prop('selectedIndex', idx);
    }
}
$('#btn-heure-moins').on('click', function () { decalerHeure(-1); });
$('#btn-heure-plus').on('click', function () { decalerHeure(1); });

function decalerDateChamp(delta) {
    const $input = $('#txt-date');
    const [y, m, d] = ($input.val() || '').split('-').map(Number);
    if (!y || !m || !d) return;
    const dt = new Date(y, m - 1, d + delta);
    const pad = n => String(n).padStart(2, '0');
    $input.val(`${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`);
}
$('#btn-date-moins').on('click', function () { decalerDateChamp(-1); });
$('#btn-date-plus').on('click', function () { decalerDateChamp(1); });

$('#btn-reset-filtres').on('click', function () {
    searchEquipe = deptFiltre = divisionFiltre = pouleFiltre = journeeFiltre = dateFiltre = '';
    doublonsIds = null;
    $('#btn-doublons').removeClass('active btn-warning').addClass('btn-outline-warning');
    $('#search-equipe').val('');
    $('#sel-dept, #sel-division, #sel-poule, #sel-journee, #sel-date').val('');
    renderListe();
});

$('#btn-doublons').on('click', function () {
    if (doublonsIds) {                    // désactivation
        doublonsIds = null;
        $(this).removeClass('active btn-warning').addClass('btn-outline-warning');
        renderListe();
        return;
    }
    $.get(`${RENCONTRE_BASE}/doublons`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        doublonsIds = (res.ids || []).map(String);
        if (!doublonsIds.length) { toast('Aucune rencontre en doublon.'); return; }
        $('#btn-doublons').addClass('active btn-warning').removeClass('btn-outline-warning');
        renderListe();
        toast(`${res.groupes} groupe(s) de doublons — ${doublonsIds.length} rencontre(s).`);
    }, 'json').fail(() => toast('Erreur réseau.', false));
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-rencontres thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
