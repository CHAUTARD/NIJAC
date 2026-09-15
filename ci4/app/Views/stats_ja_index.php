<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Statistiques JA (EN17)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; display: flex; flex-direction: column; min-height: 100vh; }

        #page-header { background: #2e7d32; color: #fff; padding: .5rem 1.25rem; font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: .75rem; }

        #toolbar-user { background: #f8fafc; border-bottom: 1px solid #dde5f0; padding: .3rem 1rem; display: flex; align-items: center; justify-content: space-between; font-size: .85rem; }
        #toolbar-user .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar-user .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center; gap: .35rem; color: #c00; font-weight: 700; cursor: pointer; text-decoration: underline dotted;
        }
        #toolbar-user .ts-pwd-warning:hover { color: #900; }

        #toolbar { --strip-bg: #f8fafc; background: #f8fafc; border-bottom: 1px solid #dde5f0; padding: .4rem 1rem; display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; font-size: .85rem; }

        #stats-wrap { padding: 1.25rem; flex: 1; }

        /* Tableau */
        .stats-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .stats-table thead th {
            background: var(--nijac-blue); color: #fff;
            padding: .45rem .6rem; white-space: nowrap;
            cursor: pointer; user-select: none;
        }
        .stats-table thead th:hover { background: #2a4a8b; }
        .stats-table thead th.sort-asc::after  { content: ' ▲'; }
        .stats-table thead th.sort-desc::after { content: ' ▼'; }
        .stats-table tbody tr:hover { background: #eef4ff; }
        .stats-table td { padding: .35rem .6rem; border-bottom: 1px solid #e5e7eb; }
        .stats-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .stats-table tfoot td { font-weight: 700; background: #e8eef7; padding: .4rem .6rem; border-top: 2px solid var(--nijac-blue); }
        .stats-table tfoot td.num { text-align: right; }

        /* Barres de progression mini */
        .mini-bar-wrap { display: flex; align-items: center; gap: .4rem; }
        .mini-bar { height: 8px; border-radius: 4px; background: #bfdbfe; flex-shrink: 0; }

        /* Badges grade */
        .grade-badge { font-size: .68rem; padding: .15rem .4rem; border-radius: 20px; font-weight: 600; white-space: nowrap; }
        .grade-national { background: #fef3c7; color: #92400e; }
        .grade-regional  { background: #dcfce7; color: #14532d; }
        .grade-other     { background: #f1f5f9; color: #475569; }

        /* Graphes JA + rencontres par département (petits multiples, 2 par ligne) */
        .chart-section-title { font-weight: 700; color: var(--nijac-blue); font-size: .95rem; }
        .chart-section-subtitle { font-size: .75rem; color: #6b7280; margin-bottom: .6rem; }
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        @media (max-width: 700px) { .chart-grid { grid-template-columns: 1fr; } }
        .chart-card { background: #fff; border: 1px solid #e0e8f0; border-radius: 6px; padding: .6rem .8rem; }
        .chart-card-wide { grid-column: 1 / -1; }
        .chart-card-title { font-weight: 600; color: #374151; font-size: .8rem; margin-bottom: .3rem; }
        .chart-legend-row { display: flex; flex-wrap: wrap; gap: .3rem 1rem; margin-bottom: .35rem; }
        .chart-legend-item { display: inline-flex; align-items: center; gap: .3rem; font-size: .78rem; color: #374151; cursor: pointer; }
        .chart-legend-item input[type="checkbox"] { margin: 0; cursor: pointer; }
        .chart-swatch { width: 10px; height: 10px; border-radius: 2px; display: inline-block; flex-shrink: 0; }

        @media print {
            #toolbar, #toolbar-user, #page-footer, .no-print { display: none !important; }
            body { background: #fff; }
            .stats-table thead th { background: #1a3a6b !important; -webkit-print-color-adjust: exact; }
            .chart-card { border-color: #999; -webkit-print-color-adjust: exact; }
        }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
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
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'bar-chart-fill', 'phTitle' => 'Statistiques des Juges-Arbitres', 'phCode' => 'EN17',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar utilisateur : recopié de Nominateur/includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Barre de filtres -->
<div id="toolbar">
    <span class="combo-field">
        <label for="filtre-phase">Phase</label>
        <select id="filtre-phase">
            <option value="1"<?= (int) $defaultPhase === 1 ? ' selected' : '' ?>>Phase 1</option>
            <option value="2"<?= (int) $defaultPhase === 2 ? ' selected' : '' ?>>Phase 2</option>
        </select>
    </span>
    <span class="combo-field">
        <label for="filtre-annee">Saison</label>
        <select id="filtre-annee">
            <?php foreach ($anneesDispo as $a): ?>
            <option value="<?= $a ?>"<?= (int) $a === (int) $defaultAnnee ? ' selected' : '' ?>><?= $a ?>&#8209;<?= $a + 1 ?></option>
            <?php endforeach; ?>
        </select>
    </span>
    <button class="btn btn-sm btn-primary" id="btn-charger">
        <i class="bi bi-search me-1"></i>Afficher
    </button>
    <button class="btn btn-sm btn-outline-success ms-auto no-print" id="btn-export-csv">
        <i class="bi bi-filetype-csv me-1"></i>Export CSV
    </button>
    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Imprimer
    </button>
</div>

<!-- Zone principale -->
<div id="stats-wrap">
    <div id="loading" class="text-center text-muted py-5" style="display:none;">
        <div class="spinner-border spinner-border-sm me-2"></div>Chargement…
    </div>
    <div id="empty-msg" class="text-center text-muted py-5" style="display:none;">
        <i class="bi bi-inbox" style="font-size:2rem;"></i><br>Aucun arbitrage sur cette période.
    </div>
    <div id="dept-charts" class="mb-3" style="display:none;">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <div id="chart-section-title" class="chart-section-title"></div>
            <span class="combo-field ms-auto">
                <label for="sel-journee-surbrillance">Mettre en évidence</label>
                <select id="sel-journee-surbrillance"><option value="">Aucune mise en évidence</option></select>
            </span>
        </div>
        <div class="chart-section-subtitle">Axe horizontal : département · Axe vertical : nombre</div>
        <div id="chart-dept-grid" class="chart-grid"></div>
    </div>
    <div id="table-wrap" style="display:none;">
        <table class="stats-table" id="stats-table">
            <thead>
                <tr>
                    <th data-col="Nom">Juge-Arbitre</th>
                    <th data-col="Grade">Grade</th>
                    <th data-col="Club">Club</th>
                    <th data-col="nb_arbitrages" class="sort-desc">Arbitrages</th>
                    <th data-col="total_km">Km</th>
                    <th data-col="total_peages">Péages (€)</th>
                    <th data-col="total_indemnite">Indemnité (€)</th>
                    <th data-col="total_frais">Total frais (€)</th>
                </tr>
            </thead>
            <tbody id="stats-tbody"></tbody>
            <tfoot id="stats-tfoot"></tfoot>
        </table>
    </div>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const BASE = '<?= site_url('stats-ja') ?>';

let _rows   = [];
const sortState = { col: 'nb_arbitrages', asc: false };
let _maxArb  = 1;
let _cfg     = {};

function fmt2(v) { return parseFloat(v || 0).toFixed(2).replace('.', ','); }
function esc(s)  { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function gradeBadge(g) {
    g = g || '';
    const low = g.toLowerCase();
    let cls = 'grade-other';
    if (low.includes('national')) cls = 'grade-national';
    else if (low.includes('régional') || low.includes('regional')) cls = 'grade-regional';
    return g ? `<span class="grade-badge ${cls}">${esc(g)}</span>` : '<span class="text-muted">–</span>';
}

function renderTable() {
    const sorted = [..._rows].sort((a, b) => {
        let va = a[sortState.col], vb = b[sortState.col];
        if (!isNaN(va) && !isNaN(vb)) { va = parseFloat(va); vb = parseFloat(vb); }
        else { va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase(); }
        if (va < vb) return sortState.asc ? -1 :  1;
        if (va > vb) return sortState.asc ?  1 : -1;
        return 0;
    });

    const tbody = $('#stats-tbody');
    if (sorted.length === 0) { tbody.html(''); return; }

    tbody.html(sorted.map(r => {
        const barW = Math.round((r.nb_arbitrages / _maxArb) * 80);
        return `<tr>
            <td>
                <div class="mini-bar-wrap">
                    <div class="mini-bar" style="width:${barW}px;background:#93c5fd;"></div>
                    <strong>${esc(r.Nom)}</strong>&nbsp;${esc(r.Prenom)}
                </div>
            </td>
            <td>${gradeBadge(r.Grade)}</td>
            <td>${esc(r.Club || '–')}</td>
            <td class="num"><strong>${r.nb_arbitrages}</strong></td>
            <td class="num">${parseInt(r.total_km)}</td>
            <td class="num">${fmt2(r.total_peages)}</td>
            <td class="num">${fmt2(r.total_indemnite)}</td>
            <td class="num"><strong>${fmt2(r.total_frais)}</strong></td>
        </tr>`;
    }).join(''));

    refreshTriEntetes();
}

const PALETTE_DEPTS = ['#1a3a6b', '#2e7d32', '#f59e0b', '#db2777', '#7c3aed', '#0d9488', '#dc2626', '#65a30d'];

const COULEUR_JA    = '#1a3a6b'; // bleu — fixe, distinct des couleurs de journée ci-dessous
const COULEUR_TOTAL = '#e11d1d'; // rouge — fixe, idem
// Une couleur par journée, choisies pour rester visuellement distinctes entre elles et du bleu/rouge fixes ci-dessus.
const PALETTE_JOURNEES = ['#2e7d32', '#f59e0b', '#7c3aed', '#0d9488', '#db2777', '#65a30d', '#ea580c', '#9333ea', '#0891b2', '#78350f'];

/** Camembert du total de rencontres par département, avec légende (valeur + %). */
function svgCamembert(rows, valeurs) {
    const total = valeurs.reduce((a, b) => a + b, 0) || 1;
    const cx = 140, cy = 140, r = 125;
    let angle = -90;
    const toXY = a => [cx + r * Math.cos(a * Math.PI / 180), cy + r * Math.sin(a * Math.PI / 180)];

    const slices = valeurs.map((v, i) => {
        const a0 = angle, a1 = angle + (v / total) * 360;
        angle = a1;
        const large = (a1 - a0) > 180 ? 1 : 0;
        const [x0, y0] = toXY(a0), [x1, y1] = toXY(a1);
        const color = PALETTE_DEPTS[i % PALETTE_DEPTS.length];
        const pct = Math.round(v / total * 1000) / 10;
        return `<path d="M${cx},${cy} L${x0.toFixed(1)},${y0.toFixed(1)} A${r},${r} 0 ${large} 1 ${x1.toFixed(1)},${y1.toFixed(1)} Z" fill="${color}">
            <title>${esc(rows[i].nom)} : ${v} rencontre(s) (${pct}%)</title>
        </path>`;
    }).join('');

    const legende = valeurs.map((v, i) => {
        const color = PALETTE_DEPTS[i % PALETTE_DEPTS.length];
        const pct = Math.round(v / total * 1000) / 10;
        return `<div style="display:flex;align-items:center;gap:.5rem;font-size:.95rem;margin-bottom:.5rem;">
            <span style="width:14px;height:14px;border-radius:3px;background:${color};flex-shrink:0;"></span>
            <span>${esc(rows[i].dept)} — ${esc(rows[i].nom)} : <strong>${v}</strong> (${pct}%)</span>
        </div>`;
    }).join('');

    return `<div style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;">
        <svg viewBox="0 0 280 280" style="width:280px;height:280px;flex-shrink:0;">${slices}</svg>
        <div style="flex:1;min-width:220px;">${legende}</div>
    </div>`;
}

const couleurJournee = idx => PALETTE_JOURNEES[idx % PALETTE_JOURNEES.length];

/** Légende HTML (checkboxes réelles, donc hors SVG) sur 2 lignes : JA/Total, puis une par date de journée. */
function htmlLegendeCombinee(journees, journeesDates, lignesVisibles) {
    const item = (key, color, label) => {
        const checked = lignesVisibles[key] !== false;
        return `<label class="chart-legend-item">
            <input type="checkbox" class="chk-ligne-visible" data-key="${key}" ${checked ? 'checked' : ''}>
            <span class="chart-swatch" style="background:${color}"></span>${esc(label)}
        </label>`;
    };
    const ligne1 = item('ja', COULEUR_JA, 'JA actifs') + item('total', COULEUR_TOTAL, 'Total rencontres');
    const ligne2 = journees.map((j, i) => item('j' + j, couleurJournee(i), journeesDates[j] || `Journée ${j}`)).join('');
    return `<div class="chart-legend-row">${ligne1}</div><div class="chart-legend-row">${ligne2}</div>`;
}

/** Un seul graphe combiné : JA actifs, total rencontres, et une ligne par journée, toutes avec bulles de valeur.
 *  journeeSurbrillance : numéro de journée (string) à mettre en avant — les autres lignes sont atténuées. '' = aucune.
 *  lignesVisibles : { ja, total, j<numéro>: bool } — une ligne décochée n'est pas tracée. */
function svgLignesCombinees(rows, journees, journeesDates, journeeSurbrillance, lignesVisibles) {
    const totalParDept = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_rencontres, 0));
    const dateJournee  = j => journeesDates[j] || `Journée ${j}`;
    const visible = key => lignesVisibles[key] !== false;

    const W = 760, padL = 44, padR = 16, padT = 16, padB = 30;
    const plotW = W - padL - padR;
    const plotH = 280;
    const H     = padT + plotH + padB;
    const n     = rows.length;
    const maxVal = Math.max(1, ...rows.map(r => +r.nb_ja), ...totalParDept, ...journees.flatMap(j => rows.map(r => +r.par_journee[j].nb_rencontres)));
    const xFor   = i => n > 1 ? padL + i * plotW / (n - 1) : padL + plotW / 2;
    const yFor   = v => padT + plotH - (v / maxVal) * plotH;

    function traceLigne(valeurs) {
        const pts = valeurs.map((v, i) => [xFor(i), yFor(v)]);
        return pts.map((p, i) => (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    }

    function ligneAvecBulles(valeurs, color, strokeWidth, titreFn, attenuee) {
        const dots = valeurs.map((v, i) => {
            const x = xFor(i), y = yFor(v);
            const titreTag = titreFn ? `<title>${esc(titreFn(i))}</title>` : '';
            return `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="3" fill="${color}">${titreTag}</circle>`;
        }).join('');
        const bulles = valeurs.map((v, i) => {
            const x = xFor(i), y = yFor(v);
            const val = String(v);
            const bw = Math.max(18, val.length * 7 + 10);
            const bx = Math.min(Math.max(x - bw - 4, padL), W - padR - bw);
            const by = Math.min(Math.max(y - 7, padT), padT + plotH - 14);
            return `<g><rect x="${bx.toFixed(1)}" y="${by}" width="${bw}" height="14" rx="3" fill="${color}"></rect>
                <text x="${(bx + bw / 2).toFixed(1)}" y="${by + 10}" text-anchor="middle" font-size="9" font-weight="700" fill="#fff">${esc(val)}</text></g>`;
        }).join('');
        return `<g opacity="${attenuee ? 0.15 : 1}"><path d="${traceLigne(valeurs)}" fill="none" stroke="${color}" stroke-width="${strokeWidth}"></path>${dots}${bulles}</g>`;
    }

    const surbrillanceActive = journeeSurbrillance !== '' && journeeSurbrillance != null;
    const ligneJa    = visible('ja')    ? ligneAvecBulles(rows.map(r => +r.nb_ja), COULEUR_JA, 2, null, surbrillanceActive) : '';
    const ligneTotal = visible('total') ? ligneAvecBulles(totalParDept, COULEUR_TOTAL, 2.5,
        i => `${rows[i].nom} — Total : ${totalParDept[i]} rencontre(s) sur ${journees.length} journée(s)`, surbrillanceActive) : '';
    const lignesJournees = journees.map((j, idx) => {
        if (!visible('j' + j)) return '';
        const color   = couleurJournee(idx);
        const valeurs = rows.map(r => +r.par_journee[j].nb_rencontres);
        const estSelection = String(j) === String(journeeSurbrillance);
        return ligneAvecBulles(valeurs, color, estSelection ? 3.2 : 1.6, i => {
            const c = rows[i].par_journee[j];
            return `${rows[i].nom} — ${dateJournee(j)} : ${c.nb_rencontres} rencontre(s) (avec arbitre : ${c.nb_avec_arbitre}, sans : ${c.nb_sans_arbitre})`;
        }, surbrillanceActive && !estSelection);
    }).join('');

    const xLabels = rows.map((r, i) => `<text x="${xFor(i).toFixed(1)}" y="${padT + plotH + 16}" text-anchor="middle" font-size="10" fill="#6b7280">${esc(r.dept)}</text>`).join('');
    const xAxisMid = padL + plotW / 2;
    const yAxisMid = padT + plotH / 2;

    return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block;">
        <line x1="${padL}" y1="${padT + plotH}" x2="${W - padR}" y2="${padT + plotH}" stroke="#e5e7eb"></line>
        ${lignesJournees}
        ${ligneTotal}
        ${ligneJa}
        ${xLabels}
        <text x="${xAxisMid.toFixed(1)}" y="${H - 6}" text-anchor="middle" font-size="10" font-weight="600" fill="#374151">Département</text>
        <text x="12" y="${yAxisMid.toFixed(1)}" text-anchor="middle" font-size="10" font-weight="600" fill="#374151" transform="rotate(-90 12 ${yAxisMid.toFixed(1)})">Nombre</text>
    </svg>`;
}

function carte(titre, contenuHtml) {
    return `<div class="chart-card chart-card-wide"><div class="chart-card-title">${esc(titre)}</div>${contenuHtml}</div>`;
}

// Dernières données du graphe départements chargées, pour redessiner sans requête serveur
// quand l'utilisateur change juste la mise en évidence ou la visibilité d'une ligne.
let _deptRows = [], _deptJournees = [], _deptJourneesDates = {}, _deptPhase = null, _deptAnnee = null;
let journeeSurbrillance = '';
let lignesVisibles = { ja: true, total: true }; // clé 'j<numéro>' ajoutée dynamiquement par journée

/** Camembert agrandi des rencontres par département, et un seul graphe combiné pour JA actifs + rencontres par journée. */
function renderDeptChart(rows, journees, journeesDates, phase, annee) {
    if (!rows.length) { $('#chart-dept-grid').html('<div class="text-muted small">Aucune donnée.</div>'); return; }

    const totalParDept = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_rencontres, 0));

    $('#chart-section-title').text(`JA actifs et rencontres par département — Phase ${phase}, saison ${annee}‑${+annee + 1}`);

    const cartes = [
        carte('Rencontres par département (total saison)', svgCamembert(rows, totalParDept)),
        carte('JA actifs et rencontres par journée, par département',
            htmlLegendeCombinee(journees, journeesDates, lignesVisibles) +
            svgLignesCombinees(rows, journees, journeesDates, journeeSurbrillance, lignesVisibles)),
    ];

    $('#chart-dept-grid').html(cartes.join(''));
}

function peuplerSelectSurbrillance(journees, journeesDates) {
    const $sel = $('#sel-journee-surbrillance');
    const valPrecedente = journeeSurbrillance;
    $sel.empty().append('<option value="">Aucune mise en évidence</option>');
    journees.forEach(j => $sel.append(new Option(journeesDates[j] || `Journée ${j}`, j)));
    journeeSurbrillance = journees.map(String).includes(valPrecedente) ? valPrecedente : '';
    $sel.val(journeeSurbrillance);
}

function chargerGraphesDept(phase, annee) {
    $.getJSON(`${BASE}/par-departement`, { phase, annee }).done(r => {
        if (!r.ok || !r.rows.length) { $('#dept-charts').hide(); return; }
        _deptRows = r.rows; _deptJournees = r.journees || []; _deptJourneesDates = r.journees_dates || {};
        _deptPhase = phase; _deptAnnee = annee;
        // Une nouvelle journée (nouvelle saison/phase) démarre visible par défaut.
        _deptJournees.forEach(j => { if (!('j' + j in lignesVisibles)) lignesVisibles['j' + j] = true; });
        peuplerSelectSurbrillance(_deptJournees, _deptJourneesDates);
        renderDeptChart(_deptRows, _deptJournees, _deptJourneesDates, phase, annee);
        $('#dept-charts').show();
    }).fail(() => $('#dept-charts').hide());
}

$('#chart-dept-grid').on('change', '.chk-ligne-visible', function () {
    lignesVisibles[this.dataset.key] = this.checked;
    renderDeptChart(_deptRows, _deptJournees, _deptJourneesDates, _deptPhase, _deptAnnee);
});

$('#sel-journee-surbrillance').on('change', function () {
    journeeSurbrillance = this.value;
    if (_deptRows.length) renderDeptChart(_deptRows, _deptJournees, _deptJourneesDates, _deptPhase, _deptAnnee);
});

function charger() {
    const phase = $('#filtre-phase').val();
    const annee = $('#filtre-annee').val();

    $('#table-wrap, #empty-msg, #dept-charts').hide();
    $('#loading').show();

    chargerGraphesDept(phase, annee);

    $.getJSON(`${BASE}/donnees`, { phase, annee })
        .done(r => {
            $('#loading').hide();
            if (!r.ok) { nijacToast(r.msg || 'Erreur serveur.', 'danger'); return; }
            _rows  = r.rows;
            _cfg   = r.cfg;
            _maxArb = Math.max(1, ..._rows.map(x => +x.nb_arbitrages));

            if (_rows.length === 0) { $('#empty-msg').show(); return; }

            renderTable();

            // Pied de tableau
            const t = r.totaux;
            $('#stats-tfoot').html(`<tr>
                <td colspan="3">Total (${_rows.length} JA)</td>
                <td class="num">${t.nb_arbitrages}</td>
                <td class="num">${parseInt(t.total_km)}</td>
                <td class="num">${fmt2(t.total_peages)}</td>
                <td class="num">${fmt2(t.total_indemnite)}</td>
                <td class="num">${fmt2(t.total_frais)}</td>
            </tr>`);
            $('#table-wrap').show();
        })
        .fail(() => { $('#loading').hide(); nijacToast('Erreur de communication.', 'danger'); });
}

// Tri par colonne
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#stats-table thead th[data-col]', 'col', sortState, renderTable, false);
});

$('#btn-charger').on('click', charger);

$('#filtre-phase, #filtre-annee').on('change', charger);

$('#btn-export-csv').on('click', function () {
    const phase = $('#filtre-phase').val();
    const annee = $('#filtre-annee').val();
    window.open(`${BASE}/export-csv?phase=${encodeURIComponent(phase)}&annee=${encodeURIComponent(annee)}`);
});

// Chargement initial
charger();
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
