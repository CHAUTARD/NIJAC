<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= esc($csrfToken) ?>">
<title>NIJAC – Nomination JA (E022)</title>
<link rel="stylesheet" href="/asset/css/bootstrap.min.css">
<link rel="stylesheet" href="/asset/css/bootstrap-icons.min.css">
<style>
:root { --nijac-blue: #1a3a6b; --nom-green: #2e7d32; }

body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; height:100vh; display:flex; flex-direction:column; overflow:hidden; }

/* ── En-tête ── */
#page-header { background:var(--nom-green); color:#fff; padding:.65rem 1.25rem; font-size:.9rem; font-weight:600; display:flex; align-items:center; gap:.75rem; flex-shrink:0; }
#page-header .btn-retour { margin-left:auto; }

/* ── Toolbar ── */
#toolbar { background:#f8fafc; border-bottom:1px solid #dde5f0; padding:.3rem 1rem; display:flex; align-items:center; justify-content:space-between; font-size:.85rem; flex-shrink:0; }
.ts-user { color:#1a3a6b; font-weight:600; }
.ts-pwd-warning { display:<?= $changeLogin ? 'inline-flex' : 'none' ?>; align-items:center; gap:.35rem; color:#c00; font-weight:700; cursor:pointer; text-decoration:underline dotted; }

#barre-selection { background:#fff; border-bottom:1px solid #dee2e6; padding:.6rem 1.25rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; flex-shrink:0; }
#barre-selection label { font-size:.85rem; font-weight:600; color:#555; margin-bottom:0; }
#barre-selection select { font-size:.85rem; min-width:130px; }
.badge-journee { font-size:.72rem; }

/* ── Info journée ── */
#info-journee { background:#e8f5e9; border-bottom:1px solid #c8e6c9; padding:.4rem 1.25rem; font-size:.82rem; color:#2e7d32; display:none; gap:1.5rem; align-items:center; flex-wrap:wrap; flex-shrink:0; }

/* ── Layout deux colonnes ── */
#main-content { display:flex; flex:1; overflow:hidden; }

/* ── Colonne gauche — rencontres ── */
#col-rencontres { width:42%; min-width:300px; border-right:2px solid #dee2e6; overflow-y:auto; display:flex; flex-direction:column; }
#col-rencontres .col-titre { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:.55rem .85rem; font-size:.82rem; font-weight:700; color:#444; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:5; }

.renc-item { padding:.55rem .85rem; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background .12s; display:flex; align-items:center; gap:.5rem; }
.renc-item:hover { background:#e8f5e9; }
.renc-item.selected { background:#c8e6c9; border-left:3px solid var(--nom-green); }
.renc-item.attribue { background:#e8eaf6; }
.renc-item.attribue.selected { background:#c5cae9; border-left:3px solid #3949ab; }
.renc-item .renc-div { font-size:.68rem; font-weight:700; background:#1a3a6b; color:#fff; padding:.1rem .35rem; border-radius:3px; flex-shrink:0; }
.renc-item .renc-corps { flex:1; min-width:0; }
.renc-item .renc-equipes { font-size:.82rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.renc-item .renc-lieu { font-size:.72rem; color:#666; }
.renc-item .renc-ja { font-size:.72rem; color:#3949ab; font-weight:600; }
.renc-item .renc-ico { font-size:1rem; flex-shrink:0; }
.renc-item.attribue .renc-ico { color:#3949ab; }
.renc-item:not(.attribue) .renc-ico { color:#bbb; }

/* ── Colonne droite — candidats JA ── */
#col-candidats { flex:1; display:flex; flex-direction:column; overflow:hidden; }
#liste-candidats { flex:1; overflow-y:auto; }
#col-candidats .col-titre { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:.55rem .85rem; font-size:.82rem; font-weight:700; color:#444; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:5; }

#placeholder-candid { display:flex; flex-direction:column; align-items:center; justify-content:center; height:200px; color:#aaa; font-size:.9rem; gap:.5rem; }

.cand-card { margin:.55rem .85rem; border:1px solid #dee2e6; border-radius:8px; background:#fff; overflow:hidden; transition:box-shadow .12s; }
.cand-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.15); }
.cand-card .cand-header { padding:.45rem .7rem; background:#e8f5e9; display:flex; align-items:center; gap:.5rem; border-bottom:1px solid #dee2e6; }
.cand-card .cand-rang { width:24px; height:24px; border-radius:50%; background:var(--nom-green); color:#fff; font-size:.75rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.cand-card .cand-nom { font-weight:700; font-size:.9rem; flex:1; }
.cand-card .cand-grade { font-size:.72rem; background:#1a3a6b; color:#fff; padding:.1rem .35rem; border-radius:3px; }
.cand-card .cand-nationale { font-size:.72rem; color:#555; margin-left:.2rem; }
.cand-card .cand-body { padding:.45rem .7rem; display:flex; align-items:center; gap:1rem; }
.cand-card .cand-loc { font-size:.78rem; color:#555; flex:1; }
.cand-card .cand-stats { font-size:.75rem; color:#888; text-align:right; }
.cand-card .cand-badges { display:flex; gap:.3rem; flex-wrap:wrap; }
.badge-pref { background:#fff3e0; color:#e65100; border:1px solid #ffb74d; font-size:.68rem; }
.badge-prox { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; font-size:.68rem; }
.badge-dispo-O { background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; font-size:.68rem; }
.badge-dispo-P { background:#fff8e1; color:#f57f17; border:1px solid #ffe082; font-size:.68rem; }
.btn-affecter { font-size:.8rem; padding:.25rem .7rem; }

#barre-actions { background:#fff; border-top:2px solid #dee2e6; padding:.65rem 1.25rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; flex-shrink:0; }
#btn-valider { display:none; }
#btn-envoyer { display:none; }

/* ── Modale récapitulatif ── */
#recapBody .recap-row { display:flex; align-items:center; gap:.5rem; padding:.3rem 0; border-bottom:1px solid #f0f0f0; font-size:.85rem; }
#recapBody .recap-div { font-size:.68rem; font-weight:700; background:#1a3a6b; color:#fff; padding:.1rem .3rem; border-radius:3px; min-width:40px; text-align:center; }
#recapBody .recap-equipes { flex:1; }
#recapBody .recap-ja { color:#2e7d32; font-weight:600; }

/* ── Modale liens ── */
#liensBody .lien-row { padding:.4rem 0; border-bottom:1px solid #eee; font-size:.83rem; }
#liensBody .lien-nom { font-weight:600; }
#liensBody .lien-url { word-break:break-all; color:#1565c0; font-size:.75rem; }

/* ── Bouton note JA ── */
.btn-note-ja { background:#f0c040; color:#1a3a6b; border:none; font-size:.8rem; font-weight:700; padding:.3rem .75rem; border-radius:5px; line-height:1.4; flex-shrink:0; box-shadow:0 2px 5px rgba(0,0,0,.2); }
.btn-note-ja:hover { background:#e0b030; color:#1a3a6b; }

/* ── Spinner ── */
.spinner-sm { width:1rem; height:1rem; }

/* ── Responsive ── */
@media (max-width: 768px) {
    #main-content { flex-direction:column; }
    #col-rencontres { width:100%; border-right:none; border-bottom:2px solid #dee2e6; max-height:40vh; }
}
</style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php -->
<div id="page-header">
    <i class="bi bi-person-check-fill fs-5 me-2"></i>Nomination des Juges-Arbitres
    <small style="color:#d0f0d0;">(E022)</small>
    <a href="<?= site_url('nominateur-menu') ?>" class="btn btn-sm btn-outline-light btn-retour">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>

<!-- Toolbar : recopié de Nominateur/includes/toolbar.php -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= esc($nomComplet) ?><?= $departement ? ' (' . esc($departement) . ')' : '' ?>
    </span>
    <a class="ts-pwd-warning" href="/changer_mot_de_passe.php" id="lnk-chg-pwd" data-base="/changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<?php require __DIR__ . '/../../../includes/modal_mdp.php'; ?>

<!-- Barre de sélection -->
<div id="barre-selection">
    <label for="sel-journee"><i class="bi bi-calendar-event me-1"></i>Journée</label>
    <select id="sel-journee" class="form-select form-select-sm w-auto" disabled>
        <option value="">— chargement —</option>
    </select>
    <div id="spinner-barre" class="spinner-border spinner-sm text-secondary ms-2" role="status" style="display:none"><span class="visually-hidden">Chargement…</span></div>
</div>

<!-- Info journée -->
<div id="info-journee" style="display:none">
    <i class="bi bi-info-circle-fill"></i>
    <span id="info-nb-renc"></span>
    <span class="text-muted">|</span>
    <span id="info-nb-attribues"></span>
</div>

<!-- Corps principal -->
<div id="main-content">

    <!-- Colonne gauche : rencontres -->
    <div id="col-rencontres">
        <div class="col-titre">
            <span><i class="bi bi-list-ul me-1"></i>Rencontres</span>
            <span id="renc-sel-titre" class="fw-normal text-muted flex-grow-1 text-center" style="font-size:.78rem"></span>
            <span id="compteur-renc" class="text-muted fw-normal" style="font-size:.75rem"></span>
        </div>
        <div id="liste-rencontres">
            <div class="text-center text-muted py-5" style="font-size:.85rem">
                <i class="bi bi-calendar2-x fs-2 d-block mb-2"></i>Sélectionnez une journée
            </div>
        </div>
    </div>

    <!-- Colonne droite : candidats JA -->
    <div id="col-candidats">
        <div class="col-titre">
            <span><i class="bi bi-people-fill me-1"></i>Candidats JA</span>
        </div>
        <div id="placeholder-candid">
            <i class="bi bi-arrow-left-circle fs-2"></i>
            <span>Sélectionnez une rencontre</span>
        </div>
        <div id="liste-candidats"></div>
    </div>

</div>

<!-- Barre d'actions -->
<div id="barre-actions">
    <button id="btn-recap" class="btn btn-outline-secondary btn-sm" style="display:none">
        <i class="bi bi-list-check me-1"></i>Récapitulatif
    </button>
    <button id="btn-valider" class="btn btn-success btn-sm">
        <i class="bi bi-check-circle-fill me-1"></i>Valider les nominations
    </button>
    <button id="btn-envoyer" class="btn btn-primary btn-sm">
        <i class="bi bi-envelope-fill me-1"></i>Envoyer les convocations
    </button>
    <span id="msg-actions" class="ms-auto text-muted" style="font-size:.82rem"></span>
</div>

<!-- Modale récapitulatif -->
<div class="modal fade" id="modalRecap" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-list-check me-2"></i>Récapitulatif des nominations</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2" id="recapBody"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-success btn-sm" id="btn-valider-modal">
                    <i class="bi bi-check-circle-fill me-1"></i>Confirmer la validation
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modale liens d'envoi -->
<div class="modal fade" id="modalLiens" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-envelope-fill me-2"></i>Convocations envoyées</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <p class="mb-2" id="msg-envoi-resume" style="font-size:.85rem"></p>
                <div id="liensBody"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modale note JA (lecture seule) -->
<div class="modal fade" id="modalNoteJa" tabindex="-1" aria-labelledby="modalNoteJaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#1a3a6b;color:#fff">
                <h5 class="modal-title fs-6" id="modalNoteJaLabel">
                    <i class="bi bi-sticky-fill me-2" style="color:#f0c040"></i>Note de <span id="note-ja-nom"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-2" style="font-size:.78rem"><i class="bi bi-info-circle me-1"></i>Information communiquée par le JA à destination des nominateurs.</p>
                <div id="note-ja-texte"
                     class="p-3 rounded"
                     style="background:#fffde7;border:1px solid #f0c040;font-size:.88rem;white-space:pre-wrap;min-height:60px"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script src="/asset/js/jquery-3.7.1.min.js"></script>
<script src="/asset/js/nijac-csrf.js"></script>
<script src="/asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const NOM_BASE = '<?= site_url('nomination') ?>';

// ── État ─────────────────────────────────────────────────────────────────────
let journeeCourante = null;  // {Journee, Date}
let rencontres      = [];    // tableau rencontres de la journée (avec VenueLat/VenueLon)
let jaList          = [];    // tous les JA disponibles pour la journée (chargé une fois)
let nominations     = {};    // {Id_Rencontre: {Id_JA, Nom, Prenom}} — état local

// ── Helpers ──────────────────────────────────────────────────────────────────
function ajax(action, params) {
    return $.ajax({ url: `${NOM_BASE}/${action}`, dataType: 'json', ...params });
}

function spin(show) {
    $('#spinner-barre').toggle(show);
}

function formatDate(s) {
    // YYYY-MM-DD → "Samedi 20 Septembre 2025"
    if (!s) return '';
    const d = new Date(s + 'T00:00:00');
    const jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    const mois  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return `${jours[d.getDay()]} ${d.getDate()} ${mois[d.getMonth()]} ${d.getFullYear()}`;
}

// ── Init ─────────────────────────────────────────────────────────────────────
$(function () {
    chargerJournees();

    $('#sel-journee').on('change', function () {
        const val = this.value;
        if (!val) return;
        const [journee, date] = val.split('|');
        journeeCourante = { Journee: parseInt(journee), Date: date };
        chargerRencontres();
    });

    $('#btn-recap').on('click', afficherRecap);
    $('#btn-valider').on('click', afficherRecap);
    $('#btn-valider-modal').on('click', validerNominations);
    $('#btn-envoyer').on('click', envoyerConvocations);
});

// ── Journées ─────────────────────────────────────────────────────────────────
function chargerJournees() {
    spin(true);
    $('#sel-journee').prop('disabled', true).empty().append('<option value="">Chargement…</option>');
    ajax('journees', { method: 'GET' })
        .done(function (r) {
            const $sel = $('#sel-journee').empty().append('<option value="">— Journée —</option>');
            if (!r.ok || !r.data.length) {
                $sel.append('<option value="" disabled>Aucune journée</option>');
            } else {
                r.data.forEach(j => {
                    $sel.append(
                        $('<option>')
                            .val(`${j.Journee}|${j.Date}`)
                            .text(`J${j.Journee} — ${formatDate(j.Date)}`)
                    );
                });
            }
            $sel.prop('disabled', false);
            // Sélectionner la première journée (toutes sont à venir)
            const $first = $sel.find('option').not('[value=""]').first();
            if ($first.length) {
                $sel.val($first.val()).trigger('change');
            }
        })
        .always(() => spin(false));
}

// ── Rencontres + JA chargés en parallèle ─────────────────────────────────────
function chargerRencontres() {
    if (!journeeCourante) return;
    spin(true);
    nominations = {};
    jaList      = [];
    $('#liste-rencontres').html('<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-success"></div></div>');
    viderCandidats();

    const ajaxRencontres = ajax('rencontres-journee', {
        method: 'GET',
        data: { journee: journeeCourante.Journee, date: journeeCourante.Date }
    });
    const ajaxJa = ajax('candidats-journee', {
        method: 'GET',
        data: { date: journeeCourante.Date }
    });

    $.when(ajaxRencontres, ajaxJa)
        .done(function (rRenc, rJa) {
            const r = rRenc[0];
            const j = rJa[0];
            if (!r.ok) {
                $('#liste-rencontres').html(`<div class="text-danger p-3">${r.err}</div>`);
                return;
            }
            rencontres = r.data;
            rencontres.forEach(rc => {
                if (rc.IdJaAffecte) {
                    nominations[rc.Id_Rencontre] = { Id_JA: rc.IdJaAffecte, Nom: rc.NomJaAffecte || '', Prenom: '' };
                }
            });
            if (j.ok) jaList = j.data;
            renderRencontres();
            mettreAJourInfoJournee();
            mettreAJourBoutons();
            // Sélectionner automatiquement la première rencontre non attribuée, sinon la première
            const premiereRenc = rencontres.find(rc => !nominations[rc.Id_Rencontre]) || rencontres[0];
            if (premiereRenc) selectionnerRencontre(premiereRenc.Id_Rencontre);
        })
        .always(() => spin(false));
}

function renderRencontres() {
    const $liste = $('#liste-rencontres').empty();
    if (!rencontres.length) {
        $liste.html('<div class="text-center text-muted py-4" style="font-size:.85rem">Aucune rencontre</div>');
        return;
    }
    rencontres.forEach(rc => {
        const attr     = !!nominations[rc.Id_Rencontre];
        const nomJa    = attr ? (nominations[rc.Id_Rencontre].Prenom + ' ' + nominations[rc.Id_Rencontre].Nom).trim() : '';
        const divColor = rc.DivisionColor || '#1a3a6b';
        const heure    = rc.Heure ? rc.Heure.substring(0, 5) : '';
        const poule    = rc.Poule ? `Poule ${escHtml(rc.Poule)}` : '';
        const salle    = rc.NomSalle ? escHtml(rc.NomSalle) + ' — ' : '';
        const lieu     = salle + [rc.CpSalle, rc.VilleSalle].filter(Boolean).map(escHtml).join(' ');
        $liste.append(`
            <div class="renc-item ${attr ? 'attribue' : ''}" data-id="${rc.Id_Rencontre}">
                <span class="renc-div" style="background:${escHtml(divColor)}">${escHtml(rc.DivisionCode || '')}</span>
                <div class="renc-corps">
                    <div class="renc-equipes">${escHtml(rc.NomDom)} vs ${escHtml(rc.NomExt || '?')}</div>
                    <div class="renc-lieu">
                        ${heure ? `<i class="bi bi-clock" style="font-size:.68rem"></i> ${heure}` : ''}
                        ${poule ? `<span class="ms-2">${poule}</span>` : ''}
                        ${lieu  ? `<span class="ms-2"><i class="bi bi-geo-alt" style="font-size:.68rem"></i> ${lieu}</span>` : ''}
                    </div>
                    ${attr ? `<div class="renc-ja"><i class="bi bi-person-check me-1"></i>${escHtml(nomJa)}</div>` : ''}
                </div>
                <i class="bi ${attr ? 'bi-person-check-fill' : 'bi-person-dash'} renc-ico"></i>
            </div>
        `);
    });

    $('#liste-rencontres').off('click', '.renc-item').on('click', '.renc-item', function () {
        selectionnerRencontre(parseInt($(this).data('id')));
    });

    const nb    = rencontres.length;
    const nbAttr = Object.keys(nominations).length;
    $('#compteur-renc').text(`${nbAttr}/${nb} attribué${nbAttr > 1 ? 's' : ''}`);
}

// ── Sélection d'une rencontre ─────────────────────────────────────────────────
let rencSelectionnee = null;

function selectionnerRencontre(idRenc) {
    rencSelectionnee = idRenc;
    $('#liste-rencontres .renc-item').removeClass('selected');
    $(`#liste-rencontres .renc-item[data-id="${idRenc}"]`).addClass('selected');

    const rc = rencontres.find(r => r.Id_Rencontre == idRenc);
    if (rc) {
        $('#renc-sel-titre').text(`${rc.NomDom} vs ${rc.NomExt || '?'}`);
    }

    afficherCandidatsPourRencontre(idRenc);
}

// ── Candidats JA pour une rencontre — filtrage et tri côté client ─────────────
function afficherCandidatsPourRencontre(idRenc) {
    const rc = rencontres.find(r => r.Id_Rencontre == idRenc);
    $('#placeholder-candid').hide();

    if (!rc || !jaList.length) {
        $('#liste-candidats').html('<div class="text-center text-muted py-4" style="font-size:.85rem"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA disponible pour cette journée</div>');
        return;
    }

    const venueLat = rc.VenueLat != null ? parseFloat(rc.VenueLat) : null;
    const venueLon = rc.VenueLon != null ? parseFloat(rc.VenueLon) : null;
    const clubDom  = rc.IdClubDom;
    const divNat   = (rc.DivisionCode || '').startsWith('N');

    const candidats = [];
    jaList.forEach(ja => {
        const dispoJournee = ja.DispoJournee == 1;
        const dispoRencs   = ja.DispoRencontres
            ? ja.DispoRencontres.split(',').map(Number)
            : [];
        const prefereRenc  = dispoRencs.includes(idRenc);
        if (!dispoJournee && !prefereRenc) return;

        if (ja.Id_Club && ja.Id_Club === clubDom) return;

        const dejaAffecte = Object.entries(nominations).some(
            ([rid, nom]) => parseInt(rid) !== idRenc && nom.Id_JA == ja.Id_JA
        );
        if (dejaAffecte) return;

        const dist  = haversineKm(ja.JaLat, ja.JaLon, venueLat, venueLon);
        const score = (prefereRenc ? 300 : 0)
            + (dispoJournee ? 100 : 50)
            + (dist != null && dist <= 20 ? 50 : 0)
            + (divNat && ja.Nationale == 1 ? 200 : 0);

        candidats.push({
            ...ja,
            DistanceKm:    dist,
            PrefereRenc:   prefereRenc ? 1 : 0,
            Disponibilite: dispoJournee ? 'O' : 'P',
            Score:         score
        });
    });

    candidats.sort((a, b) =>
        b.Score - a.Score || a.NbNominations - b.NbNominations || a.Nom.localeCompare(b.Nom)
    );

    const jaAffecteId = nominations[idRenc] ? nominations[idRenc].Id_JA : null;

    // JA affecté toujours en tête, indépendamment du score
    if (jaAffecteId != null) {
        const idx = candidats.findIndex(c => c.Id_JA == jaAffecteId);
        if (idx > 0) candidats.unshift(candidats.splice(idx, 1)[0]);
    }

    const top5 = candidats.slice(0, 15);
    $('#liste-candidats').empty();

    if (!top5.length) {
        $('#liste-candidats').html('<div class="text-center text-muted py-4" style="font-size:.85rem"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA disponible pour cette rencontre</div>');
    } else {
        top5.forEach((ja, idx) => {
            const estAffecte = (jaAffecteId != null && ja.Id_JA == jaAffecteId);
            const loc     = [ja.Cp, ja.Ville].filter(Boolean).join(' ');
            const distTxt = ja.DistanceKm != null ? `${ja.DistanceKm} km` : '';
            const nomMin  = ja.NbNominations > 0 ? `${ja.NbNominations} arb.` : '0 arb.';

            let badges = '';
            if (ja.PrefereRenc == 1) badges += '<span class="badge badge-pref rounded-pill me-1"><i class="bi bi-star-fill me-1"></i>Choix JA</span>';
            if (ja.DistanceKm != null && ja.DistanceKm <= 20) badges += '<span class="badge badge-prox rounded-pill me-1"><i class="bi bi-geo-alt-fill me-1"></i>Proche</span>';
            badges += `<span class="badge ${ja.Disponibilite === 'O' ? 'badge-dispo-O' : 'badge-dispo-P'} rounded-pill">${ja.Disponibilite === 'O' ? 'Disponible' : 'Partiel'}</span>`;

            const noteBtn = ja.Note
                ? `<button class="btn btn-note-ja btn-note-ja-trigger" data-note="${escHtml(ja.Note)}" data-nom="${escHtml(ja.Prenom + ' ' + ja.Nom)}"><i class="bi bi-sticky-fill me-1"></i>Note</button>`
                : '';

            const headerStyle = estAffecte
                ? 'background:#2e7d32;color:#fff;'
                : '';
            const rangStyle = estAffecte
                ? 'background:#fff;color:#2e7d32;'
                : '';
            const actionBtn = estAffecte
                ? `<button class="btn btn-danger btn-retirer btn-sm px-3" data-renc="${idRenc}" style="font-size:.8rem;font-weight:700;"><i class="bi bi-x-lg me-1"></i>Retirer</button>`
                : `<button class="btn btn-success btn-affecter btn-sm" data-renc="${idRenc}"><i class="bi bi-person-check me-1"></i>Affecter</button>`;

            const cardBorder = estAffecte ? 'border:2px solid #2e7d32;' : '';
            const bodyBg     = estAffecte ? 'background:#f1f8f1;' : '';

            const card = $(`
                <div class="cand-card" data-ja="${ja.Id_JA}" data-nom="${escHtml(ja.Nom)}" data-prenom="${escHtml(ja.Prenom)}" style="${cardBorder}">
                    <div class="cand-header" style="${headerStyle}">
                        <span class="cand-rang" style="${rangStyle}">${idx + 1}</span>
                        <span class="cand-nom">${escHtml(ja.Prenom)} ${escHtml(ja.Nom)}</span>
                        <span class="cand-nationale">${ja.Nationale == 1 ? 'Nationale : <b>Oui</b>' : 'Nationale : Non'}</span>
                        ${noteBtn}
                        ${ja.Grade ? `<span class="cand-grade">${escHtml(ja.Grade)}</span>` : ''}
                    </div>
                    <div class="cand-body" style="${bodyBg}">
                        <div class="cand-loc">
                            ${loc ? `<i class="bi bi-geo-alt me-1" style="font-size:.75rem"></i>${escHtml(loc)}` : ''}
                            ${distTxt ? `<span class="ms-2 text-success fw-semibold" style="font-size:.75rem"><i class="bi bi-car-front me-1"></i>${distTxt}</span>` : ''}
                        </div>
                        <div class="cand-stats">${nomMin} cette saison</div>
                    </div>
                    <div style="padding:.35rem .7rem .5rem; display:flex; align-items:center; justify-content:space-between;${bodyBg}">
                        <div class="cand-badges">${badges}</div>
                        ${actionBtn}
                    </div>
                </div>
            `);
            $('#liste-candidats').append(card);
        });
    }

    $('#liste-candidats').off('click', '.btn-affecter').on('click', '.btn-affecter', function () {
        const idJa   = parseInt($(this).closest('.cand-card').data('ja'));
        const nom    = $(this).closest('.cand-card').data('nom');
        const prenom = $(this).closest('.cand-card').data('prenom');
        affecterJa(idRenc, idJa, nom, prenom);
    });

    $('#liste-candidats').off('click', '.btn-retirer').on('click', '.btn-retirer', function () {
        retirerJa(idRenc);
    });
}

function viderCandidats() {
    $('#liste-candidats').empty();
    $('#placeholder-candid').show();
    $('#renc-sel-titre').text('');
    rencSelectionnee = null;
}

// ── Affecter / Retirer ────────────────────────────────────────────────────────
function affecterJa(idRenc, idJa, nom, prenom) {
    ajax('affecter-ja', {
        method: 'POST',
        data:   { id_rencontre: idRenc, id_ja: idJa }
    }).done(function (r) {
        if (!r.ok) { nijacToast('Erreur : ' + r.err, 'danger'); return; }
        nominations[idRenc] = { Id_JA: idJa, Nom: nom, Prenom: prenom };

        // Affectations automatiques dans la même salle
        const auto = r.autoAffectes || [];
        auto.forEach(function (idAuto) {
            nominations[idAuto] = { Id_JA: idJa, Nom: nom, Prenom: prenom };
        });
        if (auto.length > 0) {
            nijacToast(`Affectation automatique : ${escHtml(prenom)} ${escHtml(nom)} a également été affecté(e) à ${auto.length} autre(s) rencontre(s) dans la même salle.`, 'info', 6000);
        }

        renderRencontres();
        mettreAJourBoutons();
        mettreAJourInfoJournee();
        // Passer à la rencontre suivante non attribuée
        const nonAttr = rencontres.find(rc => !nominations[rc.Id_Rencontre]);
        if (nonAttr) selectionnerRencontre(nonAttr.Id_Rencontre);
        else         afficherCandidatsPourRencontre(idRenc);
    });
}

function retirerJa(idRenc) {
    ajax('retirer-ja', {
        method: 'POST',
        data:   { id_rencontre: idRenc }
    }).done(function (r) {
        if (!r.ok) { nijacToast('Erreur : ' + r.err, 'danger'); return; }
        delete nominations[idRenc];
        renderRencontres();
        mettreAJourBoutons();
        mettreAJourInfoJournee();
        afficherCandidatsPourRencontre(idRenc);
    });
}

// ── Mise à jour UI ────────────────────────────────────────────────────────────
function mettreAJourBoutons() {
    const total    = rencontres.length;
    const attrib   = Object.keys(nominations).length;
    const toutFait = (total > 0 && attrib === total);

    // Valider visible quand tout est attribué
    $('#btn-valider').toggle(toutFait);
    // Récap visible dès qu'il y a au moins une attribution
    $('#btn-recap').toggle(attrib > 0);
    // Envoyer visible si journée validée (on vérifie une nomination Valide)
    $('#btn-envoyer').hide(); // rafraîchi après validation

    if (toutFait) {
        $('#msg-actions').text('Toutes les rencontres sont attribuées — vous pouvez valider.');
    } else if (attrib > 0) {
        $('#msg-actions').text(`${attrib} / ${total} rencontre${attrib > 1 ? 's' : ''} attribuée${attrib > 1 ? 's' : ''}.`);
    } else {
        $('#msg-actions').text('');
    }
}

function mettreAJourInfoJournee() {
    if (!journeeCourante) { $('#info-journee').hide(); return; }
    const total  = rencontres.length;
    const attrib = Object.keys(nominations).length;
    $('#info-nb-renc').html(`<strong>${total}</strong> rencontre${total > 1 ? 's' : ''}`);
    $('#info-nb-attribues').html(`<strong>${attrib}/${total}</strong> attribué${attrib > 1 ? 's' : ''}`);
    $('#info-journee').css('display', 'flex');
}

// ── Récapitulatif ─────────────────────────────────────────────────────────────
function afficherRecap() {
    const $body = $('#recapBody').empty();
    rencontres.forEach(rc => {
        const nom = nominations[rc.Id_Rencontre];
        $body.append(`
            <div class="recap-row">
                <span class="recap-div">${escHtml(rc.DivisionCode || '')}</span>
                <span class="recap-equipes">${escHtml(rc.NomDom)} vs ${escHtml(rc.NomExt || '?')}</span>
                ${nom
                    ? `<span class="recap-ja"><i class="bi bi-person-check me-1"></i>${escHtml((nom.Prenom + ' ' + nom.Nom).trim())}</span>`
                    : `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Non attribué</span>`
                }
            </div>
        `);
    });
    new bootstrap.Modal('#modalRecap').show();
}

// ── Validation ────────────────────────────────────────────────────────────────
function validerNominations() {
    bootstrap.Modal.getInstance('#modalRecap')?.hide();
    ajax('valider', {
        method: 'POST',
        data: {
            journee: journeeCourante.Journee,
            date:    journeeCourante.Date
        }
    }).done(function (r) {
        if (!r.ok) { nijacToast('Erreur : ' + r.err, 'danger'); return; }
        $('#btn-valider').hide();
        $('#btn-envoyer').show();
        $('#msg-actions').html('<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Nominations validées — vous pouvez envoyer les convocations.</span>');
    });
}

// ── Envoi des convocations ────────────────────────────────────────────────────
function envoyerConvocations() {
    nijacConfirm('Envoyer les convocations par e-mail à tous les JA nominés ?', function () {
        ajax('envoyer-convocations', {
            method: 'POST',
            data: {
                journee: journeeCourante.Journee,
                date:    journeeCourante.Date
            }
        }).done(function (r) {
            if (!r.ok) { nijacToast('Erreur : ' + r.err, 'danger'); return; }
            // Afficher la modale avec les liens
            const $body = $('#liensBody').empty();
            (r.liens || []).forEach(l => {
                $body.append(`
                    <div class="lien-row">
                        <div class="lien-nom"><i class="bi bi-person-fill me-1 text-success"></i>${escHtml(l.nom)} — ${escHtml(l.rencontre)}</div>
                        ${l.email ? `<div class="text-muted" style="font-size:.75rem"><i class="bi bi-envelope me-1"></i>${escHtml(l.email)}</div>` : '<div class="text-warning" style="font-size:.75rem"><i class="bi bi-exclamation-triangle me-1"></i>Pas d\'email</div>'}
                        <div class="lien-url"><a href="${escHtml(l.lien)}" target="_blank">${escHtml(l.lien)}</a></div>
                    </div>
                `);
            });
            const envoyes = r.envoyes || 0;
            const erreurs = (r.erreurs || []).length;
            $('#msg-envoi-resume').html(
                `<i class="bi bi-check-circle-fill text-success me-1"></i><strong>${envoyes}</strong> email${envoyes > 1 ? 's' : ''} envoyé${envoyes > 1 ? 's' : ''}.` +
                (erreurs > 0 ? ` <span class="text-danger">${erreurs} échec${erreurs > 1 ? 's' : ''}.</span>` : '')
            );
            new bootstrap.Modal('#modalLiens').show();
            if (envoyes > 0 || (r.liens || []).length > 0) $('#btn-envoyer').hide();
        });
    }, null, { type: 'question', title: 'Envoi des convocations', confirmLabel: 'Envoyer' });
}

// ── Note JA (lecture seule) ───────────────────────────────────────────────────
$(document).on('click', '.btn-note-ja-trigger', function (e) {
    e.stopPropagation();
    const note = $(this).data('note');
    const nom  = $(this).data('nom');
    $('#note-ja-nom').text(nom);
    $('#note-ja-texte').text(note);
    new bootstrap.Modal('#modalNoteJa').show();
});

// ── Utilitaires ───────────────────────────────────────────────────────────────
function haversineKm(lat1, lon1, lat2, lon2) {
    if (lat1 == null || lon1 == null || lat2 == null || lon2 == null) return null;
    lat1 = parseFloat(lat1); lon1 = parseFloat(lon1);
    lat2 = parseFloat(lat2); lon2 = parseFloat(lon2);
    if (isNaN(lat1) || isNaN(lon1) || isNaN(lat2) || isNaN(lon2)) return null;
    const R = 6371, rad = Math.PI / 180;
    const dLat = (lat2 - lat1) * rad, dLon = (lon2 - lon1) * rad;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2;
    return Math.round(R * 2 * Math.asin(Math.sqrt(Math.min(1, a))));
}

function escHtml(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
<script src="/asset/js/nijac-toast.js"></script>
</body>
</html>
