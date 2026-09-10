<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Date des rencontres (EN23)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête vert, comme les autres écrans nominateur (E003) */
        #page-header { background: #2e7d32; }

        #panel-liste { width: 68%; }
        .cell-equipe:hover { text-decoration: underline; cursor: pointer; }

        /* Bandeau de filtres : comboboxes « label en encoche » comme EN11
           (nijac.css .combo-field). Strip clair, l'encoche du label reprend
           --strip-bg pour s'y fondre. */
        #menu-strip {
            --strip-bg: #f8fafc;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .4rem .75rem;
            flex-wrap: wrap;
        }
        #menu-strip .btn { margin-top: .6rem; border-radius: 999px; }
        /* Badge « n / n » : pastille arrondie comme EN11 (.count-badge),
           en surchargeant le #lbl-count de nijac-liste-edit.css (plus spécifique). */
        #menu-strip #lbl-count {
            margin-left: 0; margin-top: .6rem;
            height: 2.15rem; padding: 0 .9rem;
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 5.5rem;
            background: #eef2f9; border: 1.5px solid #d3dae6; border-radius: 999px;
            font-size: .82rem; font-weight: 700; color: var(--nijac-blue);
        }

        /* ── Menu « Colonnes » : ligne avec flèches de réordonnancement ── */
        #menu-colonnes-list { min-width: 300px; }
        #menu-colonnes-list .mc-row { display: flex; align-items: center; gap: .25rem; }
        #menu-colonnes-list .mc-row > label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        #menu-colonnes-list .mc-move {
            border: 1px solid #c8d4e8; background: #fff; color: #1a3a6b;
            width: 1.5rem; height: 1.5rem; line-height: 1; font-size: .7rem;
            border-radius: 4px; cursor: pointer; padding: 0; flex-shrink: 0;
        }
        #menu-colonnes-list .mc-move:hover:not(:disabled) { background: #e8eef7; }
        #menu-colonnes-list .mc-move:disabled { opacity: .3; cursor: default; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar3', 'phTitle' => 'Date des rencontres', 'phCode' => 'EN23',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="menu-strip">
            <span class="count-badge" id="lbl-count">0 / 0</span>
            <span style="flex:1"></span>
            <span class="combo-field">
                <details id="menu-colonnes">
                    <summary id="menu-colonnes-resume">Colonnes</summary>
                    <div id="menu-colonnes-list"></div>
                </details>
            </span>
            <span class="combo-field">
                <label for="sel-dept">Département</label>
                <select id="sel-dept" style="width:170px;">
                    <option value="">Tous</option>
                    <?php foreach ($deptActifs as $d): ?>
                    <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-division">Division</label>
                <select id="sel-division" style="width:130px;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-poule">Poule</label>
                <select id="sel-poule" style="width:110px;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-journee">Journée</label>
                <select id="sel-journee" style="width:120px;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-date">Date</label>
                <select id="sel-date" style="width:auto;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="search-equipe">Équipe</label>
                <input type="search" id="search-equipe" placeholder="Équipe…" style="width:220px;">
            </span>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>
        </div>
        <div id="table-wrapper">
            <table id="tbl-rencontres">
                <thead>
                    <tr>
                        <th style="width:140px" data-field="date">Date<span class="sort-icon"></span></th>
                        <th style="width:60px" data-field="heure">Heure<span class="sort-icon"></span></th>
                        <th style="width:55px" data-field="poule">Poule<span class="sort-icon"></span></th>
                        <th style="width:65px" data-field="journee">Journée<span class="sort-icon"></span></th>
                        <th style="width:70px" data-field="division">Division<span class="sort-icon"></span></th>
                        <th data-field="domicile">Domicile<span class="sort-icon"></span></th>
                        <th data-field="exterieur">Extérieur<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une rencontre dans la liste pour modifier sa date ou son heure.</div>

        <div id="form-rencontre" style="display:none;">
            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <span class="form-label d-block">Rencontre</span>
                    <div class="form-readonly">
                        <span id="txt-dom"></span> vs <span id="txt-ext"></span>
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

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
                <button class="btn btn-sm btn-nouveau px-3" id="btn-annuler">Annuler</button>
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
const RENCONTRE_BASE = '<?= site_url('rencontres-date') ?>';
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
        const $tdDom = $('<td>').attr('data-field', 'domicile').addClass('cell-equipe').text(r.NomDom ?? '')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomDom, r.Id_Rencontre); });
        const $tdExt = $('<td>').attr('data-field', 'exterieur').addClass('cell-equipe').text(r.NomExt ?? '—')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomExt, r.Id_Rencontre); });
        $('<tr>').attr('data-id', r.Id_Rencontre).append(
            $('<td>').attr('data-field', 'date').text(date),
            $('<td>').attr('data-field', 'heure').text(heure),
            $('<td>').attr('data-field', 'poule').text(r.Poule ?? ''),
            $('<td>').attr('data-field', 'journee').text(r.Journee ?? ''),
            $('<td>').attr('data-field', 'division').append(macaronDivision(r.Division, r.DivisionColor)),
            $tdDom,
            $tdExt
        ).on('click', function () { selectionnerLigne($(this)); }).appendTo($body);
    });

    appliquerColonnesCachees();
    appliquerOrdreColonnes();

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
    $('#txt-dom').text(r.NomDom ?? '');
    $('#txt-ext').text(r.NomExt ?? '—');
    $('#txt-division').empty().append(macaronDivision(r.Division, r.DivisionColor));
    $('#txt-date').val(r.Date ? r.Date.substring(0, 10) : '');
    $('#sel-heure').val((r.Heure ?? '').substring(0, 5));
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const payload = {
        date:  $('#txt-date').val(),
        heure: $('#sel-heure').val(),
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
    $('#search-equipe').val('');
    $('#sel-dept, #sel-division, #sel-poule, #sel-journee, #sel-date').val('');
    renderListe();
});

// ── Affichage / masquage / ordre des colonnes (mémorisé dans le navigateur) ──
const LS_COLONNES = 'nijac_en23_colonnes_cachees';
const LS_ORDRE    = 'nijac_en23_colonnes_ordre';
// Ordre naturel du thead = source de vérité des champs existants.
const CHAMPS_NATURELS = [...document.querySelectorAll('#tbl-rencontres thead th[data-field]')]
    .map(th => th.getAttribute('data-field'));

let colonnesCachees;
try {
    const brut = localStorage.getItem(LS_COLONNES);
    colonnesCachees = new Set(brut !== null ? JSON.parse(brut) : []);
} catch (e) { colonnesCachees = new Set(); }

let ordreColonnes;
try {
    const brut = localStorage.getItem(LS_ORDRE);
    ordreColonnes = brut !== null ? JSON.parse(brut) : [...CHAMPS_NATURELS];
} catch (e) { ordreColonnes = [...CHAMPS_NATURELS]; }
// Réconcilie avec le thead réel (champ ajouté / retiré depuis la dernière visite).
ordreColonnes = ordreColonnes.filter(f => CHAMPS_NATURELS.includes(f));
CHAMPS_NATURELS.forEach(f => { if (!ordreColonnes.includes(f)) ordreColonnes.push(f); });

function persistOrdre() {
    try { localStorage.setItem(LS_ORDRE, JSON.stringify(ordreColonnes)); } catch (e) {}
}

function appliquerColonnesCachees() {
    document.querySelectorAll('#tbl-rencontres [data-field]').forEach(el => {
        el.style.display = colonnesCachees.has(el.getAttribute('data-field')) ? 'none' : '';
    });
    const total = CHAMPS_NATURELS.length;
    $('#menu-colonnes-resume').text(`Colonnes ${total - colonnesCachees.size}/${total}`);
}

// Réordonne physiquement les cellules [data-field] de chaque ligne (thead + tbody)
// selon ordreColonnes. thead et tbody restent alignés → le tri par index marche.
function appliquerOrdreColonnes() {
    document.querySelectorAll('#tbl-rencontres tr').forEach(tr => {
        const parCle = {}, reste = [];
        [...tr.children].forEach(c => {
            const f = c.getAttribute('data-field');
            if (f) parCle[f] = c; else reste.push(c);
        });
        ordreColonnes.forEach(f => { if (parCle[f]) tr.appendChild(parCle[f]); });
        reste.forEach(c => tr.appendChild(c));
    });
}

function deplacerColonne(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= ordreColonnes.length) return;
    [ordreColonnes[i], ordreColonnes[j]] = [ordreColonnes[j], ordreColonnes[i]];
    persistOrdre();
    construireMenuColonnes();
    appliquerOrdreColonnes();
}

function construireMenuColonnes() {
    const $box = $('#menu-colonnes-list').empty();
    const labels = {};
    document.querySelectorAll('#tbl-rencontres thead th[data-field]').forEach(th => {
        labels[th.getAttribute('data-field')] = (th.textContent || '').replace(/[⇅▲▼]/g, '').trim();
    });
    ordreColonnes.forEach((field, i) => {
        const $chk = $('<input type="checkbox">').prop('checked', !colonnesCachees.has(field));
        $chk.on('change', function () {
            if (this.checked) colonnesCachees.delete(field);
            else              colonnesCachees.add(field);
            try { localStorage.setItem(LS_COLONNES, JSON.stringify([...colonnesCachees])); } catch (e) {}
            appliquerColonnesCachees();
        });
        const $up   = $('<button type="button" class="mc-move" title="Monter">▲</button>').prop('disabled', i === 0);
        const $down = $('<button type="button" class="mc-move" title="Descendre">▼</button>').prop('disabled', i === ordreColonnes.length - 1);
        $up.on('click',   () => deplacerColonne(i, -1));
        $down.on('click', () => deplacerColonne(i,  1));
        $('<div class="mc-row">')
            .append($('<label>').append($chk).append(document.createTextNode(' ' + (labels[field] || field))))
            .append($up).append($down)
            .appendTo($box);
    });
}
construireMenuColonnes();
appliquerColonnesCachees();
appliquerOrdreColonnes();

// Ferme le menu « Colonnes » au clic hors de celui-ci
$(document).on('click', function (e) {
    if (!$(e.target).closest('#menu-colonnes').length) $('#menu-colonnes').removeAttr('open');
});

// ── Tri sur clic en-tête (par champ : reste correct même colonnes réordonnées) ─
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
function trierLignes() {
    if (sortState.col == null) return;
    const ths = [...document.querySelectorAll('#tbl-rencontres thead th[data-field]')];
    const idx = ths.findIndex(th => th.getAttribute('data-field') === sortState.col);
    if (idx >= 0) nijacSortRows('#tbody-liste', idx, sortState.asc);
}
$(function () {
    nijacSortableTable('#tbl-rencontres thead th[data-field]', 'field', sortState, trierLignes);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
