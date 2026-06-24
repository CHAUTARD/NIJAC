<?php
/**
 * NIJAC – Administration base de données (E099)
 *
 * Interface d'administration intégrée :
 *   - Navigation dans les tables
 *   - Structure des tables (DESCRIBE + ALTER TABLE)
 *   - CRUD complet (Browse / Insert / Edit / Delete)
 *   - Requêteur SQL libre avec affichage tabulaire des résultats
 *
 * Accès réservé aux administrateurs NIJAC.
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Liste des tables ──────────────────────────────────────────────────
        if ($action === 'tables') {
            $rows = $pdo->query("SHOW TABLE STATUS")->fetchAll();
            $tables = array_map(fn($r) => [
                'name'    => $r['Name'],
                'rows'    => $r['Rows'],
                'engine'  => $r['Engine'],
                'size'    => ($r['Data_length'] + $r['Index_length']),
                'comment' => $r['Comment'],
            ], $rows);
            echo json_encode(['ok' => true, 'tables' => $tables]);
            exit;
        }

        // ── Structure d'une table ─────────────────────────────────────────────
        if ($action === 'describe') {
            $table = $_GET['table'] ?? $_POST['table'] ?? '';
            if (!preg_match('/^\w+$/', $table)) {
                echo json_encode(['ok' => false, 'msg' => 'Nom de table invalide.']);
                exit;
            }
            $cols  = $pdo->query("DESCRIBE `$table`")->fetchAll();
            $idxRaw = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll();
            $indexes = [];
            foreach ($idxRaw as $idx) {
                $k = $idx['Key_name'];
                $indexes[$k]['name']    = $k;
                $indexes[$k]['unique']  = !$idx['Non_unique'];
                $indexes[$k]['columns'][] = $idx['Column_name'];
            }
            echo json_encode(['ok' => true, 'columns' => $cols, 'indexes' => array_values($indexes)]);
            exit;
        }

        // ── Parcourir les données ─────────────────────────────────────────────
        if ($action === 'browse') {
            $table  = $_GET['table'] ?? '';
            $page   = max(0, (int)($_GET['page'] ?? 0));
            $limit  = max(1, min(500, (int)($_GET['limit'] ?? 50)));
            $search = trim($_GET['search'] ?? '');
            $orderCol = $_GET['order'] ?? '';
            $orderDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

            if (!preg_match('/^\w+$/', $table)) {
                echo json_encode(['ok' => false, 'msg' => 'Nom de table invalide.']);
                exit;
            }
            // Récupère les colonnes pour la recherche
            $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);

            $where = '';
            $params = [];
            if ($search !== '') {
                $parts = array_map(fn($c) => "`$c` LIKE ?", $cols);
                $where = 'WHERE ' . implode(' OR ', $parts);
                $params = array_fill(0, count($cols), "%$search%");
            }

            $orderClause = '';
            if ($orderCol && in_array($orderCol, $cols)) {
                $orderClause = "ORDER BY `$orderCol` $orderDir";
            }

            $countSql = "SELECT COUNT(*) FROM `$table` $where";
            $total = (int)$pdo->prepare($countSql)->execute($params) ? $pdo->prepare($countSql)->execute($params) : 0;
            $stmtC = $pdo->prepare($countSql);
            $stmtC->execute($params);
            $total = (int)$stmtC->fetchColumn();

            $offset = $page * $limit;
            $sql    = "SELECT * FROM `$table` $where $orderClause LIMIT $limit OFFSET $offset";
            $stmt   = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            echo json_encode([
                'ok'    => true,
                'rows'  => $rows,
                'total' => $total,
                'cols'  => $cols,
                'page'  => $page,
                'limit' => $limit,
            ]);
            exit;
        }

        // ── Lire une ligne (pour édition) ─────────────────────────────────────
        if ($action === 'get_row') {
            $table = $_GET['table'] ?? '';
            $pk    = $_GET['pk']    ?? '';
            $pkVal = $_GET['pkval'] ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk)) {
                echo json_encode(['ok' => false, 'msg' => 'Paramètre invalide.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = ? LIMIT 1");
            $stmt->execute([$pkVal]);
            $row = $stmt->fetch();
            if (!$row) {
                echo json_encode(['ok' => false, 'msg' => 'Ligne introuvable.']);
                exit;
            }
            echo json_encode(['ok' => true, 'row' => $row]);
            exit;
        }

        // ── Insérer une ligne ─────────────────────────────────────────────────
        if ($action === 'insert') {
            $table = $_POST['table'] ?? '';
            $data  = $_POST['data']  ?? [];

            if (!preg_match('/^\w+$/', $table) || !is_array($data) || empty($data)) {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }
            // Filtrer les clés de colonnes
            $validCols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $filtered  = array_filter($data, fn($k) => in_array($k, $validCols), ARRAY_FILTER_USE_KEY);
            if (empty($filtered)) {
                echo json_encode(['ok' => false, 'msg' => 'Aucune colonne valide.']);
                exit;
            }
            $cols  = array_keys($filtered);
            $vals  = array_values($filtered);
            $sql   = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
            $pdo->prepare($sql)->execute($vals);
            echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        }

        // ── Modifier une ligne ────────────────────────────────────────────────
        if ($action === 'update') {
            $table = $_POST['table'] ?? '';
            $pk    = $_POST['pk']    ?? '';
            $pkVal = $_POST['pkval'] ?? '';
            $data  = $_POST['data']  ?? [];

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk) || !is_array($data)) {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }
            $validCols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $filtered  = array_filter($data, fn($k) => in_array($k, $validCols) && $k !== $pk, ARRAY_FILTER_USE_KEY);
            if (empty($filtered)) {
                echo json_encode(['ok' => false, 'msg' => 'Aucune colonne à modifier.']);
                exit;
            }
            $setParts = array_map(fn($c) => "`$c` = ?", array_keys($filtered));
            $vals     = array_values($filtered);
            $vals[]   = $pkVal;
            $sql      = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE `$pk` = ?";
            $pdo->prepare($sql)->execute($vals);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Supprimer une ligne ───────────────────────────────────────────────
        if ($action === 'delete') {
            $table = $_POST['table'] ?? '';
            $pk    = $_POST['pk']    ?? '';
            $pkVal = $_POST['pkval'] ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk)) {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }
            $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$pkVal]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Requêteur SQL libre ───────────────────────────────────────────────
        if ($action === 'sql') {
            $sql = trim($_POST['sql'] ?? '');
            if ($sql === '') {
                echo json_encode(['ok' => false, 'msg' => 'Requête vide.']);
                exit;
            }

            $t0   = microtime(true);
            $stmt = $pdo->query($sql);
            $ms   = round((microtime(true) - $t0) * 1000, 2);

            if ($stmt === false) {
                echo json_encode(['ok' => false, 'msg' => 'Erreur lors de l\'exécution.']);
                exit;
            }

            $type = strtoupper(strtok(ltrim($sql), " \t\n"));
            if (in_array($type, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'])) {
                $rows = $stmt->fetchAll();
                $cols = $rows ? array_keys($rows[0]) : [];
                echo json_encode(['ok' => true, 'type' => 'select', 'cols' => $cols, 'rows' => $rows, 'ms' => $ms]);
            } else {
                $affected = $stmt->rowCount();
                echo json_encode(['ok' => true, 'type' => 'write', 'affected' => $affected, 'ms' => $ms]);
            }
            exit;
        }

        // ── Ajouter une colonne ───────────────────────────────────────────────
        if ($action === 'add_column') {
            $table   = $_POST['table']   ?? '';
            $colName = $_POST['colname'] ?? '';
            $colType = $_POST['coltype'] ?? '';
            $nullable= ($_POST['nullable'] ?? '0') === '1';
            $default = $_POST['default']  ?? null;
            $after   = $_POST['after']    ?? '';
            $comment = $_POST['comment']  ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName) || trim($colType) === '') {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }

            $def = "`$colName` $colType";
            $def .= $nullable ? ' NULL' : ' NOT NULL';
            if ($default !== null && $default !== '') {
                $def .= " DEFAULT " . $pdo->quote($default);
            }
            if ($comment !== '') {
                $def .= " COMMENT " . $pdo->quote($comment);
            }
            $afterClause = preg_match('/^\w+$/', $after) ? "AFTER `$after`" : '';
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $def $afterClause");
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Modifier une colonne ──────────────────────────────────────────────
        if ($action === 'modify_column') {
            $table   = $_POST['table']   ?? '';
            $colName = $_POST['colname'] ?? '';
            $colType = $_POST['coltype'] ?? '';
            $nullable= ($_POST['nullable'] ?? '0') === '1';
            $default = $_POST['default']  ?? null;
            $comment = $_POST['comment']  ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName) || trim($colType) === '') {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }

            $def = "`$colName` $colType";
            $def .= $nullable ? ' NULL' : ' NOT NULL';
            if ($default !== null && $default !== '') {
                $def .= " DEFAULT " . $pdo->quote($default);
            }
            if ($comment !== '') {
                $def .= " COMMENT " . $pdo->quote($comment);
            }
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN $def");
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Supprimer une colonne ─────────────────────────────────────────────
        if ($action === 'drop_column') {
            $table   = $_POST['table']   ?? '';
            $colName = $_POST['colname'] ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName)) {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
                exit;
            }
            $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$colName`");
            echo json_encode(['ok' => true]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$u        = $_SESSION['utilisateur'];
$nomComplet = htmlspecialchars(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<title>NIJAC – Administration BDD (E099)</title>
<link rel="stylesheet" href="asset/css/bootstrap.min.css">
<link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
<style>
:root { --nijac-blue: #1a3a6b; }

body {
    background: #f0f4fa;
    font-family: 'Segoe UI', system-ui, sans-serif;
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Toolbar ── */
#toolbar {
    background: #f8fafc;
    border-bottom: 1px solid #dde5f0;
    padding: .3rem 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: .85rem;
    flex-shrink: 0;
}
#toolbar .ts-user { color: var(--nijac-blue); font-weight: 600; }

/* ── Page header ── */
#page-header {
    background: var(--nijac-blue);
    color: #fff;
    padding: .65rem 1.25rem;
    font-size: .9rem;
    font-weight: 600;
    flex-shrink: 0;
}
#page-header a { color: #93c5fd; font-weight: 400; }

/* ── Layout principal ── */
#main-layout {
    display: flex;
    flex: 1;
    overflow: hidden;
    gap: 0;
}

/* ── Sidebar tables ── */
#sidebar {
    width: 220px;
    min-width: 180px;
    max-width: 280px;
    background: #fff;
    border-right: 1px solid #dde5f0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
    resize: horizontal;
}
#sidebar-header {
    background: #1a3a6b;
    color: #fff;
    padding: .5rem .75rem;
    font-size: .8rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
#table-search {
    padding: .4rem .5rem;
    border-bottom: 1px solid #eee;
}
#table-search input {
    width: 100%;
    font-size: .8rem;
    padding: .2rem .4rem;
    border: 1px solid #ccc;
    border-radius: 4px;
}
#table-list {
    flex: 1;
    overflow-y: auto;
    font-size: .8rem;
}
#table-list .tbl-item {
    padding: .3rem .75rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .4rem;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    user-select: none;
}
#table-list .tbl-item:hover { background: #eff6ff; color: #1a3a6b; }
#table-list .tbl-item.active { background: #dbeafe; color: #1a3a6b; font-weight: 700; }
#table-list .tbl-cnt {
    margin-left: auto;
    font-size: .7rem;
    color: #9ca3af;
}

/* ── Zone de travail ── */
#workspace {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Onglets ── */
#ws-tabs {
    background: #f8fafc;
    border-bottom: 1px solid #dde5f0;
    padding: 0 1rem;
    display: flex;
    align-items: flex-end;
    gap: .2rem;
    flex-shrink: 0;
}
.ws-tab {
    padding: .45rem .85rem;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    color: #6b7280;
    background: transparent;
    white-space: nowrap;
}
.ws-tab:hover { color: var(--nijac-blue); background: #e5eaf5; }
.ws-tab.active { color: var(--nijac-blue); background: #fff; border-color: #dde5f0; }

/* ── Contenu onglets ── */
#ws-content {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.tab-pane { display: none; flex: 1; flex-direction: column; overflow: hidden; padding: .75rem 1rem; }
.tab-pane.active { display: flex; }

/* ── Tableau générique ── */
.data-table-wrap {
    flex: 1;
    overflow: auto;
    border: 1px solid #dde5f0;
    border-radius: 6px;
}
.data-table {
    width: 100%;
    font-size: .78rem;
    border-collapse: collapse;
}
.data-table thead th {
    background: #1a3a6b;
    color: #fff;
    padding: .4rem .5rem;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 2;
    cursor: pointer;
}
.data-table thead th:hover { background: #2a4a8b; }
.data-table thead th.sorted-asc::after  { content: ' ▲'; }
.data-table thead th.sorted-desc::after { content: ' ▼'; }
.data-table tbody tr:nth-child(even) { background: #f8fafc; }
.data-table tbody tr:hover { background: #dbeafe; }
.data-table td {
    padding: .35rem .5rem;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}
.data-table td.null-val { color: #9ca3af; font-style: italic; }
.data-table td.num-val { text-align: right; }
.data-table td .actions { display: flex; gap: 3px; }

/* ── Pagination ── */
#browse-pagination {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .4rem 0;
    font-size: .8rem;
    flex-shrink: 0;
}

/* ── Barre de recherche browse ── */
#browse-toolbar {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding-bottom: .5rem;
    flex-wrap: wrap;
    flex-shrink: 0;
}

/* ── Requêteur SQL ── */
#sql-editor {
    width: 100%;
    font-family: 'Consolas', 'Courier New', monospace;
    font-size: .82rem;
    border: 1px solid #dde5f0;
    border-radius: 6px;
    padding: .5rem;
    resize: vertical;
    min-height: 120px;
}
#sql-result { flex: 1; overflow: auto; }
#sql-meta {
    font-size: .78rem;
    color: #6b7280;
    padding: .3rem 0;
    flex-shrink: 0;
}

/* ── Structure table ── */
.struct-badge {
    display: inline-block;
    font-size: .7rem;
    padding: .1rem .35rem;
    border-radius: 3px;
    font-weight: 600;
}
.badge-pk  { background: #fef3c7; color: #92400e; }
.badge-nn  { background: #fee2e2; color: #991b1b; }
.badge-idx { background: #e0f2fe; color: #075985; }
.badge-uni { background: #f0fdf4; color: #14532d; }

/* ── Modales ── */
.modal-header { background: var(--nijac-blue); color: #fff; }
.modal-header .btn-close { filter: invert(1); }

/* ── Statut ── */
.status-ok  { color: #16a34a; }
.status-err { color: #dc2626; }

#no-table-msg {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    font-size: 1rem;
    flex-direction: column;
    gap: .5rem;
}
</style>
</head>
<body>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-database-gear me-2"></i>Administration base de données
    <small class="opacity-75 ms-2">(E099)</small>
</div>

<!-- ToolStrip -->
<div id="toolbar">
    <span class="ts-user"><i class="bi bi-person-fill me-1"></i><?= $nomComplet ?></span>
    <span class="badge bg-danger">Admin BDD</span>
    <a href="admin_menu.php" class="btn btn-sm btn-light py-0 ms-auto">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Layout -->
<div id="main-layout">

    <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
    <div id="sidebar">
        <div id="sidebar-header">
            <span><i class="bi bi-table me-1"></i>Tables</span>
            <span id="table-count" class="opacity-75"></span>
        </div>
        <div id="table-search">
            <input type="text" id="tbl-filter" placeholder="Filtrer…" autocomplete="off">
        </div>
        <div id="table-list"></div>
    </div>

    <!-- ── Zone de travail ──────────────────────────────────────────────── -->
    <div id="workspace">

        <!-- Onglets -->
        <div id="ws-tabs">
            <div class="ws-tab active" data-tab="browse"><i class="bi bi-grid-3x3 me-1"></i>Données</div>
            <div class="ws-tab" data-tab="structure"><i class="bi bi-layout-text-sidebar me-1"></i>Structure</div>
            <div class="ws-tab" data-tab="sql"><i class="bi bi-terminal me-1"></i>Requêteur SQL</div>
        </div>

        <!-- Contenu -->
        <div id="ws-content">

            <!-- ── Onglet Données ───────────────────────────────────────── -->
            <div class="tab-pane active" id="pane-browse">
                <div id="no-table-msg">
                    <i class="bi bi-database" style="font-size:2.5rem;color:#cbd5e1;"></i>
                    <span>Sélectionnez une table dans le panneau de gauche</span>
                </div>
                <div id="browse-content" style="display:none;flex-direction:column;flex:1;overflow:hidden;">
                    <div id="browse-toolbar">
                        <strong id="browse-title" class="me-2" style="color:var(--nijac-blue);"></strong>
                        <input type="text" id="browse-search" class="form-control form-control-sm" style="width:200px;" placeholder="Rechercher…">
                        <select id="browse-limit" class="form-select form-select-sm" style="width:auto;">
                            <option value="25">25 / page</option>
                            <option value="50" selected>50 / page</option>
                            <option value="100">100 / page</option>
                            <option value="250">250 / page</option>
                        </select>
                        <button class="btn btn-sm btn-primary" id="btn-new-row">
                            <i class="bi bi-plus-lg me-1"></i>Nouveau
                        </button>
                        <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-refresh-browse">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table" id="browse-table">
                            <thead id="browse-thead"></thead>
                            <tbody id="browse-tbody"></tbody>
                        </table>
                    </div>
                    <div id="browse-pagination"></div>
                </div>
            </div>

            <!-- ── Onglet Structure ─────────────────────────────────────── -->
            <div class="tab-pane" id="pane-structure">
                <div id="struct-no-table" class="text-center text-muted py-4">
                    <i class="bi bi-database" style="font-size:2rem;"></i><br>Sélectionnez une table
                </div>
                <div id="struct-content" style="display:none;flex-direction:column;flex:1;overflow:hidden;">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-shrink-0">
                        <strong id="struct-title" style="color:var(--nijac-blue);"></strong>
                        <button class="btn btn-sm btn-success ms-auto" id="btn-add-col">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter une colonne
                        </button>
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table" id="struct-table">
                            <thead>
                                <tr>
                                    <th>Colonne</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Clé</th>
                                    <th>Défaut</th>
                                    <th>Extra</th>
                                    <th style="width:90px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="struct-tbody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 flex-shrink-0" id="struct-indexes"></div>
                </div>
            </div>

            <!-- ── Onglet SQL ───────────────────────────────────────────── -->
            <div class="tab-pane" id="pane-sql">
                <div class="d-flex flex-column" style="flex:1;overflow:hidden;gap:.5rem;">
                    <div class="flex-shrink-0">
                        <textarea id="sql-editor" spellcheck="false" placeholder="SELECT * FROM ja LIMIT 10 ;">SELECT * FROM ja LIMIT 10 ;</textarea>
                        <div class="d-flex gap-2 mt-1 align-items-center">
                            <button class="btn btn-sm btn-primary" id="btn-run-sql" title="Exécuter (Ctrl+Entrée)">
                                <i class="bi bi-play-fill me-1"></i>Exécuter <kbd style="font-size:.7rem;opacity:.8;">Ctrl+↵</kbd>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="btn-clear-sql"
                                    title="Effacer la requête et le résultat">
                                <i class="bi bi-x-lg me-1"></i>Effacer
                            </button>
                            <button class="btn btn-sm btn-outline-success d-none" id="btn-export-csv"
                                    title="Télécharger le résultat en CSV">
                                <i class="bi bi-filetype-csv me-1"></i>Exporter CSV
                            </button>
                            <div id="sql-meta" class="ms-auto align-self-center"></div>
                        </div>
                    </div>
                    <div id="sql-result" class="data-table-wrap"></div>
                </div>
            </div>

        </div><!-- /ws-content -->
    </div><!-- /workspace -->
</div><!-- /main-layout -->

<!-- ── Modal Ligne (Insert / Edit) ──────────────────────────────────────────── -->
<div class="modal fade" id="modal-row" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-row-title">Nouvelle ligne</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-row"></form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-primary" id="btn-save-row"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal Ajouter Colonne ────────────────────────────────────────────────── -->
<div class="modal fade" id="modal-add-col" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="col-modal-title">Ajouter une colonne</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-col">
                    <input type="hidden" id="col-action" value="add_column">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Nom de la colonne</label>
                        <input type="text" class="form-control form-control-sm" id="col-name" required pattern="\w+" placeholder="ex: date_creation">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Type SQL</label>
                        <input type="text" class="form-control form-control-sm" id="col-type" required placeholder="ex: VARCHAR(255) / INT / DATE / TEXT">
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" class="form-check-input" id="col-nullable">
                        <label class="form-check-label small" for="col-nullable">Nullable (NULL autorisé)</label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Valeur par défaut <span class="text-muted fw-normal">(optionnel)</span></label>
                        <input type="text" class="form-control form-control-sm" id="col-default" placeholder="ex: 0 / '' / CURRENT_TIMESTAMP">
                    </div>
                    <div class="mb-2" id="col-after-wrap">
                        <label class="form-label small fw-bold">Insérer après <span class="text-muted fw-normal">(optionnel)</span></label>
                        <select class="form-select form-select-sm" id="col-after">
                            <option value="">— En dernier —</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Commentaire <span class="text-muted fw-normal">(optionnel)</span></label>
                        <input type="text" class="form-control form-control-sm" id="col-comment">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-success" id="btn-save-col"><i class="bi bi-plus-lg me-1"></i>Ajouter</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal Modifier Colonne ───────────────────────────────────────────────── -->
<div class="modal fade" id="modal-mod-col" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Modifier la colonne</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-mod-col">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Colonne</label>
                        <input type="text" class="form-control form-control-sm" id="modcol-name" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Nouveau type SQL</label>
                        <input type="text" class="form-control form-control-sm" id="modcol-type" required>
                    </div>
                    <div class="mb-2 form-check">
                        <input type="checkbox" class="form-check-input" id="modcol-nullable">
                        <label class="form-check-label small" for="modcol-nullable">Nullable</label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Valeur par défaut</label>
                        <input type="text" class="form-control form-control-sm" id="modcol-default">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Commentaire</label>
                        <input type="text" class="form-control form-control-sm" id="modcol-comment">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-warning" id="btn-save-modcol"><i class="bi bi-pencil me-1"></i>Modifier</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>

<script>
'use strict';

/* ── État global ── */
const state = {
    currentTable : null,
    currentTab   : 'browse',
    browsePage   : 0,
    browseOrder  : '',
    browseDir    : 'ASC',
    browseSearch : '',
    browseLimit  : 50,
    structCols   : [],
    allTables    : [],
    pkCol        : null,
};

/* ── Helpers ── */
const api = (data) => $.post('db-admin.php', data);
const apiGet = (params) => $.get('db-admin.php', params);

function escHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtSize(b) {
    if (b < 1024) return b + ' o';
    if (b < 1048576) return (b/1024).toFixed(1) + ' Ko';
    return (b/1048576).toFixed(1) + ' Mo';
}

function setStatus(msg, ok) {
    // pas de barre de statut ici, on utilise une alerte légère
}

/* ─────────────────────────────────────────────────────────────────────────────
   Onglets
───────────────────────────────────────────────────────────────────────────── */
$('.ws-tab').on('click', function () {
    const tab = $(this).data('tab');
    $('.ws-tab').removeClass('active');
    $(this).addClass('active');
    $('.tab-pane').removeClass('active');
    $('#pane-' + tab).addClass('active');
    state.currentTab = tab;

    if (tab === 'structure' && state.currentTable) loadStructure(state.currentTable);
});

/* ─────────────────────────────────────────────────────────────────────────────
   Chargement de la liste des tables
───────────────────────────────────────────────────────────────────────────── */
function loadTables() {
    apiGet({ action: 'tables' }).done(r => {
        if (!r.ok) return;
        state.allTables = r.tables;
        renderTableList(r.tables);
    });
}

function renderTableList(tables) {
    $('#table-count').text(tables.length);
    const filter = $('#tbl-filter').val().toLowerCase();
    const filtered = filter ? tables.filter(t => t.name.toLowerCase().includes(filter)) : tables;

    const html = filtered.map(t => `
        <div class="tbl-item${state.currentTable === t.name ? ' active' : ''}" data-table="${escHtml(t.name)}">
            <i class="bi bi-table" style="font-size:.75rem;"></i>
            <span>${escHtml(t.name)}</span>
            <span class="tbl-cnt">${t.rows ?? '?'}</span>
        </div>`).join('');
    $('#table-list').html(html || '<div class="p-2 text-muted" style="font-size:.78rem;">Aucune table</div>');
}

$('#tbl-filter').on('input', () => renderTableList(state.allTables));

$('#table-list').on('click', '.tbl-item', function () {
    const table = $(this).data('table');
    selectTable(table);
});

function selectTable(table) {
    state.currentTable = table;
    state.browsePage   = 0;
    state.browseOrder  = '';
    state.browseDir    = 'ASC';
    state.browseSearch = '';
    $('#browse-search').val('');
    renderTableList(state.allTables);

    if (state.currentTab === 'browse')   loadBrowse();
    if (state.currentTab === 'structure') loadStructure(table);
}

/* ─────────────────────────────────────────────────────────────────────────────
   Onglet Données – Browse
───────────────────────────────────────────────────────────────────────────── */
function loadBrowse() {
    if (!state.currentTable) return;
    $('#no-table-msg').hide();
    $('#browse-content').css('display','flex');
    $('#browse-title').text(state.currentTable);

    apiGet({
        action : 'browse',
        table  : state.currentTable,
        page   : state.browsePage,
        limit  : state.browseLimit,
        search : state.browseSearch,
        order  : state.browseOrder,
        dir    : state.browseDir,
    }).done(r => {
        if (!r.ok) { alert(r.msg); return; }

        // Détection PK
        state.pkCol = r.cols[0] ?? null;

        // Thead
        const thCells = r.cols.map(c => {
            let cls = '';
            if (c === state.browseOrder) cls = state.browseDir === 'ASC' ? 'sorted-asc' : 'sorted-desc';
            return `<th class="${cls}" data-col="${escHtml(c)}">${escHtml(c)}</th>`;
        });
        thCells.push('<th style="width:80px;">Actions</th>');
        $('#browse-thead').html('<tr>' + thCells.join('') + '</tr>');

        // Tbody
        if (r.rows.length === 0) {
            const span = r.cols.length + 1;
            $('#browse-tbody').html(`<tr><td colspan="${span}" class="text-center text-muted py-3">Aucune donnée</td></tr>`);
        } else {
            const rows = r.rows.map(row => {
                const cells = r.cols.map(c => {
                    const v = row[c];
                    if (v === null) return `<td class="null-val">NULL</td>`;
                    const isNum = !isNaN(v) && v !== '';
                    return `<td class="${isNum ? 'num-val' : ''}" title="${escHtml(v)}">${escHtml(String(v).substring(0,100))}${String(v).length > 100 ? '…' : ''}</td>`;
                });
                const pk  = state.pkCol;
                const pkv = pk ? escHtml(row[pk]) : '';
                cells.push(`<td><div class="actions">
                    <button class="btn btn-xs btn-outline-primary btn-edit-row" style="padding:0 .3rem;font-size:.72rem;" data-pk="${escHtml(pk)}" data-pkval="${pkv}" title="Modifier"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-xs btn-outline-danger btn-del-row" style="padding:0 .3rem;font-size:.72rem;" data-pk="${escHtml(pk)}" data-pkval="${pkv}" title="Supprimer"><i class="bi bi-trash"></i></button>
                </div></td>`);
                return '<tr>' + cells.join('') + '</tr>';
            });
            $('#browse-tbody').html(rows.join(''));
        }

        // Pagination
        const total   = r.total;
        const pages   = Math.ceil(total / r.limit);
        const current = r.page;
        let pagHtml = `<span class="me-2 text-muted">${total} ligne(s) — page ${current+1}/${pages || 1}</span>`;
        pagHtml += `<button class="btn btn-xs btn-outline-secondary" id="pg-first" ${current===0?'disabled':''} style="font-size:.75rem;padding:0 .4rem;">«</button>`;
        pagHtml += `<button class="btn btn-xs btn-outline-secondary" id="pg-prev"  ${current===0?'disabled':''} style="font-size:.75rem;padding:0 .4rem;">‹</button>`;
        pagHtml += `<button class="btn btn-xs btn-outline-secondary" id="pg-next"  ${current>=pages-1?'disabled':''} style="font-size:.75rem;padding:0 .4rem;">›</button>`;
        pagHtml += `<button class="btn btn-xs btn-outline-secondary" id="pg-last"  ${current>=pages-1?'disabled':''} style="font-size:.75rem;padding:0 .4rem;">»</button>`;
        $('#browse-pagination').html(pagHtml);

        $('#pg-first').on('click', () => { state.browsePage = 0; loadBrowse(); });
        $('#pg-prev').on('click',  () => { state.browsePage = Math.max(0, current-1); loadBrowse(); });
        $('#pg-next').on('click',  () => { state.browsePage = current+1; loadBrowse(); });
        $('#pg-last').on('click',  () => { state.browsePage = pages-1; loadBrowse(); });
    });
}

// Tri par colonne
$('#browse-thead').on('click', 'th[data-col]', function () {
    const col = $(this).data('col');
    if (state.browseOrder === col) {
        state.browseDir = state.browseDir === 'ASC' ? 'DESC' : 'ASC';
    } else {
        state.browseOrder = col;
        state.browseDir   = 'ASC';
    }
    state.browsePage = 0;
    loadBrowse();
});

// Recherche
let searchTimer;
$('#browse-search').on('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        state.browseSearch = $(this).val();
        state.browsePage   = 0;
        loadBrowse();
    }, 350);
});

$('#browse-limit').on('change', function () {
    state.browseLimit = +$(this).val();
    state.browsePage  = 0;
    loadBrowse();
});

$('#btn-refresh-browse').on('click', loadBrowse);

/* ── Nouveau / Édition ligne ── */
function openRowModal(mode, pk, pkVal) {
    const table = state.currentTable;
    if (!table) return;

    if (mode === 'edit') {
        $('#modal-row-title').text('Modifier une ligne — ' + table);
    } else {
        $('#modal-row-title').text('Nouvelle ligne — ' + table);
    }

    // Récupère la structure de la table pour construire le formulaire
    apiGet({ action: 'describe', table }).done(r => {
        if (!r.ok) { alert(r.msg); return; }

        const buildForm = (rowData) => {
            const fields = r.columns.map(col => {
                const isAuto = col.Extra && col.Extra.includes('auto_increment');
                const val    = rowData ? (rowData[col.Field] ?? '') : '';
                const lbl    = col.Field + (col.Key === 'PRI' ? ' 🔑' : '') + (col.Null === 'NO' ? ' *' : '');
                const hint   = col.Type + (col.Default !== null ? ' (défaut: ' + col.Default + ')' : '');

                if (isAuto && mode === 'insert') {
                    return `<div class="mb-2">
                        <label class="form-label small fw-bold">${escHtml(lbl)}</label>
                        <input type="text" class="form-control form-control-sm" name="${escHtml(col.Field)}" value="[auto]" disabled>
                        <small class="text-muted">Généré automatiquement</small>
                    </div>`;
                }

                const required = col.Null === 'NO' && col.Default === null && !isAuto ? 'required' : '';
                const type = col.Type.toLowerCase().includes('text') || col.Type.toLowerCase().includes('json') ? 'textarea' : 'input';

                if (type === 'textarea') {
                    return `<div class="mb-2">
                        <label class="form-label small fw-bold">${escHtml(lbl)}</label>
                        <textarea class="form-control form-control-sm" name="${escHtml(col.Field)}" rows="3" ${required}>${escHtml(val)}</textarea>
                        <small class="text-muted">${escHtml(hint)}</small>
                    </div>`;
                }
                return `<div class="mb-2">
                    <label class="form-label small fw-bold">${escHtml(lbl)}</label>
                    <input type="text" class="form-control form-control-sm" name="${escHtml(col.Field)}" value="${escHtml(val)}" ${required} placeholder="${escHtml(col.Type)}">
                    <small class="text-muted">${escHtml(hint)}</small>
                </div>`;
            });

            $('#form-row').html(fields.join(''));
            $('#form-row').data('mode', mode).data('pk', pk).data('pkval', pkVal);
            new bootstrap.Modal('#modal-row').show();
        };

        if (mode === 'edit' && pk && pkVal !== undefined) {
            apiGet({ action: 'get_row', table, pk, pkval: pkVal }).done(gr => {
                if (!gr.ok) { alert(gr.msg); return; }
                buildForm(gr.row);
            });
        } else {
            buildForm(null);
        }
    });
}

$('#btn-new-row').on('click', () => openRowModal('insert'));

$(document).on('click', '.btn-edit-row', function () {
    openRowModal('edit', $(this).data('pk'), $(this).data('pkval'));
});

$(document).on('click', '.btn-del-row', function () {
    const pk    = $(this).data('pk');
    const pkVal = $(this).data('pkval');
    if (!confirm(`Supprimer la ligne ${pk} = ${pkVal} ?`)) return;

    api({ action: 'delete', table: state.currentTable, pk, pkval: pkVal }).done(r => {
        if (!r.ok) { alert('Erreur : ' + r.msg); return; }
        loadBrowse();
    });
});

$('#btn-save-row').on('click', function () {
    const form   = $('#form-row');
    const mode   = form.data('mode');
    const pk     = form.data('pk');
    const pkVal  = form.data('pkval');
    const table  = state.currentTable;

    const data = {};
    form.find('[name]:not([disabled])').each(function () {
        data[$(this).attr('name')] = $(this).val();
    });

    if (mode === 'insert') {
        api({ action: 'insert', table, data }).done(r => {
            if (!r.ok) { alert('Erreur : ' + r.msg); return; }
            bootstrap.Modal.getInstance('#modal-row').hide();
            loadBrowse();
        });
    } else {
        api({ action: 'update', table, pk, pkval: pkVal, data }).done(r => {
            if (!r.ok) { alert('Erreur : ' + r.msg); return; }
            bootstrap.Modal.getInstance('#modal-row').hide();
            loadBrowse();
        });
    }
});

/* ─────────────────────────────────────────────────────────────────────────────
   Onglet Structure
───────────────────────────────────────────────────────────────────────────── */
function loadStructure(table) {
    $('#struct-no-table').hide();
    $('#struct-content').css('display','flex');
    $('#struct-title').text(table);

    apiGet({ action: 'describe', table }).done(r => {
        if (!r.ok) { alert(r.msg); return; }
        state.structCols = r.columns;

        const rows = r.columns.map(col => {
            const badges = [];
            if (col.Key === 'PRI') badges.push('<span class="struct-badge badge-pk">PK</span>');
            if (col.Key === 'MUL') badges.push('<span class="struct-badge badge-idx">IDX</span>');
            if (col.Key === 'UNI') badges.push('<span class="struct-badge badge-uni">UNI</span>');
            if (col.Null === 'NO') badges.push('<span class="struct-badge badge-nn">NN</span>');

            return `<tr>
                <td><strong>${escHtml(col.Field)}</strong></td>
                <td><code style="font-size:.75rem;">${escHtml(col.Type)}</code></td>
                <td>${col.Null === 'YES' ? '✓' : '✗'}</td>
                <td>${badges.join(' ')}</td>
                <td>${col.Default !== null ? escHtml(col.Default) : '<span class="null-val">NULL</span>'}</td>
                <td><small class="text-muted">${escHtml(col.Extra)}</small></td>
                <td><div class="actions">
                    <button class="btn btn-xs btn-outline-warning btn-mod-col" style="padding:0 .3rem;font-size:.72rem;" data-col="${escHtml(col.Field)}" data-type="${escHtml(col.Type)}" data-null="${col.Null}" data-default="${escHtml(col.Default??'')}" title="Modifier"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-xs btn-outline-danger btn-drop-col" style="padding:0 .3rem;font-size:.72rem;" data-col="${escHtml(col.Field)}" ${col.Key==='PRI'?'disabled':''} title="Supprimer"><i class="bi bi-trash"></i></button>
                </div></td>
            </tr>`;
        });
        $('#struct-tbody').html(rows.join(''));

        // Index
        if (r.indexes.length > 0) {
            const idxHtml = r.indexes.map(idx => {
                const type = idx.name === 'PRIMARY' ? 'PRIMARY' : (idx.unique ? 'UNIQUE' : 'INDEX');
                return `<span class="badge ${idx.name==='PRIMARY'?'bg-warning text-dark':idx.unique?'bg-success':'bg-secondary'} me-1">${escHtml(type)}: ${escHtml(idx.name)} (${escHtml(idx.columns.join(', '))})</span>`;
            }).join('');
            $('#struct-indexes').html('<div class="mt-1"><strong style="font-size:.78rem;">Index :</strong> ' + idxHtml + '</div>');
        } else {
            $('#struct-indexes').empty();
        }
    });
}

// Bouton Ajouter colonne
$('#btn-add-col').on('click', function () {
    if (!state.currentTable) return;
    $('#col-modal-title').text('Ajouter une colonne — ' + state.currentTable);
    $('#col-action').val('add_column');
    $('#col-name, #col-type, #col-default, #col-comment').val('');
    $('#col-nullable').prop('checked', false);

    // Populate "after" select
    const opts = ['<option value="">— En dernier —</option>']
        .concat(state.structCols.map(c => `<option value="${escHtml(c.Field)}">${escHtml(c.Field)}</option>`));
    $('#col-after').html(opts.join(''));
    new bootstrap.Modal('#modal-add-col').show();
});

$('#btn-save-col').on('click', function () {
    api({
        action   : 'add_column',
        table    : state.currentTable,
        colname  : $('#col-name').val(),
        coltype  : $('#col-type').val(),
        nullable : $('#col-nullable').is(':checked') ? '1' : '0',
        default  : $('#col-default').val(),
        after    : $('#col-after').val(),
        comment  : $('#col-comment').val(),
    }).done(r => {
        if (!r.ok) { alert('Erreur : ' + r.msg); return; }
        bootstrap.Modal.getInstance('#modal-add-col').hide();
        loadStructure(state.currentTable);
    });
});

// Modifier colonne
$(document).on('click', '.btn-mod-col', function () {
    const col = $(this).data('col');
    $('#modcol-name').val(col);
    $('#modcol-type').val($(this).data('type'));
    $('#modcol-nullable').prop('checked', $(this).data('null') === 'YES');
    $('#modcol-default').val($(this).data('default'));
    $('#modcol-comment').val('');
    new bootstrap.Modal('#modal-mod-col').show();
});

$('#btn-save-modcol').on('click', function () {
    api({
        action   : 'modify_column',
        table    : state.currentTable,
        colname  : $('#modcol-name').val(),
        coltype  : $('#modcol-type').val(),
        nullable : $('#modcol-nullable').is(':checked') ? '1' : '0',
        default  : $('#modcol-default').val(),
        comment  : $('#modcol-comment').val(),
    }).done(r => {
        if (!r.ok) { alert('Erreur : ' + r.msg); return; }
        bootstrap.Modal.getInstance('#modal-mod-col').hide();
        loadStructure(state.currentTable);
    });
});

// Supprimer colonne
$(document).on('click', '.btn-drop-col', function () {
    const col = $(this).data('col');
    if (!confirm(`Supprimer la colonne "${col}" de "${state.currentTable}" ?\n\nCette action est IRRÉVERSIBLE.`)) return;
    api({ action: 'drop_column', table: state.currentTable, colname: col }).done(r => {
        if (!r.ok) { alert('Erreur : ' + r.msg); return; }
        loadStructure(state.currentTable);
    });
});

/* ─────────────────────────────────────────────────────────────────────────────
   Onglet Requêteur SQL
───────────────────────────────────────────────────────────────────────────── */
let lastSqlResult = null; // { cols, rows } du dernier SELECT

function runSql() {
    const sql = $('#sql-editor').val().trim();
    if (!sql) return;

    $('#sql-result').html('<div class="text-muted p-2">Exécution…</div>');
    $('#sql-meta').text('');
    $('#btn-export-csv').addClass('d-none');
    lastSqlResult = null;

    api({ action: 'sql', sql }).done(r => {
        if (!r.ok) {
            $('#sql-result').html(`<div class="alert alert-danger p-2 m-1" style="font-size:.82rem;"><i class="bi bi-exclamation-triangle me-1"></i>${escHtml(r.msg)}</div>`);
            $('#sql-meta').html('');
            return;
        }

        const meta = `<span class="status-ok"><i class="bi bi-check-circle me-1"></i>OK</span> — ${r.ms} ms`;

        if (r.type === 'select') {
            if (r.rows.length === 0) {
                $('#sql-result').html('<div class="text-muted p-2">Aucun résultat.</div>');
            } else {
                lastSqlResult = { cols: r.cols, rows: r.rows };
                $('#btn-export-csv').removeClass('d-none');
                const ths = r.cols.map(c => `<th>${escHtml(c)}</th>`).join('');
                const trs = r.rows.map(row => {
                    const tds = r.cols.map(c => {
                        const v = row[c];
                        if (v === null) return `<td class="null-val">NULL</td>`;
                        const s = String(v);
                        return `<td title="${escHtml(s)}">${escHtml(s.substring(0,200))}${s.length>200?'…':''}</td>`;
                    }).join('');
                    return `<tr>${tds}</tr>`;
                }).join('');
                $('#sql-result').html(`<table class="data-table"><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table>`);
            }
            $('#sql-meta').html(meta + ` — <strong>${r.rows.length}</strong> ligne(s) retournée(s)`);
        } else {
            $('#sql-result').html(`<div class="alert alert-success p-2 m-1" style="font-size:.82rem;"><i class="bi bi-check-circle me-1"></i><strong>${r.affected}</strong> ligne(s) affectée(s)</div>`);
            $('#sql-meta').html(meta);
            loadTables();
        }
    }).fail(() => {
        $('#sql-result').html('<div class="alert alert-danger p-2 m-1">Erreur de communication avec le serveur.</div>');
    });
}

function exportCsv() {
    if (!lastSqlResult) return;
    const { cols, rows } = lastSqlResult;
    const escape = v => {
        if (v === null || v === undefined) return '';
        const s = String(v);
        return s.includes(';') || s.includes('"') || s.includes('\n')
            ? '"' + s.replace(/"/g, '""') + '"'
            : s;
    };
    const lines = [cols.map(escape).join(';')];
    rows.forEach(row => lines.push(cols.map(c => escape(row[c])).join(';')));
    const bom  = '﻿'; // BOM UTF-8 pour Excel
    const blob = new Blob([bom + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'export_' + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

$('#btn-run-sql').on('click', runSql);
$('#btn-export-csv').on('click', exportCsv);
$('#btn-clear-sql').on('click', () => {
    $('#sql-editor').val('');
    $('#sql-result').empty();
    $('#sql-meta').text('');
    $('#btn-export-csv').addClass('d-none');
    lastSqlResult = null;
});

$('#sql-editor').on('keydown', function (e) {

    // Ctrl+Entrée → exécuter
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        runSql();
        return;
    }

    // Tab / Shift+Tab → indenter / désindenter le bloc sélectionné
    if (e.key === 'Tab') {
        e.preventDefault();
        const el  = this;
        const s   = el.selectionStart;
        const end = el.selectionEnd;
        const val = el.value;

        if (s === end) {
            // Pas de sélection : insérer 4 espaces au curseur
            el.value = val.substring(0, s) + '    ' + val.substring(end);
            el.selectionStart = el.selectionEnd = s + 4;
        } else {
            // Sélection multi-ligne : indenter ou désindenter chaque ligne
            const before   = val.substring(0, s);
            const selected = val.substring(s, end);
            const after    = val.substring(end);
            const lineStart = before.lastIndexOf('\n') + 1;
            const block     = val.substring(lineStart, end);

            if (e.shiftKey) {
                // Désindenter : retirer jusqu'à 4 espaces en début de chaque ligne
                const newBlock = block.replace(/^( {1,4})/gm, '');
                const diff     = block.length - newBlock.length;
                el.value = val.substring(0, lineStart) + newBlock + after;
                el.selectionStart = Math.max(lineStart, s - Math.min(4, s - lineStart));
                el.selectionEnd   = end - diff;
            } else {
                // Indenter : ajouter 4 espaces en début de chaque ligne
                const newBlock = block.replace(/^/gm, '    ');
                const diff     = newBlock.length - block.length;
                el.value = val.substring(0, lineStart) + newBlock + after;
                el.selectionStart = s + (s === lineStart ? 4 : 4);
                el.selectionEnd   = end + diff;
            }
        }
    }
});

/* ─────────────────────────────────────────────────────────────────────────────
   Init
───────────────────────────────────────────────────────────────────────────── */
loadTables();
</script>
</body>
</html>
