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
        .chart-section-title { font-weight: 700; color: var(--nijac-blue); font-size: .95rem; margin-bottom: .5rem; }
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        @media (max-width: 700px) { .chart-grid { grid-template-columns: 1fr; } }
        .chart-card { background: #fff; border: 1px solid #e0e8f0; border-radius: 6px; padding: .6rem .8rem; }
        .chart-card-wide { grid-column: 1 / -1; }
        .chart-card-title { font-weight: 600; color: #374151; font-size: .8rem; margin-bottom: .3rem; }
        .chart-canvas-wrap { position: relative; height: 260px; }
        .chart-canvas-wrap-lg { height: 320px; }
        .chart-canvas-wrap-pie { height: 300px; max-width: 420px; margin: 0 auto; }

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
        <div id="chart-section-title" class="chart-section-title"></div>
        <div class="chart-grid">
            <div class="chart-card chart-card-wide">
                <div class="chart-card-title">Rencontres par département (total saison)</div>
                <div class="chart-canvas-wrap chart-canvas-wrap-pie"><canvas id="chart-camembert"></canvas></div>
            </div>
            <div class="chart-card chart-card-wide">
                <div class="chart-card-title">JA actifs et rencontres par journée, par département</div>
                <div class="chart-canvas-wrap chart-canvas-wrap-lg"><canvas id="chart-combine"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-card-title">Taux de couverture (arbitre nommé ou JA du club recevant)</div>
                <div class="chart-canvas-wrap"><canvas id="chart-couverture"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-card-title">Charge par JA (rencontres / JA actif)</div>
                <div class="chart-canvas-wrap"><canvas id="chart-charge"></canvas></div>
            </div>
            <div class="chart-card chart-card-wide">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <div class="chart-card-title mb-0">Rencontres / JA nommé / JA du club par journée</div>
                    <span class="combo-field ms-auto">
                        <label for="sel-dept-barres">Département</label>
                        <select id="sel-dept-barres"></select>
                    </span>
                </div>
                <div class="chart-canvas-wrap"><canvas id="chart-barres"></canvas></div>
            </div>
        </div>
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
<script src="<?= base_url('asset/js/chart.umd.min.js') ?>"></script>
<script>
'use strict';

const BASE = '<?= site_url('stats-ja') ?>';
const DEPT_USER = <?= json_encode($departement) ?>;

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
const couleurJournee = idx => PALETTE_JOURNEES[idx % PALETTE_JOURNEES.length];

const SERIES_BARRES_JOURNEE = [
    { cle: 'nb_rencontres',   label: 'Rencontres',      color: COULEUR_TOTAL },
    { cle: 'nb_avec_arbitre', label: 'JA nommé',        color: COULEUR_JA },
    { cle: 'nb_arbitre_club', label: 'JA du club',      color: '#0d9488' },
    { cle: 'nb_sans_arbitre', label: 'Sans nomination', color: '#f59e0b' },
];

// Dernières données du graphe départements chargées (pour le sélecteur département du graphe en barres).
let _deptRows = [], _deptJournees = [], _deptJourneesDates = {};
let deptBarresSelection = DEPT_USER || ''; // par défaut, le département du nominateur connecté

let chartCamembert = null, chartCombine = null, chartCouverture = null, chartCharge = null, chartBarres = null;

// Petit plugin maison pour afficher le pourcentage au centre de chaque portion :
// pas besoin de vendorer chartjs-plugin-datalabels pour ce seul besoin.
const pluginPourcentages = {
    id: 'pourcentages',
    afterDraw(chart) {
        const meta = chart.getDatasetMeta(0);
        const data = chart.data.datasets[0].data;
        const total = data.reduce((a, b) => a + b, 0) || 1;
        const ctx = chart.ctx;
        ctx.save();
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 11px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        meta.data.forEach((arc, i) => {
            if (!data[i]) return;
            const pct = Math.round(data[i] / total * 1000) / 10;
            const pos = arc.tooltipPosition();
            ctx.fillText(pct + ' %', pos.x, pos.y);
        });
        ctx.restore();
    },
};

// Plugin maison affichant la valeur au-dessus de chaque point (ligne) ou de chaque barre.
// opts.format : mise en forme optionnelle de la valeur affichée (défaut : valeur brute).
const pluginValeurs = {
    id: 'valeurs',
    afterDatasetsDraw(chart, args, opts) {
        const ctx = chart.ctx;
        const format = (opts && opts.format) || (v => v);
        chart.data.datasets.forEach((dataset, di) => {
            const meta = chart.getDatasetMeta(di);
            if (meta.hidden || dataset.hidden) return;
            meta.data.forEach((el, i) => {
                const val = dataset.data[i];
                if (val === null || val === undefined) return;
                const { x, y } = el.getProps(['x', 'y'], true);
                ctx.save();
                ctx.font = 'bold 9px sans-serif';
                ctx.fillStyle = dataset.borderColor || dataset.backgroundColor || '#111827';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(String(format(val)), x, y - 4);
                ctx.restore();
            });
        });
    },
};

/** Camembert du total de rencontres par département, pourcentage affiché dans chaque portion. */
function majChartCamembert(rows, totalParDept) {
    if (chartCamembert) chartCamembert.destroy();
    chartCamembert = new Chart(document.getElementById('chart-camembert'), {
        type: 'pie',
        data: {
            labels: rows.map(r => `${r.dept} — ${r.nom}`),
            datasets: [{ data: totalParDept, backgroundColor: rows.map((r, i) => PALETTE_DEPTS[i % PALETTE_DEPTS.length]) }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: ctx => {
                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0) || 1;
                    const pct = Math.round(ctx.parsed / total * 1000) / 10;
                    return `${ctx.label} : ${ctx.parsed} rencontre(s) (${pct} %)`;
                } } },
            },
        },
        plugins: [pluginPourcentages],
    });
}

/** Graphe combiné : JA actifs, total rencontres, et une ligne par journée. */
function majChartCombine(rows, journees, journeesDates) {
    const totalParDept = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_rencontres, 0));

    const datasets = [
        { label: 'JA actifs', data: rows.map(r => +r.nb_ja), borderColor: COULEUR_JA, backgroundColor: COULEUR_JA, borderWidth: 2, tension: .15, pointRadius: 3 },
        { label: 'Total rencontres', data: totalParDept, borderColor: COULEUR_TOTAL, backgroundColor: COULEUR_TOTAL, borderWidth: 2.5, tension: .15, pointRadius: 3 },
        ...journees.map((j, i) => ({
            label: journeesDates[j] || `Journée ${j}`,
            data: rows.map(r => +r.par_journee[j].nb_rencontres),
            borderColor: couleurJournee(i), backgroundColor: couleurJournee(i), borderWidth: 1.5, tension: .15, pointRadius: 2.5,
            _journee: j,
        })),
    ];

    if (chartCombine) chartCombine.destroy();
    chartCombine = new Chart(document.getElementById('chart-combine'), {
        type: 'line',
        data: { labels: rows.map(r => [String(r.dept), r.nom]), datasets },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'Départements' } },
                y: { beginAtZero: true, title: { display: true, text: 'Nombres' } },
            },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: ctx => {
                    const ds = ctx.dataset;
                    if (ds._journee != null) {
                        const c = rows[ctx.dataIndex].par_journee[ds._journee];
                        return `${ds.label} : ${c.nb_rencontres} (nommé : ${c.nb_avec_arbitre}, JA club : ${c.nb_arbitre_club}, sans : ${c.nb_sans_arbitre})`;
                    }
                    return `${ds.label} : ${ctx.formattedValue}`;
                } } },
                valeurs: {},
            },
        },
        plugins: [pluginValeurs],
    });
}

/** Petit graphe à une seule courbe (une valeur par département). */
function majChartLigneUnique(chartRef, canvasId, rows, valeurs, color, formatY, titreFn) {
    if (chartRef) chartRef.destroy();
    return new Chart(document.getElementById(canvasId), {
        type: 'line',
        data: {
            labels: rows.map(r => [String(r.dept), r.nom]),
            datasets: [{ data: valeurs, borderColor: color, backgroundColor: color, tension: .15 }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => titreFn(ctx.dataIndex) } }, valeurs: { format: formatY } },
            scales: { y: { beginAtZero: true, ticks: { callback: formatY } } },
        },
        plugins: [pluginValeurs],
    });
}

function construireDatasetsBarres(row, journees) {
    return SERIES_BARRES_JOURNEE.map(s => ({ label: s.label, backgroundColor: s.color, data: journees.map(j => +row.par_journee[j][s.cle]) }));
}

/** Barres groupées (rencontres / JA nommé / JA du club) par date de journée, pour le département choisi. */
function majChartBarres(rows, journees, journeesDates) {
    if (!rows.map(r => String(r.dept)).includes(deptBarresSelection)) deptBarresSelection = String(rows[0].dept);
    const row = rows.find(r => String(r.dept) === deptBarresSelection);

    const $sel = $('#sel-dept-barres').empty();
    rows.forEach(r => $sel.append(new Option(`${r.dept} — ${r.nom}`, r.dept, false, String(r.dept) === deptBarresSelection)));

    if (chartBarres) chartBarres.destroy();
    chartBarres = new Chart(document.getElementById('chart-barres'), {
        type: 'bar',
        data: { labels: journees.map(j => journeesDates[j] || `Journée ${j}`), datasets: construireDatasetsBarres(row, journees) },
        options: {
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Nombres' } } },
            plugins: { legend: { position: 'bottom' }, valeurs: {} },
        },
        plugins: [pluginValeurs],
    });
}

/** (Re)dessine les 5 graphes départements à partir des données chargées. */
function renderDeptChart(rows, journees, journeesDates, phase, annee) {
    if (!rows.length) return;

    const totalParDept       = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_rencontres, 0));
    const avecArbitreParDept = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_avec_arbitre, 0));
    const arbitreClubParDept = rows.map(r => journees.reduce((s, j) => s + +r.par_journee[j].nb_arbitre_club, 0));
    // Couvert = arbitre nommé par NIJAC OU JA du club recevant (R3M/R4M, pas de manque réel dans ce cas).
    const couvertParDept = rows.map((r, i) => avecArbitreParDept[i] + arbitreClubParDept[i]);
    const tauxCouverture = rows.map((r, i) => totalParDept[i] > 0 ? Math.round((couvertParDept[i] / totalParDept[i]) * 1000) / 10 : 0);
    const chargeParJa    = rows.map((r, i) => +r.nb_ja > 0 ? Math.round((totalParDept[i] / +r.nb_ja) * 10) / 10 : 0);

    $('#chart-section-title').text(`JA actifs et rencontres par département — Phase ${phase}, saison ${annee}‑${+annee + 1}`);

    majChartCamembert(rows, totalParDept);
    majChartCombine(rows, journees, journeesDates);
    chartCouverture = majChartLigneUnique(chartCouverture, 'chart-couverture', rows, tauxCouverture, '#0d9488', v => v + ' %',
        i => `${rows[i].nom} : ${couvertParDept[i]}/${totalParDept[i]} rencontres couvertes (dont ${arbitreClubParDept[i]} par un JA du club) — ${tauxCouverture[i]} %`);
    chartCharge = majChartLigneUnique(chartCharge, 'chart-charge', rows, chargeParJa, '#7c3aed', v => v,
        i => `${rows[i].nom} : ${totalParDept[i]} rencontre(s) pour ${rows[i].nb_ja} JA actif(s)`);
    majChartBarres(rows, journees, journeesDates);
}

function chargerGraphesDept(phase, annee) {
    $.getJSON(`${BASE}/par-departement`, { phase, annee }).done(r => {
        if (!r.ok || !r.rows.length) { $('#dept-charts').hide(); return; }
        _deptRows = r.rows; _deptJournees = r.journees || []; _deptJourneesDates = r.journees_dates || {};
        renderDeptChart(_deptRows, _deptJournees, _deptJourneesDates, phase, annee);
        $('#dept-charts').show();
    }).fail(() => $('#dept-charts').hide());
}

$('#sel-dept-barres').on('change', function () {
    deptBarresSelection = this.value;
    const row = _deptRows.find(r => String(r.dept) === deptBarresSelection);
    if (row && chartBarres) { chartBarres.data.datasets = construireDatasetsBarres(row, _deptJournees); chartBarres.update(); }
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
