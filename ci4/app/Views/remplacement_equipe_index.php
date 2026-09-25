<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Remplacement équipe (EN24)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête vert, comme les autres écrans nominateur (E003) */
        #page-header { background: #2e7d32; }

        #panel-liste { width: 62%; }
        .cell-equipe:hover { text-decoration: underline; cursor: pointer; }

        .badge-ja-oui, .badge-ja-non {
            display: inline-block; padding: .15rem .55rem; border-radius: 999px;
            font-size: .72rem; font-weight: 700;
        }
        .badge-ja-oui { background: #d4edda; color: #155724; }
        .badge-ja-non { background: #f1f3f5; color: #868e96; }

        #recap-equipe {
            background: #f8fafc; border: 1px solid #dde5f0; border-radius: 8px;
            padding: .6rem .8rem; margin-bottom: .8rem;
        }
        #recap-equipe .nom { font-weight: 700; color: var(--nijac-blue); }

        #remplacement-resultats {
            border: 1px solid #dde5f0; border-radius: 6px; margin-top: .25rem;
            max-height: 220px; overflow-y: auto; display: none;
        }
        #remplacement-resultats .item {
            padding: .4rem .6rem; cursor: pointer; font-size: .85rem;
            border-bottom: 1px solid #eef2f9;
        }
        #remplacement-resultats .item:last-child { border-bottom: none; }
        #remplacement-resultats .item:hover { background: #eef2f9; }
        #remplacement-resultats .item .club { color: #6c757d; font-size: .78rem; }

        #equipe-remplacement-choisie {
            display: none; background: #d4edda; border: 1px solid #b6dfc0; border-radius: 8px;
            padding: .5rem .7rem; margin-top: .5rem; font-size: .88rem;
        }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'arrow-left-right', 'phTitle' => 'Remplacement équipe', 'phCode' => 'EN24',
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
                <label for="sel-dept">Département</label>
                <select id="sel-dept" style="width:190px;">
                    <option value="">Tous</option>
                    <option value="76+27">76 + 27 — Seine-Maritime + Eure</option>
                    <?php foreach ($deptActifs as $d): ?>
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
                        <th style="width:140px" data-field="date">Date<span class="sort-icon"></span></th>
                        <th style="width:60px" data-field="heure">Heure<span class="sort-icon"></span></th>
                        <th style="width:55px" data-field="poule">Poule<span class="sort-icon"></span></th>
                        <th style="width:65px" data-field="journee">Journée<span class="sort-icon"></span></th>
                        <th style="width:70px" data-field="division">Division<span class="sort-icon"></span></th>
                        <th data-field="domicile">Domicile<span class="sort-icon"></span></th>
                        <th data-field="exterieur">Extérieur<span class="sort-icon"></span></th>
                        <th style="width:60px" data-field="ja">JA</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Cliquez sur le nom d'une équipe (Domicile ou Extérieur) dans le tableau pour la remplacer.</div>

        <div id="form-remplacement" style="display:none;">
            <div id="recap-equipe">
                Équipe à remplacer : <span class="nom" id="recap-nom"></span><br>
                <span id="recap-club" class="text-muted small"></span>
            </div>

            <label class="form-label" for="search-remplacement">Équipe de remplacement</label>
            <input type="search" id="search-remplacement" class="form-control form-control-sm" placeholder="Rechercher une équipe ou un club…" autocomplete="off">
            <div id="remplacement-resultats"></div>
            <div id="equipe-remplacement-choisie"></div>

            <div id="panel-boutons" class="mt-3">
                <button class="btn btn-sm btn-supprimer px-3" id="btn-remplacer" disabled><i class="bi bi-arrow-left-right me-1"></i>Confirmer le remplacement</button>
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
const BASE = '<?= site_url('remplacement-equipe') ?>';
const DIVISION_NOMS = <?= json_encode($divisionNoms ?? [], JSON_UNESCAPED_UNICODE) ?>;
function libDivision(code) {
    const n = DIVISION_NOMS[code];
    return n ? code + ' — ' + n : code;
}

let rencontres = [];       // toutes les rencontres (comme EN23)
let equipesToutes = [];    // toutes les équipes (recherche de remplacement)
let equipeCourante = null;      // équipe à remplacer (objet issu de equipesToutes)
let equipeRemplacement = null;  // équipe choisie pour la remplacer (objet)

let searchEquipe  = '';
let deptFiltre     = '';
let divisionFiltre = '';
let pouleFiltre    = '';
let journeeFiltre  = '';
let dateFiltre     = '';

const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

/** "YYYY-MM-DD" → "Samedi 19/09/2026". Construction via composants locaux (pas de décalage UTC). */
function formatDateAvecJour(dateStr) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.substring(0, 10).split('-').map(Number);
    const jour = JOURS_SEMAINE[new Date(y, m - 1, d).getDay()];
    return `${jour} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

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

function chargerListe() {
    $.get(`${BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        rencontres = res.rencontres;
        peuplerFiltres();
        renderListe();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function chargerEquipes() {
    $.get(`${BASE}/equipes`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        equipesToutes = res.equipes;
    }, 'json').fail(() => toast('Erreur réseau.', false));
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

function rencontresFiltrees() {
    const equipe = searchEquipe.toLowerCase();
    return rencontres.filter(r => {
        if (equipe
            && !String(r.NomDom ?? '').toLowerCase().includes(equipe)
            && !String(r.NomExt ?? '').toLowerCase().includes(equipe)) return false;
        if (deptFiltre && !deptFiltre.split('+').includes(deptDeClub(r.IdClubDom))) return false;
        if (divisionFiltre && String(r.Division ?? '') !== divisionFiltre) return false;
        if (pouleFiltre && String(r.Poule ?? '') !== pouleFiltre) return false;
        if (journeeFiltre && String(r.Journee ?? '') !== journeeFiltre) return false;
        if (dateFiltre && (r.Date ?? '').substring(0, 10) !== dateFiltre) return false;
        return true;
    });
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = rencontresFiltrees();
    $('#lbl-count').text(`${affichees.length} / ${rencontres.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="8" class="text-center text-muted py-3">Aucune rencontre.</td></tr>');
        return;
    }

    affichees.forEach(r => {
        const date = formatDateAvecJour(r.Date);
        const heure = (r.Heure ?? '').substring(0, 5);
        const $tdDom = $('<td>').addClass('cell-equipe').text(r.NomDom ?? '')
            .on('click', function (e) { e.stopPropagation(); choisirEquipe(r.Id_EquipeDom, r.NomDom); });
        const $tdExt = $('<td>').addClass('cell-equipe').text(r.NomExt ?? '—')
            .on('click', function (e) { e.stopPropagation(); if (r.Id_EquipeExt) choisirEquipe(r.Id_EquipeExt, r.NomExt); });
        const $ja = +r.JaNomme
            ? $('<span class="badge-ja-oui">').text('Oui')
            : $('<span class="badge-ja-non">').text('Non');
        $('<tr>').attr('data-id', r.Id_Rencontre).append(
            $('<td>').text(date),
            $('<td>').text(heure),
            $('<td>').text(r.Poule ?? ''),
            $('<td>').text(r.Journee ?? ''),
            $('<td>').append(macaronDivision(r.Division, r.DivisionColor)),
            $tdDom,
            $tdExt,
            $('<td>').append($ja)
        ).appendTo($body);
    });
}

$('#search-equipe').on('input', function () { searchEquipe = $(this).val().trim(); renderListe(); });
$('#sel-dept').on('change', function () { deptFiltre = $(this).val(); renderListe(); });
$('#sel-poule').on('change', function () { pouleFiltre = $(this).val(); renderListe(); });
$('#sel-journee').on('change', function () { journeeFiltre = $(this).val(); renderListe(); });
$('#sel-date').on('change', function () { dateFiltre = $(this).val(); renderListe(); });
$('#btn-reset-filtres').on('click', function () {
    deptFiltre = divisionFiltre = pouleFiltre = journeeFiltre = dateFiltre = searchEquipe = '';
    $('#sel-dept, #sel-poule, #sel-journee, #sel-date').val('');
    $('#search-equipe').val('');
    majPanelDivision();
    renderListe();
});

/** Clic sur un nom d'équipe (Domicile ou Extérieur) : la désigne comme équipe à remplacer. */
function choisirEquipe(idEquipe, nom) {
    equipeCourante = equipesToutes.find(e => String(e.Id_Equipe) === String(idEquipe))
        || { Id_Equipe: idEquipe, Nom: nom, Division: '', NomClub: '' };
    equipeRemplacement = null;
    $('#equipe-remplacement-choisie').hide().empty();
    $('#search-remplacement').val('');
    $('#remplacement-resultats').hide().empty();
    majBoutonRemplacer();
    setStatus('');

    $('#no-selection').hide();
    $('#form-remplacement').show();
    $('#recap-nom').text(equipeCourante.Division ? `${equipeCourante.Nom} — ${libDivision(equipeCourante.Division)}` : equipeCourante.Nom);
    $('#recap-club').text(equipeCourante.NomClub ?? '');

    // Filtre le tableau sur cette équipe, comme le clic sur une équipe en EN23.
    searchEquipe = nom;
    $('#search-equipe').val(nom);
    renderListe();
}

/**
 * Rencontres de l'équipe courante actuellement AFFICHÉES (respecte les filtres
 * Département/Division/Poule/Journée/Date/Équipe en cours) — c'est sur cet
 * ensemble, pas sur la totalité de la saison de l'équipe, que porte la
 * vérification des nominations puis le remplacement.
 */
function rencontresAffichees() {
    if (!equipeCourante) return [];
    return rencontresFiltrees().filter(r =>
        String(r.Id_EquipeDom) === String(equipeCourante.Id_Equipe) || String(r.Id_EquipeExt) === String(equipeCourante.Id_Equipe));
}

function majBoutonRemplacer() {
    $('#btn-remplacer').prop('disabled', !(equipeCourante && equipeRemplacement));
}

$('#search-remplacement').on('input', function () {
    const q = $(this).val().trim().toLowerCase();
    const $res = $('#remplacement-resultats').empty();
    if (!q || !equipeCourante) { $res.hide(); return; }

    const matches = equipesToutes
        .filter(e => String(e.Id_Equipe) !== String(equipeCourante.Id_Equipe))
        .filter(e => e.Nom.toLowerCase().includes(q) || e.NomClub.toLowerCase().includes(q))
        .slice(0, 15);

    if (!matches.length) {
        $res.append('<div class="item text-muted">Aucun résultat.</div>').show();
        return;
    }

    matches.forEach(e => {
        $('<div class="item">')
            .append($('<div>').text(`${e.Nom} — ${libDivision(e.Division)}`))
            .append($('<div class="club">').text(e.NomClub))
            .on('click', function () {
                equipeRemplacement = e;
                $('#equipe-remplacement-choisie').show()
                    .html(`<i class="bi bi-check-circle-fill me-1"></i>Remplacement par <strong>${$('<div>').text(e.Nom).html()}</strong> (${$('<div>').text(e.NomClub).html()})`);
                $res.hide().empty();
                $('#search-remplacement').val('');
                majBoutonRemplacer();
            })
            .appendTo($res);
    });
    $res.show();
});

/**
 * Deux confirmations successives avant toute écriture en base (rien n'est
 * modifié tant que la dernière étape n'est pas validée) :
 *   1. suppression des nominations déjà faites sur ces rencontres,
 *   2. remplacement de l'équipe dans la table rencontre.
 * Une seule requête POST est envoyée, après la 2ᵉ confirmation.
 */
$('#btn-remplacer').on('click', function () {
    if (!equipeCourante || !equipeRemplacement) return;

    const rencontresEquipe = rencontresAffichees();
    if (!rencontresEquipe.length) { toast('Aucune rencontre affichée pour cette équipe.', false); return; }
    const nbJa = rencontresEquipe.filter(r => +r.JaNomme).length;

    const confirmerRemplacement = function () {
        const msg = `Remplacer « ${equipeCourante.Nom} » par « ${equipeRemplacement.Nom} » sur ${rencontresEquipe.length} rencontre(s) affichée(s) ?`;
        nijacConfirm(msg, function () {
            $('#btn-remplacer').prop('disabled', true);
            $.post(`${BASE}/remplacer`, {
                id_equipe: equipeCourante.Id_Equipe,
                id_equipe_remplacement: equipeRemplacement.Id_Equipe,
                ids: JSON.stringify(rencontresEquipe.map(r => r.Id_Rencontre)),
            }, function (res) {
                if (res.ok) {
                    toast(res.msg);
                    setStatus(res.msg);
                    equipeCourante = null;
                    equipeRemplacement = null;
                    $('#no-selection').show();
                    $('#form-remplacement').hide();
                    chargerListe();
                } else {
                    toast(res.msg, false);
                    setStatus(res.msg, false);
                    majBoutonRemplacer();
                }
            }, 'json').fail(() => { toast('Erreur réseau.', false); majBoutonRemplacer(); });
        }, null, { type: 'danger', confirmLabel: 'Remplacer' });
    };

    if (nbJa > 0) {
        const msgNom = `${nbJa} nomination(s) de JA déjà faite(s) sur ces rencontres affichées seront supprimées (à refaire ensuite en EN14). Continuer ?`;
        nijacConfirm(msgNom, confirmerRemplacement, null, { type: 'danger', confirmLabel: 'Supprimer les nominations' });
    } else {
        confirmerRemplacement();
    }
});

$('#btn-annuler').on('click', function () {
    equipeCourante = null;
    equipeRemplacement = null;
    $('#no-selection').show();
    $('#form-remplacement').hide();
});

// Différé : nijac-division-filter.js est chargé après ce script (voir plus bas),
// donc pas encore défini si peuplerFiltres() (appelé par chargerListe) l'invoquait ici de façon synchrone.
$(function () {
    chargerListe();
    chargerEquipes();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-division-filter.js') ?>"></script>
</body>
</html>
