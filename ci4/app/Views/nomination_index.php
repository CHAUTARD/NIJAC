<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>NIJAC – Nomination JA (EN14)</title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
<style>
:root { --nom-green: #2e7d32; }

body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; height:100vh; display:flex; flex-direction:column; overflow:hidden; }

/* ── En-tête ── */
#page-header { background:var(--nom-green); color:#fff; padding:.5rem 1.25rem; font-size:.9rem; font-weight:600; display:flex; align-items:center; gap:.75rem; flex-shrink:0; }

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
#main-content { display:flex; flex:1; min-height:0; overflow:hidden; }

/* ── Colonne gauche — rencontres ── */
#col-rencontres { width:42%; min-width:300px; border-right:2px solid #dee2e6; overflow-y:auto; display:flex; flex-direction:column; }
#col-rencontres .col-titre { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:.55rem .85rem; font-size:.82rem; font-weight:700; color:#444; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:5; }

.renc-item { padding:.55rem .85rem; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background .12s; display:flex; align-items:center; gap:.5rem; }
.renc-item:nth-child(odd) { background:#e3f4e9; }
/* Survol : s'applique à toutes les lignes, y compris attribuée / sélectionnée */
#liste-rencontres .renc-item:hover { background:#d3ecdd; }
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
.renc-item .renc-check { flex-shrink:0; width:16px; height:16px; cursor:pointer; }
.renc-item .renc-check-placeholder { flex-shrink:0; width:16px; display:inline-block; }
.renc-envoi-badge { font-size:.65rem; font-weight:700; padding:.1rem .4rem; border-radius:10px; white-space:nowrap; display:inline-block; }
.renc-envoi-badge.envoye { background:#e8f5e9; color:#2e7d32; }
.renc-envoi-badge.a-envoyer { background:#fff3e0; color:#e65100; }

/* ── Colonne droite — candidats JA ── */
#col-candidats { flex:1; display:flex; flex-direction:column; overflow:hidden; }
#liste-candidats { flex:1; overflow-y:auto; }
#col-candidats .col-titre { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:.55rem .85rem; font-size:.82rem; font-weight:700; color:#444; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:5; }

#placeholder-candid { display:flex; flex-direction:column; align-items:center; justify-content:center; height:200px; color:#aaa; font-size:.9rem; gap:.5rem; }

/* ── Panneau « arbitrage club » (message n°7) ── */
#panel-arbitre-club { flex:1; overflow-y:auto; padding:.85rem; display:flex; flex-direction:column; }
#panel-arbitre-club #ac-message { flex:1; min-height:180px; resize:vertical; font-family:'Consolas','Courier New',monospace; }
#ac-marqueurs code { display:inline-block; background:#eef4fc; border:1px solid #b8d0ee; border-radius:4px; padding:.05rem .4rem; margin:.1rem .15rem 0 0; font-size:.7rem; color:#1a3a6b; cursor:pointer; }
#ac-marqueurs code:hover { background:#cfe0f8; }
.renc-arbclub { margin-top:.25rem; font-size:.7rem; font-weight:700; color:#e65100; }

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

/* ── Pied de page ── */
#page-footer { background:#e8eef7; border-top:1px solid #c8d4e8; padding:.25rem 1rem; font-size:.8rem; display:flex; justify-content:center; align-items:center; flex-shrink:0; }
#status-bar { color:#374151; min-height:18px; }
.footer-copyright { color:#6b7280; white-space:nowrap; }
.footer-logo { height:20px; width:auto; opacity:.75; }
#page-footer.pf-status-left { display:grid; grid-template-columns:1fr auto 1fr; align-items:center; }
#page-footer.pf-status-left #status-bar { grid-column:1; justify-self:start; text-align:left; }
#page-footer.pf-status-left .footer-copyright { grid-column:2; justify-self:center; }
</style>
<link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'person-check-fill', 'phTitle' => 'Nomination des Juges-Arbitres', 'phCode' => 'EN14',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar : recopié de Nominateur/includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Barre de sélection -->
<div id="barre-selection">
    <label for="sel-journee"><i class="bi bi-calendar-event me-1"></i>Journée</label>
    <select id="sel-journee" class="form-select form-select-sm w-auto" disabled>
        <option value="">— chargement —</option>
    </select>
    <div id="spinner-barre" class="spinner-border spinner-sm text-secondary ms-2" role="status" style="display:none"><span class="visually-hidden">Chargement…</span></div>
    <a href="<?= site_url('stats-nomination') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm ms-auto">
        <i class="bi bi-bar-chart-line me-1"></i>Statistiques
    </a>
</div>

<!-- Info journée -->
<div id="info-journee" style="display:none">
    <i class="bi bi-info-circle-fill"></i>
    <span id="info-nb-renc"></span>
    <span class="text-muted">|</span>
    <span id="info-nb-attribues"></span>
    <button id="btn-recap" class="btn btn-outline-secondary btn-sm ms-auto" style="display:none">
        <i class="bi bi-list-check me-1"></i>Récapitulatif
    </button>
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
        <!-- Filtre des candidats d'un autre département (voir majFiltreHorsDept()) -->
        <div id="hors-dept-box" style="display:none;align-items:center;flex-wrap:wrap;gap:.4rem;padding:.4rem .85rem;background:#fbfbfd;border-bottom:1px solid #eee;font-size:.75rem;">
            <span style="font-weight:700;color:#555;white-space:nowrap;"><i class="bi bi-signpost-2 me-1"></i>Autres dépts</span>
            <span id="hd-checks" style="display:flex;flex-wrap:wrap;gap:.5rem;"></span>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="btn-hd-tous" style="font-size:.72rem;">Tout cocher</button>
            <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="btn-hd-inv" style="font-size:.72rem;">Inverser</button>
        </div>
        <div id="placeholder-candid">
            <i class="bi bi-arrow-left-circle fs-2"></i>
            <span>Sélectionnez une rencontre</span>
        </div>
        <div id="liste-candidats"></div>

        <!-- Rencontre en arbitrage club : éditeur du message n°7 (comme EN15) + envoi -->
        <div id="panel-arbitre-club" style="display:none;">
            <div class="small text-muted mb-2">
                <i class="bi bi-info-circle me-1"></i>Rencontre en <strong>arbitrage club</strong> : envoyez au correspondant la demande de désignation du juge-arbitre (message&nbsp;n°7). Le lien renvoie vers la page publique EN25.
            </div>
            <label class="form-label small mb-1" for="ac-sujet">Sujet</label>
            <input type="text" id="ac-sujet" class="form-control form-control-sm mb-2" maxlength="150">
            <label class="form-label small mb-1" for="ac-message">Message</label>
            <textarea id="ac-message" class="form-control form-control-sm mb-1" rows="11" style="font-size:.78rem;"></textarea>
            <div id="ac-marqueurs" class="mb-2">
                <?php foreach (['{CORR_NOM}','{DOM}','{EXT}','{DATE}','{HEURE}','{DIVISION}','{JOURNEE}','{POULE}','{SALLE_NOM}','{SALLE_CP}','{SALLE_VILLE}','{URL_ARBITRE_CLUB}','{UTI_PRENOM}','{UTI_NOM}'] as $mk): ?>
                <code data-m="<?= $mk ?>"><?= $mk ?></code>
                <?php endforeach; ?>
            </div>
            <button id="ac-envoyer" class="btn btn-warning btn-sm w-100"><i class="bi bi-envelope-fill me-1"></i>Envoyer la demande au club</button>
            <div id="ac-status" class="small mt-2"></div>
        </div>
    </div>

</div>

<!-- Barre d'actions -->
<div id="barre-actions">
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

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const NOM_BASE = '<?= site_url('nomination') ?>';
const DEPT_NOMS = <?= json_encode($deptNoms ?? [], JSON_UNESCAPED_UNICODE) ?>;
const MAX_CANDIDATS = <?= (int) ($nbCandidats ?? 15) ?> || 15;  // EA91 → nomination_nb_candidats

// ── État ─────────────────────────────────────────────────────────────────────
let journeeCourante = null;  // {Journee, Date}
let rencontres      = [];    // tableau rencontres de la journée (avec VenueLat/VenueLon)
let jaList          = [];    // tous les JA disponibles pour la journée (chargé une fois)
let nominations     = {};    // {Id_Rencontre: {Id_JA, Nom, Prenom}} — état local
let selectionEnvoi  = new Set(); // Id_Rencontre cochés pour l'envoi de convocation

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
            // Présélection : convocations déjà validées et pas encore envoyées
            selectionEnvoi = new Set(
                rencontres.filter(rc => rc.Valide == 1 && rc.EmailEnvoye != 1).map(rc => rc.Id_Rencontre)
            );
            if (j.ok) jaList = j.data;
            majFiltreHorsDept();
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
        const valide   = rc.Valide == 1;
        const envoye   = rc.EmailEnvoye == 1;
        const checkbox = valide
            ? `<input type="checkbox" class="renc-check" data-id="${rc.Id_Rencontre}" ${selectionEnvoi.has(rc.Id_Rencontre) ? 'checked' : ''} title="Sélectionner pour l'envoi de la convocation">`
            : '<span class="renc-check-placeholder"></span>';
        const envoiBadge = valide
            ? (envoye
                ? '<span class="ms-2 renc-envoi-badge envoye"><i class="bi bi-check-circle-fill me-1"></i>Envoyée</span>'
                : '<span class="ms-2 renc-envoi-badge a-envoyer"><i class="bi bi-envelope-exclamation-fill me-1"></i>À envoyer</span>')
            : '';
        // Arbitrage club (R3M/R4M, club recevant en SouhaitJA='Club', pas encore de JA) :
        // la demande au correspondant se fait dans le panneau de droite.
        const arbClub  = !attr && rc.SouhaitJADom === 'Club' && ['R3M', 'R4M'].includes(rc.DivisionCode || '');
        const btnAsk   = arbClub
            ? '<div class="renc-arbclub"><i class="bi bi-envelope me-1"></i>Arbitrage club — demande à envoyer</div>'
            : '';
        $liste.append(`
            <div class="renc-item ${attr ? 'attribue' : ''}" data-id="${rc.Id_Rencontre}">
                ${checkbox}
                <span class="renc-div" style="background:${escHtml(divColor)}">${escHtml(rc.DivisionCode || '')}</span>
                <div class="renc-corps">
                    <div class="renc-equipes">${escHtml(rc.NomDom)} vs ${escHtml(rc.NomExt || '?')}</div>
                    <div class="renc-lieu">
                        ${heure ? `<i class="bi bi-clock" style="font-size:.68rem"></i> ${heure}` : ''}
                        ${poule ? `<span class="ms-2">${poule}</span>` : ''}
                        ${lieu  ? `<span class="ms-2"><i class="bi bi-geo-alt" style="font-size:.68rem"></i> ${lieu}</span>` : ''}
                        ${envoiBadge}
                    </div>
                    ${attr ? `<div class="renc-ja"><i class="bi bi-person-check me-1"></i>${escHtml(nomJa)}</div>` : ''}
                    ${btnAsk}
                </div>
                <i class="bi ${attr ? 'bi-person-check-fill' : 'bi-person-dash'} renc-ico"></i>
            </div>
        `);
    });

    $('#liste-rencontres').off('click', '.renc-item').on('click', '.renc-item', function () {
        selectionnerRencontre(parseInt($(this).data('id')));
    });

    $('#liste-rencontres').off('click', '.renc-check').on('click', '.renc-check', function (e) {
        e.stopPropagation();
        const id = parseInt($(this).data('id'));
        if (this.checked) selectionEnvoi.add(id); else selectionEnvoi.delete(id);
        mettreAJourBoutons();
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

function deptDeJa(ja) {
    return (ja.Cp && ja.Cp.length >= 2) ? ja.Cp.substring(0, 2) : String(ja.CodeDept || '');
}

// Construit les cases « autre dépt » à partir des candidats hors périmètre chargés
// (jaList). « Tout cocher » / « Inverser » masqués s'il n'y a qu'un seul département.
function majFiltreHorsDept() {
    const depts = [...new Set(jaList.filter(j => j.HorsDept == 1).map(deptDeJa))]
        .filter(Boolean).sort();
    const $box = $('#hd-checks').empty();
    if (!depts.length) { $('#hors-dept-box').hide(); return; }
    depts.forEach(code => {
        const nom = DEPT_NOMS[code] || '';
        $box.append(
            `<label style="cursor:pointer;display:inline-flex;align-items:center;gap:.25rem;">
                <input type="checkbox" class="hd-chk form-check-input mt-0" value="${escHtml(code)}">
                ${escHtml(code)}${nom ? ' ' + escHtml(nom) : ''}
            </label>`
        );
    });
    $('#btn-hd-tous, #btn-hd-inv').toggle(depts.length > 1);
    $('#hors-dept-box').css('display', 'flex');
}

function rafraichirCandidats() {
    if (rencSelectionnee != null) afficherCandidatsPourRencontre(rencSelectionnee);
}
$('#hd-checks').on('change', '.hd-chk', rafraichirCandidats);
$('#btn-hd-tous').on('click', function () { $('.hd-chk').prop('checked', true); rafraichirCandidats(); });
$('#btn-hd-inv').on('click', function () { $('.hd-chk').each(function () { this.checked = !this.checked; }); rafraichirCandidats(); });

// ── Candidats JA pour une rencontre — filtrage et tri côté client ─────────────
function afficherCandidatsPourRencontre(idRenc) {
    const rc = rencontres.find(r => r.Id_Rencontre == idRenc);
    $('#placeholder-candid').hide();

    // Rencontre en arbitrage club sans JA : panneau « message n°7 » à droite.
    const arbClub = rc && !nominations[idRenc]
        && rc.SouhaitJADom === 'Club' && ['R3M', 'R4M'].includes(rc.DivisionCode || '');
    if (arbClub) {
        $('#liste-candidats').hide().empty();
        chargerPanelArbitreClub(idRenc);
        return;
    }
    $('#panel-arbitre-club').hide();
    $('#liste-candidats').show();

    if (!rc || !jaList.length) {
        $('#liste-candidats').html('<div class="text-center text-muted py-4" style="font-size:.85rem"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA disponible pour cette journée</div>');
        return;
    }

    const venueLat = rc.VenueLat != null ? parseFloat(rc.VenueLat) : null;
    const venueLon = rc.VenueLon != null ? parseFloat(rc.VenueLon) : null;
    const clubDom  = rc.IdClubDom;
    const divNat   = (rc.DivisionCode || '').startsWith('N');
    // R3M/R4M : un JA peut arbitrer une rencontre de son propre club
    // (aucune limite du nombre d'arbitrages d'un JA pour un club).
    const divArbClub = ['R3M', 'R4M'].includes(rc.DivisionCode || '');

    const candidats = [];
    jaList.forEach(ja => {
        const dispoJournee = ja.DispoJournee == 1;
        const dispoRencs   = ja.DispoRencontres
            ? ja.DispoRencontres.split(',').map(Number)
            : [];
        const prefereRenc  = dispoRencs.includes(idRenc);
        if (!dispoJournee && !prefereRenc) return;

        // JA d'un autre département (accepte via EN22) : affiché seulement si la
        // case de SON département est cochée dans le filtre « Autres dépts ».
        if (ja.HorsDept == 1) {
            const coches = $('.hd-chk:checked').map(function () { return this.value; }).get();
            if (!coches.includes(deptDeJa(ja))) return;
        }

        const sonClub = !!(ja.Id_Club && ja.Id_Club === clubDom);
        if (sonClub && !divArbClub) return;

        // Max 2 nominations par JA sur la journée : on masque le JA seulement
        // s'il est déjà nominé sur 2 autres rencontres du jour.
        const nbCeJour = Object.entries(nominations).filter(
            ([rid, nom]) => parseInt(rid) !== idRenc && nom.Id_JA == ja.Id_JA
        ).length;
        if (nbCeJour >= 2) return;

        const dist  = haversineKm(ja.JaLat, ja.JaLon, venueLat, venueLon);
        const score = (prefereRenc ? 300 : 0)
            + (dispoJournee ? 100 : 50)
            + (dist != null && dist <= 20 ? 50 : 0)
            + (divNat && ja.Nationale == 1 ? 200 : 0);

        candidats.push({
            ...ja,
            DistanceKm:    dist,
            PrefereRenc:   prefereRenc ? 1 : 0,
            SonClub:       sonClub ? 1 : 0,
            Disponibilite: dispoJournee ? 'O' : 'P',
            Score:         score
        });
    });

    // Tri principal par kilométrage (JA le plus proche en tête, distance
    // inconnue en dernier), puis score / nb d'arbitrages / nom en départage.
    candidats.sort((a, b) => {
        const da = a.DistanceKm == null ? Infinity : a.DistanceKm;
        const db = b.DistanceKm == null ? Infinity : b.DistanceKm;
        return da - db
            || b.Score - a.Score
            || a.NbNominations - b.NbNominations
            || a.Nom.localeCompare(b.Nom);
    });

    const jaAffecteId = nominations[idRenc] ? nominations[idRenc].Id_JA : null;

    // JA affecté toujours en tête, indépendamment du score
    if (jaAffecteId != null) {
        const idx = candidats.findIndex(c => c.Id_JA == jaAffecteId);
        if (idx > 0) candidats.unshift(candidats.splice(idx, 1)[0]);
    }

    const top5 = candidats.slice(0, MAX_CANDIDATS);
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
            if (ja.HorsDept == 1) badges += '<span class="badge rounded-pill me-1" style="background:#6c757d;color:#fff;"><i class="bi bi-signpost-2 me-1"></i>Autre dépt</span>';
            if (ja.PrefereRenc == 1) badges += '<span class="badge badge-pref rounded-pill me-1"><i class="bi bi-star-fill me-1"></i>Choix JA</span>';
            if (ja.SonClub == 1) badges += '<span class="badge rounded-pill me-1" style="background:#8e24aa;color:#fff;"><i class="bi bi-house-fill me-1"></i>Son club</span>';
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
        retirerJaAvecConfirmation(idRenc);
    });
}

// ── Panneau arbitrage club : éditeur du message n°7 + envoi ──────────────────
function chargerPanelArbitreClub(idRenc) {
    $('#panel-arbitre-club').show();
    $('#ac-status').text('').removeClass('text-danger text-success');
    $('#ac-envoyer').prop('disabled', true).data('id', idRenc);
    $('#ac-sujet, #ac-message').prop('disabled', true).val('Chargement…');

    $.get('<?= site_url('nomination/message-arbitre-club') ?>', { id_rencontre: idRenc }, function (res) {
        if (!res.ok) { $('#ac-status').addClass('text-danger').text(res.err || 'Erreur.'); return; }
        $('#ac-sujet').val(res.sujet || '').prop('disabled', false);
        $('#ac-message').val(res.message || '').prop('disabled', false);
        if (!res.corr_email) {
            $('#ac-status').addClass('text-danger').text('Aucun email de correspondant pour ce club (à compléter en EN27).');
        } else {
            $('#ac-envoyer').prop('disabled', false);
            $('#ac-status').text('Destinataire : ' + (res.corr_nom || res.corr_email));
        }
    }, 'json').fail(() => $('#ac-status').addClass('text-danger').text('Erreur réseau.'));
}

$('#ac-marqueurs').on('click', 'code', function () {
    const el = document.getElementById('ac-message');
    if (el.disabled) return;
    const s = el.selectionStart, e = el.selectionEnd, m = $(this).data('m');
    el.value = el.value.slice(0, s) + m + el.value.slice(e);
    el.focus();
    el.selectionStart = el.selectionEnd = s + m.length;
});

$('#ac-envoyer').on('click', function () {
    const id = $(this).data('id');
    const $b = $(this).prop('disabled', true);
    $('#ac-status').removeClass('text-danger text-success').text('Envoi en cours…');
    $.post('<?= site_url('nomination/demander-ja-club') ?>', {
        id_rencontre: id,
        sujet:        $('#ac-sujet').val(),
        message:      $('#ac-message').val(),
    }, function (res) {
        if (res.ok) {
            $('#ac-status').addClass('text-success').text(res.msg);
            toast(res.msg);
        } else {
            $('#ac-status').addClass('text-danger').text(res.err || res.msg || 'Erreur.');
            $b.prop('disabled', false);
        }
    }, 'json').fail(() => { $('#ac-status').addClass('text-danger').text('Erreur réseau.'); $b.prop('disabled', false); });
});

function viderCandidats() {
    $('#liste-candidats').show().empty();
    $('#panel-arbitre-club').hide();
    $('#hors-dept-box').hide();
    $('#placeholder-candid').show();
    $('#renc-sel-titre').text('');
    rencSelectionnee = null;
}

// ── Affecter / Retirer ────────────────────────────────────────────────────────
// Affecter/retirer un JA réinitialise Valide/EmailEnvoye côté serveur
// (affecterNomination()/DELETE) — répercuté ici pour garder le badge et la
// case à cocher de chaque rencontre cohérents sans recharger toute la page.
function reinitialiserValidation(idRenc) {
    const rc = rencontres.find(r => r.Id_Rencontre === idRenc);
    if (rc) { rc.Valide = 0; rc.EmailEnvoye = 0; }
    selectionEnvoi.delete(idRenc);
}

function affecterJa(idRenc, idJa, nom, prenom) {
    ajax('affecter-ja', {
        method: 'POST',
        data:   { id_rencontre: idRenc, id_ja: idJa }
    }).done(function (r) {
        if (!r.ok) { nijacToast('Erreur : ' + r.err, 'danger'); return; }
        nominations[idRenc] = { Id_JA: idJa, Nom: nom, Prenom: prenom };
        reinitialiserValidation(idRenc);

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
        reinitialiserValidation(idRenc);
        renderRencontres();
        mettreAJourBoutons();
        mettreAJourInfoJournee();
        afficherCandidatsPourRencontre(idRenc);
    });
}

function retirerJaAvecConfirmation(idRenc) {
    const rc  = rencontres.find(r => r.Id_Rencontre == idRenc);
    const nom = nominations[idRenc];
    if (!rc || !nom) return;
    const libelle = `${rc.NomDom} vs ${rc.NomExt || '?'}`;
    const jaNom   = `${nom.Prenom} ${nom.Nom}`.trim();
    nijacConfirm(`Retirer la nomination de ${jaNom} pour ${libelle} ?`, function () {
        retirerJa(idRenc);
        if (bootstrap.Modal.getInstance('#modalRecap')) afficherRecap();
    }, null, { type: 'danger', title: 'Retirer la nomination', confirmLabel: 'Retirer' });
}

$(document).on('click', '.recap-btn-retirer', function (e) {
    e.stopPropagation();
    retirerJaAvecConfirmation(parseInt($(this).data('renc')));
});

// ── Mise à jour UI ────────────────────────────────────────────────────────────
function mettreAJourBoutons() {
    const total    = rencontres.length;
    const attrib   = Object.keys(nominations).length;
    const toutFait = (total > 0 && attrib === total);
    const validees = rencontres.filter(rc => rc.Valide == 1).length;

    // Valider visible quand tout est attribué (y compris pour re-valider après une modification)
    $('#btn-valider').toggle(toutFait);
    // Récap visible dès qu'il y a au moins une attribution
    $('#btn-recap').toggle(attrib > 0);
    // Envoyer visible dès qu'au moins une nomination est validée — persiste au
    // rechargement de la page, pas seulement juste après avoir cliqué Valider
    $('#btn-envoyer').toggle(validees > 0).prop('disabled', selectionEnvoi.size === 0);

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
                    ? `<span class="recap-ja"><i class="bi bi-person-check me-1"></i>${escHtml((nom.Prenom + ' ' + nom.Nom).trim())}</span>
                       <button class="btn btn-sm btn-outline-danger recap-btn-retirer ms-auto" data-renc="${rc.Id_Rencontre}" title="Retirer cette nomination"><i class="bi bi-trash"></i></button>`
                    : `<span class="text-danger ms-auto"><i class="bi bi-x-circle me-1"></i>Non attribué</span>`
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
        rencontres.forEach(rc => {
            if (nominations[rc.Id_Rencontre]) {
                rc.Valide      = 1;
                rc.EmailEnvoye = 0;
                selectionEnvoi.add(rc.Id_Rencontre);
            }
        });
        renderRencontres();
        mettreAJourBoutons();
        $('#msg-actions').html('<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Nominations validées — sélectionnez les convocations à envoyer ci-contre.</span>');
    });
}

// ── Envoi des convocations ────────────────────────────────────────────────────
function envoyerConvocations() {
    if (selectionEnvoi.size === 0) {
        nijacToast('Sélectionnez au moins une convocation à envoyer.', 'warning');
        return;
    }
    const ids = [...selectionEnvoi];
    nijacConfirm(`Envoyer ${ids.length} convocation${ids.length > 1 ? 's' : ''} par e-mail ?`, function () {
        ajax('envoyer-convocations', {
            method: 'POST',
            data: {
                journee: journeeCourante.Journee,
                date:    journeeCourante.Date,
                ids:     JSON.stringify(ids)
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
            // Recharger depuis le serveur pour refléter le statut d'envoi réel
            // (un échec d'email individuel ne marque pas EmailEnvoye côté serveur)
            chargerRencontres();
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
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
