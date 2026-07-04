<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Administration base de données (E099), portage CI4 de db-admin.php.
 *
 * Interface d'administration SQL directe : navigation des tables, structure
 * (DESCRIBE + ALTER TABLE), CRUD complet (Browse/Insert/Edit/Delete),
 * requêteur SQL libre. Outil "bris de glace" réservé au seul utilisateur
 * CHAUTARD (filtre "adminauth" + vérification manuelle du login, même règle
 * que E018). Aucune protection supplémentaire au-delà de celle du legacy :
 * cet écran donne volontairement un accès SQL total à cet unique compte.
 */
class DbAdminController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
    }

    private function guardChautard(): ?ResponseInterface
    {
        if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
            return redirect()->to(site_url('admin-menu'));
        }

        return null;
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function index()
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $u = $_SESSION['utilisateur'] ?? [];

        return view('db_admin_index', [
            'nomComplet' => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
        ]);
    }

    public function tables(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $rows   = getPDO()->query('SHOW TABLE STATUS')->fetchAll();
            $tables = array_map(fn ($r) => [
                'name'    => $r['Name'],
                'rows'    => $r['Rows'],
                'engine'  => $r['Engine'],
                'size'    => ($r['Data_length'] + $r['Index_length']),
                'comment' => $r['Comment'],
            ], $rows);

            return $this->response->setJSON(['ok' => true, 'tables' => $tables]);
        });
    }

    public function describe(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table = $this->request->getGet('table') ?? $this->request->getPost('table') ?? '';
            if (!preg_match('/^\w+$/', $table)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de table invalide.']);
            }
            $pdo     = getPDO();
            $cols    = $pdo->query("DESCRIBE `$table`")->fetchAll();
            $idxRaw  = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll();
            $indexes = [];
            foreach ($idxRaw as $idx) {
                $k = $idx['Key_name'];
                $indexes[$k]['name']      = $k;
                $indexes[$k]['unique']    = !$idx['Non_unique'];
                $indexes[$k]['columns'][] = $idx['Column_name'];
            }

            return $this->response->setJSON(['ok' => true, 'columns' => $cols, 'indexes' => array_values($indexes)]);
        });
    }

    public function browse(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo      = getPDO();
            $table    = $this->request->getGet('table') ?? '';
            $page     = max(0, (int) ($this->request->getGet('page') ?? 0));
            $limit    = max(1, min(500, (int) ($this->request->getGet('limit') ?? 50)));
            $search   = trim($this->request->getGet('search') ?? '');
            $orderCol = $this->request->getGet('order') ?? '';
            $orderDir = strtoupper($this->request->getGet('dir') ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

            if (!preg_match('/^\w+$/', $table)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de table invalide.']);
            }
            $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_COLUMN);

            $where  = '';
            $params = [];
            if ($search !== '') {
                $parts  = array_map(fn ($c) => "`$c` LIKE ?", $cols);
                $where  = 'WHERE ' . implode(' OR ', $parts);
                $params = array_fill(0, count($cols), "%$search%");
            }

            $orderClause = '';
            if ($orderCol && in_array($orderCol, $cols)) {
                $orderClause = "ORDER BY `$orderCol` $orderDir";
            }

            $stmtC = $pdo->prepare("SELECT COUNT(*) FROM `$table` $where");
            $stmtC->execute($params);
            $total = (int) $stmtC->fetchColumn();

            $offset = $page * $limit;
            $sql    = "SELECT * FROM `$table` $where $orderClause LIMIT $limit OFFSET $offset";
            $stmt   = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            return $this->response->setJSON([
                'ok'    => true,
                'rows'  => $rows,
                'total' => $total,
                'cols'  => $cols,
                'page'  => $page,
                'limit' => $limit,
            ]);
        });
    }

    public function getRow(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table = $this->request->getGet('table') ?? '';
            $pk    = $this->request->getGet('pk') ?? '';
            $pkVal = $this->request->getGet('pkval') ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètre invalide.']);
            }
            $stmt = getPDO()->prepare("SELECT * FROM `$table` WHERE `$pk` = ? LIMIT 1");
            $stmt->execute([$pkVal]);
            $row = $stmt->fetch();
            if (!$row) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne introuvable.']);
            }

            return $this->response->setJSON(['ok' => true, 'row' => $row]);
        });
    }

    public function insert(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo   = getPDO();
            $table = $this->request->getPost('table') ?? '';
            $data  = $this->request->getPost('data') ?? [];

            if (!preg_match('/^\w+$/', $table) || !is_array($data) || empty($data)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            $validCols = $pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_COLUMN);
            $filtered  = array_filter($data, fn ($k) => in_array($k, $validCols), ARRAY_FILTER_USE_KEY);
            if (empty($filtered)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune colonne valide.']);
            }
            $cols = array_keys($filtered);
            $vals = array_values($filtered);
            $sql  = "INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);

            return $this->response->setJSON(['ok' => true, 'id' => $pdo->lastInsertId()]);
        });
    }

    public function update(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo   = getPDO();
            $table = $this->request->getPost('table') ?? '';
            $pk    = $this->request->getPost('pk') ?? '';
            $pkVal = $this->request->getPost('pkval') ?? '';
            $data  = $this->request->getPost('data') ?? [];

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk) || !is_array($data)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            $validCols = $pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_COLUMN);
            $filtered  = array_filter($data, fn ($k) => in_array($k, $validCols) && $k !== $pk, ARRAY_FILTER_USE_KEY);
            if (empty($filtered)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune colonne à modifier.']);
            }
            $setParts = array_map(fn ($c) => "`$c` = ?", array_keys($filtered));
            $vals     = array_values($filtered);
            $vals[]   = $pkVal;
            $sql      = 'UPDATE `' . $table . '` SET ' . implode(', ', $setParts) . " WHERE `$pk` = ?";
            $pdo->prepare($sql)->execute($vals);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function delete(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table = $this->request->getPost('table') ?? '';
            $pk    = $this->request->getPost('pk') ?? '';
            $pkVal = $this->request->getPost('pkval') ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $pk)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            getPDO()->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([$pkVal]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function sql(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo = getPDO();
            $sql = trim($this->request->getPost('sql') ?? '');
            if ($sql === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Requête vide.']);
            }

            $t0   = microtime(true);
            $stmt = $pdo->query($sql);
            $ms   = round((microtime(true) - $t0) * 1000, 2);

            if ($stmt === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Erreur lors de l'exécution."]);
            }

            $type = strtoupper(strtok(ltrim($sql), " \t\n"));
            if (in_array($type, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'])) {
                $rows = $stmt->fetchAll();
                $cols = $rows ? array_keys($rows[0]) : [];

                return $this->response->setJSON(['ok' => true, 'type' => 'select', 'cols' => $cols, 'rows' => $rows, 'ms' => $ms]);
            }

            $affected = $stmt->rowCount();

            return $this->response->setJSON(['ok' => true, 'type' => 'write', 'affected' => $affected, 'ms' => $ms]);
        });
    }

    public function addColumn(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo      = getPDO();
            $table    = $this->request->getPost('table') ?? '';
            $colName  = $this->request->getPost('colname') ?? '';
            $colType  = $this->request->getPost('coltype') ?? '';
            $nullable = ($this->request->getPost('nullable') ?? '0') === '1';
            $default  = $this->request->getPost('default') ?? null;
            $after    = $this->request->getPost('after') ?? '';
            $comment  = $this->request->getPost('comment') ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName) || trim($colType) === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }

            $def = "`$colName` $colType";
            $def .= $nullable ? ' NULL' : ' NOT NULL';
            if ($default !== null && $default !== '') {
                $def .= ' DEFAULT ' . $pdo->quote($default);
            }
            if ($comment !== '') {
                $def .= ' COMMENT ' . $pdo->quote($comment);
            }
            $afterClause = preg_match('/^\w+$/', $after) ? "AFTER `$after`" : '';
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $def $afterClause");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function modifyColumn(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo      = getPDO();
            $table    = $this->request->getPost('table') ?? '';
            $colName  = $this->request->getPost('colname') ?? '';
            $colType  = $this->request->getPost('coltype') ?? '';
            $nullable = ($this->request->getPost('nullable') ?? '0') === '1';
            $default  = $this->request->getPost('default') ?? null;
            $comment  = $this->request->getPost('comment') ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName) || trim($colType) === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }

            $def = "`$colName` $colType";
            $def .= $nullable ? ' NULL' : ' NOT NULL';
            if ($default !== null && $default !== '') {
                $def .= ' DEFAULT ' . $pdo->quote($default);
            }
            if ($comment !== '') {
                $def .= ' COMMENT ' . $pdo->quote($comment);
            }
            $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN $def");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function dropColumn(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table   = $this->request->getPost('table') ?? '';
            $colName = $this->request->getPost('colname') ?? '';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $colName)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            getPDO()->exec("ALTER TABLE `$table` DROP COLUMN `$colName`");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function exportCsv(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $table = $this->request->getGet('table') ?? '';
        if (!preg_match('/^\w+$/', $table)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Table invalide.']);
        }

        $pdo  = getPDO();
        $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_COLUMN);
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cols, ';');
        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $table . '_' . date('Ymd_His') . '.csv"')
            ->setBody($csv);
    }

    public function truncate(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table = $this->request->getPost('table') ?? '';
            if (!preg_match('/^\w+$/', $table)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Table invalide.']);
            }
            getPDO()->exec("TRUNCATE TABLE `$table`");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function addIndex(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table  = $this->request->getPost('table') ?? '';
            $name   = $this->request->getPost('idxname') ?? '';
            $cols   = $this->request->getPost('cols') ?? '';
            $unique = ($this->request->getPost('unique') ?? '0') === '1';

            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $name)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            $colArr = array_values(array_filter(array_map('trim', explode(',', $cols)), fn ($c) => preg_match('/^\w+$/', $c)));
            if (empty($colArr)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Colonnes invalides.']);
            }
            $colsDef = '`' . implode('`,`', $colArr) . '`';
            $type    = $unique ? 'UNIQUE INDEX' : 'INDEX';
            getPDO()->exec("ALTER TABLE `$table` ADD $type `$name` ($colsDef)");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function dropIndex(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table = $this->request->getPost('table') ?? '';
            $name  = $this->request->getPost('idxname') ?? '';
            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $name)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            getPDO()->exec("ALTER TABLE `$table` DROP INDEX `$name`");

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function renameColumn(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $table   = $this->request->getPost('table') ?? '';
            $oldName = $this->request->getPost('oldname') ?? '';
            $newName = $this->request->getPost('newname') ?? '';
            if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $oldName) || !preg_match('/^\w+$/', $newName)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            getPDO()->exec("ALTER TABLE `$table` RENAME COLUMN `$oldName` TO `$newName`");

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
