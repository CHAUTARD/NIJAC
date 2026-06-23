<?php
/**
 * NIJAC – Demandes de JA pour équipes R3 / R4 (E025)
 *
 * Permet de signaler qu'une équipe R3 ou R4 demande un Juge-Arbitre.
 * Quand JAdemande = 1, toutes les rencontres à domicile de cette équipe
 * passent automatiquement en ArbitrageObligatoire = 1.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-17
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// ── Sécurité : accès admin uniquement ────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) ) {
    header('Location: ../index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Liste des équipes R3/R4 avec filtre département ────────────────
        if ($action === 'liste') {
            $dept = (int)($_GET['dept'] ?? 0);

            $sql = "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.JAdemande,
                           c.Id_Club, c.Nom AS NomClub,
                           d.Division, d.Nom AS NomDivision,
                           FLOOR(c.Id_Club / 10000) % 100 AS Departement
                    FROM equipe e
                    JOIN club c ON c.Id_Club = e.Id_Club
                    JOIN division d ON d.Id_Division = e.Id_Division
                    WHERE e.Id_Division IN (1, 10)";
            $params = [];
            if ($dept > 0) {
                $sql .= " AND FLOOR(c.Id_Club / 10000) % 100 = ?";
                $params[] = $dept;
            }
            $sql .= " ORDER BY d.Ord DESC, c.Nom, e.Nom";

            $rows = $pdo->prepare($sql);
            $rows->execute($params);
            echo json_encode(['ok' => true, 'data' => $rows->fetchAll()]);
            exit;
        }

        // ── Lire les départements actifs depuis la configuration ───────────
        if ($action === 'departements') {
            $stmt = $pdo->query(
                "SELECT valeur FROM configuration WHERE cle = 'departements_actifs'"
            );
            $row   = $stmt->fetch();
            $codes = $row
                ? array_map('intval', array_filter(array_map('trim', explode(',', $row['valeur']))))
                : [];
            if (!$codes) { echo json_encode(['ok' => true, 'data' => []]); exit; }
            sort($codes);
            $ph   = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $pdo->prepare(
                "SELECT CAST(code AS UNSIGNED) AS code, nom
                 FROM departement
                 WHERE CAST(code AS UNSIGNED) IN ($ph)
                 ORDER BY CAST(code AS UNSIGNED)"
            );
            $stmt->execute($codes);
            echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        // ── Basculer JAdemande pour une équipe ────────────────────────────
        if ($action === 'toggle') {
            $idEquipe = (int)($_POST['id_equipe'] ?? 0);
            $valeur   = (int)($_POST['valeur']    ?? 0); // 0 ou 1

            if ($idEquipe <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'Équipe invalide.']);
                exit;
            }

            // Mettre à jour JAdemande sur l'équipe
            $stmt = $pdo->prepare('UPDATE equipe SET JAdemande = ? WHERE Id_Equipe = ?');
            $stmt->execute([$valeur, $idEquipe]);

            // Mettre à jour ArbitrageObligatoire sur les rencontres dom de cette équipe.
            // Si JAdemande=1 → oblige l'arbitrage.
            // Si JAdemande=0 → remet la valeur par défaut de la division.
            if ($valeur === 1) {
                $stmt = $pdo->prepare(
                    'UPDATE rencontre SET ArbitrageObligatoire = 1
                     WHERE Id_EquipeDom = ?'
                );
                $stmt->execute([$idEquipe]);
            } else {
                // Remet ArbitrageObligatoire selon la valeur de la division
                $stmt = $pdo->prepare(
                    'UPDATE rencontre r
                     JOIN equipe e ON e.Id_Equipe = r.Id_EquipeDom
                     JOIN division d ON d.Id_Division = e.Id_Division
                     SET r.ArbitrageObligatoire = d.ArbitrageObligatoire
                     WHERE r.Id_EquipeDom = ?'
                );
                $stmt->execute([$idEquipe]);
            }

            $nbRenc = $stmt->rowCount();
            $msg = $valeur
                ? "JA demandé – $nbRenc rencontre(s) mise(s) en arbitrage obligatoire."
                : "Demande annulée – $nbRenc rencontre(s) remise(s) à la valeur de la division.";

            echo json_encode(['ok' => true, 'msg' => $msg]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] JA_R3R4.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ───────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$isAdmin     = !empty($moi['is_admin']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Demandes JA R3/R4</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; --r34-color: #ede7f6; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* ── Barre de filtres ── */
        #filter-bar {
            background: var(--r34-color);
            border-bottom: 2px solid #c5b0e8;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        #filter-bar label { font-size: .85rem; font-weight: 700; margin-bottom: 0; }
        #filter-bar select { font-size: .85rem; width: auto; }
        #txt-recherche { font-size: .85rem; width: 220px; }

        /* ── Zone principale ── */
        #main-area {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.25rem;
        }

        /* ── Table ── */
        #tbl-equipes {
            width: 100%;
            font-size: .88rem;
            border-collapse: collapse;
        }

        #tbl-equipes thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .4rem .6rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            cursor: pointer;
            user-select: none;
        }
        #tbl-equipes thead th:hover { background: #d8e4f4; }
        #tbl-equipes thead th.sort-asc  .sort-icon::after { content: ' ▲'; font-size: .7rem; color: var(--nijac-blue); }
        #tbl-equipes thead th.sort-desc .sort-icon::after { content: ' ▼'; font-size: .7rem; color: var(--nijac-blue); }
        #tbl-equipes thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: ' ⇅'; font-size: .65rem; color: #aaa; }

        #tbl-equipes tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-equipes tbody tr:hover { background: #f3effe; }
        #tbl-equipes tbody td { padding: .35rem .6rem; vertical-align: middle; }

        tr.ja-demande td { background: #e8f5e9 !important; }
        tr.ja-demande:hover td { background: #d4edda !important; }

        .badge-r3  { background: #fff; border: 1px solid #999; color: #333; }
        .badge-r4  { background: #D3D3D3; border: 1px solid #999; color: #333; }
        .badge-dept { background: #5c6bc0; color: #fff; font-size: .75rem; }

        /* ── Toast ── */
        #toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1100;
        }

        .toast-msg {
            background: #1a3a6b;
            color: #fff;
            padding: .6rem 1.1rem;
            border-radius: 6px;
            font-size: .85rem;
            margin-top: .4rem;
            animation: fadeIn .2s;
        }
        .toast-msg.ok  { background: #2e7d32; }
        .toast-msg.err { background: #c62828; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; } }

        /* ── Spinner ── */
        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,.45);
            z-index: 900;
            align-items: center;
            justify-content: center;
        }
        #spinner.visible { display: flex; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-person-badge'; $pageTitle = 'Demandes JA pour équipes R3 / R4'; $pageCode = 'E025'; $backUrl = $isAdmin ? '../admin_menu.php' : 'menu.php'; $backBtnClass = 'ms-auto btn btn-sm btn-outline-light'; require __DIR__ . '/../includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Barre de filtres -->
<div id="filter-bar">
    <label for="sel-dept"><i class="bi bi-geo-alt me-1"></i>Département :</label>
    <select id="sel-dept" class="form-select form-select-sm">
        <option value="0">— Tous —</option>
    </select>

    <label for="sel-division" class="ms-3">Division :</label>
    <select id="sel-division" class="form-select form-select-sm">
        <option value="">— Toutes —</option>
        <option value="R3M">R3</option>
        <option value="R4M">R4</option>
    </select>

    <label for="txt-recherche" class="ms-3"><i class="bi bi-search me-1"></i>Recherche :</label>
    <input type="search" id="txt-recherche" class="form-control form-control-sm"
           placeholder="Club ou équipe…" autocomplete="off">

    <span id="lbl-count" class="ms-3 text-muted" style="font-size:.82rem;"></span>

    <div class="ms-auto d-flex align-items-center gap-2">
        <span style="font-size:.8rem; color:#555; display:flex; align-items:center; gap:.35rem;">
            <span style="display:inline-block;width:14px;height:14px;background:#e8f5e9;border:2px solid #2e7d32;border-radius:2px;flex-shrink:0;"></span>
            Vert = JA demandé
        </span>
    </div>
</div>

<!-- Tableau principal -->
<div id="main-area">
    <table id="tbl-equipes">
        <thead>
            <tr>
                <th style="width:3rem;"   data-col="0">Dépt<span class="sort-icon"></span></th>
                <th                       data-col="1">Club<span class="sort-icon"></span></th>
                <th                       data-col="2">Équipe<span class="sort-icon"></span></th>
                <th style="width:5rem;"   data-col="3">Division<span class="sort-icon"></span></th>
                <th style="width:8rem; text-align:center;" data-col="4">JA demandé<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-equipes">
            <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Spinner -->
<div id="spinner"><div class="spinner-border text-primary"></div></div>

<!-- Toast -->
<div id="toast-container"></div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = `toast-msg ${type}`;
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

function spin(on) {
    document.getElementById('spinner').classList.toggle('visible', on);
}

async function apiGet(params) {
    const qs = new URLSearchParams(params).toString();
    const r  = await fetch('JA_R3R4.php?' + qs);
    return r.json();
}

async function apiPost(data) {
    data._csrf = CSRF;
    const r = await fetch('JA_R3R4.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    return r.json();
}

// ── Données brutes + état de tri ─────────────────────────────────────────────
let tousEquipes = [];
let sortCol = 1;   // colonne active (0=Dépt, 1=Club, 2=Équipe, 3=Division, 4=JAdemande)
let sortAsc = true;

// ── Charger les départements disponibles ─────────────────────────────────────
async function chargerDepartements() {
    const res = await apiGet({ action: 'departements' });
    if (!res.ok) return;
    const sel = document.getElementById('sel-dept');
    res.data.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.code;
        opt.textContent = d.code + ' — ' + d.nom;
        sel.appendChild(opt);
    });
}

// ── Charger la liste des équipes ──────────────────────────────────────────────
async function chargerListe() {
    spin(true);
    const dept = document.getElementById('sel-dept').value;
    const res  = await apiGet({ action: 'liste', dept });
    spin(false);
    if (!res.ok) { toast(res.msg, 'err'); return; }
    tousEquipes = res.data;
    filtrerEtAfficher();
}

// ── Valeur de tri pour une colonne ───────────────────────────────────────────
function valTri(e, col) {
    switch (col) {
        case 0: return +e.Departement;
        case 1: return (e.NomClub   || '').toLowerCase();
        case 2: return (e.NomEquipe || '').toLowerCase();
        case 3: return (e.Division  || '').toLowerCase();
        case 4: return +e.JAdemande;
        default: return '';
    }
}

// ── Filtrer et afficher ───────────────────────────────────────────────────────
function filtrerEtAfficher() {
    const divFilter  = document.getElementById('sel-division').value;
    const recherche  = document.getElementById('txt-recherche').value.trim().toLowerCase();

    const data = tousEquipes.filter(e => {
        if (divFilter && e.Division !== divFilter) return false;
        if (recherche) {
            const inClub   = e.NomClub.toLowerCase().includes(recherche);
            const inEquipe = e.NomEquipe.toLowerCase().includes(recherche);
            if (!inClub && !inEquipe) return false;
        }
        return true;
    });

    // Tri
    data.sort((a, b) => {
        const va = valTri(a, sortCol), vb = valTri(b, sortCol);
        const cmp = va < vb ? -1 : va > vb ? 1 : 0;
        return sortAsc ? cmp : -cmp;
    });

    // Indicateurs visuels dans les en-têtes
    document.querySelectorAll('#tbl-equipes thead th').forEach(th => {
        const col = +th.dataset.col;
        th.classList.toggle('sort-asc',  col === sortCol &&  sortAsc);
        th.classList.toggle('sort-desc', col === sortCol && !sortAsc);
    });

    const tbody = document.getElementById('tbody-equipes');
    tbody.innerHTML = '';

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Aucune équipe trouvée.</td></tr>';
        document.getElementById('lbl-count').textContent = '';
        return;
    }

    const nbJA = data.filter(e => +e.JAdemande === 1).length;
    document.getElementById('lbl-count').textContent =
        `${data.length} équipe(s) — dont ${nbJA} avec JA demandé`;

    data.forEach(e => {
        const tr = document.createElement('tr');
        tr.dataset.id = e.Id_Equipe;
        if (+e.JAdemande === 1) tr.classList.add('ja-demande');

        const badgeCls = e.Division === 'R3M' ? 'badge-r3' : 'badge-r4';
        const checked  = +e.JAdemande === 1 ? 'checked' : '';

        tr.innerHTML = `
            <td><span class="badge badge-dept">${e.Departement}</span></td>
            <td>${escHtml(e.NomClub)}</td>
            <td>${escHtml(e.NomEquipe)}</td>
            <td><span class="badge ${badgeCls}">${escHtml(e.Division)}</span></td>
            <td style="text-align:center;">
                <div class="form-check form-switch d-flex justify-content-center mb-0">
                    <input class="form-check-input chk-ja" type="checkbox" role="switch"
                           data-id="${e.Id_Equipe}" ${checked}
                           title="Basculer la demande JA pour ${escHtml(e.NomEquipe)}">
                </div>
            </td>`;
        tbody.appendChild(tr);
    });
}

function escHtml(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Toggle JA demandé ────────────────────────────────────────────────────────
document.getElementById('tbody-equipes').addEventListener('change', async ev => {
    const chk = ev.target.closest('.chk-ja');
    if (!chk) return;

    const idEquipe = +chk.dataset.id;
    const valeur   = chk.checked ? 1 : 0;

    chk.disabled = true;
    const res = await apiPost({ action: 'toggle', id_equipe: idEquipe, valeur });
    chk.disabled = false;

    if (!res.ok) {
        toast(res.msg, 'err');
        chk.checked = !chk.checked; // annuler le changement visuel
        return;
    }

    // Mettre à jour le cache local
    const eq = tousEquipes.find(e => +e.Id_Equipe === idEquipe);
    if (eq) eq.JAdemande = valeur;

    // Mettre à jour la ligne visuellement
    const tr = chk.closest('tr');
    tr.classList.toggle('ja-demande', valeur === 1);

    // Rafraîchir le compteur
    const divFilter = document.getElementById('sel-division').value;
    const data = divFilter ? tousEquipes.filter(e => e.Division === divFilter) : tousEquipes;
    const nbJA = data.filter(e => +e.JAdemande === 1).length;
    document.getElementById('lbl-count').textContent =
        `${data.length} équipe(s) — dont ${nbJA} avec JA demandé`;

    toast(res.msg, 'ok');
});

// ── Tri par clic sur en-tête ─────────────────────────────────────────────────
document.querySelectorAll('#tbl-equipes thead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
        const col = +th.dataset.col;
        if (sortCol === col) { sortAsc = !sortAsc; }
        else { sortCol = col; sortAsc = true; }
        filtrerEtAfficher();
    });
});

// ── Filtres ──────────────────────────────────────────────────────────────────
document.getElementById('sel-dept').addEventListener('change', chargerListe);
document.getElementById('sel-division').addEventListener('change', filtrerEtAfficher);
document.getElementById('txt-recherche').addEventListener('input', filtrerEtAfficher);

// ── Init ─────────────────────────────────────────────────────────────────────
(async () => {
    await chargerDepartements();
    await chargerListe();
})();
</script>
</body>
</html>
