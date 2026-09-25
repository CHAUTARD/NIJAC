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
        /* Beaucoup de colonnes (toutes les colonnes de `rencontre`) : défilement
           horizontal plutôt que de casser la mise en page du panneau. */
        #table-wrapper { overflow-x: auto; }

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
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar3', 'phTitle' => 'Gestion des rencontres', 'phCode' => 'EA95',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu') . '#tab-tables', 'phBackUrl' => site_url('admin-menu') . '#tab-tables',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="menu-strip">
            <button type="button" class="btn btn-sm btn-outline-warning" id="btn-doublons" title="N'afficher que les rencontres en doublon : même affiche (domicile / extérieur / phase), quelles que soient la date, l'heure ou la journée">
                <i class="bi bi-files"></i> Doublons
            </button>
            <span style="flex:1"></span>
            <span class="count-badge" id="lbl-count">0 / 0</span>
            <span style="flex:1"></span>
            <span class="combo-field">
                <label for="sel-dept">Département</label>
                <select id="sel-dept" style="width:260px;">
                    <option value="">Tous</option>
                    <option value="76+27">76 + 27 — Seine-Maritime + Eure</option>
                    <?php foreach ($deptActifs as $d): ?>
                    <?php if (in_array((string) $d['CodeDept'], ['76', '27'], true)) continue; // fusionnés dans « 76 + 27 » ?>
                    <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="combo-field">
                <label>Division</label>
                <div id="panel-division"></div>
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
                        <th class="th-pk" style="width:90px" data-col="0">Id_Rencontre<span class="sort-icon"></span></th>
                        <th style="width:140px" data-col="1">Date<span class="sort-icon"></span></th>
                        <th style="width:60px" data-col="2">Heure<span class="sort-icon"></span></th>
                        <th style="width:55px" data-col="3">Poule<span class="sort-icon"></span></th>
                        <th style="width:65px" data-col="4">Journée<span class="sort-icon"></span></th>
                        <th style="width:55px" data-col="5">Phase<span class="sort-icon"></span></th>
                        <th style="width:70px" data-col="6">Division<span class="sort-icon"></span></th>
                        <th data-col="7">Domicile<span class="sort-icon"></span></th>
                        <th data-col="8">Extérieur<span class="sort-icon"></span></th>
                        <th style="width:75px" data-col="9">Id_Salle<span class="sort-icon"></span></th>
                        <th style="width:110px" data-col="10">Arbitrage<span class="sort-icon"></span></th>
                        <th data-col="11">Commentaire<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="12" class="text-center text-muted py-3">Chargement…</td></tr>
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
                    <span class="form-label d-block">Division</span>
                    <div class="form-readonly" id="txt-division"></div>
                </div>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label" for="edit-equipe-dom">Équipe domicile</label>
                <input type="text" id="edit-equipe-dom" class="form-control form-control-sm" list="dl-equipes" placeholder="Rechercher une équipe…" autocomplete="off">
            </div>

            <div class="mb-2">
                <label class="form-label" for="edit-equipe-ext">Équipe extérieure</label>
                <input type="text" id="edit-equipe-ext" class="form-control form-control-sm" list="dl-equipes" placeholder="Rechercher une équipe… (vide = exempt)" autocomplete="off">
                <datalist id="dl-equipes"></datalist>
            </div>

            <div class="row g-2 mb-2">
                <div class="col">
                    <label class="form-label" for="txt-date">Date</label>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary" id="btn-date-moins"><i class="bi bi-dash-lg"></i></button>
                        <input type="date" id="txt-date" class="form-control form-control-sm">
                        <button type="button" class="btn btn-outline-secondary" id="btn-date-plus"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
                <div class="col">
                    <label class="form-label" for="sel-heure">Heure</label>
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary" id="btn-heure-moins"><i class="bi bi-dash-lg"></i></button>
                        <input type="time" id="sel-heure" class="form-control form-control-sm" step="60">
                        <button type="button" class="btn btn-outline-secondary" id="btn-heure-plus"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <label class="form-label" for="txt-poule">Poule</label>
                    <input type="number" id="txt-poule" class="form-control form-control-sm" min="0" step="1" style="width:90px;">
                </div>
                <div class="col-auto">
                    <label class="form-label" for="txt-journee">Journée</label>
                    <input type="number" id="txt-journee" class="form-control form-control-sm" min="0" step="1" style="width:90px;">
                </div>
                <div class="col-auto">
                    <label class="form-label" for="txt-phase">Phase</label>
                    <input type="number" id="txt-phase" class="form-control form-control-sm" min="1" max="2" step="1" style="width:90px;">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-salle">Salle</label>
                <select id="sel-salle" class="form-select form-select-sm">
                    <option value="">—</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-arbitrage-obligatoire">Arbitrage</label>
                <select id="sel-arbitrage-obligatoire" class="form-select form-select-sm">
                    <option value="1">CRA</option>
                    <option value="0">Club</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-commentaire">Commentaire</label>
                <textarea id="txt-commentaire" class="form-control form-control-sm" rows="2"></textarea>
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
let equipes    = [];
let salles     = [];
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

/** Texte de l'info-bulle (survol) de la cellule Extérieur : club + correspondant. */
function infosExterieur(r) {
    if (!r.NomExt) return '';
    const lignes = [r.NomExt];
    if (r.NomClubExt) lignes.push(r.NomClubExt + (r.IdClubExt ? ' (' + r.IdClubExt + ')' : ''));
    if (r.CorrNomExt) lignes.push('Correspondant : ' + r.CorrNomExt);
    if (r.CorrEmailExt) lignes.push(r.CorrEmailExt);
    if (r.CorrTelExt) lignes.push(r.CorrTelExt);
    return lignes.join('\n');
}

/** Texte de l'info-bulle (survol) de la cellule Salle : nom + adresse complète. */
function infosSalle(r) {
    if (!r.NomSalle) return '';
    const adresse = [r.AdresseSalle, [r.CpSalle, r.VilleSalle].filter(Boolean).join(' ')].filter(Boolean).join('\n');
    return adresse ? r.NomSalle + '\n' + adresse : r.NomSalle;
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
        if (deptFiltre) {
            const depts = deptFiltre.split('+');
            if (!depts.includes(deptDeClub(r.IdClubDom))) return false;
        }
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
        equipes    = res.equipes || [];
        salles     = res.salles || [];
        peuplerFiltres();
        peuplerDatalistEquipes();
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function libEquipe(idEquipe) {
    const e = equipes.find(x => x.Id_Equipe == idEquipe);
    return e ? `${e.Nom} (${e.Id_Equipe})` : '';
}

/** Résout le texte saisi dans un champ Équipe (avec datalist) vers un Id_Equipe, ou '' si aucune correspondance. */
function idEquipeDepuisSaisie(texte) {
    const t = (texte ?? '').trim();
    if (t === '') return '';
    const m = /\((\d+)\)\s*$/.exec(t);
    if (m && equipes.some(e => e.Id_Equipe == m[1])) return m[1];
    const e = equipes.find(x => x.Nom === t);
    return e ? String(e.Id_Equipe) : '';
}

function peuplerDatalistEquipes() {
    const $dl = $('#dl-equipes').empty();
    equipes.forEach(e => $dl.append(new Option(`${e.Nom} (${e.Id_Equipe})`)));
}

/** Peuple #sel-salle avec la ou les salles du club passé, en conservant idSalleActuelle si elle en fait partie. */
function majSelectSalle(idClub, idSalleActuelle) {
    const $sel = $('#sel-salle').empty().append('<option value="">—</option>');
    salles.filter(s => s.Id_Club === idClub)
        .sort((a, b) => (b.EstPrincipale - a.EstPrincipale) || a.Nom.localeCompare(b.Nom))
        .forEach(s => $sel.append(new Option(s.Nom + (s.EstPrincipale == 1 ? ' (principale)' : ''), s.Id_Salle)));
    $sel.val(idSalleActuelle ?? '');
}

/** Division + salles proposées suivent l'équipe domicile actuellement saisie. */
function majApresChangementDomicile() {
    const idEquipeDom = idEquipeDepuisSaisie($('#edit-equipe-dom').val());
    const e = equipes.find(x => x.Id_Equipe == idEquipeDom);
    const color = e ? rencontres.find(r => r.Division === e.Division)?.DivisionColor : null;
    $('#txt-division').empty().append(e ? macaronDivision(e.Division, color) : '');
    majSelectSalle(e ? e.Id_Club : null, $('#sel-salle').val());
}

function majPanelDivision() {
    const divisions = [...new Set(rencontres.map(r => r.Division).filter(Boolean))].sort();
    nijacDivisionFilter('#panel-division', divisions, {
        libDivision,
        colorFor: code => rencontres.find(r => r.Division === code)?.DivisionColor,
        getFiltre: () => divisionFiltre,
        onSelect: code => { divisionFiltre = code; majPanelDivision(); renderListe(); },
    });
}

function peuplerFiltres() {
    majPanelDivision();

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
        $body.append('<tr><td colspan="12" class="text-center text-muted py-3">Aucune rencontre.</td></tr>');
        return;
    }

    affichees.forEach(r => {
        const date = formatDateAvecJour(r.Date);
        const heure = (r.Heure ?? '').substring(0, 5);
        const $tdDom = $('<td>').addClass('cell-equipe').text(r.NomDom ?? '')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomDom, r.Id_Rencontre); });
        const $tdExt = $('<td>').addClass('cell-equipe').attr('title', infosExterieur(r)).text(r.NomExt ?? '—')
            .on('click', function (e) { e.stopPropagation(); filtrerParEquipe(r.NomExt, r.Id_Rencontre); });
        const $tdIdSalle = $('<td>').attr('title', infosSalle(r)).text(r.id_Salle ?? '');
        $('<tr>').attr('data-id', r.Id_Rencontre).append(
            $('<td>').text(r.Id_Rencontre ?? ''),
            $('<td>').text(date),
            $('<td>').text(heure),
            $('<td>').text(r.Poule ?? ''),
            $('<td>').text(r.Journee ?? ''),
            $('<td>').text(r.Phase ?? ''),
            $('<td>').append(macaronDivision(r.Division, r.DivisionColor)),
            $tdDom,
            $tdExt,
            $tdIdSalle,
            $('<td>').text(r.ArbitrageCRA == 1 ? 'CRA' : 'Club'),
            $('<td>').text(r.Commentaire ?? '')
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
    $('#edit-equipe-dom').val(libEquipe(r.Id_EquipeDom));
    $('#edit-equipe-ext').val(r.Id_EquipeExt ? libEquipe(r.Id_EquipeExt) : '');
    $('#txt-division').empty().append(macaronDivision(r.Division, r.DivisionColor));
    $('#txt-date').val(r.Date ? r.Date.substring(0, 10) : '');
    $('#sel-heure').val((r.Heure ?? '').substring(0, 5));
    $('#txt-poule').val(r.Poule ?? '');
    $('#txt-journee').val(r.Journee ?? '');
    $('#txt-phase').val(r.Phase ?? '');
    majSelectSalle(r.IdClubDom, r.id_Salle);
    $('#sel-arbitrage-obligatoire').val(r.ArbitrageCRA == 1 ? '1' : '0');
    $('#txt-commentaire').val(r.Commentaire ?? '');
    setStatus('');
}

$('#edit-equipe-dom').on('change', majApresChangementDomicile);

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;

    const idEquipeDom = idEquipeDepuisSaisie($('#edit-equipe-dom').val());
    if (!idEquipeDom) { setStatus('Équipe domicile introuvable : choisissez-la dans la liste proposée.', false); return; }
    const texteExt = $('#edit-equipe-ext').val().trim();
    const idEquipeExt = texteExt === '' ? '' : idEquipeDepuisSaisie(texteExt);
    if (texteExt !== '' && !idEquipeExt) { setStatus('Équipe extérieure introuvable : choisissez-la dans la liste proposée, ou laissez vide (exempt).', false); return; }

    const payload = {
        date:                   $('#txt-date').val(),
        heure:                  $('#sel-heure').val(),
        poule:                  $('#txt-poule').val(),
        journee:                $('#txt-journee').val(),
        phase:                  $('#txt-phase').val(),
        id_equipe_dom:          idEquipeDom,
        id_equipe_ext:          idEquipeExt,
        id_salle:               $('#sel-salle').val(),
        arbitrage_obligatoire:  $('#sel-arbitrage-obligatoire').val(),
        commentaire:            $('#txt-commentaire').val().trim(),
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

// Suppression d'une rencontre — depuis le bouton du panneau d'édition ou la
// dernière colonne de la liste.
function supprimerRencontre(id, libelle) {
    nijacConfirm(`Supprimer la rencontre ${libelle} ?`, function () {
        $.ajax({ url: `${RENCONTRE_BASE}/${id}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (!res.ok) { toast(res.msg, false); return; }
            toast(res.msg);
            if (currentId == id) {
                currentId = null;
                $('#form-rencontre').hide();
                $('#no-selection').show();
            }
            chargerListe();
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'danger' });
}

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    supprimerRencontre(currentId, `${$('#edit-equipe-dom').val()} vs ${$('#edit-equipe-ext').val() || 'exempt'}`);
});

$('#search-equipe').on('input', function () { searchEquipe = $(this).val().trim(); renderListe(); });
$('#sel-dept').on('change', function () { deptFiltre = $(this).val(); renderListe(); });
$('#sel-poule').on('change', function () { pouleFiltre = $(this).val(); renderListe(); });
$('#sel-journee').on('change', function () { journeeFiltre = $(this).val(); renderListe(); });
$('#sel-date').on('change', function () { dateFiltre = $(this).val(); renderListe(); });

// Champ <input type="time"> : saisie libre 00:00–23:59 ; les boutons -/+ décalent
// de 15 min, bornés (pas de bascule minuit).
function decalerHeure(delta) {
    const $inp = $('#sel-heure');
    const [h, m] = ($inp.val() || '09:00').split(':').map(Number);
    const tot = Math.max(0, Math.min(1439, h * 60 + m + delta * 15));
    const pad = n => String(n).padStart(2, '0');
    $inp.val(`${pad(Math.floor(tot / 60))}:${pad(tot % 60)}`);
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
    $('#sel-dept, #sel-poule, #sel-journee, #sel-date').val('');
    majPanelDivision();
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
<script src="<?= base_url('asset/js/nijac-division-filter.js') ?>"></script>
</body>
</html>
