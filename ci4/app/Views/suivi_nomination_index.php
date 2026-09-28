<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Suivi des nominations (EN28)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête vert, comme les autres écrans nominateur (E003) */
        #page-header { background: #2e7d32; }

        #panel-liste { width: 100%; }

        /* Bandeau de filtres : comboboxes « label en encoche » comme EN23 */
        #menu-strip {
            --strip-bg: #f8fafc;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .4rem .75rem;
            flex-wrap: wrap;
        }
        #menu-strip .btn { margin-top: .6rem; border-radius: 999px; }
        #menu-strip #lbl-count {
            margin-left: 0; margin-top: .6rem;
            height: 2.15rem; padding: 0 .9rem;
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 5.5rem;
            background: #eef2f9; border: 1.5px solid #d3dae6; border-radius: 999px;
            font-size: .82rem; font-weight: 700; color: var(--nijac-blue);
        }

        #tbl-suivi td.num, #tbl-suivi th.num { text-align: right; }
        #tbl-suivi td.centre, #tbl-suivi th.centre { text-align: center; }
        #tbl-suivi .non-saisi { color: #9aa5b8; }
        /* valeur saisie mais non comptée (2e rencontre du jour, ou arbitrage Club) — mêmes règles qu'EN17 */
        #tbl-suivi .deja-compte { color: #9aa5b8; text-decoration: line-through; cursor: help; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'clipboard2-check', 'phTitle' => 'Suivi des nominations', 'phCode' => 'EN28',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="menu-strip">
            <span style="flex:1"></span>
            <span class="count-badge" id="lbl-count">0 / 0</span>
            <span style="flex:1"></span>
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
            <span class="combo-field">
                <label for="search-ja">JA</label>
                <input type="search" id="search-ja" placeholder="Nom du JA…" style="width:200px;">
            </span>
            <span class="combo-field">
                <label for="sel-saisie">Date saisie</label>
                <select id="sel-saisie" style="width:150px;">
                    <option value="">Toutes</option>
                    <option value="oui">Renseignée</option>
                    <option value="non">Non renseignée</option>
                </select>
            </span>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>
        </div>
        <div id="table-wrapper">
            <table id="tbl-suivi">
                <thead>
                    <tr>
                        <th style="width:130px" data-field="date">Date<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px" data-field="division">Division<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px" data-field="arbitrage">Arbitrage<span class="sort-icon"></span></th>
                        <th data-field="domicile">Domicile<span class="sort-icon"></span></th>
                        <th data-field="exterieur">Extérieur<span class="sort-icon"></span></th>
                        <th class="centre" style="width:90px" data-field="licence">N° licence<span class="sort-icon"></span></th>
                        <th data-field="ja">JA<span class="sort-icon"></span></th>
                        <th style="width:110px" data-field="ebp">Compte EBP<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="peage">Péage<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="km">Km<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="defisc">Défisc.<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="saisie">Date saisie<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px">Modifier</th>
                        <th class="centre" style="width:80px">Rappel</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="14" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Popup modification d'une nomination -->
<div class="modal fade" id="modal-modif" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-pencil me-2"></i>Modifier la nomination</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="modif-rencontre" class="small text-muted mb-3"></div>
                <div class="row g-2">
                    <div class="col-5">
                        <label class="form-label small mb-1" for="modif-arbitrage">Arbitrage</label>
                        <select id="modif-arbitrage" class="form-select form-select-sm">
                            <option value="1">CRA</option>
                            <option value="0">Club</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1" for="modif-ja">Juge-arbitre</label>
                        <select id="modif-ja" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1" for="modif-peage">Péage (€)</label>
                        <input type="number" id="modif-peage" class="form-control form-control-sm" min="0" step="0.01">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1" for="modif-km">Kilomètres</label>
                        <input type="number" id="modif-km" class="form-control form-control-sm" min="0" step="1">
                    </div>
                    <div class="col-4 d-flex align-items-end">
                        <div class="form-check mb-1">
                            <input type="checkbox" id="modif-defisc" class="form-check-input">
                            <label class="form-check-label small" for="modif-defisc">Défiscalisation</label>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>La date de saisie sera mise à la date du jour.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-success" id="btn-modif-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const SUIVI_BASE = '<?= site_url('suivi-nomination') ?>';
let nominations = [];
const filtres   = { date: '', equipe: '', ja: '', saisie: '' };
const sortState = { col: null, asc: true };

const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

/** "YYYY-MM-DD" → "Samedi 19/09/2026" (abrégé : "Sam 19/09/2026"). Construction via composants locaux (pas de décalage UTC). */
function formatDateAvecJour(dateStr, abrege = false) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.substring(0, 10).split('-').map(Number);
    const jour = JOURS_SEMAINE[new Date(y, m - 1, d).getDay()].substring(0, abrege ? 3 : undefined);
    return `${jour} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

/* Couleur de texte (blanc/noir) selon la luminosité du fond — identique à EN23 */
function textColorFor(hex) {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0,2), 16);
    const g = parseInt(c.substring(2,4), 16);
    const b = parseInt(c.substring(4,6), 16);
    const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return lum > 0.55 ? '#111' : '#fff';
}

function macaronDivision(division, color) {
    const bg = color && /^#[0-9a-fA-F]{6}$/.test(color) ? color : '#1a3a6b';
    return $('<span class="badge">').text(division ?? '').css({ background: bg, color: textColorFor(bg) });
}

function nominationsFiltrees() {
    const equipe = filtres.equipe.toLowerCase();
    const ja     = filtres.ja.toLowerCase();
    return nominations.filter(n => {
        if (filtres.date && (n.Date ?? '').substring(0, 10) !== filtres.date) return false;
        if (equipe
            && !String(n.NomDom ?? '').toLowerCase().includes(equipe)
            && !String(n.NomExt ?? '').toLowerCase().includes(equipe)) return false;
        if (ja && !String(n.NomJa ?? '').toLowerCase().includes(ja)) return false;
        if (filtres.saisie === 'oui' && !n.DateSaisie) return false;
        if (filtres.saisie === 'non' && n.DateSaisie) return false;
        return true;
    });
}

/** rencontre.ArbitrageCRA : 1 = arbitrage fourni par la CRA, 0 = à la charge du club */
const libArbitrage = n => n.ArbitrageCRA === null ? '' : (+n.ArbitrageCRA ? 'CRA' : 'Club');

// Clé de tri par colonne — sur les données (pas sur le texte des cellules : la date
// affichée « Samedi 19/09/2026 » ne se trierait pas chronologiquement).
const CLES_TRI = {
    date:      n => (n.Date ?? '') + (n.Heure ?? ''),
    division:  n => n.Division ?? '',
    arbitrage: n => libArbitrage(n),
    domicile:  n => n.NomDom ?? '',
    exterieur: n => n.NomExt ?? '',
    licence:   n => +n.Id_JA,
    ja:        n => n.NomJa ?? '',
    ebp:       n => n.NumCompteEBP ?? '',
    peage:     n => n.DateSaisie ? +n.Peage : -1,
    km:        n => n.DateSaisie ? +n.Kilometre : -1,
    defisc:    n => n.DateSaisie ? +n.Defiscalisation : -1,
    saisie:    n => n.DateSaisie ?? '',
};

/** Lignes du tableau : filtrées puis triées (une ligne par nomination). */
function lignesAffichees() {
    const affichees = nominationsFiltrees();
    const cle = CLES_TRI[sortState.col];
    if (cle) {
        affichees.sort((a, b) => {
            const va = cle(a), vb = cle(b);
            const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb), 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    }
    return affichees;
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = lignesAffichees();
    $('#lbl-count').text(`${affichees.length} / ${nominations.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="14" class="text-center text-muted py-3">Aucune nomination.</td></tr>');
        return;
    }

    const kmNon = nonComptees('Kilometre'), peageNon = nonComptees('Peage');
    affichees.forEach(n => {
        // Saisi mais non compté (déjà compté sur une autre rencontre du jour, ou arbitrage Club) : barré + info-bulle
        const nonCompte = (champ, sec) => !!n.DateSaisie && +n[champ] > 0 && (!estCra(n) || sec.has(n.Id_Nomination));
        const titreNonCompte = estCra(n) ? 'Déjà compté sur une autre rencontre du même jour' : 'Arbitrage Club : non remboursé';
        // DateSaisie NULL = le JA n'a encore rien saisi dans EN21 : pas de valeurs à afficher
        const saisi = !!n.DateSaisie;
        const $modifier = $('<button type="button" class="btn btn-sm btn-outline-secondary" title="Modifier cette nomination">')
            .html('<i class="bi bi-pencil"></i>')
            .on('click', function () { ouvrirModification(n); });
        const $rappel = $('<button type="button" class="btn btn-sm btn-outline-primary btn-rappel">')
            .attr('title', n.EmailJa ? 'Envoyer un message de rappel au JA' : 'JA sans adresse email')
            .prop('disabled', !n.EmailJa)
            .html('<i class="bi bi-envelope"></i>')
            .on('click', function () { envoyerRappel(n, $(this)); });
        $('<tr>').append(
            $('<td>').attr('data-field', 'date').text(formatDateAvecJour(n.Date, true)),
            $('<td class="centre">').attr('data-field', 'division').append(macaronDivision(n.Division, n.DivisionColor)),
            $('<td class="centre">').attr('data-field', 'arbitrage').text(libArbitrage(n)),
            $('<td>').attr('data-field', 'domicile').text(n.NomDom ?? ''),
            $('<td>').attr('data-field', 'exterieur').text(n.NomExt ?? '—'),
            $('<td class="centre">').attr('data-field', 'licence').attr('title', n.Telephone || null).text(n.Id_JA),
            $('<td>').attr('data-field', 'ja').attr('title', n.Telephone || null).text(n.NomJa ?? ''),
            $('<td>').attr('data-field', 'ebp').text(n.NumCompteEBP ?? ''),
            $('<td class="num">').attr('data-field', 'peage').toggleClass('non-saisi', !saisi)
                .toggleClass('deja-compte', nonCompte('Peage', peageNon)).attr('title', nonCompte('Peage', peageNon) ? titreNonCompte : null)
                .text(saisi ? Number(n.Peage ?? 0).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €' : '—'),
            $('<td class="num">').attr('data-field', 'km').toggleClass('non-saisi', !saisi)
                .toggleClass('deja-compte', nonCompte('Kilometre', kmNon)).attr('title', nonCompte('Kilometre', kmNon) ? titreNonCompte : null)
                .text(saisi ? (n.Kilometre ?? 0) : '—'),
            $('<td class="centre">').attr('data-field', 'defisc').toggleClass('non-saisi', !saisi)
                .text(saisi ? (+n.Defiscalisation ? 'Oui' : 'Non') : '—'),
            $('<td class="centre">').attr('data-field', 'saisie').toggleClass('non-saisi', !saisi)
                .text(saisi ? n.DateSaisie.substring(0, 10).split('-').reverse().join('/') : '—'),
            $('<td class="centre">').append($modifier),
            $('<td class="centre">').append(saisi ? '' : $rappel) // frais déjà saisis : plus de rappel
        ).on('dblclick', function () { ouvrirModification(n); }).appendTo($body);
    });
}

function envoyerRappel(n, $btn) {
    nijacConfirm(`Envoyer un message de rappel à ${n.NomJa} ?`, function () {
        $btn.prop('disabled', true);
        $.post(`${SUIVI_BASE}/rappel`, { id_nomination: n.Id_Nomination }, function (r) {
            toast(r.msg, !!r.ok);
            $btn.prop('disabled', false);
        }, 'json').fail(function () {
            toast('Erreur réseau.', false);
            $btn.prop('disabled', false);
        });
    });
}

// ── Modification d'une nomination (popup) ────────────────────────────────────
let jaListe = null;      // JA actifs du périmètre, chargés à la première ouverture
let modifNom = null;     // nomination en cours de modification

function remplirListeJa(n) {
    const $sel = $('#modif-ja').empty();
    let liste = jaListe;
    // le JA actuel reste sélectionnable même s'il n'est pas dans la liste (inactif, autre département)
    if (!liste.some(j => +j.Id_JA === +n.Id_JA)) {
        const [prenom, ...nom] = String(n.NomJa ?? '').split(' ');
        liste = [{ Id_JA: n.Id_JA, Nom: nom.join(' '), Prenom: prenom }, ...liste];
    }
    liste.forEach(j => $sel.append($('<option>').val(j.Id_JA).text(`${j.Nom} ${j.Prenom} (${j.Id_JA})`)));
    $sel.val(n.Id_JA);
}

function ouvrirModification(n) {
    modifNom = n;
    const ouvrir = () => {
        remplirListeJa(n);
        $('#modif-rencontre').text(`${formatDateAvecJour(n.Date, true)} — ${n.NomDom} / ${n.NomExt ?? '?'}`);
        $('#modif-arbitrage').val(+n.ArbitrageCRA ? '1' : '0');
        $('#modif-peage').val(n.Peage ?? 0);
        $('#modif-km').val(n.Kilometre ?? 0);
        $('#modif-defisc').prop('checked', !!+n.Defiscalisation);
        bootstrap.Modal.getOrCreateInstance('#modal-modif').show();
    };
    if (jaListe) { ouvrir(); return; }
    $.get(`${SUIVI_BASE}/ja-liste`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        jaListe = res.ja;
        ouvrir();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

$('#btn-modif-enregistrer').on('click', function () {
    if (!modifNom) return;
    const $btn = $(this).prop('disabled', true);
    $.post(`${SUIVI_BASE}/modifier`, {
        id_nomination: modifNom.Id_Nomination,
        id_ja:         $('#modif-ja').val(),
        arbitrage:     $('#modif-arbitrage').val(),
        peage:         $('#modif-peage').val(),
        km:            $('#modif-km').val(),
        defisc:        $('#modif-defisc').is(':checked') ? 1 : 0,
    }, function (r) {
        $btn.prop('disabled', false);
        toast(r.msg, !!r.ok);
        if (!r.ok) return;
        bootstrap.Modal.getInstance('#modal-modif').hide();
        chargerListe();
    }, 'json').fail(function () {
        $btn.prop('disabled', false);
        toast('Erreur réseau.', false);
    });
});

// ── Frais non comptés (mêmes règles qu'EN17) ─────────────────────────────────
// Une ligne par nomination, sans regroupement. Mêmes règles qu'EN17 (tableau « Arbitrages et frais ») : seules les
// rencontres à arbitrage CRA valent des frais (Club : 0), et un JA qui arbitre plusieurs rencontres CRA le même jour ne
// fait qu'un déplacement — les km, et de même les péages, ne sont conservés que sur la 1re rencontre du jour qui en porte
// (heure la plus précoce, puis n° de nomination), les suivantes sont barrées dans le tableau. Une rencontre à 0 ne « consomme »
// pas le déplacement : le JA a pu ne saisir ses frais que sur l'une des deux.
const estCra = n => +n.ArbitrageCRA === 1;

/** Ids des nominations dont la valeur de `champ` ('Kilometre' | 'Peage') n'est PAS comptée : 2e rencontre et suivantes du jour. */
function nonComptees(champ) {
    const vus = new Set(), sec = new Set();
    nominations.filter(n => estCra(n) && +n[champ] > 0)
        .sort((a, b) => ((a.Date ?? '') + (a.Heure ?? '')).localeCompare((b.Date ?? '') + (b.Heure ?? '')) || a.Id_Nomination - b.Id_Nomination)
        .forEach(n => {
            const cle = `${n.Id_JA}|${(n.Date ?? '').substring(0, 10)}`;
            if (vus.has(cle)) sec.add(n.Id_Nomination); else vus.add(cle);
        });
    return sec;
}


function chargerListe() {
    $.get(`${SUIVI_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        nominations = res.nominations;
        const dates = [...new Set(nominations.map(n => (n.Date ?? '').substring(0, 10)).filter(Boolean))].sort();
        const $sel = $('#sel-date');
        $sel.find('option:not(:first)').remove();
        dates.forEach(d => $sel.append(new Option(formatDateAvecJour(d), d)));
        $sel.val(filtres.date);
        renderListe();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

$('#sel-date').on('change', function () { filtres.date = $(this).val(); renderListe(); });
$('#sel-saisie').on('change', function () { filtres.saisie = $(this).val(); renderListe(); });
// Debounce : renderListe() reconstruit tout le tableau
let searchTimer;
function filtreTexte(cle) {
    return function () {
        const val = $(this).val().trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { filtres[cle] = val; renderListe(); }, 200);
    };
}
$('#search-equipe').on('input', filtreTexte('equipe'));
$('#search-ja').on('input', filtreTexte('ja'));
$('#btn-reset-filtres').on('click', function () {
    filtres.date = filtres.equipe = filtres.ja = filtres.saisie = '';
    $('#sel-date, #sel-saisie, #search-equipe, #search-ja').val('');
    renderListe();
});

$(function () {
    nijacSortableTable('#tbl-suivi thead th[data-field]', 'field', sortState, renderListe);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
