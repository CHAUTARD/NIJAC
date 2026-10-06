<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Statistiques CRA (EC75)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
    <style>
        /* Même habillage qu'EC71 (EN11 / nijac-skin.css, en-tête sarcelle E009). */
        #page-header { background: #00695c; }
        #panel-liste { width: 100%; border-right: 0; }
        #liste-header {
            --strip-bg: var(--en-bg);
            background: var(--en-bg); color: inherit; font-weight: 400;
            padding: .4rem 1.4rem 1rem; gap: .55rem; flex-wrap: wrap;
        }
        #liste-header .combo-field > select {
            font-size: .85rem; padding: .42rem 2rem .42rem .8rem;
            border: 1.5px solid #d3dae6; border-radius: 12px; max-width: 26rem;
        }
        #liste-header #txt-recherche {
            width: 290px; max-width: 100%; font-size: .85rem; padding: .32rem .9rem;
            border-radius: 999px; background: var(--en-card);
            border: 1px solid var(--en-line); box-shadow: 0 1px 2px rgba(16,24,40,.04);
        }
        /* Pastille compteur centrée (voir EC71 : nijac-liste-edit.css force inline-block). */
        #lbl-count { display: inline-flex; align-items: center; justify-content: center; min-width: 2.6rem; height: 1.9rem; line-height: 1; text-align: center; padding: 0 .9rem; font-size: .82rem; }
        #liste-titre { flex-basis: 100%; margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--en-text, inherit); }
        #chart-wrap { margin: 0 1.4rem 1rem; padding: .8rem 1rem; background: var(--en-card); border: 1px solid var(--en-line); border-radius: var(--en-radius); }
        #chart-wrap h2 { font-size: .9rem; font-weight: 700; margin: 0 0 .5rem; }
        #chart-box { position: relative; height: 260px; }

        #table-wrapper {
            overflow: auto; background: var(--en-card); margin: 0 1.4rem 1rem;
            border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06);
        }
        #table-wrapper table { font-size: .82rem; }
        #table-wrapper table thead th {
            background: var(--en-card); border: 0; border-bottom: 2px solid var(--en-line);
            color: var(--en-muted); font-size: .68rem; font-weight: 700;
            letter-spacing: .5px; text-transform: uppercase; padding: .7rem .6rem; text-align: left;
        }
        #table-wrapper table thead th[data-col]:hover { background: #f6f8fb; }
        #table-wrapper table thead th.th-num { text-align: center; white-space: nowrap; }
        #table-wrapper td.td-num { text-align: center; }
        #table-wrapper td.nowrap { white-space: nowrap; }
        #table-wrapper table tbody tr { border-bottom: 1px solid #f2f3f6; }
        #table-wrapper table tbody tr:nth-child(odd) { background: #f7f8fa; }
        #table-wrapper table tbody tr:nth-child(even) { background: transparent; }
        #table-wrapper table tbody tr:hover { background: #E9ECEF; }
        #table-wrapper table tbody td, #table-wrapper table tfoot td { border: 0; padding: .55rem .6rem; }
        #table-wrapper table tfoot td { border-top: 2px solid var(--en-line); font-weight: 700; background: var(--en-card); }
        #table-wrapper td.td-total { font-weight: 700; }
        .dispo-detail { display: block; font-size: .7rem; color: var(--en-muted); }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'bar-chart-line', 'phTitle' => 'Statistiques CRA', 'phCode' => 'EC75',
    'phCrumbLabel' => 'CRA Convoc', 'phCrumbUrl' => site_url('cra-convoc-menu'), 'phBackUrl' => site_url('cra-convoc-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="liste-header">
            <h1 id="liste-titre">Statistique nomination</h1>
            <span id="lbl-count" class="count-badge" title="Nombre de JA affichés">0</span>
            <span class="combo-field ms-auto">
                <label for="sel-dept">Département</label>
                <select id="sel-dept"><option value="">Tous les départements</option></select>
            </span>
            <span class="combo-field">
                <label for="txt-recherche">Recherche</label>
                <input type="search" id="txt-recherche" placeholder="Rechercher (nom, prénom)…">
            </span>
        </div>
        <div id="chart-wrap">
            <h2>Compétitions : JA prévus et JA convoqués</h2>
            <div id="chart-box"><canvas id="chart-compet"></canvas></div>
        </div>
        <div id="table-wrapper">
            <table id="tbl-stats">
                <thead>
                    <tr>
                        <th data-col="NomComplet">NOM Prénom<span class="sort-icon"></span></th>
                        <th data-col="Grades">Grades<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="CodeDept">Dépt<span class="sort-icon"></span></th>
                        <th data-col="NomClub">Club<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NbDispo" title="Nombre de compétitions avec la réponse « Disponible » (détail : à confirmer / sous condition)">Disponible<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NbJA" title="Désignations comme JA principal, toutes compétitions">Nominations principal<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NbAdj" title="Désignations comme adjoint, toutes compétitions">Nominations adjoint<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="Total">Total<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NonUtilisees" title="Disponible − Total (minimum 0)">Dispos non utilisées<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="9" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end">Totaux</td>
                        <td class="td-num" id="tot-ja">0</td>
                        <td class="td-num" id="tot-adj">0</td>
                        <td class="td-num" id="tot-total">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/chart.umd.min.js') ?>"></script>
<script>
'use strict';
const STATS_URL = '<?= site_url('cra-stats/data') ?>';
const GRADES = ['JA1', 'JA2', 'JA3', 'JAN', 'JAI'];
const NUM_COLS = ['NbDispo', 'NbJA', 'NbAdj', 'Total', 'NonUtilisees'];
const COMPETITIONS = <?= json_encode($competitions, JSON_NUMERIC_CHECK) ?>;
let jas = [];
// Par défaut : les JA les moins nommés en haut (Total croissant, puis nom).
const sortState = { col: 'Total', asc: true };

function afficher() {
    const q = $('#txt-recherche').val().trim().toLowerCase();
    const dept = $('#sel-dept').val();
    const col = sortState.col;
    const rows = jas
        .filter(j => (!q || j.NomComplet.toLowerCase().includes(q)) && (!dept || j.CodeDept === dept))
        .sort((a, b) => {
            const cmp = NUM_COLS.includes(col) ? a[col] - b[col]
                : String(a[col] ?? '').localeCompare(String(b[col] ?? ''), 'fr');
            return (sortState.asc ? cmp : -cmp) || a.NomComplet.localeCompare(b.NomComplet, 'fr');
        });

    $('#lbl-count').text(rows.length);
    $('#tot-ja').text(rows.reduce((s, j) => s + j.NbJA, 0));
    $('#tot-adj').text(rows.reduce((s, j) => s + j.NbAdj, 0));
    $('#tot-total').text(rows.reduce((s, j) => s + j.Total, 0));
    const $body = $('#tbody-liste').empty();
    if (!rows.length) {
        $body.append('<tr><td colspan="9" class="text-center text-muted py-3">Aucun JA disponible.</td></tr>');
        return;
    }
    rows.forEach(j => {
        const detail = [j.NbAConfirmer ? `${j.NbAConfirmer} à confirmer` : '', j.NbSousCondition ? `${j.NbSousCondition} sous condition` : '']
            .filter(Boolean).join(', ');
        $('<tr>').attr('data-id', j.Id_JA).append(
            $('<td class="nowrap">').append($('<strong>').text(j.Nom.toUpperCase()), document.createTextNode(' ' + j.Prenom)),
            $('<td class="nowrap">').text(j.Grades),
            $('<td class="td-num">').text(j.CodeDept ?? ''),
            $('<td>').text(j.NomClub ?? ''),
            $('<td class="td-num">').attr('title', detail).append(document.createTextNode(j.NbDispo),
                detail ? $('<span class="dispo-detail">').text(detail) : ''),
            $('<td class="td-num">').text(j.NbJA),
            $('<td class="td-num">').text(j.NbAdj),
            $('<td class="td-num td-total">').text(j.Total),
            $('<td class="td-num">').text(j.NonUtilisees)
        ).appendTo($body);
    });
}

function chargerListe() {
    $.get(STATS_URL, function (res) {
        if (!res || !res.ok) { echecChargement(res && res.msg); return; }
        jas = res.data.map(j => {
            const n = k => Number(j[k]) || 0;
            const r = {
                ...j, NbDispo: n('NbDispo'), NbAConfirmer: n('NbAConfirmer'), NbSousCondition: n('NbSousCondition'),
                NbJA: n('NbJA'), NbAdj: n('NbAdj'),
                Nom: j.Nom || '', Prenom: j.Prenom || '',
                NomComplet: `${(j.Nom || '').toUpperCase()} ${j.Prenom || ''}`,
                Grades: GRADES.filter(g => Number(j[g]) === 1).join(' '),
            };
            r.Total = r.NbJA + r.NbAdj;
            r.NonUtilisees = Math.max(0, r.NbDispo - r.Total);
            return r;
        });
        const depts = [...new Set(jas.map(j => j.CodeDept).filter(Boolean))].sort();
        $('#sel-dept').html('<option value="">Tous les départements</option>' + depts.map(d => `<option>${d}</option>`).join(''));
        afficher();
    }, 'json').fail(() => echecChargement());
}

/** Session expirée (redirection HTML vers login) ou erreur serveur. */
function echecChargement(msg) {
    jas = [];
    afficher();
    $('#tbody-liste').html('<tr><td colspan="9" class="text-center text-danger py-3"></td></tr>')
        .find('td').text(msg || 'Impossible de charger les statistiques (session expirée ?) : rechargez la page.');
    nijacToast(msg || 'Statistiques non chargées : session expirée ou erreur serveur, rechargez la page.', 'danger', 8000);
}

$('#txt-recherche').on('input', afficher);
$('#sel-dept').on('change', afficher);

// Différé : nijac-sortable-table.js / nijac-toast.js sont chargés après ce script.
$(function () {
    nijacSortableTable('#tbl-stats thead th[data-col]', 'col', sortState, afficher);
    chargerListe();
    new Chart('chart-compet', {
        type: 'bar',
        data: {
            labels: COMPETITIONS.map(c => 'n°' + c.Numero),
            datasets: [
                { label: 'JA prévus', data: COMPETITIONS.map(c => c.Prevus), backgroundColor: '#94a3b8' },
                { label: 'JA convoqués', data: COMPETITIONS.map(c => c.Convoques), backgroundColor: '#00695c' },
            ],
        },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' } } },
    });
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
