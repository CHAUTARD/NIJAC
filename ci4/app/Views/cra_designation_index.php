<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Désignation CRA (EC73)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
    <style>
        /* Habillage EN11 (nijac-skin.css), en-tête sarcelle E009 comme EC71. */
        /* Hauteur = viewport : header/toolbar/footer fixes, les deux panneaux de <main> défilent seuls. */
        body { background: var(--en-bg); height: 100vh; display: flex; flex-direction: column; }
        #page-header { background: #00695c; }
        main { flex: 1; min-height: 0; padding: 1rem 1.4rem; display: flex; gap: 1rem; }
        #pan-liste { flex: 0 0 45%; min-width: 0; display: flex; flex-direction: column; }
        #pan-form  { flex: 1 1 55%; min-width: 0; overflow-y: auto; }
        .carte {
            background: var(--en-card); border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06); padding: 1rem 1.2rem; margin-bottom: 1rem;
        }
        #invite { padding: 3rem 1.2rem; text-align: center; color: var(--en-muted); }

        /* Liste des compétitions : recherche en pilule + tableau EC71/EN11. */
        /* En-tête : compteur à gauche, recherche alignée à droite ; passe dessous en pleine largeur si trop étroit. */
        /* flex-end : pastille alignée sur le CHAMP (bas commun), pas sur le libellé « Recherche » au-dessus.
           --strip-bg = fond de l'encoche du libellé (.combo-field > label de nijac.css), comme EN11. */
        #liste-header { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .55rem; margin-bottom: .3rem; --strip-bg: var(--en-bg); }
        #champ-recherche { margin-left: auto; max-width: 100%; }
        /* Recherche = pilule #search-input d'EN11 (nijac.css .combo-field > input + nijac-skin.css #menu-strip #search-input). */
        #txt-recherche {
            box-sizing: border-box; height: 2.15rem; width: 290px; max-width: 100%;
            padding: .32rem .9rem; line-height: 1.2; font-size: .85rem; font-weight: 600; color: var(--nijac-blue);
            background: var(--en-card); border: 1px solid var(--en-line); border-radius: 999px;
            box-shadow: 0 1px 2px rgba(16,24,40,.04); transition: border-color .12s;
        }
        #txt-recherche:focus { outline: none; border-color: var(--nijac-blue); }
        #txt-recherche::placeholder { color: #9aa5b8; font-weight: 400; }
        /* Pastille compteur : nombre centré, même hauteur que le champ (2.15rem) ; margin-top .6rem de .count-badge
           (nijac.css, compense l'encoche du libellé quand le bandeau est centré) neutralisé : l'alignement vient du flex-end. */
        #lbl-count {
            margin: 0; box-sizing: border-box; height: 2.15rem; display: inline-flex; align-items: center; justify-content: center;
            text-align: center; min-width: 2.6rem; padding: 0 .6rem; line-height: 1;
        }
        /* Barre d'envoi collée sous le tableau (hors zone défilante) : compte-rendu au-dessus du bouton. */
        #barre-envoi { flex: 0 0 auto; background: #f8f9fa; border-top: 1px solid var(--en-line); padding: .5rem .6rem; }
        #btn-convoquer { white-space: nowrap; }
        #compte-rendu { white-space: pre-line; font-size: .82rem; max-height: 20vh; overflow-y: auto; }
        .legende { font-size: .78rem; color: var(--en-muted); margin-bottom: .5rem; }
        #table-wrapper {
            flex: 1; min-height: 0; overflow: auto; background: var(--en-card);
            border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06);
        }
        #tbl-competitions { width: 100%; font-size: .82rem; border-collapse: collapse; }
        #tbl-competitions thead th {
            position: sticky; top: 0; z-index: 1;
            background: var(--en-card); border: 0; box-shadow: inset 0 -2px 0 var(--en-line);
            color: var(--en-muted); font-size: .68rem; font-weight: 700; white-space: nowrap;
            letter-spacing: .5px; text-transform: uppercase; padding: .7rem .5rem; text-align: left;
            cursor: pointer; user-select: none;
        }
        #tbl-competitions thead th:hover { background: #f6f8fb; }
        #tbl-competitions thead th.th-pk { color: #e65100; }
        #tbl-competitions thead th.th-c, #tbl-competitions td.td-c { text-align: center; }
        #tbl-competitions thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-competitions thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-competitions thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-competitions thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-competitions tbody tr { border-bottom: 1px solid #f2f3f6; cursor: pointer; }
        /* Priorité des fonds : sélection > survol (gris) > rose (sans JA) / vert (complète) > zébrage neutre blanc / gris très léger. */
        #tbl-competitions tbody tr:nth-child(odd) { background: #f7f8fa; }
        #tbl-competitions tbody tr.sans-ja { background: #FFE4E8; }
        #tbl-competitions tbody tr.complete { background: #E3F4E9; }
        #tbl-competitions tbody tr:hover { background: #E9ECEF; }
        #tbl-competitions tbody tr.selected, #tbl-competitions tbody tr.selected:hover { background: #c2e6cf; box-shadow: inset 4px 0 0 #00695c; font-weight: 600; }
        /* Sélection sur une ligne rose : liseré rose en plus. */
        #tbl-competitions tbody tr.selected.sans-ja { box-shadow: inset 4px 0 0 #00695c, inset 8px 0 0 #FFC7CE; }
        .pastille-sans-ja, .pastille-complete { display: inline-block; width: .8rem; height: .8rem; border-radius: 3px; border: 1px solid #FFC7CE; background: #FFE4E8; vertical-align: -1px; margin-right: .2rem; }
        .pastille-complete { border-color: #b7dfc4; background: #E3F4E9; }
        #tbl-competitions td.td-chk { cursor: default; vertical-align: middle; }
        #tbl-competitions .chk-conv, #chk-tout { cursor: pointer; margin: 0; }
        #tbl-competitions .chk-conv:disabled { cursor: not-allowed; }
        #tbl-competitions .ind-conv { color: #00695c; margin-left: .15rem; }
        #tbl-competitions tbody td { padding: .5rem; vertical-align: top; }
        #tbl-competitions td.nowrap { white-space: nowrap; }
        #tbl-competitions td code { color: var(--en-muted); font-size: inherit; }
        #tbl-competitions .ind-ko { color: #b45309; }
        #tbl-competitions .ind-ok { color: #15803d; }
        /* Bandeau des critères de la compétition choisie. */
        #bandeau { background: #e0f2f1; border-left: 5px solid #00695c; }
        #bandeau .titre { font-size: 1.15rem; font-weight: 700; color: #00695c; }
        #bandeau .crit { display: flex; flex-wrap: wrap; gap: .4rem 1.6rem; margin-top: .4rem; font-size: .88rem; }
        #bandeau .crit b { color: var(--en-muted); font-weight: 600; margin-right: .25rem; }
        .slot { display: flex; gap: .5rem; align-items: center; margin-bottom: .45rem; flex-wrap: wrap; }
        .slot label { width: 7.5rem; font-weight: 600; font-size: .9rem; }
        .slot input[type=search] { width: 11rem; font-size: .82rem; }
        .slot select { flex: 1; min-width: 16rem; font-size: .85rem; }
        .slot.en-trop label { color: #b45309; }
        .slot .trop { color: #b45309; font-weight: 600; font-size: .8rem; flex-basis: 100%; padding-left: 8rem; }
        .slot .conflit { color: #b91c1c; font-weight: 600; font-size: .8rem; flex-basis: 100%; padding-left: 8rem; }
        /* Cartouches JA / Adjoints : côte à côte si la place le permet (≥ 2 × 34rem), sinon empilés. */
        .cartouches { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 34rem), 1fr)); gap: 1rem; align-items: start; }
        .cartouche { background: #fff; border: 1px solid var(--en-line); border-radius: var(--en-radius); overflow: hidden; }
        .cartouche-titre { background: #e0f2f1; border-bottom: 1px solid var(--en-line); color: #00695c; font-weight: 700; font-size: .95rem; padding: .45rem .8rem; margin: 0; }
        .cartouche-corps { padding: .7rem .8rem .3rem; }
        #err-designation { white-space: pre-line; font-size: .85rem; }
        /* Légende des couleurs de disponibilité (EC74) des listes de JA. */
        .legende-dispo { display: flex; flex-wrap: wrap; gap: .3rem .9rem; font-size: .78rem; color: var(--en-muted); margin-bottom: .6rem; }
        .legende-dispo span::before { content: ''; display: inline-block; width: .8rem; height: .8rem; border-radius: 3px; border: 1px solid var(--en-line); margin-right: .3rem; vertical-align: -1px; background: var(--c); }
        /* Liste déroulante colorée (le <select> natif, caché, reste le magasin de données). */
        .slot .combo { flex: 1; min-width: 16rem; }
        .combo-btn { width: 100%; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .85rem; color: #212529; }
        .combo-panel {
            position: fixed; z-index: 1050; max-height: 18rem; overflow-y: auto; margin: 0; padding: .25rem 0;
            list-style: none; background: #fff; border: 1px solid var(--en-line); border-radius: .375rem;
            box-shadow: 0 .5rem 1rem rgba(16,24,40,.18); font-size: .85rem;
        }
        .combo-grp { font-size: .68rem; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; color: var(--en-muted); padding: .35rem .6rem .1rem; }
        .combo-opt { position: relative; padding: .3rem 1.6rem .3rem .6rem; color: #212529; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,.7); }
        .combo-opt:hover, .combo-opt.actif { filter: brightness(.94); }
        .combo-opt.actif { outline: 2px solid #00695c; outline-offset: -2px; }
        .combo-opt[aria-selected=true] { font-weight: 700; }
        .combo-opt[aria-selected=true]::after { content: '✓'; position: absolute; right: .55rem; top: .3rem; color: #00695c; }
        .combo-opt[aria-disabled=true] { opacity: .5; cursor: not-allowed; filter: none; }

        /* Barre d'actions : en bas du formulaire, alignée à droite, séparée du contenu. */
        .form-actions {
            display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .5rem;
            margin-top: 1rem; padding-top: .8rem; border-top: 1px solid var(--en-line);
        }

        /* Pied de page (partials/page_footer) : mêmes règles qu'EN11 / E009 — ce
           fichier ne charge pas nijac-liste-edit.css, qui les porte pour EC71/EC72 ;
           sans elles le logo s'affichait à sa taille native et la grille statut/
           copyright n'existait pas. Fond/bordure laissés à nijac-skin.css. */
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

        /* Écran étroit : panneaux empilés, la page défile (tableau à hauteur limitée au-dessus). */
        @media (max-width: 991.98px) {
            body { height: auto; min-height: 100vh; }
            main { flex-direction: column; }
            #pan-liste { flex: none; }
            #table-wrapper { flex: none; max-height: 45vh; }
            #pan-form { flex: none; overflow: visible; scroll-margin-top: .5rem; }
        }

        @media (max-width: 576px) {
            main { padding: .75rem; }
            #champ-recherche { flex: 1 1 100%; margin-left: 0; }
            #txt-recherche { width: 100%; }
            #btn-convoquer { width: 100%; }
            .slot label { width: 100%; }
            .slot input[type=search], .slot select, .slot .combo { width: 100%; min-width: 0; flex: 1 1 100%; }
            .combo-opt { min-height: 2.5rem; display: flex; align-items: center; }
            .combo-opt[aria-selected=true]::after { top: auto; }
            .slot .conflit, .slot .trop { padding-left: 0; }
            .form-actions .btn { flex: 1 1 100%; }
            #page-footer.pf-status-left { display: flex; flex-direction: column; gap: .2rem; }
            .footer-copyright { white-space: normal; text-align: center; }
        }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'person-check', 'phTitle' => 'Désignation CRA', 'phCode' => 'EC73',
    'phCrumbLabel' => 'CRA Convoc', 'phCrumbUrl' => site_url('cra-convoc-menu'), 'phBackUrl' => site_url('cra-convoc-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<main>
  <section id="pan-liste">
    <div id="liste-header">
        <span id="lbl-count" class="count-badge" title="Compétitions affichées">0</span>
        <span class="combo-field" id="champ-recherche">
            <label for="txt-recherche">Recherche</label>
            <input type="search" id="txt-recherche" title="Rechercher (n°, libellé, lieu, dépt)" placeholder="Rechercher (n°, libellé, lieu)…">
        </span>
    </div>
    <div class="legende">✔ = désignation complète · ◐ = partielle · ✉ = convocations envoyées · <span class="pastille-sans-ja"></span>Sans JA principal · <span class="pastille-complete"></span>Complète · Listes de JA (EC74) : ✔ disponible · ◐ sous condition / à confirmer · ✖ indisponible · ? non renseigné</div>
    <div id="table-wrapper">
        <table id="tbl-competitions">
            <thead>
                <tr>
                    <th class="th-c" title="Envoyer les convocations"><input type="checkbox" id="chk-tout" class="form-check-input" aria-label="Cocher toutes les compétitions affichées pour l'envoi des convocations"></th>
                    <th class="th-c" data-col="Etat" title="État de la désignation"><span class="sort-icon"></span></th>
                    <th data-col="DateDebut">Dates<span class="sort-icon"></span></th>
                    <th data-col="Libelle">Libellé<span class="sort-icon"></span></th>
                    <th data-col="Lieu">Lieu<span class="sort-icon"></span></th>
                    <th class="th-c" data-col="CodeDept">Dépt<span class="sort-icon"></span></th>
                    <th data-col="NiveauJA">Niveau JA<span class="sort-icon"></span></th>
                    <th class="th-c" data-col="Designes" title="JA désignés / attendus (adjoints compris)">Désignés<span class="sort-icon"></span></th>
                </tr>
            </thead>
            <tbody id="tbody-liste">
                <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
        </table>
    </div>
    <div id="barre-envoi">
        <div id="compte-rendu" class="alert mb-2 d-none" role="status"></div>
        <button type="button" class="btn btn-success btn-sm" id="btn-convoquer" disabled><i class="bi bi-envelope-paper me-1"></i>Envoyer les convocations (<span id="nb-coches">0</span>)</button>
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
                <span><b>Club</b><span id="b-club"></span></span>
                <span><b>Dépt</b><span id="b-dept"></span></span>
                <span><b>Tables</b><span id="b-tables"></span></span>
                <span><b>Niveau JA</b><span id="b-niveau"></span></span>
                <span><b>JA attendus</b><span id="b-nbja"></span></span>
            </div>
        </div>

        <form class="carte" id="form-designation">
            <div class="legende-dispo">
                <span style="--c:#e3f4e9">Disponible</span><span style="--c:#FCE4D6">À confirmer</span><span style="--c:#DDEBF7">Sous condition</span><span style="--c:#EDEDED">Non renseigné</span>
            </div>
            <div class="cartouches">
                <section class="cartouche">
                    <h6 class="cartouche-titre"><i class="bi bi-person-badge me-1"></i>Juges-Arbitres <span id="cpt-ja"></span></h6>
                    <div class="cartouche-corps" id="slots-ja"></div>
                </section>
                <section class="cartouche" id="cart-adj">
                    <h6 class="cartouche-titre"><i class="bi bi-people me-1"></i>Adjoints <span id="cpt-adj"></span></h6>
                    <div class="cartouche-corps" id="slots-adj"></div>
                </section>
            </div>
            <div id="err-designation" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn-effacer"><i class="bi bi-trash3 me-1"></i>Effacer la désignation</button>
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer la désignation</button>
            </div>
        </form>
    </div>
  </section>
</main>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const BASE = '<?= site_url('cra-designation') ?>';
const GRADES = ['JA1', 'JA2', 'JA3', 'JAN', 'JAI'];
let competitions = [];
let jas = [];
let courante = null;
const sortState = { col: 'DateDebut', asc: true }; // tri par défaut : date de début
let indispos = {}; // Id_JA → compétitions (autres) aux dates qui se chevauchent
const coches = new Set(); // Id_CRA_Competition cochés pour l'envoi des convocations (conservés entre deux affichages)
let modeDev = false;
const INVITE = $('#invite').html(); // invite initiale (remplacée par echecChargement())
// Disponibilité EC74 (CRA_Dispo.Disponible) : rang de tri dans les listes de JA + fond de l'option.
const STATUTS = {
    'Disponible':                { rang: 1, fond: '#e3f4e9' },
    'À confirmer':               { rang: 2, fond: '#FCE4D6' },
    'Disponible sous condition': { rang: 3, fond: '#DDEBF7' },
    'Non renseigné':             { rang: 4, fond: '#EDEDED' },
    'Indisponible':              { rang: 5, fond: '#FFC7CE' },
};

/** « EC n°X (dates) » pour chaque compétition en conflit. */
function texteIndispo(lst, avecLibelle) {
    return lst.map(x => `EC n°${x.Numero}${avecLibelle ? ' ' + x.Libelle : ''} (${formatDates(x.DateDebut, x.DateFin)})`).join(', ');
}

/** 'AAAA-MM-JJ' → 'JJ/MM/AAAA', et '14-15/11/2026' si même mois/année (comme EC71). */
function formatDates(d, f) {
    const [ya, ma, da] = d.split('-');
    if (!f || f === d) return `${da}/${ma}/${ya}`;
    const [yb, mb, db] = f.split('-');
    if (ya === yb && ma === mb) return `${da}-${db}/${ma}/${ya}`;
    return `${da}/${ma}/${ya} - ${db}/${mb}/${yb}`;
}

/** Rang du plus bas des grades listés dans NiveauJA (« JAN JA3 » → JA3). */
function rangMin(niveau) {
    const r = niveau.split(/\s+/).map(g => GRADES.indexOf(g)).filter(i => i >= 0);
    return r.length ? Math.min(...r) : 1;
}

function eligible(ja, min) {
    return GRADES.slice(min).some(g => Number(ja[g]) === 1);
}

function libelleJa(j) {
    const grades = GRADES.slice(1).filter(g => Number(j[g]) === 1).join(' ');
    return `${(j.Nom || '').toUpperCase()} ${j.Prenom || ''}${j.CodeDept ? ` (${j.CodeDept})` : ''} — ${j.NomClub || 'sans club'} — ${grades}`;
}

const nbDes = c => Number(c.NbDesJA) + Number(c.NbDesAdj);

/** Indicateur de la liste : ⚠ à corriger, ✔ complète, ◐ partielle, rien sinon ; rang = clé de tri. */
function etat(c) {
    if (c.ACorriger) return { sym: '⚠', cls: 'ind-ko', titre: 'À corriger', rang: 3 };
    if (c.Complete)  return { sym: '✔', cls: 'ind-ok', titre: 'Complète', rang: 2 };
    if (nbDes(c) > 0) return { sym: '◐', cls: '', titre: 'Partielle', rang: 1 };
    return { sym: '', cls: '', titre: '', rang: 0 };
}

function valeurTri(c, col) {
    if (col === 'Etat') return etat(c).rang;
    if (col === 'Designes') return nbDes(c);
    return String(c[col] ?? '');
}

/** Cochable pour l'envoi des convocations : au moins un JA principal désigné. */
const cochable = c => Number(c.NbDesJA) > 0;

/** 'AAAA-MM-JJ HH:MM:SS' → 'JJ/MM/AAAA à HH:MM'. */
function formatDateHeure(dt) {
    const [d, h] = String(dt).split(' ');
    return `${formatDates(d)}${h ? ' à ' + h.slice(0, 5) : ''}`;
}

/** Case « tout cocher » (lignes affichées et cochables) + compteur du bouton d'envoi. */
function majCoches() {
    const $cases = $('#tbody-liste .chk-conv:not(:disabled)');
    const nbCoches = $cases.filter(':checked').length;
    $('#chk-tout').prop({ checked: $cases.length > 0 && nbCoches === $cases.length,
        indeterminate: nbCoches > 0 && nbCoches < $cases.length, disabled: !$cases.length });
    $('#nb-coches').text(coches.size);
    $('#btn-convoquer').prop('disabled', !coches.size);
}

/** Tableau des compétitions : recherche (n°, libellé, lieu, dépt), tri, ligne courante surlignée. */
function afficherListe() {
    // Cases cochées : seules les compétitions encore cochables restent retenues.
    [...coches].forEach(id => { const c = competitions.find(x => String(x.Id_CRA_Competition) === id); if (!c || !cochable(c)) coches.delete(id); });
    const q = $('#txt-recherche').val().trim().toLowerCase();
    const col = sortState.col;
    const rows = competitions
        .filter(c => !q || `n°${c.Numero} ${c.Libelle} ${c.Lieu} ${c.CodeDept ?? ''}`.toLowerCase().includes(q))
        .sort((a, b) => {
            const va = valeurTri(a, col), vb = valeurTri(b, col);
            const cmp = typeof va === 'number' ? va - vb : va.localeCompare(vb, 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    $('#lbl-count').text(rows.length);
    const $body = $('#tbody-liste').empty();
    if (!rows.length) {
        $body.append('<tr><td colspan="8" class="text-center text-muted py-3">Aucune compétition.</td></tr>');
        majCoches();
        return;
    }
    const idCour = courante ? String(courante.Id_CRA_Competition) : null;
    rows.forEach(c => {
        const e = etat(c);
        const id = String(c.Id_CRA_Competition);
        const sansJa = Number(c.NbPrincipaux) > 0 && Number(c.NbDesJA) === 0;
        const complete = !!c.Complete && !c.ACorriger;
        const $etat = $('<td class="td-c nowrap">').addClass(e.cls).attr('title', e.titre || null).text(e.sym);
        if (Number(c.NbConvoques) > 0) {
            const nbConvAdj = Number(c.NbConvAdj), nbConvJa = Number(c.NbConvoques) - nbConvAdj;
            const tous = Number(c.NbConvoques) >= nbDes(c) ? ' — tous les désignés convoqués' : ` sur ${nbDes(c)} désigné(s)`;
            $etat.append($('<span class="ind-conv">✉</span>').attr('title',
                `Convocations envoyées le ${formatDateHeure(c.DerniereConvocation)} (${nbConvJa} JA principal(aux), ${nbConvAdj} adjoint(s)${tous})`));
        }
        $('<tr tabindex="0">').attr('data-id', id)
            .toggleClass('selected', id === idCour)
            .toggleClass('sans-ja', sansJa).toggleClass('complete', complete)
            .attr('title', sansJa ? 'Aucun JA principal désigné' : (complete ? 'Désignation complète' : null)).append(
            $('<td class="td-c td-chk">').append($('<input type="checkbox" class="chk-conv form-check-input">')
                .val(id).prop({ checked: coches.has(id), disabled: !cochable(c) })
                .attr({ title: cochable(c) ? 'Envoyer les convocations' : 'Aucun JA principal désigné : rien à convoquer',
                    'aria-label': `Envoyer les convocations de la compétition n°${c.Numero}` })),
            $etat,
            $('<td class="nowrap">').text(formatDates(c.DateDebut, c.DateFin)),
            $('<td>').append($('<code>').text(`n°${c.Numero}`), ' — ', document.createTextNode(c.Libelle ?? '')),
            $('<td>').text(c.Lieu),
            $('<td class="td-c">').text(c.CodeDept ?? ''),
            $('<td class="nowrap">').text(c.NiveauJA),
            $('<td class="td-c nowrap">').text(`${nbDes(c)}/${Number(c.NbPrincipaux) + Number(c.NbAdjoints)}`)
        ).appendTo($body);
    });
    majCoches();
}

/** Affiche la compétition d'id donné (prise dans la liste fraîche) ou l'invite si absente. */
function selectionner(id) {
    afficherCompetition(competitions.find(c => String(c.Id_CRA_Competition) === String(id)) || null);
    afficherListe();
}

function chargerDonnees(cb) {
    $.get(`${BASE}/data`, function (res) {
        if (!res.ok) { nijacToast(res.msg || 'Erreur de chargement.', 'danger'); return; }
        competitions = res.competitions;
        jas = res.jas;
        modeDev = !!res.modeDev;
        afficherListe();
        if (cb) cb();
    }, 'json').fail(() => nijacToast('Erreur de chargement.', 'danger'));
}

function creerSlot(role, rang, min) {
    const $sel = $('<select class="form-select form-select-sm">').attr('data-role', role)
        .append('<option value="">— non désigné —</option>');
    // data-nom : seul « nom prénom » sert au filtre (pas le club ni les grades du libellé)
    jas.filter(j => eligible(j, min)).forEach(j => $('<option>').val(j.Id_JA).text(libelleJa(j))
        .attr('data-nom', `${j.Nom || ''} ${j.Prenom || ''}`.toLowerCase()).appendTo($sel));
    const $rech = $('<input type="search" class="form-control form-control-sm" placeholder="Filtrer…">');
    const $slot = $('<div class="slot">').append(
        $('<label>').text(`${role} n°${rang}`), $rech, $sel, $('<div class="conflit d-none">'));
    creerCombo($sel, `${role} n°${rang}`);
    return $slot;
}

/* ---- Liste déroulante colorée : le <select> natif, caché, garde valeur/options/événements ;
   le widget l'affiche (bouton + panneau reconstruit à l'ouverture) et y écrit via .trigger('change'). ---- */
const GROUPES = { 'Disponible': 'Disponibles', 'À confirmer': 'À confirmer', 'Disponible sous condition': 'Sous condition',
    'Non renseigné': 'Non renseignés', 'Indisponible': 'Indisponibles' };
let comboOuvert = null; // { sel, $btn, $pan, actif } : un seul panneau ouvert à la fois
let comboSeq = 0;

function creerCombo($sel, libelle) {
    const id = `combo-${++comboSeq}`;
    const $btn = $('<button type="button" class="combo-btn form-select form-select-sm" role="combobox" aria-haspopup="listbox" aria-expanded="false">')
        .attr({ 'aria-controls': id, 'aria-label': libelle });
    const $pan = $('<ul class="combo-panel d-none" role="listbox" tabindex="-1">').attr({ id, 'aria-label': libelle });
    $sel.addClass('d-none').attr({ 'aria-hidden': 'true', tabindex: '-1' }).after($('<div class="combo">').append($btn, $pan));
    syncCombo($sel[0]);
}

const selDe = el => $(el).closest('.slot').children('select')[0];

/** Recopie l'état du select caché sur le bouton (libellé, fond du statut, is-invalid, disabled). */
function syncCombo(sel) {
    const opt = sel.options[sel.selectedIndex];
    $(sel).next('.combo').children('.combo-btn').text(opt ? opt.text : '').attr('title', opt ? opt.title : '')
        .css('background-color', opt && opt.dataset.dispo ? STATUTS[opt.dataset.dispo].fond : '')
        .toggleClass('is-invalid', sel.classList.contains('is-invalid')).prop('disabled', sel.disabled);
    if (comboOuvert && comboOuvert.sel === sel) { remplirPanneau(); placerPanneau(); }
}

/** Panneau = options non masquées du select, dans son ordre (déjà trié), entêtes de groupe par statut. */
function remplirPanneau() {
    const { sel, $pan } = comboOuvert;
    const items = [];
    let grp = null;
    Array.from(sel.options).forEach((o, i) => {
        if (o.hidden) return;
        const st = o.dataset.dispo;
        if (st && st !== grp) { grp = st; items.push($('<li class="combo-grp" role="presentation">').text(GROUPES[st] || st)); }
        const $li = $('<li class="combo-opt" role="option">').attr({ id: `${$pan[0].id}-${i}`, title: o.title || '',
            'aria-selected': String(o.selected), 'aria-disabled': String(o.disabled) }).data('i', i).text(o.text);
        if (st) $li.css('background-color', STATUTS[st].fond);
        items.push($li);
    });
    $pan.empty().append(items);
    const $act = $pan.children('.combo-opt').filter((_, li) => $(li).data('i') === comboOuvert.actif);
    if ($act.length) marquerActif($act); else comboOuvert.$btn.removeAttr('aria-activedescendant');
}

/** Sous le bouton, même largeur ; vers le haut s'il manque de place en bas. */
function placerPanneau() {
    const r = comboOuvert.$btn[0].getBoundingClientRect(), p = comboOuvert.$pan[0];
    const max = 18 * parseFloat(getComputedStyle(document.documentElement).fontSize);
    const bas = innerHeight - r.bottom - 8, haut = r.top - 8;
    const versHaut = bas < Math.min(p.scrollHeight, max) && haut > bas;
    p.style.maxHeight = Math.max(120, Math.min(max, versHaut ? haut : bas)) + 'px';
    p.style.left = r.left + 'px';
    p.style.width = r.width + 'px';
    p.style.top = (versHaut ? r.top - p.offsetHeight : r.bottom) + 'px';
}

function ouvrirCombo(sel) {
    if (sel.disabled) return;
    if (!comboOuvert || comboOuvert.sel !== sel) {
        fermerCombo();
        const $c = $(sel).next('.combo');
        comboOuvert = { sel, $btn: $c.children('.combo-btn'), $pan: $c.children('.combo-panel'), actif: sel.selectedIndex };
        comboOuvert.$btn.attr('aria-expanded', 'true');
        comboOuvert.$pan.removeClass('d-none');
    }
    remplirPanneau();
    placerPanneau();
    const $act = comboOuvert.$pan.children('.actif');
    if ($act.length) marquerActif($act); // re-cadre une fois la hauteur définitive connue
}

function fermerCombo() {
    if (!comboOuvert) return;
    comboOuvert.$btn.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    comboOuvert.$pan.addClass('d-none').empty();
    comboOuvert = null;
}

function marquerActif($li) {
    comboOuvert.$pan.children('.actif').removeClass('actif');
    $li.addClass('actif');
    comboOuvert.actif = $li.data('i');
    comboOuvert.$btn.attr('aria-activedescendant', $li.attr('id'));
    // Défilement du seul panneau (scrollIntoView pourrait aussi faire défiler #pan-form).
    const p = comboOuvert.$pan[0], li = $li[0];
    if (li.offsetTop < p.scrollTop) p.scrollTop = li.offsetTop;
    else if (li.offsetTop + li.offsetHeight > p.scrollTop + p.clientHeight) p.scrollTop = li.offsetTop + li.offsetHeight - p.clientHeight;
}

function deplacerCombo(d) {
    const $opts = comboOuvert.$pan.children('.combo-opt[aria-disabled=false]');
    if (!$opts.length) return;
    const k = $opts.index($opts.filter('.actif')) + d;
    marquerActif($opts.eq(Math.max(0, Math.min($opts.length - 1, k))));
}

/** Choix : écrit dans le select caché puis 'change' (majOptions recalcule et resynchronise). */
function choisirCombo(i) {
    const { sel, $btn } = comboOuvert;
    const o = sel.options[i];
    if (!o || o.disabled) return;
    fermerCombo();
    if (sel.selectedIndex !== i) { sel.selectedIndex = i; $(sel).trigger('change'); }
    syncCombo(sel);
    $btn.trigger('focus');
}

function afficherCompetition(c) {
    fermerCombo(); // les slots (et leur panneau) vont être recréés
    courante = c;
    $('#zone').toggleClass('d-none', !c);
    $('#invite').toggleClass('d-none', !!c).html(INVITE);
    if (!c) return;
    $('#b-titre').text(`n°${c.Numero} — ${c.Libelle}`);
    $('#b-dates').text(formatDates(c.DateDebut, c.DateFin));
    $('#b-lieu').text(c.Lieu);
    $('#b-club').text(c.Id_Club ? `${c.NomClub ?? ''} (${c.Id_Club})` : '—');
    $('#b-dept').text(c.CodeDept ?? '—');
    $('#b-tables').text(c.NbTablesMin && c.NbTablesMax && c.NbTablesMin !== c.NbTablesMax
        ? `${c.NbTablesMin} à ${c.NbTablesMax}` : (c.NbTablesMin || c.NbTablesMax || '—'));
    $('#b-niveau').text(c.NiveauJA);
    // NbrJA = total, adjoints compris (quotas calculés par data()).
    const nbP = Number(c.NbPrincipaux), nbA = Number(c.NbAdjoints);
    $('#b-nbja').text(`${nbP + nbA} en tout` + (nbA ? ` (dont ${nbA} adjoint${nbA > 1 ? 's' : ''})` : ''));

    const minJa = rangMin(c.NiveauJA);
    const $ja = $('#slots-ja').empty();
    for (let i = 1; i <= nbP; i++) $ja.append(creerSlot('JA', i, minJa));
    const $adj = $('#slots-adj').empty();
    for (let i = 1; i <= nbA; i++) $adj.append(creerSlot('Adjoint', i, 1));
    $('#cart-adj').toggleClass('d-none', !nbA);
    $('#err-designation').addClass('d-none').text('');
    indispos = {};
    majOptions();

    $.get(`${BASE}/${c.Id_CRA_Competition}`, function (res) {
        if (courante !== c) return; // autre compétition choisie entre-temps
        if (!res.ok) { echecChargement(c, res.msg); return; }
        let enTrop = false;
        res.data.forEach(d => {
            // Désignation ancienne au-delà du nombre attendu (NbrJA = total, adjoints compris) :
            // affichée dans une liste « à corriger » plutôt que perdue ; le serveur refuse
            // l'enregistrement tant qu'elle n'est pas vidée (elle est alors supprimée).
            const $box = d.Role === 'JA' ? $('#slots-ja') : $('#slots-adj');
            while ($box.children('.slot').length < Number(d.Rang)) {
                const r = $box.children('.slot').length + 1;
                const $slot = creerSlot(d.Role, r, 1).addClass('en-trop');
                $slot.find('label').text(`${d.Role} n°${r} ⚠`);
                $slot.append($('<div class="trop">')
                    .text('⚠ En trop par rapport au nombre de JA attendus (ancienne désignation) : à corriger, choisir « — non désigné — » (supprimée à l\'enregistrement).'));
                $box.append($slot);
                if (d.Role === 'Adjoint') $('#cart-adj').removeClass('d-none');
                enTrop = true;
            }
            const $s = $(`#form-designation select[data-role="${d.Role}"]`).eq(Number(d.Rang) - 1);
            if (!$s.find(`option[value="${d.Id_JA}"]`).length) {
                // JA désigné qui n'est plus proposé (grade/région) : on le garde visible.
                $('<option>').val(d.Id_JA).text(`JA #${d.Id_JA} (hors liste)`).appendTo($s);
            }
            $s.val(String(d.Id_JA));
        });
        indispos = res.indispos || {};
        $('#form-designation option').each(function () {
            if (indispos[this.value]) this.text += ` — déjà désigné : ${texteIndispo(indispos[this.value])}`;
        });
        // Disponibilité déclarée (EC74) : simple information, n'empêche pas de choisir le JA.
        const dispos = res.dispos || {};
        // ✔ Disponible · ◐ sous condition / à confirmer (précisé) · ✖ Indisponible · ? Non renseigné ou aucune ligne.
        const marques = { 'Disponible': ['✔ ', ''], 'Disponible sous condition': ['◐ ', ' (sous condition)'],
            'À confirmer': ['◐ ', ' (à confirmer)'], 'Indisponible': ['✖ ', ''] };
        $('#form-designation option').each(function () {
            if (this.value === '') return;
            const [pre, suf] = marques[dispos[this.value]] || ['? ', ''];
            this.text = pre + this.text + suf;
            this.title = dispos[this.value] || 'Non renseigné';
            const st = STATUTS[this.title] ? this.title : 'Non renseigné';
            this.dataset.dispo = st;
            this.style.backgroundColor = STATUTS[st].fond;
            this.style.color = '#212529';
        });
        // Tri : statut (ordre STATUTS) puis NOM prénom ; « — non désigné — » reste en tête.
        // Les nœuds sont déplacés (pas recréés) : valeur choisie et désactivations conservées.
        $('#form-designation select').each(function () {
            const v = this.value;
            const opts = $(this).find('option').get().filter(o => o.value !== '');
            opts.sort((a, b) => STATUTS[a.dataset.dispo].rang - STATUTS[b.dataset.dispo].rang
                || (a.dataset.nom || '').localeCompare(b.dataset.nom || '', 'fr', { sensitivity: 'base' }));
            $(this).append(opts);
            // Les JA « Indisponible » ne sont pas proposés (sauf s'ils sont déjà désignés dans cette liste).
            opts.forEach(o => { if (o.dataset.dispo === 'Indisponible' && o.value !== v) o.remove(); });
            this.value = v;
        });
        majOptions();
        if (enTrop) {
            nijacToast('Désignation existante au-delà du nombre de JA attendus : listes ⚠ à corriger avant d\'enregistrer.', 'warning', 6000);
        }
        if ($('#form-designation .slot select.is-invalid').length) {
            nijacToast('Désignation existante en conflit de dates : à corriger avant d\'enregistrer.', 'warning', 6000);
        }
    }, 'json').fail(() => echecChargement(c));
}

/**
 * Désignation enregistrée non chargée (session expirée → redirection 302 vers login, réponse HTML
 * non JSON ; erreur serveur) : les listes déjà affichées resteraient à « — non désigné — » comme si
 * rien n'était désigné, et un enregistrement effacerait la désignation réelle → formulaire retiré.
 */
function echecChargement(c, msg) {
    if (courante !== c) return; // autre compétition choisie entre-temps
    afficherCompetition(null);
    afficherListe();
    $('#invite').text(msg || 'Impossible de charger la désignation enregistrée (session expirée ?) : rechargez la page.');
    nijacToast(msg || 'Désignation non chargée : session expirée ou erreur serveur, rechargez la page.', 'danger', 8000);
}

/**
 * Désactive dans chaque liste les JA déjà choisis dans une autre liste ou déjà
 * désignés ailleurs aux mêmes dates (sauf la valeur courante, pour qu'un conflit
 * antérieur reste visible) ; signale ce conflit sous la liste.
 */
function majOptions() {
    const $sels = $('#form-designation select');
    const pris = $sels.map((_, s) => s.value).get().filter(Boolean);
    $sels.each(function () {
        const v = this.value;
        $(this).find('option').each(function () {
            this.disabled = this.value !== '' && this.value !== v && (pris.includes(this.value) || !!indispos[this.value]);
        });
        // Liste fermée : fond du statut de l'option choisie (aucun pour « — non désigné — »).
        const opt = this.options[this.selectedIndex];
        this.style.backgroundColor = opt && opt.dataset.dispo ? STATUTS[opt.dataset.dispo].fond : '';
        const lst = indispos[v];
        $(this).toggleClass('is-invalid', !!lst).siblings('.conflit').toggleClass('d-none', !lst)
            .text(lst ? `⛔ Déjà désigné aux mêmes dates sur ${texteIndispo(lst, true)} : à remplacer avant d'enregistrer.` : '');
        syncCombo(this);
    });
    // Compteurs des cartouches : désignés / attendus (JA = NbrJA − NbrAdjoint, Adjoints = NbrAdjoint).
    if (courante) {
        const nb = role => $sels.filter(`[data-role="${role}"]`).filter((_, s) => s.value !== '').length;
        $('#cpt-ja').text(`(${nb('JA')}/${Number(courante.NbPrincipaux)})`);
        $('#cpt-adj').text(`(${nb('Adjoint')}/${Number(courante.NbAdjoints)})`);
    }
}

// Colonne des cases à cocher : ne sélectionne pas la ligne (handler délégué plus profond, exécuté avant celui de la ligne).
$('#tbody-liste').on('click keydown', 'td.td-chk', e => e.stopPropagation());
$('#tbody-liste').on('change', '.chk-conv', function () {
    if (this.checked) coches.add(this.value); else coches.delete(this.value);
    majCoches();
});
// Tout cocher / décocher : seulement les lignes affichées (recherche) et cochables.
$('#chk-tout').on('change', function () {
    const coche = this.checked;
    $('#tbody-liste .chk-conv:not(:disabled)').each(function () {
        this.checked = coche;
        if (coche) coches.add(this.value); else coches.delete(this.value);
    });
    majCoches();
});

/** Confirmation (nombres, avertissements, options) puis envoi des convocations des compétitions cochées. */
$('#btn-convoquer').on('click', function () {
    const lst = competitions.filter(c => coches.has(String(c.Id_CRA_Competition)) && cochable(c));
    if (!lst.length) return;
    const somme = k => lst.reduce((n, c) => n + Number(c[k] || 0), 0);
    const nbJa = somme('NbDesJA'), nbAdj = somme('NbDesAdj'), sansEmail = somme('NbSansEmail'), deja = somme('NbConvoques');
    const incompletes = lst.filter(c => !c.Complete || c.ACorriger);
    const lignes = [`Envoyer les convocations de ${lst.length} compétition(s) : ${lst.map(c => 'n°' + c.Numero).join(', ')} ?`,
        `Destinataires : ${nbJa + nbAdj} désigné(s) — ${nbJa} JA principal(aux), ${nbAdj} adjoint(s).`];
    if (incompletes.length) lignes.push(`⚠ Désignation incomplète ou à corriger : ${incompletes.map(c => 'n°' + c.Numero).join(', ')}.`);
    if (sansEmail) lignes.push(`⚠ ${sansEmail} désigné(s) sans email : non convoqué(s).`);
    if (deja) lignes.push(`⚠ ${deja} désigné(s) déjà convoqué(s) : ignoré(s) sauf si « Renvoyer » est coché.`);
    if (modeDev) lignes.push('Mode Développement : les emails sont redirigés vers l\'adresse de développement.');
    nijacConfirm(lignes.join('\n'), function () {
        envoyerConvocations(lst, $('#chk-renvoyer').is(':checked'), $('#chk-cc-conv').is(':checked'));
    }, null, { type: 'question', title: 'Envoi des convocations', confirmLabel: 'Envoyer' });
    // nijacConfirm n'affiche que du texte : options ajoutées au corps de la modale (relu à la confirmation).
    const opt = (id, txt) => $('<label class="form-check d-block mb-0 mt-2">').append(
        $('<input type="checkbox" class="form-check-input">').attr('id', id), $('<span class="form-check-label">').text(txt));
    $('#nijac-confirm-modal-body').append(deja ? opt('chk-renvoyer', 'Renvoyer aussi à ceux déjà convoqués') : '', opt('chk-cc-conv', 'M\'envoyer une copie (Cc)'));
});

/** Un appel par compétition (évite le timeout SMTP, montre la progression), puis compte-rendu. */
function envoyerConvocations(lst, renvoyer, cc) {
    const cr = { ok: [], okAdj: [], sans: [], ign: [], echec: [] };
    const $btn = $('#btn-convoquer').prop('disabled', true);
    const $cr = $('#compte-rendu').removeClass('d-none alert-success alert-warning').addClass('alert-info');
    let i = 0;
    const suivant = () => {
        if (i >= lst.length) return fin();
        const c = lst[i++];
        $cr.text(`Envoi en cours… compétition ${i} / ${lst.length} (n°${c.Numero})`);
        $.post(`${BASE}/convocations`, { id: c.Id_CRA_Competition, renvoyer: renvoyer ? '1' : '0', cc: cc ? '1' : '0' }, function (r) {
            const t = r.titre || `n°${c.Numero}`;
            if (!r.ok) { cr.echec.push(`${t} : ${r.msg}`); return suivant(); }
            r.envoyes.forEach(n => cr.ok.push(`${n} (${t})`));
            (r.envoyesAdj || []).forEach(n => cr.okAdj.push(`${n} (${t})`));
            r.sansEmail.forEach(n => cr.sans.push(`${n} (${t})`));
            r.ignores.forEach(n => cr.ign.push(`${n} — ${t}`));
            r.echecs.forEach(n => cr.echec.push(`${n} — ${t}`));
            if (r.stop) { cr.echec.push(`Envoi interrompu (${lst.length - i} compétition(s) non traitée(s)).`); return fin(); }
            suivant();
        }, 'json').fail(() => { cr.echec.push(`n°${c.Numero} : erreur réseau`); suivant(); });
    };
    const fin = () => {
        const nbOk = cr.ok.length + cr.okAdj.length;
        const lignes = [`Convocations envoyées : ${nbOk}`,
            `- JA principaux : ${cr.ok.length}${cr.ok.length ? ' — ' + cr.ok.join(', ') : ''}`,
            `- Adjoints : ${cr.okAdj.length}${cr.okAdj.length ? ' — ' + cr.okAdj.join(', ') : ''}`];
        if (cr.echec.length) lignes.push(`Échecs : ${cr.echec.length}\n- ${cr.echec.join('\n- ')}`);
        if (cr.sans.length) lignes.push(`Sans email (non envoyés) : ${cr.sans.length} — ${cr.sans.join(', ')}`);
        if (cr.ign.length) lignes.push(`Ignorés : ${cr.ign.length}\n- ${cr.ign.join('\n- ')}`);
        $cr.removeClass('alert-info').addClass(cr.echec.length || cr.sans.length ? 'alert-warning' : 'alert-success').text(lignes.join('\n'));
        nijacToast(`${nbOk} convocation(s) envoyée(s) (${cr.ok.length} JA, ${cr.okAdj.length} adjoint(s))` + (cr.echec.length ? `, ${cr.echec.length} échec(s)` : '')
            + (cr.sans.length ? `, ${cr.sans.length} sans email` : '') + (cr.ign.length ? `, ${cr.ign.length} ignorée(s)` : ''),
            cr.echec.length ? 'warning' : 'success', 6000);
        coches.clear();
        const id = courante ? String(courante.Id_CRA_Competition) : null;
        chargerDonnees(() => { if (id) selectionner(id); }); // ✉ et compteurs à jour ; bouton réactivé par majCoches()
        $btn.prop('disabled', !coches.size);
    };
    suivant();
}

// Clic (ou Entrée) sur une ligne : sélectionne la compétition ; re-clic sur la courante sans effet
// (ne pas perdre une saisie en cours). Écran étroit : défile jusqu'au formulaire empilé dessous.
$('#tbody-liste').on('click keydown', 'tr[data-id]', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter') return;
    const id = this.dataset.id;
    if (!courante || String(courante.Id_CRA_Competition) !== id) selectionner(id);
    if (window.matchMedia('(max-width: 991.98px)').matches) {
        document.getElementById('pan-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

$('#txt-recherche').on('input', afficherListe);

$('#form-designation').on('change', 'select', majOptions);

$('#form-designation').on('input', 'input[type=search]', function () {
    const q = this.value.trim().toLowerCase();
    const $sel = $(this).siblings('select');
    $sel.find('option').each(function () {
        const nom = this.dataset.nom || '';
        const prenomNom = nom.split(' ').reverse().join(' ');
        this.hidden = this.value !== '' && this.value !== $sel.val() && !!q && !nom.includes(q) && !prenomNom.includes(q);
    });
    ouvrirCombo($sel[0]); // le panneau reflète les options non masquées
});

// Liste déroulante colorée : souris, clavier (flèches, Entrée/Espace, Échap, Tab), clic extérieur.
$('#form-designation').on('click', '.combo-btn', function () {
    const sel = selDe(this);
    if (comboOuvert && comboOuvert.sel === sel) fermerCombo(); else ouvrirCombo(sel);
});
$('#form-designation').on('mousedown', '.combo-panel', e => e.preventDefault()); // garde le focus
$('#form-designation').on('click', '.combo-opt', function () { if (comboOuvert) choisirCombo($(this).data('i')); });
$('#form-designation').on('keydown', '.combo-btn, .slot input[type=search]', function (e) {
    const sel = selDe(this), ouvert = !!comboOuvert && comboOuvert.sel === sel;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (ouvert) deplacerCombo(e.key === 'ArrowDown' ? 1 : -1); else ouvrirCombo(sel);
    } else if (ouvert && (e.key === 'Enter' || (e.key === ' ' && this.tagName === 'BUTTON'))) {
        e.preventDefault(); // ni bascule du bouton, ni envoi du formulaire
        choisirCombo(comboOuvert.actif);
    } else if (ouvert && e.key === 'Escape') {
        e.preventDefault();
        fermerCombo();
    } else if (e.key === 'Tab') {
        fermerCombo();
    }
});
$(document).on('mousedown', function (e) {
    if (!comboOuvert) return;
    const $t = $(e.target);
    if (!($t.closest('.combo, input[type=search]').length && selDe(e.target) === comboOuvert.sel)) fermerCombo();
});
window.addEventListener('scroll', e => { if (comboOuvert && !comboOuvert.$pan[0].contains(e.target)) placerPanneau(); }, true);
window.addEventListener('resize', () => { if (comboOuvert) placerPanneau(); });

$('#form-designation').on('submit', function (e) {
    e.preventDefault();
    if (!courante) return;
    const val = role => $(`#form-designation select[data-role="${role}"]`).map((_, s) => s.value).get();
    $('#err-designation').addClass('d-none').text('');
    $.post(`${BASE}/${courante.Id_CRA_Competition}`, { ja: val('JA'), adjoint: val('Adjoint') }, function (res) {
        if (res.err) {
            // Refus serveur (chevauchement de dates) : détail en texte brut dans le formulaire.
            $('#err-designation').text(res.err).removeClass('d-none');
            nijacToast('Enregistrement refusé : conflit de dates.', 'danger', 6000);
            return;
        }
        nijacToast(res.msg, res.ok ? 'success' : 'danger');
        if (!res.ok) return;
        // Ré-affiche : les listes ⚠ vidées disparaissent une fois enregistrées.
        const id = String(courante.Id_CRA_Competition);
        chargerDonnees(() => selectionner(id));
    }, 'json').fail(() => nijacToast('Erreur réseau.', 'danger'));
});

$('#btn-effacer').on('click', function () {
    if (!courante) return;
    const id = String(courante.Id_CRA_Competition);
    nijacConfirm(`Effacer toute la désignation de la compétition n°${courante.Numero} « ${courante.Libelle} » ?`, function () {
        $.ajax({ url: `${BASE}/${id}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            nijacToast(res.msg, res.ok ? 'success' : 'danger');
            if (res.ok) chargerDonnees(() => selectionner(id));
        }).fail(() => nijacToast('Erreur réseau.', 'danger'));
    }, null, { type: 'danger', confirmLabel: 'Effacer' });
});

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
