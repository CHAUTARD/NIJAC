<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Disponibilités CRA (EC74)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
    <style>
        /* Même mise en page qu'EC73 : tableau des compétitions à gauche, détail à droite. */
        body { background: var(--en-bg); height: 100vh; display: flex; flex-direction: column; }
        #page-header { background: #00695c; }
        main { flex: 1; min-height: 0; padding: 1rem 1.4rem; display: flex; gap: 1rem; }
        #pan-liste { flex: 0 0 50%; min-width: 0; display: flex; flex-direction: column; }
        #pan-form  { flex: 1 1 50%; min-width: 0; overflow-y: auto; }
        .carte {
            background: var(--en-card); border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06); padding: 1rem 1.2rem; margin-bottom: 1rem;
        }
        #invite { padding: 3rem 1.2rem; text-align: center; color: var(--en-muted); }

        #liste-header { display: flex; align-items: center; gap: .55rem; margin-bottom: .3rem; }
        #txt-recherche {
            flex: 1; max-width: 22rem; font-size: .85rem; padding: .32rem .9rem;
            border-radius: 999px; background: var(--en-card);
            border: 1px solid var(--en-line); box-shadow: 0 1px 2px rgba(16,24,40,.04);
        }
        #lbl-count { margin: 0; justify-content: center; min-width: 2.6rem; }
        .legende { font-size: .78rem; color: var(--en-muted); margin-bottom: .5rem; }
        #table-wrapper {
            flex: 1; min-height: 0; overflow: auto; background: var(--en-card);
            border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06);
        }
        .tbl { width: 100%; font-size: .82rem; border-collapse: collapse; }
        .tbl thead th {
            position: sticky; top: 0; z-index: 1;
            background: var(--en-card); border: 0; box-shadow: inset 0 -2px 0 var(--en-line);
            color: var(--en-muted); font-size: .68rem; font-weight: 700; white-space: nowrap;
            letter-spacing: .5px; text-transform: uppercase; padding: .7rem .5rem; text-align: left;
        }
        .tbl th.th-c, .tbl td.td-c { text-align: center; }
        .tbl tbody td { padding: .45rem .5rem; vertical-align: top; }
        .tbl tbody tr { border-bottom: 1px solid #f2f3f6; }
        .tbl td.nowrap { white-space: nowrap; }
        #tbl-competitions thead th[data-col] { cursor: pointer; user-select: none; }
        #tbl-competitions thead th[data-col]:hover { background: #f6f8fb; }
        #tbl-competitions thead th.th-pk { color: #e65100; }
        #tbl-competitions thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-competitions thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-competitions thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-competitions thead th[data-col]:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-competitions tbody tr { cursor: pointer; }
        #tbl-competitions tbody tr:nth-child(odd) { background: #e3f4e9; }
        #tbl-competitions tbody tr:hover { background: #d3ecdd; }
        #tbl-competitions tbody tr.selected { background: #c2e6cf; box-shadow: inset 4px 0 0 #00695c; font-weight: 600; }
        #tbl-competitions tbody tr.passee { color: var(--en-muted); }
        #tbl-competitions td code { color: var(--en-muted); font-size: inherit; }
        /* Pastilles de statut : mêmes couleurs que la matrice de suivi. */
        .st { display: inline-block; padding: .05rem .45rem; border-radius: 999px; font-weight: 600; font-size: .78rem; white-space: nowrap; }
        .st-dispo   { background: #c6efce; color: #006100; }
        .st-indispo { background: #ffc7ce; color: #9c0006; }
        .st-nr      { background: #e5e7eb; color: #374151; }
        .st-conf    { background: #fde2c4; color: #9a4b00; }
        .st-cond    { background: #cfe2f3; color: #1e4e79; }
        .compteurs { display: flex; flex-wrap: wrap; gap: .2rem; }
        .c-sans { color: #6b7280; }

        #bandeau { background: #e0f2f1; border-left: 5px solid #00695c; }
        #bandeau .titre { font-size: 1.15rem; font-weight: 700; color: #00695c; }
        #bandeau .crit { display: flex; flex-wrap: wrap; gap: .4rem 1.6rem; margin-top: .4rem; font-size: .88rem; }
        #bandeau .crit b { color: var(--en-muted); font-weight: 600; margin-right: .25rem; }
        #cible { font-size: .85rem; }
        .envoi-params { display: flex; flex-wrap: wrap; gap: .5rem 1.2rem; align-items: center; font-size: .85rem; margin: .6rem 0; }
        .envoi-params input[type=date] { width: 10.5rem; }
        #tbl-ja tbody tr:hover { background: #f6f8fb; }
        #tbl-ja .statut small { display: block; color: var(--en-muted); }
        #tbl-ja .saisie { display: flex; flex-direction: column; gap: .2rem; min-width: 11rem; }
        #tbl-ja .saisie .form-select, #tbl-ja .saisie .form-control { font-size: .75rem; padding-top: .1rem; padding-bottom: .1rem; }
        #tbl-ja .saisie .btn { padding: .1rem .45rem; font-size: .75rem; }
        #compte-rendu { white-space: pre-line; font-size: .85rem; }
        .form-actions {
            display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .5rem;
            margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--en-line);
        }

        #page-footer {
            padding: .25rem 1rem; font-size: .8rem; flex-shrink: 0;
            display: flex; justify-content: center; align-items: center; gap: 1rem;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }

        @media (max-width: 991.98px) {
            body { height: auto; min-height: 100vh; }
            main { flex-direction: column; }
            #pan-liste { flex: none; }
            #table-wrapper { flex: none; max-height: 45vh; }
            #pan-form { flex: none; overflow: visible; scroll-margin-top: .5rem; }
        }
        @media (max-width: 576px) {
            main { padding: .75rem; }
            #txt-recherche { max-width: none; }
            .form-actions .btn { flex: 1 1 100%; }
            #page-footer.pf-status-left { display: flex; flex-direction: column; gap: .2rem; }
            .footer-copyright { white-space: normal; text-align: center; }
        }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar-check', 'phTitle' => 'Disponibilités CRA', 'phCode' => 'EC74',
    'phCrumbLabel' => 'CRA Convoc', 'phCrumbUrl' => site_url('cra-convoc-menu'), 'phBackUrl' => site_url('cra-convoc-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<main>
  <section id="pan-liste">
    <div id="liste-header">
        <input type="search" id="txt-recherche" aria-label="Rechercher une compétition" placeholder="Rechercher (libellé, lieu)…">
        <span id="lbl-count" class="count-badge">0</span>
        <div class="form-check form-switch mb-0 ms-auto small" title="Choix proposés au JA (page publique) et à la saisie manuelle ; les réponses déjà enregistrées restent affichées">
            <input class="form-check-input" type="checkbox" role="switch" id="chk-choix-etendus" disabled>
            <label class="form-check-label" for="chk-choix-etendus">Proposer « À confirmer » et « Sous condition »</label>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-import"><i class="bi bi-upload me-1"></i>Importer la matrice</button>
    </div>
    <div class="legende">Cochez plusieurs compétitions pour une demande groupée (un seul email par JA) · Dem. = demandes envoyées · Réponses :
        <span class="st st-dispo">Disponible</span> <span class="st st-conf">À confirmer</span> <span class="st st-cond">Sous condition</span>
        <span class="st st-indispo">Indisponible</span> <span class="st st-nr">Sans réponse</span></div>
    <div id="table-wrapper">
        <table id="tbl-competitions" class="tbl">
            <thead>
                <tr>
                    <th class="th-c"><input type="checkbox" id="chk-tout-compet" class="form-check-input" title="Cocher toutes les compétitions à venir" aria-label="Cocher toutes les compétitions à venir"></th>
                    <th class="th-pk th-c" data-col="Numero">N°<span class="sort-icon"></span></th>
                    <th data-col="DateDebut">Dates<span class="sort-icon"></span></th>
                    <th data-col="Libelle">Libellé<span class="sort-icon"></span></th>
                    <th data-col="Lieu">Lieu<span class="sort-icon"></span></th>
                    <th data-col="NiveauJA">Niveau<span class="sort-icon"></span></th>
                    <th class="th-c" data-col="NbDemandes" title="Demandes envoyées">Dem.<span class="sort-icon"></span></th>
                    <th data-col="NbDispo" title="Détail par statut (tri : nombre de disponibles)">Réponses<span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody id="tbody-liste">
                <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
        </table>
    </div>
  </section>

  <section id="pan-form">
    <div id="invite" class="carte"><i class="bi bi-arrow-left-circle me-1"></i>Sélectionnez une compétition dans la liste</div>

    <div id="zone" class="d-none">
        <div class="carte" id="bandeau">
            <div class="titre" id="b-titre"></div>
            <div class="crit">
                <span><b>Dates</b><span id="b-dates"></span></span>
                <span><b>Lieu</b><span id="b-lieu"></span></span>
                <span><b>Niveau JA</b><span id="b-niveau"></span></span>
                <span><b>Réponses</b><span id="b-compteurs" class="compteurs d-inline-flex"></span></span>
            </div>
        </div>

        <div class="carte">
            <div id="cible"></div>
            <div class="envoi-params">
                <label class="d-flex align-items-center gap-2">Répondre avant le
                    <input type="date" id="date-limite" class="form-control form-control-sm"></label>
                <label class="form-check mb-0"><input type="checkbox" id="chk-cc" class="form-check-input"> M'envoyer une copie (Cc)</label>
            </div>
            <div class="mb-2 d-flex flex-wrap align-items-end gap-2">
                <div class="combo-field" style="--strip-bg: var(--en-card)">
                    <label for="sel-filtre-dispo">Disponibilité</label>
                    <select id="sel-filtre-dispo" autocomplete="off">
                        <option value="">Tous</option>
                        <option>Disponible</option>
                        <option>Disponible sous condition</option>
                        <option>À confirmer</option>
                        <option>Indisponible</option>
                        <option>Non renseigné</option>
                        <option>Pas demandé</option>
                    </select>
                </div>
                <span id="lbl-ja-count" class="small text-muted align-self-center" aria-live="polite"></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-sel-non-demandes"><i class="bi bi-check2-square me-1"></i>Tout sélectionner les non-demandés</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-sel-aucun">Aucun</button>
            </div>
            <div class="table-responsive">
                <table id="tbl-ja" class="tbl">
                    <thead>
                        <tr>
                            <th class="th-c"><span class="visually-hidden">Sélection</span></th>
                            <th>JA</th>
                            <th>Grades</th>
                            <th>Statut</th>
                            <th class="th-c">Saisie manuelle</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-ja"></tbody>
                </table>
            </div>
            <div id="compte-rendu" class="alert mt-3 mb-0 d-none" role="status"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-warning btn-sm" id="btn-relancer"><i class="bi bi-arrow-repeat me-1"></i>Relancer les sans réponse</button>
                <button type="button" class="btn btn-success btn-sm" id="btn-demander"><i class="bi bi-envelope me-1"></i>Demander les disponibilités</button>
            </div>
        </div>
    </div>
  </section>
</main>

<div class="modal fade" id="modalImport" tabindex="-1" aria-labelledby="modalImportTitre" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalImportTitre"><i class="bi bi-upload me-1"></i>Importer la matrice des disponibilités</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Classeur Excel (.xlsx, 5 Mo max.) avec une feuille « Matrice » (JA en ligne 1, épreuves « date | libellé » en colonne A)
            et, si possible, une feuille « Référents » (nom + licence). Les statuts Disponible, Indisponible, À confirmer et Disponible sous condition
            sont repris tels quels · Non renseigné → rien n'est écrit. Une réponse existante (statut autre que Non renseigné) est conservée sauf si « Écraser » est coché.</p>
        <input type="file" id="file-import" class="form-control form-control-sm mb-2" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        <label class="form-check"><input type="checkbox" id="chk-ecraser" class="form-check-input"> Écraser les réponses existantes</label>
        <div id="import-resume" class="alert mt-3 mb-0 d-none" role="status" style="white-space: pre-line; font-size: .85rem;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-import-analyser"><i class="bi bi-search me-1"></i>Analyser</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-import-valider" disabled><i class="bi bi-check2 me-1"></i>Importer</button>
      </div>
    </div>
  </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const BASE = '<?= site_url('cra-dispo') ?>';
const GRADES = ['JA1', 'JA2', 'JA3', 'JAN', 'JAI'];
let competitions = [];
let jas = [];
let modeDev = false;
let choixEtendus = true;   // réglage configuration.cra_dispo_choix_etendus
const CHOIX_ETENDUS = ['À confirmer', 'Disponible sous condition'];
let courante = null;
let statuts = {};          // Id_JA → ligne CRA_Dispo de la compétition courante
const coches = new Set();  // Id_CRA_Competition cochées (demande groupée)
const sortState = { col: 'DateDebut', asc: true };

function formatDates(d, f) {
    const [ya, ma, da] = d.split('-');
    if (!f || f === d) return `${da}/${ma}/${ya}`;
    const [yb, mb, db] = f.split('-');
    if (ya === yb && ma === mb) return `${da}-${db}/${ma}/${ya}`;
    return `${da}/${ma}/${ya} - ${db}/${mb}/${yb}`;
}
const dateFr = dt => dt ? dt.substring(0, 10).split('-').reverse().join('/') : '';

// Les 5 valeurs de CRA_Dispo.Disponible → classe de pastille (couleurs de la matrice).
const NR = 'Non renseigné';
const STATUTS = { 'Non renseigné': 'st-nr', 'Indisponible': 'st-indispo', 'Disponible': 'st-dispo', 'À confirmer': 'st-conf', 'Disponible sous condition': 'st-cond' };
const pastille = (txt, statut, title) => $('<span class="st">').addClass(STATUTS[statut]).text(txt).attr('title', title || null);
/** Pastilles des compteurs d'une compétition (seulement les statuts présents). */
function compteurs(c) {
    return [
        ['NbDispo', 'Disponible', 'disponible(s)'], ['NbConfirmer', 'À confirmer', 'à confirmer'],
        ['NbCondition', 'Disponible sous condition', 'sous condition'], ['NbIndispo', 'Indisponible', 'indisponible(s)'],
        ['NbSans', NR, 'sans réponse'],
    ].filter(([k]) => Number(c[k]) > 0).map(([k, st, lib]) => pastille(c[k], st, `${c[k]} ${lib}`));
}

/** Mêmes règles qu'EC73 : rang du plus bas grade listé dans NiveauJA, grade ≥ ce rang. */
function rangMin(niveau) {
    const r = String(niveau || '').split(/\s+/).map(g => GRADES.indexOf(g)).filter(i => i >= 0);
    return r.length ? Math.min(...r) : 1;
}
const eligible = (ja, c) => GRADES.slice(rangMin(c.NiveauJA)).some(g => Number(ja[g]) === 1);
const passee = c => Number(c.Passee) === 1;

function valeurTri(c, col) {
    if (['Numero', 'NbDemandes', 'NbDispo'].includes(col)) return Number(c[col]) || 0;
    return String(c[col] ?? '');
}

function afficherListe() {
    const q = $('#txt-recherche').val().trim().toLowerCase();
    const col = sortState.col;
    const rows = competitions
        .filter(c => !q || `${c.Libelle} ${c.Lieu}`.toLowerCase().includes(q))
        .sort((a, b) => {
            const va = valeurTri(a, col), vb = valeurTri(b, col);
            const cmp = typeof va === 'number' ? va - vb : va.localeCompare(vb, 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    $('#lbl-count').text(rows.length);
    const $body = $('#tbody-liste').empty();
    if (!rows.length) {
        $body.append('<tr><td colspan="8" class="text-center text-muted py-3">Aucune compétition.</td></tr>');
        return;
    }
    const idCour = courante ? String(courante.Id_CRA_Competition) : null;
    rows.forEach(c => {
        const id = String(c.Id_CRA_Competition);
        const $chk = $('<input type="checkbox" class="form-check-input chk-compet">').val(id)
            .prop('checked', coches.has(id)).prop('disabled', passee(c))
            .attr('aria-label', `Inclure la compétition n°${c.Numero}`)
            .attr('title', passee(c) ? 'Compétition passée' : 'Inclure dans la demande');
        $('<tr tabindex="0">').attr('data-id', id).toggleClass('selected', id === idCour).toggleClass('passee', passee(c)).append(
            $('<td class="td-c">').append($chk),
            $('<td class="td-c">').append($('<code>').text(c.Numero)),
            $('<td class="nowrap">').text(formatDates(c.DateDebut, c.DateFin)),
            $('<td>').text(c.Libelle),
            $('<td>').text(c.Lieu),
            $('<td class="nowrap">').text(c.NiveauJA),
            $('<td class="td-c">').text(c.NbDemandes),
            $('<td>').append($('<div class="compteurs">').append(compteurs(c)))
        ).appendTo($body);
    });
}

/** Compétitions visées par un envoi : les cochées, sinon la compétition courante (si à venir). */
function cibles() {
    const lst = competitions.filter(c => coches.has(String(c.Id_CRA_Competition)) && !passee(c));
    if (lst.length) return lst;
    return courante && !passee(courante) ? [courante] : [];
}

function afficherCible() {
    const lst = cibles();
    $('#cible').empty().append(lst.length
        ? $('<span>').text('Envoi pour : ' + lst.map(c => `n°${c.Numero} ${c.Libelle} (${formatDates(c.DateDebut, c.DateFin)})`).join(' · '))
        : $('<span class="text-danger">').text('Compétition passée : aucune demande possible (saisie manuelle uniquement).'));
}

function chargerDonnees(cb) {
    $.get(`${BASE}/data`, function (res) {
        if (!res.ok) { nijacToast('Erreur de chargement.', 'danger'); return; }
        competitions = res.competitions;
        jas = res.jas;
        modeDev = !!res.modeDev;
        choixEtendus = !!res.choixEtendus;
        $('#chk-choix-etendus').prop({ checked: choixEtendus, disabled: false });
        if (courante) courante = competitions.find(c => String(c.Id_CRA_Competition) === String(courante.Id_CRA_Competition)) || null;
        afficherListe();
        if (cb) cb();
    }, 'json').fail(() => nijacToast('Erreur de chargement.', 'danger'));
}

function selectionner(id) {
    courante = competitions.find(c => String(c.Id_CRA_Competition) === String(id)) || null;
    afficherListe();
    $('#zone').toggleClass('d-none', !courante);
    $('#invite').toggleClass('d-none', !!courante);
    if (!courante) return;
    const c = courante;
    $('#b-titre').text(`n°${c.Numero} — ${c.Libelle}`);
    $('#b-dates').text(formatDates(c.DateDebut, c.DateFin));
    $('#b-lieu').text(c.Lieu);
    $('#b-niveau').text(c.NiveauJA);
    $('#b-compteurs').empty().append($('<span>').text(`${c.NbDemandes} demandé(s)`), compteurs(c));
    afficherCible();
    statuts = {};
    $('#tbody-ja').html('<tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>');
    $.get(`${BASE}/${c.Id_CRA_Competition}`, function (res) {
        if (courante !== c) return;
        if (!res.ok) { nijacToast('Erreur de chargement.', 'danger'); return; }
        res.data.forEach(s => { statuts[s.Id_JA] = s; });
        afficherJas();
    }, 'json');
}

/** Cellule « Statut » : pastille (ou « Demandé le … » / « Pas demandé ») + détail de la réponse. */
function celluleStatut(s) {
    if (!s || s.Disponible === NR) {
        return [$('<span class="c-sans">').text(s && s.DateDemande ? `Demandé le ${dateFr(s.DateDemande)}` : 'Pas demandé')];
    }
    const qui = s.Source === 'Saisie' ? `saisie${s.NomUtilisateur ? ' par ' + s.NomUtilisateur : ''}` : 'réponse du JA';
    const det = `le ${dateFr(s.DateReponse)} (${qui})` + (s.Commentaire ? ` — « ${s.Commentaire} »` : '');
    return [pastille(s.Disponible, s.Disponible), $('<small>').text(det)];
}

function afficherJas() {
    const $body = $('#tbody-ja').empty();
    const liste = jas.filter(j => eligible(j, courante));
    if (!liste.length) {
        $body.append('<tr><td colspan="5" class="text-center text-muted py-3">Aucun JA éligible.</td></tr>');
        filtrerJas();
        return;
    }
    liste.forEach(j => {
        const s = statuts[j.Id_JA];
        const grades = GRADES.slice(1).filter(g => Number(j[g]) === 1).join(' ');
        const $nom = $('<td>').append($('<span>').text(`${(j.Nom || '').toUpperCase()} ${j.Prenom || ''}`),
            $('<small class="d-block text-muted">').text(j.NomClub || 'sans club'));
        if (Number(j.AEmail) !== 1) $nom.append($('<small class="d-block text-danger">').text('✖ pas d\'email'));
        const qui = `${j.Nom} ${j.Prenom}`;
        // Saisie manuelle : statut (« Non renseigné » = effacer la réponse) + commentaire optionnel.
        // Réglage choix étendus à non : statuts masqués sauf la valeur actuelle de la ligne (conservable).
        const actuel = s ? s.Disponible : NR;
        const $sel = $('<select class="form-select form-select-sm sel-statut">').attr('aria-label', `Statut de ${qui}`)
            .append(Object.keys(STATUTS).filter(v => choixEtendus || !CHOIX_ETENDUS.includes(v) || v === actuel)
                .map(v => $('<option>').val(v).text(v + (!choixEtendus && CHOIX_ETENDUS.includes(v) ? ' (valeur actuelle)' : ''))))
            .val(actuel);
        const $com = $('<input type="text" class="form-control form-control-sm txt-com" maxlength="255" placeholder="Commentaire (facultatif)">')
            .attr('aria-label', `Commentaire pour ${qui}`).val(s && s.Commentaire ? s.Commentaire : '');
        // data-dispo : clé du filtre « Disponibilité » (statut enregistré, ou « Pas demandé » sans ligne CRA_Dispo).
        $('<tr>').attr({ 'data-ja': j.Id_JA, 'data-dispo': s ? s.Disponible : 'Pas demandé' }).append(
            $('<td class="td-c">').append($('<input type="checkbox" class="form-check-input chk-ja">').val(j.Id_JA)
                .attr('aria-label', `Sélectionner ${qui}`)),
            $nom,
            $('<td class="nowrap">').text(grades),
            $('<td class="statut">').append(celluleStatut(s)),
            $('<td>').append($('<div class="saisie">').append($sel, $com,
                $('<button type="button" class="btn btn-outline-primary btn-saisie">').attr('data-ja', j.Id_JA).text('Enregistrer')))
        ).appendTo($body);
    });
    filtrerJas();
}

/** Filtre « Disponibilité » (côté client) : masque les lignes non concernées, sans toucher à leur coche. */
function filtrerJas() {
    const f = $('#sel-filtre-dispo').val();
    $('#tbody-ja tr[data-ja]').each(function () {
        $(this).toggleClass('d-none', !!f && this.dataset.dispo !== f);
    });
    majCompteurJas();
}
/** Compteur « n affichés / N » + sélectionnés (dont masqués par le filtre). */
function majCompteurJas() {
    const $tr = $('#tbody-ja tr[data-ja]');
    const nSel = $tr.find('.chk-ja:checked').length;
    const nMasq = $tr.filter('.d-none').find('.chk-ja:checked').length;
    $('#lbl-ja-count').text(`${$tr.not('.d-none').length} affichés / ${$tr.length}`
        + (nSel ? ` · ${nSel} sélectionné(s)` + (nMasq ? ` dont ${nMasq} masqué(s), non envoyé(s)` : '') : ''));
}

/** Envoie la demande (ou la relance) aux JA donnés, un appel par JA, puis affiche le compte-rendu. */
function envoyer(idsJa, relance) {
    const lst = cibles();
    if (!lst.length) { nijacToast('Aucune compétition à venir sélectionnée.', 'warning'); return; }
    if (!idsJa.length) { nijacToast(relance ? 'Aucun JA demandé sans réponse.' : 'Aucun JA sélectionné.', 'warning'); return; }
    const sansEmail = idsJa.filter(id => Number((jas.find(j => String(j.Id_JA) === String(id)) || {}).AEmail) !== 1).length;
    const msg = `${relance ? 'Relancer' : 'Envoyer une demande de disponibilités à'} ${idsJa.length} JA`
        + (sansEmail ? ` (dont ${sansEmail} sans email, qui seront ignorés)` : '')
        + ` pour ${lst.length} compétition(s) : ${lst.map(c => 'n°' + c.Numero).join(', ')} ?`
        + (modeDev ? ' Mode Développement : les emails sont redirigés vers l\'adresse de développement.' : '');
    nijacConfirm(msg, function () {
        const params = {
            competitions: lst.map(c => c.Id_CRA_Competition), relance: relance ? '1' : '0',
            date_limite: $('#date-limite').val(), cc: $('#chk-cc').is(':checked') ? '1' : '0',
        };
        const cr = { ok: [], sans: [], rien: [], echec: [] };
        const $btns = $('#btn-demander, #btn-relancer').prop('disabled', true);
        const $cr = $('#compte-rendu').removeClass('d-none alert-success alert-warning').addClass('alert-info');
        let i = 0;
        const suivant = () => {
            if (i >= idsJa.length) return fin();
            $cr.text(`Envoi en cours… ${i + 1} / ${idsJa.length}`);
            $.post(`${BASE}/envoyer`, { ...params, id_ja: idsJa[i++] }, function (r) {
                if (r.ok) cr.ok.push(r.nom);
                else if (r.sansEmail) cr.sans.push(r.nom);
                else if (r.skip) cr.rien.push(r.nom);
                else cr.echec.push(`${r.nom || '?'} : ${r.msg}`);
                if (r.stop) { cr.echec.push(`Envoi interrompu (${idsJa.length - i} JA non traités).`); return fin(); }
                suivant();
            }, 'json').fail(() => { cr.echec.push(`JA #${idsJa[i - 1]} : erreur réseau`); suivant(); });
        };
        const fin = () => {
            $btns.prop('disabled', false);
            const lignes = [`Emails envoyés : ${cr.ok.length}`];
            if (cr.sans.length) lignes.push(`JA sans email (non envoyés) : ${cr.sans.join(', ')}`);
            if (cr.rien.length) lignes.push(`Sans compétition concernée : ${cr.rien.join(', ')}`);
            if (cr.echec.length) lignes.push(`Échecs :\n- ${cr.echec.join('\n- ')}`);
            $cr.removeClass('alert-info').addClass(cr.echec.length || cr.sans.length ? 'alert-warning' : 'alert-success').text(lignes.join('\n'));
            nijacToast(`${cr.ok.length} email(s) envoyé(s)` + (cr.echec.length ? `, ${cr.echec.length} échec(s)` : ''), cr.echec.length ? 'warning' : 'success');
            const id = courante ? String(courante.Id_CRA_Competition) : null;
            chargerDonnees(() => { if (id) selectionner(id); });
        };
        suivant();
    }, null, { type: 'question', confirmLabel: 'Envoyer' });
}

$('#tbody-liste').on('click keydown', 'tr[data-id]', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    if ($(e.target).is('.chk-compet')) return;
    const id = this.dataset.id;
    if (!courante || String(courante.Id_CRA_Competition) !== id) selectionner(id);
    if (window.matchMedia('(max-width: 991.98px)').matches) {
        document.getElementById('pan-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
$('#tbody-liste').on('change', '.chk-compet', function () {
    this.checked ? coches.add(this.value) : coches.delete(this.value);
    if (courante) afficherCible();
});
$('#chk-tout-compet').on('change', function () {
    const on = this.checked;
    competitions.filter(c => !passee(c)).forEach(c => on ? coches.add(String(c.Id_CRA_Competition)) : coches.delete(String(c.Id_CRA_Competition)));
    afficherListe();
    if (courante) afficherCible();
});
$('#txt-recherche').on('input', afficherListe);

// Réglage enregistré immédiatement ; en cas d'échec la case revient à l'état précédent.
$('#chk-choix-etendus').on('change', function () {
    const on = this.checked;
    const $chk = $(this).prop('disabled', true);
    $.post(`${BASE}/reglage`, { valeur: on ? '1' : '0' }, function (res) {
        nijacToast(res.ok ? res.msg : (res.msg || 'Erreur.'), res.ok ? 'success' : 'danger');
        if (res.ok) {
            choixEtendus = on;
            if (courante && $('#tbody-ja .sel-statut').length) afficherJas();
        } else {
            $chk.prop('checked', choixEtendus);
        }
    }, 'json').fail(() => { $chk.prop('checked', choixEtendus); nijacToast('Erreur réseau.', 'danger'); })
        .always(() => $chk.prop('disabled', false));
});

$('#sel-filtre-dispo').on('change', filtrerJas);
$('#tbody-ja').on('change', '.chk-ja', majCompteurJas);

// Lignes affichées seulement : les coches des lignes masquées par le filtre sont conservées.
$('#btn-sel-non-demandes').on('click', function () {
    $('#tbody-ja tr[data-ja]:not(.d-none) .chk-ja').each(function () {
        const s = statuts[this.value];
        this.checked = !(s && s.DateDemande);
    });
    majCompteurJas();
});
$('#btn-sel-aucun').on('click', () => { $('#tbody-ja .chk-ja').prop('checked', false); majCompteurJas(); });

// Demander : seulement les lignes cochées ET affichées (les cochées masquées par le filtre ne reçoivent rien).
$('#btn-demander').on('click', () => envoyer($('#tbody-ja tr:not(.d-none) .chk-ja:checked').map((_, c) => c.value).get(), false));
// Relance : JA affichés (filtre) demandés et encore sans réponse pour la compétition courante.
$('#btn-relancer').on('click', function () {
    const ids = $('#tbody-ja tr[data-ja]:not(.d-none) .chk-ja').map((_, c) => c.value).get()
        .filter(id => statuts[id] && statuts[id].DateDemande && statuts[id].Disponible === NR);
    envoyer(ids, true);
});

$('#tbody-ja').on('click', '.btn-saisie', function () {
    if (!courante) return;
    const id = String(courante.Id_CRA_Competition);
    const $tr = $(this).closest('tr');
    const valeur = $tr.find('.sel-statut').val();
    if (!(valeur in STATUTS)) { nijacToast('Statut invalide.', 'danger'); return; }
    $.post(`${BASE}/${id}/saisie`, { id_ja: this.dataset.ja, valeur, commentaire: $tr.find('.txt-com').val().trim() }, function (res) {
        nijacToast(res.ok ? res.msg : (res.msg || 'Erreur.'), res.ok ? 'success' : 'danger');
        if (res.ok) chargerDonnees(() => selectionner(id));
    }, 'json').fail(() => nijacToast('Erreur réseau.', 'danger'));
});

// ── Import de la matrice Excel : Analyser (aperçu sans écriture) puis Importer (le fichier est renvoyé) ──
$('#btn-import').on('click', () => {
    $('#file-import').val('');
    $('#chk-ecraser').prop('checked', false);
    $('#import-resume').addClass('d-none');
    $('#btn-import-valider').prop('disabled', true);
    new bootstrap.Modal('#modalImport').show();
});
$('#file-import, #chk-ecraser').on('change', () => $('#btn-import-valider').prop('disabled', true));

function resumeImport(r) {
    const l = [
        r.ecrit ? 'Import effectué :' : 'Aperçu (aucune écriture) :',
        `JA lus : ${r.nbJa} · rapprochés : ${r.jaRapproches} · non rapprochés (ignorés) : ${r.nonRapproches.length}`,
        `Épreuves lues : ${r.nbEpreuves} · compétitions rapprochées : ${r.competRapprochees}`,
        `Lignes ${r.ecrit ? 'créées' : 'à créer'} : ${r.creer} · ${r.ecrit ? 'mises à jour' : 'à mettre à jour'} : ${r.maj}`,
        `Par statut ${r.ecrit ? 'écrit' : 'à écrire'} : ` + Object.entries(r.parStatut || {}).map(([st, n]) => `${st} ${n}`).join(' · '),
        `Ignorées : ${r.ignNonRenseigne} « Non renseigné » · ${r.ignConservees} réponse(s) existante(s) conservée(s)`
            + (r.ignInconnues ? ` · ${r.ignInconnues} valeur(s) inconnue(s)` : ''),
    ];
    if (r.masquees) {
        l.push(`⚠ ${r.masquees} cellule(s) « À confirmer » / « Disponible sous condition » ${r.ecrit ? 'importée(s)' : 'seront importée(s)'} telles quelles,`
            + ' alors que ces choix ne sont plus proposés à la saisie (réglage de l\'écran).');
    }
    if (r.nonRapproches.length) l.push('JA non rapprochés :\n- ' + r.nonRapproches.map(n => `${n.nom} (${n.raison})`).join('\n- '));
    if (r.divergences.length) l.push('Divergences :\n- ' + r.divergences.join('\n- '));
    const alerte = r.nonRapproches.length || r.divergences.length || r.masquees;
    $('#import-resume').removeClass('d-none alert-info alert-success alert-warning alert-danger')
        .addClass(alerte ? 'alert-warning' : (r.ecrit ? 'alert-success' : 'alert-info')).text(l.join('\n'));
}

function envoyerImport(etape) {
    const f = $('#file-import')[0].files[0];
    if (!f) { nijacToast('Choisissez un fichier .xlsx.', 'warning'); return; }
    if (!/\.xlsx$/i.test(f.name) || f.size > 5 * 1024 * 1024) { nijacToast('Fichier .xlsx de 5 Mo maximum.', 'warning'); return; }
    const fd = new FormData();
    fd.append('xlsx', f);
    fd.append('ecraser', $('#chk-ecraser').is(':checked') ? '1' : '0');
    const $btns = $('#btn-import-analyser, #btn-import-valider').prop('disabled', true);
    $.ajax({ url: `${BASE}/import/${etape}`, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            $('#btn-import-analyser').prop('disabled', false);
            if (!r.ok) {
                $('#import-resume').removeClass('d-none alert-info alert-success alert-warning').addClass('alert-danger').text(r.msg || 'Erreur.');
                return;
            }
            resumeImport(r);
            if (!r.ecrit) {
                $('#btn-import-valider').prop('disabled', r.creer + r.maj === 0);
                return;
            }
            nijacToast(`Import : ${r.creer} créée(s), ${r.maj} mise(s) à jour, ${r.ignNonRenseigne + r.ignConservees + r.ignInconnues} ignorée(s), `
                + `${r.nonRapproches.length} JA non rapproché(s).`, r.nonRapproches.length ? 'warning' : 'success');
            const id = courante ? String(courante.Id_CRA_Competition) : null;
            chargerDonnees(() => { if (id) selectionner(id); });
        })
        .fail(() => { $btns.prop('disabled', false); $('#btn-import-valider').prop('disabled', true); nijacToast('Erreur réseau.', 'danger'); });
}
$('#btn-import-analyser').on('click', () => envoyerImport('apercu'));
$('#btn-import-valider').on('click', () => envoyerImport('valider'));

// Différé : nijac-toast.js / nijac-csrf.js / nijac-sortable-table.js sont chargés après ce script.
$(function () {
    nijacSortableTable('#tbl-competitions thead th[data-col]', 'col', sortState, afficherListe);
    chargerDonnees();
});
</script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
