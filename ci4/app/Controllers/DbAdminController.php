<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Administration base de données (E099), requêteur SQL libre.
 *
 * Outil "bris de glace" réservé au seul utilisateur CHAUTARD (filtre
 * "adminauth" + vérification manuelle du login, même règle que E018) :
 * accès SQL total, sans restriction, pour cet unique compte.
 */
class DbAdminController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function guardChautard(): ?ResponseInterface
    {
        if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
            return redirect()->to(site_url('admin-menu'));
        }

        return null;
    }

    public function index()
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $u = $_SESSION['utilisateur'] ?? [];

        try {
            initTableConfiguration(getPDO());
        } catch (\Throwable) {
        }

        return view('db_admin_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'changeLogin' => !empty($u['change_login']),
        ]);
    }

    public function tables(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        try {
            $rows   = getPDO()->query('SHOW TABLE STATUS')->fetchAll();
            $tables = array_map(
                static fn ($r) => ['name' => $r['Name'], 'rows' => (int) $r['Rows']],
                $rows
            );
            usort($tables, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $this->response->setJSON(['ok' => true, 'tables' => $tables]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function sql(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $sql = trim($this->request->getPost('sql') ?? '');
        if ($sql === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Requête vide.']);
        }

        try {
            $pdo  = getPDO();
            $t0   = microtime(true);
            $stmt = $pdo->query($sql);
            $ms   = round((microtime(true) - $t0) * 1000, 2);

            $type = strtoupper((string) strtok(ltrim($sql), " \t\n"));
            if (in_array($type, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
                $rows = $stmt->fetchAll();
                $cols = $rows ? array_keys($rows[0]) : [];

                return $this->response->setJSON(['ok' => true, 'type' => 'select', 'cols' => $cols, 'rows' => $rows, 'ms' => $ms]);
            }

            return $this->response->setJSON(['ok' => true, 'type' => 'write', 'affected' => $stmt->rowCount(), 'ms' => $ms]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}
