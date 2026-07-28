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
            $pdo    = getPDO();
            $noms   = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $tables = array_map(
                // SHOW TABLE STATUS ne donne qu'une estimation (stats InnoDB persistées, recalculées en
                // tâche de fond après ~10% de lignes modifiées) : après un TRUNCATE + import en masse,
                // ce nombre peut rester obsolète un moment. COUNT(*) est exact et ces tables restent petites.
                static fn ($nom) => ['name' => $nom, 'rows' => (int) $pdo->query('SELECT COUNT(*) FROM `' . $nom . '`')->fetchColumn()],
                $noms
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

        $sqlInput   = trim($this->request->getPost('sql') ?? '');
        $statements = $this->splitStatements($sqlInput);
        if (!$statements) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Requête vide.']);
        }

        $pdo     = getPDO();
        $results = [];
        foreach ($statements as $idx => $stmtSql) {
            try {
                $t0   = microtime(true);
                $stmt = $pdo->query($stmtSql);
                $ms   = round((microtime(true) - $t0) * 1000, 2);

                $type = strtoupper((string) strtok(ltrim($stmtSql), " \t\n"));
                if (in_array($type, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
                    $rows = $stmt->fetchAll();
                    $cols = $rows ? array_keys($rows[0]) : [];

                    $results[] = ['ok' => true, 'type' => 'select', 'sql' => $stmtSql, 'cols' => $cols, 'rows' => $rows, 'ms' => $ms];
                } else {
                    $results[] = ['ok' => true, 'type' => 'write', 'sql' => $stmtSql, 'affected' => $stmt->rowCount(), 'ms' => $ms];
                }
            } catch (\Throwable $e) {
                $results[] = ['ok' => false, 'sql' => $stmtSql, 'msg' => $e->getMessage()];

                return $this->response->setJSON([
                    'ok'      => false,
                    'msg'     => sprintf('Requête %d/%d : %s', $idx + 1, count($statements), $e->getMessage()),
                    'results' => $results,
                ]);
            }
        }

        return $this->response->setJSON(['ok' => true, 'results' => $results]);
    }

    /**
     * Découpe une saisie en plusieurs ordres SQL séparés par ";", en ignorant les points-virgules
     * situés à l'intérieur de littéraux ('...', "...", `...`) ou de commentaires (--, #, /* *\/).
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        $len        = strlen($sql);
        $statements = [];
        $buf        = '';
        $quote      = null;
        $i          = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($quote !== null) {
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $ch . $sql[$i + 1];
                    $i   += 2;
                    continue;
                }
                $buf .= $ch;
                if ($ch === $quote) {
                    if (($sql[$i + 1] ?? null) === $quote) {
                        $buf .= $quote;
                        $i   += 2;
                        continue;
                    }
                    $quote = null;
                }
                $i++;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf  .= $ch;
                $i++;
                continue;
            }

            if (($ch === '-' && ($sql[$i + 1] ?? '') === '-') || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $i += 2;
                while ($i < $len && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($buf);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $ch;
            $i++;
        }

        $trimmed = trim($buf);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
