<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Statistiques JA (E028)</title>
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

        #toolbar { background: #f8fafc; border-bottom: 1px solid #dde5f0; padding: .4rem 1rem; display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; font-size: .85rem; }

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
        .stats-table tbody tr:nth-child(even) { background: #f4f7fb; }
        .stats-table tbody tr:hover { background: #dbeafe; }
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

        @media print {
            #toolbar, #toolbar-user, #page-footer, .no-print { display: none !important; }
            body { background: #fff; }
            .stats-table thead th { background: #1a3a6b !important; -webkit-print-color-adjust: exact; }
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
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'bar-chart-fill', 'phTitle' => 'Statistiques des Juges-Arbitres', 'phCode' => 'E028',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar utilisateur : recopié de Nominateur/includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Barre de filtres -->
<div id="toolbar">
    <label class="fw-bold" style="font-size:.82rem;">Période :</label>
    <input type="date" id="filtre-debut" class="form-control form-control-sm" style="width:145px;" value="<?= esc($defaultDebut) ?>">
    <span class="text-muted">→</span>
    <input type="date" id="filtre-fin"   class="form-control form-control-sm" style="width:145px;" value="<?= esc($defaultFin) ?>">
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

function charger() {
    const debut = $('#filtre-debut').val();
    const fin   = $('#filtre-fin').val();
    if (!debut || !fin) { nijacToast('Veuillez renseigner les deux dates.', 'warning'); return; }

    $('#table-wrap, #empty-msg').hide();
    $('#loading').show();

    $.getJSON(`${BASE}/donnees`, { debut, fin })
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

$('#filtre-debut, #filtre-fin').on('change', function () {
    if ($('#filtre-debut').val() && $('#filtre-fin').val()) charger();
});

$('#btn-export-csv').on('click', function () {
    const debut = $('#filtre-debut').val();
    const fin   = $('#filtre-fin').val();
    window.open(`${BASE}/export-csv?debut=${encodeURIComponent(debut)}&fin=${encodeURIComponent(fin)}`);
});

// Chargement initial
charger();
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
