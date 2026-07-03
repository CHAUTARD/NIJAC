<?php
/**
 * NIJAC – Statistiques des Juges-Arbitres (E028)
 *
 * Récapitulatif par JA : nombre d'arbitrages, kilomètres, péages,
 * indemnités forfaitaires et total frais sur une période choisie.
 * Export CSV disponible.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-26
 */
session_start();
$authRedirect = '../index.php';
require __DIR__ . '/../includes/auth_required.php';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/csrf.php';

$u       = $_SESSION['utilisateur'];
$isAdmin = !empty($u['is_admin']);
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

// ── API AJAX ──────────────────────────────────────────────────────────────────
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo  = getPDO();
        $depts = getDepartementsAutorises($u['id_departement'] ?? null);

        $dateDebut = $_GET['debut'] ?? $_POST['debut'] ?? date('Y-09-01');
        $dateFin   = $_GET['fin']   ?? $_POST['fin']   ?? date('Y-m-d');

        // ── Données statistiques ──────────────────────────────────────────────
        if ($action === 'donnees') {
            $indemniteForfait = (float)getConfig('indemnite_forfaitaire', '25.00');
            $tauxKm           = (float)getConfig('frais_kilometrique', '0.30');

            $sql = "
                SELECT
                    ja.Id_JA,
                    ja.Nom,
                    ja.Prenom,
                    ja.Grade,
                    cl.Nom                             AS Club,
                    COUNT(n.Id_Nomination)             AS nb_arbitrages,
                    COALESCE(SUM(n.Kilometre), 0)      AS total_km,
                    COALESCE(SUM(n.Peage), 0)          AS total_peages,
                    COUNT(n.Id_Nomination) * :indem    AS total_indemnite,
                    COALESCE(SUM(n.Kilometre), 0) * :taux
                        + COALESCE(SUM(n.Peage), 0)
                        + COUNT(n.Id_Nomination) * :indem2 AS total_frais
                FROM ja
                JOIN nomination n    ON n.Valide = 1
                JOIN disponible dn   ON dn.Id_Disponible = n.Id_Disponible AND dn.Id_JA = ja.Id_JA
                JOIN rencontre  r    ON r.Id_Rencontre  = n.Id_Rencontre
                LEFT JOIN Club  cl   ON cl.Id_Club      = ja.Id_Club
                LEFT JOIN salle s    ON s.Id_Club       = cl.Id_Club AND s.EstPrincipale = 1
                LEFT JOIN laposte lp ON lp.Id_LaPoste   = s.Id_Laposte
                WHERE r.Date BETWEEN :debut AND :fin
            ";
            $params = [
                ':indem'  => $indemniteForfait,
                ':taux'   => $tauxKm,
                ':indem2' => $indemniteForfait,
                ':debut'  => $dateDebut,
                ':fin'    => $dateFin,
            ];

            if ($depts) {
                $deptNamed = [];
                foreach ($depts as $i => $d) {
                    $key = ':d' . $i;
                    $deptNamed[]   = $key;
                    $params[$key]  = $d;
                }
                $sql .= ' AND LEFT(lp.CodePostal, 2) IN (' . implode(',', $deptNamed) . ')';
            }

            $sql .= "
                GROUP BY ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade, cl.Nom
                HAVING nb_arbitrages > 0
                ORDER BY nb_arbitrages DESC, ja.Nom, ja.Prenom
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Totaux
            $totaux = [
                'nb_arbitrages'   => array_sum(array_column($rows, 'nb_arbitrages')),
                'total_km'        => array_sum(array_column($rows, 'total_km')),
                'total_peages'    => array_sum(array_column($rows, 'total_peages')),
                'total_indemnite' => array_sum(array_column($rows, 'total_indemnite')),
                'total_frais'     => array_sum(array_column($rows, 'total_frais')),
            ];

            echo json_encode([
                'ok'     => true,
                'rows'   => $rows,
                'totaux' => $totaux,
                'cfg'    => ['indem' => $indemniteForfait, 'taux_km' => $tauxKm],
            ]);
            exit;
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($action === 'export_csv') {
            $indemniteForfait = (float)getConfig('indemnite_forfaitaire', '25.00');
            $tauxKm           = (float)getConfig('frais_kilometrique', '0.30');

            $sql = "
                SELECT
                    ja.Nom, ja.Prenom, ja.Grade, cl.Nom AS Club,
                    COUNT(n.Id_Nomination)             AS Arbitrages,
                    COALESCE(SUM(n.Kilometre), 0)      AS Km,
                    COALESCE(SUM(n.Peage), 0)          AS Peages,
                    COUNT(n.Id_Nomination) * :indem    AS Indemnite,
                    COALESCE(SUM(n.Kilometre), 0) * :taux
                        + COALESCE(SUM(n.Peage), 0)
                        + COUNT(n.Id_Nomination) * :indem2 AS Total
                FROM ja
                JOIN nomination n    ON n.Valide = 1
                JOIN disponible dn   ON dn.Id_Disponible = n.Id_Disponible AND dn.Id_JA = ja.Id_JA
                JOIN rencontre  r    ON r.Id_Rencontre = n.Id_Rencontre
                LEFT JOIN Club  cl   ON cl.Id_Club     = ja.Id_Club
                LEFT JOIN salle s    ON s.Id_Club      = cl.Id_Club AND s.EstPrincipale = 1
                LEFT JOIN laposte lp ON lp.Id_LaPoste  = s.Id_Laposte
                WHERE r.Date BETWEEN :debut AND :fin
            ";
            $params = [
                ':indem'  => $indemniteForfait,
                ':taux'   => $tauxKm,
                ':indem2' => $indemniteForfait,
                ':debut'  => $dateDebut,
                ':fin'    => $dateFin,
            ];
            if ($depts) {
                $deptNamed = [];
                foreach ($depts as $i => $d) {
                    $key = ':d' . $i;
                    $deptNamed[]  = $key;
                    $params[$key] = $d;
                }
                $sql .= ' AND LEFT(lp.CodePostal, 2) IN (' . implode(',', $deptNamed) . ')';
            }
            $sql .= " GROUP BY ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade, cl.Nom HAVING Arbitrages > 0 ORDER BY Arbitrages DESC, ja.Nom";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="stats_ja_' . $dateDebut . '_' . $dateFin . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nom', 'Prénom', 'Grade', 'Club', 'Arbitrages', 'Km', 'Péages (€)', 'Indemnité (€)', 'Total (€)'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['Nom'], $r['Prenom'], $r['Grade'], $r['Club'],
                    $r['Arbitrages'],
                    $r['Km'],
                    number_format($r['Peages'],    2, ',', ''),
                    number_format($r['Indemnite'], 2, ',', ''),
                    number_format($r['Total'],     2, ',', ''),
                ], ';');
            }
            fclose($out);
            exit;
        }

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
// Période par défaut : 1er septembre de l'année en cours → aujourd'hui
$defaultDebut = date('Y') . '-09-01';
$defaultFin   = date('Y-m-d');

// Variables requises par toolbar.php
$nomComplet  = htmlspecialchars(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Statistiques JA (E028)</title>
    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; display: flex; flex-direction: column; min-height: 100vh; }

        #page-header { background: var(--nijac-blue); color: #fff; padding: .65rem 1.25rem; font-size: .9rem; font-weight: 600; }

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
            #toolbar, .no-print { display: none !important; }
            body { background: #fff; }
            .stats-table thead th { background: #1a3a6b !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<?php
    $pageIcon  = 'bi-bar-chart-fill';
    $pageTitle = 'Statistiques des Juges-Arbitres';
    $pageCode  = 'E028';
    $backUrl   = 'menu.php';
    require __DIR__ . '/../includes/page_header.php';
?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Barre de filtres -->
<div id="toolbar">
    <label class="fw-bold" style="font-size:.82rem;">Période :</label>
    <input type="date" id="filtre-debut" class="form-control form-control-sm" style="width:145px;" value="<?= htmlspecialchars($defaultDebut) ?>">
    <span class="text-muted">→</span>
    <input type="date" id="filtre-fin"   class="form-control form-control-sm" style="width:145px;" value="<?= htmlspecialchars($defaultFin) ?>">
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

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

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

    $.getJSON('stats_ja.php', { action: 'donnees', debut, fin })
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
// Différé : nijac-sortable-table.js est chargé en fin de page (includes/footer.php),
// donc pas encore défini si on l'appelait ici de façon synchrone.
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
    window.open(`stats_ja.php?action=export_csv&debut=${encodeURIComponent(debut)}&fin=${encodeURIComponent(fin)}`);
});

// Chargement initial
charger();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
