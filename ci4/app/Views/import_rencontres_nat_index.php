<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Rencontres Nationales (E017)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        #toolbar .ts-pwd-warning:hover { color: #900; }

        .badge-div { display:inline-block; font-size:.75rem; font-weight:700; padding:.15rem .45rem; border-radius:4px; white-space:nowrap; }
        .bdiv-N1M { background:#1a3a6b; color:#fff; }
        .bdiv-N2M { background:#2563eb; color:#fff; }
        .bdiv-N3M { background:#60a5fa; color:#1e3a5f; }
        .bdiv-N1F,.bdiv-N1D { background:#7c3aed; color:#fff; }
        .bdiv-N2F,.bdiv-N2D { background:#a78bfa; color:#3b1264; }
        #tbl-assoc { width:100%; border-collapse:collapse; font-size:.84rem; }
        #tbl-assoc thead th { background:#e8eef7; border:1px solid #c8d4e8; padding:.3rem .5rem; position:sticky; top:0; z-index:1; white-space:nowrap; }
        #tbl-assoc tbody tr { border-bottom:1px solid #e8eef7; }
        #tbl-assoc tbody tr:hover { background:#f0f6ff; }
        #tbl-assoc tbody td { padding:.3rem .5rem; vertical-align:middle; }
        tr.assoc-ok td { background:#f0fdf4 !important; }
        tr.assoc-ok:hover td { background:#dcfce7 !important; }
        .club-search-wrap { position:relative; display:flex; gap:.35rem; align-items:center; }
        .club-display { flex:1; padding:.2rem .5rem; border:1px solid #c8d4e8; border-radius:4px; background:#f8faff; font-size:.83rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; color:#374151; max-width:280px; }
        .club-display.empty { color:#9ca3af; font-style:italic; }
        .club-popup { position:absolute; top:100%; left:0; z-index:999; background:#fff; border:1px solid #c8d4e8; border-radius:6px; box-shadow:0 6px 20px rgba(0,0,0,.12); min-width:280px; max-width:400px; max-height:260px; overflow-y:auto; display:none; }
        .club-popup.open { display:block; }
        .club-popup-input { width:100%; padding:.35rem .5rem; border:none; border-bottom:1px solid #e0e8f0; font-size:.83rem; outline:none; }
        .club-result { padding:.3rem .6rem; cursor:pointer; font-size:.82rem; border-bottom:1px solid #f0f4fa; }
        .club-result:hover { background:#eef3fb; }
        .stat-card { display:inline-block; text-align:center; min-width:100px; background:#fff; border:2px solid #d0d8e8; border-radius:8px; padding:.4rem .7rem; margin-right:.5rem; margin-bottom:.5rem; }
        .stat-card .sv { font-size:1.5rem; font-weight:700; color:var(--nijac-blue); }
        .stat-card .sl { font-size:.72rem; color:#6b7280; }
        .log-detail { font-size:.75rem; padding:.3rem .5rem .3rem 1.5rem; color:#374151; background:#f9fafb; border-left:3px solid #e5e7eb; }
        #spinner { display:none; position:fixed; inset:0; background:rgba(255,255,255,.5); z-index:9999; align-items:center; justify-content:center; }
        #spinner.show { display:flex; }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'trophy-fill', 'phTitle' => 'Rencontres Nationales', 'phCode' => 'E017',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="spinner"><div class="spinner-border text-primary" style="width:3rem;height:3rem;"></div></div>

<div class="container-fluid p-3">

    <!-- Récapitulatif equipe_nationale par département -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-bar-chart-fill me-2"></i>Équipes nationales enregistrées par département</div>
        <div class="card-body py-2">
            <?php
            $totalRecap = array_sum($recapDepts);
            if ($totalRecap === 0): ?>
                <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Aucune équipe nationale enregistrée. Lancez le scan ci-dessous.</span>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php foreach ($deptsNorm as $code):
                    $code = (string) $code;
                    $nb   = $recapDepts[$code] ?? 0;
                    $nom  = $allDepts[$code] ?? $code;
                    $cls  = $nb > 0 ? 'border-success text-success' : 'border-secondary text-muted';
                ?>
                    <div class="stat-card <?= $cls ?>" style="min-width:110px; border-color:inherit;">
                        <div class="sv" style="font-size:1.4rem;"><?= $nb ?></div>
                        <div class="sl"><?= esc($nom) ?> (<?= esc($code) ?>)</div>
                    </div>
                <?php endforeach; ?>
                    <div class="stat-card" style="min-width:90px; border-color:#1a3a6b; color:#1a3a6b;">
                        <div class="sv" style="font-size:1.4rem;"><?= $totalRecap ?></div>
                        <div class="sl">Total région</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs" id="nat-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-excel-btn" data-bs-toggle="tab" data-bs-target="#tab-excel" type="button" role="tab">
                <i class="bi bi-file-earmark-excel me-1"></i>Importation Excel
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-api-btn" data-bs-toggle="tab" data-bs-target="#tab-api" type="button" role="tab">
                <i class="bi bi-cloud-download me-1"></i>Importation par API
            </button>
        </li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3 mb-3" style="background:#fff;">

    <div class="tab-pane fade show active" id="tab-excel" role="tabpanel">

    <!-- Étape 1 (Excel) : sélection + analyse du fichier -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-file-earmark-excel me-2"></i>1. Analyser un fichier Excel ou texte FFTT</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Analyse un fichier déposé dans <code>Importation/Rencontres/Nationale/</code> (calendrier des
                journées + poules par division), et insère les équipes dans la table <code>equipe_nationale</code>.
                Utile en début de saison, quand l'API FFTT n'a pas encore les poules/rencontres alimentées.
                Deux formats acceptés : <code>.xlsx</code> (fichier FFTT à plusieurs feuilles), ou <code>.txt</code>
                (recopié/nettoyé à la main depuis un PDF de poules — un bloc <code>CALENDRIER</code>, puis un
                bloc <code>NATIONALE x Y</code>/<code>POULE n</code> par poule, une équipe <code>rang;nom[;Id_Club]</code>
                par ligne, le 3<sup>e</sup> champ étant optionnel).
            </p>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <select id="sel-fichier-excel" class="form-select form-select-sm w-auto">
                    <option value="">— Chargement… —</option>
                </select>
                <button id="btn-analyser-excel" class="btn btn-primary" disabled>
                    <i class="bi bi-search me-1"></i>Analyser
                </button>
                <span id="analyse-excel-status" class="text-muted small"></span>
            </div>
        </div>
    </div>

    </div><!-- /#tab-excel -->

    <div class="tab-pane fade" id="tab-api" role="tabpanel">

    <!-- Étape 0 : scanner les clubs de la région -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-search me-2"></i>Rechercher les équipes nationales des clubs de <?= esc($regionNom) ?></div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Parcourt tous les clubs de la région (<?= esc($regionNom) ?>) via l'API FFTT et détecte ceux ayant une équipe
                en division nationale (N1M, N2M, N3M, N1F, N2F…). Les équipes trouvées sont automatiquement
                ajoutées à la table <code>equipe_nationale</code> avec leur club d'origine.
            </p>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <button id="btn-scanner" class="btn btn-warning">
                    <i class="bi bi-binoculars me-1"></i>Scanner les clubs de <?= esc($regionNom) ?>
                </button>
                <span id="scan-status" class="text-muted small"></span>
                <?php if ($isChautard): ?>
                <div class="ms-auto d-flex align-items-center gap-1" title="Tester un club précis pour voir les champs retournés par l'API">
                    <input type="text" id="dbg-numclu" class="form-control form-control-sm" style="width:110px;" placeholder="n° club">
                    <button id="btn-debug-club" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-bug me-1"></i>Debug xml_equipe
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <!-- Barre de progression -->
            <div id="scan-progress-wrap" style="display:none;" class="mb-2">
                <div class="d-flex justify-content-between mb-1" style="font-size:.78rem; color:#6b7280;">
                    <span id="scan-label">Initialisation…</span>
                    <span id="scan-counter"></span>
                </div>
                <div class="progress" style="height:8px;">
                    <div id="scan-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                         role="progressbar" style="width:0%"></div>
                </div>
            </div>
            <!-- Journal des opérations -->
            <div id="scan-log" style="display:none; max-height:340px; overflow-y:auto;
                 background:#0f172a; color:#e2e8f0; border-radius:6px; padding:.6rem .85rem;
                 font-family:monospace; font-size:.76rem; line-height:1.6;">
            </div>
        </div>
    </div>

    <!-- Étape 1 : charger depuis FFTT -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-cloud-download me-2"></i>1. Charger les équipes nationales depuis l'API FFTT</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Interroge l'API FFTT pour récupérer toutes les équipes des divisions nationales (N1M, N2M, N3M, N1F, N2F)
                et les insère dans la table <code>equipe_nationale</code>.
            </p>
            <button id="btn-charger" class="btn btn-primary">
                <i class="bi bi-cloud-download me-1"></i>Charger depuis FFTT
            </button>
            <div id="res-charger" class="mt-2"></div>
        </div>
    </div>

    </div><!-- /#tab-api -->

    </div><!-- /.tab-content -->

    <!-- Cartouche Ordre des rencontres (issu de l'analyse Excel/texte), affiché avant l'association -->
    <div id="section-calendrier-excel" class="card mb-3">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-calendar3 me-1"></i>Ordre des rencontres
            <span id="lbl-saison-excel" class="badge bg-secondary" style="display:none;"></span>
            <span id="cal-hint-excel" class="text-muted small">Analysez d'abord un fichier à l'étape 1.</span>
            <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-toggle-cal-excel" style="font-size:.78rem; display:none;">
                <i class="bi bi-chevron-down"></i> Afficher
            </button>
        </div>
        <div id="cal-content-excel" class="card-body" style="display:none;"></div>
    </div>

    <!-- Étape 2 : association équipes → clubs (partagée par les deux onglets) -->
    <div id="section-assoc" class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-2"></i>2. Association équipes → département / club de <?= esc($regionNom) ?></div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span id="lbl-assoc-count" class="text-muted" style="font-size:.82rem;"></span>
                <select id="sel-div-filtre" class="form-select form-select-sm w-auto ms-2">
                    <option value="">Toutes les divisions</option>
                    <option value="N1M">N1M</option><option value="N2M">N2M</option><option value="N3M">N3M</option>
                    <option value="N1F">N1F</option><option value="N2F">N2F</option>
                </select>
                <label class="mb-0" style="font-size:.82rem;">
                    <input type="checkbox" id="chk-sans-dept" class="form-check-input me-1">Sans département
                </label>
                <label class="mb-0" style="font-size:.82rem;">
                    <input type="checkbox" id="chk-sans-club" class="form-check-input me-1">Sans club
                </label>
                <button id="btn-export-txt" class="btn btn-outline-secondary btn-sm ms-auto" disabled
                        title="Enregistre un fichier .txt dans Importation/Rencontres/Nationale/">
                    <i class="bi bi-save me-1"></i>Exporter en .txt
                </button>
            </div>
            <div style="max-height:55vh; overflow:auto; border:1px solid #d0d8e8; border-radius:6px;">
                <table id="tbl-assoc">
                    <thead><tr>
                        <th style="width:55px;">Division</th>
                        <th style="width:35px;">P.</th>
                        <th style="width:75px;">N° Club</th>
                        <th style="width:280px;">Club</th>
                        <th>Équipe</th>
                        <th style="width:70px; text-align:center;">Dépt</th>
                        <th style="width:55px; text-align:center;">Sauver</th>
                    </tr></thead>
                    <tbody id="tbody-assoc">
                        <tr><td colspan="7" class="text-center text-muted py-3">Chargez les équipes depuis l'API FFTT.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Étape 3 : import rencontres depuis le fichier Excel/texte (pas d'appel FFTT) -->
    <div id="section-import-excel" class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-calendar2-check me-2"></i>3. Importer les rencontres depuis le fichier Excel/texte (receveur = club de <?= esc($regionNom) ?>)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Reconstitue les rencontres à partir des dates et de l'ordre des rencontres du fichier analysé
                à l'étape 1, appliqués à chaque poule associée à l'étape 2. Ne crée une rencontre que si
                <strong>l'équipe à domicile</strong> est un club de la région.
            </p>
            <button id="btn-importer-excel" class="btn btn-success" disabled>
                <i class="bi bi-cloud-upload me-1"></i>Importer les rencontres (Excel/texte)
            </button>
            <span id="import-excel-hint" class="text-muted small ms-2">Analysez d'abord un fichier à l'étape 1.</span>
            <div id="res-import-excel" class="mt-2"></div>
        </div>
    </div>

    <!-- Étape 4 : fichiers Excel/texte disponibles -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-file-earmark-text me-2"></i>4. Fichiers Excel/texte disponibles</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Fichiers présents dans <code>Importation/Rencontres/Nationale/</code> :</p>
            <ul id="liste-fichiers-nat" class="list-unstyled mb-0" style="font-size:.85rem;">
                <li class="text-muted">Chargement…</li>
            </ul>
        </div>
    </div>

</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const NAT_BASE = '<?= site_url('import-rencontres-nat') ?>';
const NAT_DIR_URL = '<?= base_url('Importation/Rencontres/Nationale/') ?>';

let equipes = [];
let currentSaison = '';
let lastJournees  = [];
const DEPTS_NORM = <?= json_encode(array_map('strval', $deptsNorm)) ?>;
const ALL_DEPTS  = <?= json_encode((object) $allDepts) ?>;
const REGION_NOM     = <?= json_encode($regionNom) ?>;
const REGION_GENTILE = <?= json_encode($regionGentile) ?>;

function spinner(v) { $('#spinner').toggleClass('show', v); }
function isNorm(d)  { return d && DEPTS_NORM.includes(String(d)); }
function deptName(d){ return (d && ALL_DEPTS[d]) ? ALL_DEPTS[d] : (d || ''); }
function esc(s)     { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Fige un instantané de l'état sauvegardé en base (CodeDept/Id_Club) au moment du chargement,
// pour distinguer les valeurs affichées (éditées en local, pas encore enregistrées) de ce qui
// est réellement en base : seul cet instantané détermine la coloration verte des lignes.
function stampSaved(list) {
    list.forEach(e => { e.SavedCodeDept = e.CodeDept; e.SavedIdClub = e.Id_Club; });
    return list;
}

function deptBadge(dept) {
    if (!dept) return '<span style="font-size:.7rem;color:#9ca3af;">—</span>';
    if (isNorm(dept)) return `<span style="font-size:.68rem;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;border-radius:3px;padding:.05rem .3rem;" title="${esc(deptName(dept))}">NOR</span>`;
    return `<span style="font-size:.68rem;font-weight:600;color:#6b7280;background:#f3f4f6;border:1px solid #d1d5db;border-radius:3px;padding:.05rem .3rem;" title="${esc(deptName(dept))}">${esc(dept)}</span>`;
}

// ── 1. Charger depuis FFTT ────────────────────────────────────────────────────
$('#btn-charger').on('click', async function () {
    $(this).prop('disabled', true);
    spinner(true);
    $('#res-charger').html('<em class="text-muted small">Interrogation de l\'API FFTT (peut prendre 30–60 s)…</em>');
    try {
        const r = await $.post(`${NAT_BASE}/charger-depuis-api`, {}, null, 'json');
        spinner(false);
        if (!r.ok) { $('#res-charger').html(`<div class="alert alert-danger py-2">${esc(r.err)}</div>`); return; }
        const s = r.stats;
        let html = `<div class="alert alert-success py-2">
            <i class="bi bi-check-circle me-1"></i>
            <strong>${s.divisions} division(s)</strong> traitées —
            <strong>${s.equipes} équipe(s)</strong> nouvelles insérées` +
            (s.associations > 0 ? ` — <strong>${s.associations}</strong> association(s) club/département automatique(s)` : '') + `.`;
        if (s.erreurs?.length) html += `<br><span class="text-warning">${s.erreurs.length} erreur(s) : ${s.erreurs.map(e=>esc(e)).join(', ')}</span>`;
        html += '</div>';
        $('#res-charger').html(html);
        chargerEquipes();
    } catch(e) {
        spinner(false);
        $('#res-charger').html('<div class="alert alert-danger py-2">Erreur réseau.</div>');
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 2. Table associations ─────────────────────────────────────────────────────
function chargerEquipes() {
    $.getJSON(`${NAT_BASE}/equipes`, function(r) {
        if (!r.ok) { nijacToast('Erreur chargement équipes.', 'danger'); return; }
        equipes = stampSaved(r.equipes);
        $('#btn-export-txt').prop('disabled', !equipes.length);
        renderAssoc();
    });
}

function renderAssoc() {
    const divF = $('#sel-div-filtre').val();
    const sans = $('#chk-sans-dept').is(':checked');
    const sansClub = $('#chk-sans-club').is(':checked');
    let data = [...equipes];
    if (divF) data = data.filter(e => e.id_division === divF);
    if (sans) data = data.filter(e => !e.CodeDept);
    if (sansClub) data = data.filter(e => !e.Id_Club);

    const total    = equipes.length;
    const avecDept = equipes.filter(e => e.CodeDept).length;
    const normands = equipes.filter(e => isNorm(e.CodeDept)).length;
    const avecClub = equipes.filter(e => e.Id_Club).length;
    $('#lbl-assoc-count').text(`${avecDept}/${total} avec dépt — ${normands} ${REGION_GENTILE} — ${avecClub} avec club`);

    const $body = $('#tbody-assoc').empty();
    if (!data.length) { $body.append('<tr><td colspan="7" class="text-center text-muted py-3">Aucune équipe.</td></tr>'); return; }

    data.forEach(e => {
        const norm      = isNorm(e.CodeDept);
        const haClub    = !!e.Id_Club;
        const savedNorm = isNorm(e.SavedCodeDept);
        const rowOk     = !!e.SavedCodeDept && (!savedNorm || !!e.SavedIdClub);
        const clubCell = `
            <div class="club-search-wrap" data-id="${e.Id_EquipeNat}" data-nom="${esc(e.Nom)}">
                <div class="club-display ${haClub?'':'empty'}">${haClub ? esc(e.NomClub) : '— non associé —'}</div>
                <button class="btn btn-xs btn-outline-secondary btn-changer-club py-0 px-1" style="font-size:.76rem;"><i class="bi bi-search"></i></button>
                ${haClub ? `<button class="btn btn-xs btn-outline-danger btn-effacer-club py-0 px-1" style="font-size:.76rem;"><i class="bi bi-x"></i></button>` : ''}
                <div class="club-popup"><input class="club-popup-input" placeholder="Chercher…" autocomplete="off"><div class="club-results"></div></div>
            </div>`;

        const $tr = $('<tr>').addClass(rowOk?'assoc-ok':'').attr('data-id', e.Id_EquipeNat);
        $tr.html(`
            <td><span class="badge-div bdiv-${esc(e.id_division)}">${esc(e.id_division)}</span></td>
            <td style="text-align:center;">${e.Poule||'—'}</td>
            <td class="cell-num-club" style="text-align:center;">
                <input type="text" class="input-numclub form-control form-control-sm text-center px-1" value="${haClub ? esc(e.Id_Club) : ''}" placeholder="N° club" maxlength="8" style="width:80px;font-size:.8rem;" data-id="${e.Id_EquipeNat}">
            </td>
            <td>${clubCell}</td>
            <td style="font-weight:600;">${esc(e.Nom)}</td>
            <td style="text-align:center;">
                <input type="text" class="input-dept form-control form-control-sm text-center px-1" value="${esc(e.CodeDept??'')}" placeholder="76" maxlength="3" style="width:58px;display:inline-block;" data-id="${e.Id_EquipeNat}">
                <span class="dept-badge ms-1" data-id="${e.Id_EquipeNat}">${deptBadge(e.CodeDept)}</span>
            </td>
            <td style="text-align:center;">
                <button class="btn btn-sm btn-success btn-sauver py-0" data-id="${e.Id_EquipeNat}" data-dept="${esc(e.CodeDept??'')}" data-club="${e.Id_Club??''}"><i class="bi bi-floppy"></i></button>
            </td>`);
        $body.append($tr);
    });
}

$(document).on('input', '.input-dept', function() {
    const idEn = +$(this).attr('data-id');
    const dept = $(this).val().trim().toUpperCase();
    const $tr  = $(this).closest('tr');
    $tr.find(`.dept-badge[data-id="${idEn}"]`).html(deptBadge(dept||null));
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.CodeDept = dept||null; }
});

let searchTimer = null;
// Retire le dernier mot de la requête s'il ne fait que 2 caractères (souvent un sigle/suffixe
// qui ne correspond à rien dans le nom du club en base, et qui empêche sinon de trouver le club).
function trimShortTrailingWord(q) {
    return q.replace(/\s+\S{2}$/, '').trim();
}
function searchClubs($input, onDone) {
    const q = trimShortTrailingWord($input.val().trim());
    const $res = $input.siblings('.club-results').empty();
    const prevXhr = $input.data('searchXhr');
    if (prevXhr && prevXhr.abort) prevXhr.abort(); // annule une recherche précédente encore en vol (évite qu'une réponse plus lente écrase des résultats plus récents)
    if (q.length < 2) { onDone && onDone(); return; }
    const xhr = $.getJSON(`${NAT_BASE}/recherche-club`, {q}, function(r) {
        $res.empty();
        if (!r.ok || !r.clubs.length) { $res.html('<div class="club-result text-muted">Aucun club.</div>'); onDone && onDone(); return; }
        r.clubs.forEach(c => $('<div class="club-result">').html(`<strong>${esc(c.Nom)}</strong> <small class="text-muted">#${c.Id_Club}</small>`).attr({'data-id-club':c.Id_Club,'data-nom-club':c.Nom}).appendTo($res));
        onDone && onDone();
    });
    $input.data('searchXhr', xhr);
}
$(document).on('input', '.club-popup-input', function() {
    const $input = $(this);
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => searchClubs($input), 280);
});
// Entrée = valide immédiatement le nom saisi et sélectionne le premier club trouvé
$(document).on('keydown', '.club-popup-input', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const $input = $(this);
    clearTimeout(searchTimer);
    searchClubs($input, function() {
        const $first = $input.siblings('.club-results').find('.club-result[data-id-club]').first();
        if ($first.length) pickClubResult($first);
    });
});

$(document).on('click', '.btn-changer-club', function(e) {
    e.stopPropagation();
    $('.club-popup').removeClass('open');
    const $wrap = $(this).closest('.club-search-wrap');
    const nomBase = ($wrap.attr('data-nom') || '').replace(/\s+\d+$/, '').trim();
    $wrap.find('.club-popup').addClass('open').find('.club-popup-input').val(nomBase).trigger('focus').trigger('input');
    $wrap.find('.club-results').empty();
});
$(document).on('click', function() { $('.club-popup').removeClass('open'); });
$(document).on('click', '.club-popup', e => e.stopPropagation());

function deptFromIdClub(idClub) {
    return String(idClub ?? '').substr(2, 2);
}

function pickClubResult($result) {
    const $wrap = $result.closest('.club-search-wrap');
    const $tr   = $wrap.closest('tr');
    const idEn  = +$wrap.attr('data-id');
    const idClub = $result.attr('data-id-club');
    const nom    = $result.attr('data-nom-club');
    const dept   = deptFromIdClub(idClub);
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club=idClub; eq.NomClub=nom; eq.CodeDept=dept; }
    $wrap.find('.club-display').removeClass('empty').html(esc(nom));
    $tr.find('.input-numclub').val(idClub);
    $tr.find('.input-dept').val(dept);
    $tr.find('.dept-badge').html(deptBadge(dept));
    $tr.find('.btn-sauver').attr('data-club', idClub).attr('data-dept', dept);
    $wrap.find('.club-popup').removeClass('open');
    sauverAssoc($tr);
}

$(document).on('click', '.club-result', function() {
    pickClubResult($(this));
});

$(document).on('click', '.btn-effacer-club', function() {
    const $wrap = $(this).closest('.club-search-wrap');
    const idEn  = +$wrap.attr('data-id');
    const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
    if (eq) { eq.Id_Club=null; eq.NomClub=null; }
    $wrap.closest('tr').find('.btn-sauver').attr('data-club','');
    renderAssoc();
});

// ── Saisie directe du n° de club ──────────────────────────────────────────────
$(document).on('change', '.input-numclub', function() {
    const $input = $(this);
    const idEn   = +$input.attr('data-id');
    const numero = $input.val().trim().toUpperCase();
    const $tr    = $input.closest('tr');
    const eq     = equipes.find(e => +e.Id_EquipeNat === idEn);
    $input.val(numero);

    if (numero === '') {
        if (eq) { eq.Id_Club = null; eq.NomClub = null; }
        $tr.find('.btn-sauver').attr('data-club', '');
        renderAssoc();
        return;
    }

    $input.prop('disabled', true);
    $.getJSON(`${NAT_BASE}/club-par-numero`, {numero}, function(r) {
        $input.prop('disabled', false);
        if (!r.ok) {
            nijacToast(r.err || 'Club introuvable.', 'danger');
            $input.val(eq?.Id_Club ?? '');
            return;
        }
        const dept = deptFromIdClub(r.club.Id_Club);
        if (eq) { eq.Id_Club = r.club.Id_Club; eq.NomClub = r.club.Nom; eq.CodeDept = dept; }
        $input.val(r.club.Id_Club);
        $tr.find('.club-display').removeClass('empty').html(esc(r.club.Nom));
        $tr.find('.input-dept').val(dept);
        $tr.find('.dept-badge').html(deptBadge(dept));
        $tr.find('.btn-sauver').attr('data-club', r.club.Id_Club).attr('data-dept', dept);
        sauverAssoc($tr);
    }).fail(() => { $input.prop('disabled', false); nijacToast('Erreur réseau.', 'danger'); });
});

function sauverAssoc($tr) {
    const idEn   = +$tr.attr('data-id');
    const dept   = $tr.find('.input-dept').val().trim();
    const idClub = $tr.find('.btn-sauver').attr('data-club') || '';
    const $btn   = $tr.find('.btn-sauver').prop('disabled', true);
    $.post(`${NAT_BASE}/sauvegarder-assoc`, {id_equipe_nat:idEn, code_dept:dept, id_club:idClub}, function(r) {
        $btn.prop('disabled', false);
        if (r.ok) {
            const eq = equipes.find(e => +e.Id_EquipeNat === idEn);
            if (eq) { eq.CodeDept = dept || null; eq.SavedCodeDept = eq.CodeDept; eq.SavedIdClub = idClub || null; }
            renderAssoc();
            if (r.warn) nijacToast(r.warn, 'warning');
        } else nijacToast('Erreur : ' + r.err, 'danger');
    }, 'json').fail(() => { $btn.prop('disabled', false); nijacToast('Erreur réseau.', 'danger'); });
}

$(document).on('click', '.btn-sauver', function() {
    sauverAssoc($(this).closest('tr'));
});

$('#sel-div-filtre, #chk-sans-dept, #chk-sans-club').on('change', renderAssoc);

// ── Export .txt (calendrier persisté + équipes/poules/N° Club de l'étape 2) ──
// Généré et enregistré côté serveur (Importation/Rencontres/Nationale/YYYYMMJJ_Poules_Nationales.txt),
// pour rester réutilisable indépendamment de la session/du navigateur en cours.
$('#btn-export-txt').on('click', async function () {
    $(this).prop('disabled', true);
    try {
        const r = await $.post(`${NAT_BASE}/exporter-txt`, {}, null, 'json');
        if (!r.ok) { nijacToast('Erreur export : ' + r.err, 'danger'); return; }
        nijacToast(`Fichier enregistré : ${r.fichier}`, 'success');
    } catch (e) {
        nijacToast('Erreur réseau.', 'danger');
    } finally {
        $(this).prop('disabled', !equipes.length);
    }
});

// Charger les équipes existantes au démarrage si table non vide
$.getJSON(`${NAT_BASE}/equipes`, function(r) {
    if (r.ok && r.equipes.length) {
        equipes = stampSaved(r.equipes);
        $('#btn-export-txt').prop('disabled', false);
        renderAssoc();
    }
});

// ── Onglet Importation Excel ──────────────────────────────────────────────────
function fmtDateNat(iso) {
    const p = String(iso).split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
}

function afficherCalendrierExcel(journees) {
    if (!journees.length) { $('#cal-content-excel').html('<p class="text-muted mb-0">Aucune journée détectée.</p>'); return; }
    let th1 = '', th2 = '';
    journees.forEach(j => {
        const matchs = j.matchs.map(m => `${m[0]}-${m[1]}`).join(' / ');
        th1 += `<th style="text-align:center; white-space:nowrap; padding:.25rem .5rem;">J${j.journee}<br><span style="font-weight:400; font-size:.78rem;">${fmtDateNat(j.date)}</span></th>`;
        th2 += `<td style="text-align:center; font-size:.8rem; color:#4b5563; white-space:nowrap; padding:.2rem .5rem;">${matchs}</td>`;
    });
    $('#cal-content-excel').html(`
        <div style="overflow-x:auto;">
          <table style="border-collapse:collapse; border:1px solid #d0d8e8; font-size:.84rem; background:#fff; border-radius:6px; overflow:hidden;">
            <thead><tr style="background:#e8eef7; color:#1a3a6b;">${th1}</tr></thead>
            <tbody><tr>${th2}</tr></tbody>
          </table>
        </div>`);
}

$('#btn-toggle-cal-excel').on('click', function () {
    const $c = $('#cal-content-excel');
    const open = $c.is(':visible');
    $c.toggle(!open);
    $(this).html(open ? '<i class="bi bi-chevron-down"></i> Afficher' : '<i class="bi bi-chevron-up"></i> Masquer');
});

// Liste des fichiers Excel/texte disponibles (étape 1 + liste de l'étape 4)
$.getJSON(`${NAT_BASE}/fichiers-excel`, function (r) {
    const $sel  = $('#sel-fichier-excel').empty();
    const $list = $('#liste-fichiers-nat').empty();
    if (!r.ok || !r.fichiers.length) {
        $sel.append('<option value="">— Aucun fichier .xlsx disponible —</option>');
        $list.append('<li class="text-muted">Aucun fichier.</li>');
        return;
    }
    $sel.append('<option value="">— Choisir un fichier —</option>');
    r.fichiers.forEach(f => {
        $sel.append(`<option value="${esc(f)}">${esc(f)}</option>`);
        $list.append(`<li><a href="${NAT_DIR_URL}${encodeURIComponent(f)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark me-1"></i>${esc(f)}</a></li>`);
    });
    $('#btn-analyser-excel').prop('disabled', false);
}).fail(() => {
    $('#sel-fichier-excel').empty().append('<option value="">— Erreur de chargement —</option>');
    $('#liste-fichiers-nat').empty().append('<li class="text-danger">Erreur de chargement.</li>');
    nijacToast('Erreur réseau lors du chargement de la liste des fichiers Excel.', 'danger');
});

// Calendrier persisté (table nationale_calendrier) : restaure le cartouche "Ordre des rencontres"
// et active l'étape 3, même après un rechargement de page (sans nouvelle analyse cette session).
$.getJSON(`${NAT_BASE}/calendrier`, function (r) {
    if (!r.ok || !r.journees.length) return;
    currentSaison = r.saison;
    lastJournees  = r.journees;
    if (currentSaison) { $('#lbl-saison-excel').text('Saison ' + currentSaison).show(); }
    $('#cal-hint-excel').hide();
    $('#btn-toggle-cal-excel').show();
    afficherCalendrierExcel(r.journees);
    $('#btn-importer-excel').prop('disabled', false);
    $('#import-excel-hint').hide();
});

$('#sel-fichier-excel').on('change', function () {
    $('#btn-analyser-excel').prop('disabled', !this.value);
});

$('#btn-analyser-excel').on('click', async function () {
    const fichier = $('#sel-fichier-excel').val();
    if (!fichier) return;
    $(this).prop('disabled', true);
    spinner(true);
    $('#analyse-excel-status').text('Analyse en cours…');
    try {
        const r = await $.post(`${NAT_BASE}/analyser-excel`, { fichier }, null, 'json');
        spinner(false);
        if (!r.ok) { $('#analyse-excel-status').text(''); nijacToast('Erreur analyse : ' + r.err, 'danger'); return; }

        currentSaison = r.data.saison;
        lastJournees  = r.data.journees;
        $('#lbl-saison-excel').text('Saison ' + r.data.saison).show();
        $('#cal-hint-excel').hide();
        $('#btn-toggle-cal-excel').show();
        afficherCalendrierExcel(r.data.journees);
        $('#cal-content-excel').hide();
        $('#btn-toggle-cal-excel').html('<i class="bi bi-chevron-down"></i> Afficher');

        let statusMsg = `${r.data.pools.length} poule(s), ${r.data.journees.length} journée(s).`;
        if (r.auto_assoc > 0) statusMsg += ` ${r.auto_assoc} association(s) copiée(s) automatiquement.`;
        if (r.erreurs?.length) statusMsg += ` ⚠ ${r.erreurs.join(', ')}`;
        $('#analyse-excel-status').text(statusMsg);
        if (r.data.journees.length) {
            $('#btn-importer-excel').prop('disabled', false);
            $('#import-excel-hint').hide();
        }
        chargerEquipes();
    } catch (e) {
        spinner(false);
        $('#analyse-excel-status').text('');
        nijacToast('Erreur réseau.', 'danger');
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 3 (Excel). Import rencontres depuis le fichier Excel ─────────────────────
$('#btn-importer-excel').on('click', async function() {
    const conf = await new Promise(resolve => nijacConfirm(
        `Importer les rencontres à partir du calendrier enregistré (receveur = club ${REGION_GENTILE}) ?\nLes doublons seront ignorés.`,
        () => resolve(true), () => resolve(false)
    ));
    if (!conf) return;

    $(this).prop('disabled', true);
    spinner(true);
    $('#res-import-excel').html('<em class="text-muted small">Import en cours…</em>');
    try {
        const r = await $.post(`${NAT_BASE}/importer-excel`, {}, null, 'json');
        spinner(false);
        if (!r.ok) { $('#res-import-excel').html(`<div class="alert alert-danger py-2">${esc(r.err)}</div>`); return; }
        const s = r.stats;
        let html = `<div class="d-flex flex-wrap gap-2 mb-2">
            <div class="stat-card"><div class="sv text-success">${s.rencontres_creees}</div><div class="sl">Rencontres créées</div></div>
            <div class="stat-card"><div class="sv text-primary">${s.equipes_creees}</div><div class="sl">Équipes créées</div></div>
            <div class="stat-card"><div class="sv text-secondary">${s.doublons}</div><div class="sl">Doublons</div></div>
            <div class="stat-card"><div class="sv text-muted">${s.ignores}</div><div class="sl">Ignorées</div></div>
        </div>`;
        if (s.erreurs?.length) {
            html += '<div class="alert alert-warning py-2"><strong>Avertissements :</strong><ul class="mb-0">';
            s.erreurs.forEach(e => html += `<li>${esc(e)}</li>`);
            html += '</ul></div>';
        } else {
            html += '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Import terminé sans erreur.</div>';
        }
        if (s.log?.length) {
            const icons = {rencontre:'bi-calendar-check text-success', equipe:'bi-people-fill text-primary'};
            const rows = s.log.map(l => `<div><i class="bi ${icons[l.type]??'bi-dot'}" style="font-size:.7rem;"></i> ${esc(l.val)}</div>`).join('');
            html += `<div class="log-detail mt-1">${rows}</div>`;
        }
        $('#res-import-excel').html(html);
    } catch(e) {
        spinner(false);
        $('#res-import-excel').html('<div class="alert alert-danger py-2">Erreur réseau.</div>');
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 0b. Debug xml_equipe ──────────────────────────────────────────────────────
$('#btn-debug-club').on('click', async function () {
    const numclu = $('#dbg-numclu').val().trim();
    if (!numclu) { nijacToast('Saisissez un numéro de club.', 'warning'); return; }
    $(this).prop('disabled', true);
    $('#scan-log').show().empty();
    $('#scan-progress-wrap').hide();

    function logLine(html) {
        const $log = $('#scan-log');
        $log.append('<div>' + html + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    try {
        const r = await $.getJSON(`${NAT_BASE}/debug-club?numclu=` + encodeURIComponent(numclu));
        if (!r.ok) { logLine(`<span style="color:#f87171;">Erreur : ${esc(r.err)}</span>`); return; }
        logLine(`<span style="color:#94a3b8;">${r.equipes.length} équipe(s) retournée(s) par xml_equipe pour ${esc(numclu)} :</span>`);
        r.equipes.forEach((eq, i) => {
            logLine(`<span style="color:#e2e8f0;"><strong>#${i+1}</strong> ${JSON.stringify(eq)}</span>`);
        });
        if (!r.equipes.length) {
            logLine(`<span style="color:#facc15;">XML brut :</span>`);
            logLine(`<span style="color:#94a3b8;">${esc(r.raw)}</span>`);
        }
    } catch(e) {
        const detail = e && e.status ? `HTTP ${e.status} — ${esc((e.responseText || '(corps vide)').substring(0,500))}` : esc(String(e));
        logLine(`<span style="color:#f87171;">Erreur réseau : ${detail}</span>`);
    } finally {
        $(this).prop('disabled', false);
    }
});

// ── 0. Scanner les clubs normands ─────────────────────────────────────────────
$('#btn-scanner').on('click', async function () {
    const $btn = $(this).prop('disabled', true);
    $('#scan-log').show().empty();
    $('#scan-progress-wrap').show();
    $('#scan-bar').css('width', '0%').removeClass('bg-success').addClass('bg-warning progress-bar-animated');
    $('#scan-label').text('Récupération de la liste des clubs…');
    $('#scan-counter').text('');
    $('#scan-status').text('');

    function logLine(html) {
        const $log = $('#scan-log');
        $log.append('<div>' + html + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    try {
        // Vider le cache session des divisions nationales (pour forcer le rechargement)
        await $.post(`${NAT_BASE}/reset-cache`, {}, null, 'json');

        // Étape 1 : lister tous les clubs
        const rClubs = await $.getJSON(`${NAT_BASE}/clubs-region`);
        if (!rClubs.ok) { logLine('<span style="color:#f87171;">Erreur : ' + esc(rClubs.err??'') + '</span>'); return; }

        const clubs  = rClubs.clubs;
        const total  = clubs.length;
        let traites  = 0, trouves = 0, doublons = 0, erreurs = 0;

        logLine(`<span style="color:#94a3b8;">${total} club(s) à analyser…</span>`);
        $('#scan-label').text('Analyse en cours…');

        // Étape 2 : scanner club par club
        for (const club of clubs) {
            traites++;
            const pct = Math.round(traites / total * 100);
            $('#scan-bar').css('width', pct + '%');
            $('#scan-counter').text(`${traites} / ${total}`);
            $('#scan-label').text(esc(club.nom) + ' (' + club.dept + ')');

            try {
                const r = await $.post(`${NAT_BASE}/scanner-club`, {
                    numclu: club.numclu,
                    dept:   club.dept,
                    nom:    club.nom,
                }, null, 'json');

                if (!r.ok) {
                    erreurs++;
                    logLine(`<span style="color:#f87171;">✗ ${esc(club.nom)} — ${esc(r.err??'')}</span>`);
                    continue;
                }

                if (r.nationales && r.nationales.length) {
                    r.nationales.forEach(n => {
                        const isNew = n.new;
                        const icon  = isNew ? '⊕' : '↺';
                        const color = isNew ? '#4ade80' : '#facc15';
                        if (isNew) trouves++; else doublons++;
                        logLine(`<span style="color:${color};">${icon} <strong style="color:#e2e8f0;">${esc(club.nom)}</strong> — <span style="color:#93c5fd;">${esc(n.div)}</span> — ${esc(n.lib)}</span>`);
                    });
                } else {
                    logLine(`<span style="color:#475569;">— ${esc(club.nom)} — Pas d'équipe Nationale</span>`);
                }
            } catch(e) {
                erreurs++;
                const detail = e && e.status ? `HTTP ${e.status} — ${esc((e.responseText || '(corps vide)').substring(0,500))}` : esc(String(e));
                logLine(`<span style="color:#f87171;">✗ ${esc(club.nom)} — ${detail}</span>`);
            }
        }

        // Terminé
        $('#scan-bar').css('width','100%').removeClass('bg-warning progress-bar-animated').addClass('bg-success');
        $('#scan-label').text('Scan terminé.');
        $('#scan-counter').text(`${traites} / ${total}`);
        const msg = `${trouves} équipe(s) nouvelle(s), ${doublons} déjà connue(s)${erreurs ? ', ' + erreurs + ' erreur(s)' : ''}.`;
        $('#scan-status').html(`<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>${esc(msg)}</span>`);
        logLine(`<span style="color:#94a3b8; border-top:1px solid #334155; display:block; margin-top:.4rem; padding-top:.4rem;">Terminé — ${msg}</span>`);

        // Rafraîchir la table d'association
        if (trouves > 0) {
            $.getJSON(`${NAT_BASE}/equipes`, function(r2) {
                if (r2.ok && r2.equipes.length) {
                    equipes = stampSaved(r2.equipes);
                    $('#btn-export-txt').prop('disabled', false);
                    renderAssoc();
                }
            });
        }
    } catch(e) {
        logLine(`<span style="color:#f87171;">Erreur inattendue : ${esc(String(e))}</span>`);
    } finally {
        $btn.prop('disabled', false);
    }
});
</script>
<?php // Différé : nijac-sortable-table.js n'est pas utilisé sur cet écran (legacy non plus). ?>
</body>
</html>
