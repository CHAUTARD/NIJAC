<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Nettoyage / Restauration de phase (E016), portage CI4 de clean.php.
 *
 * Outil admin destructeur : vidage/sauvegarde de la phase en cours, sauvegarde
 * et restauration totale de la base, restauration d'une table isolée depuis
 * une sauvegarde totale. Chaque action (hors les deux listages de fichiers)
 * exige une revérification du mot de passe administrateur, en plus du filtre
 * "adminauth" et du CSRF — reproduit à l'identique du fichier legacy.
 *
 * Pas de Model : lecture/écriture de fichiers .sql bruts, introspection
 * dynamique des tables (SHOW TABLES / SHOW CREATE TABLE), exécution
 * d'instructions SQL arbitraires — bien au-delà du Query Builder simple.
 * Réutilise getPDO() directement, comme le fichier legacy.
 */
class CleanController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
    }

    private function moi(): array
    {
        return $_SESSION['utilisateur'] ?? [];
    }

    /**
     * Exécute une action et convertit toute exception en réponse JSON
     * ['ok' => false, 'msg' => ...] au lieu de laisser remonter une page
     * d'erreur HTML — clean.php enveloppe de la même façon la totalité de son
     * dispatcher d'actions, un filet de sécurité important ici vu la nature
     * destructrice de ces opérations (exécution de SQL brut depuis fichier).
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            error_log('[NIJAC] clean.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] clean.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    private function sqlDir(): string
    {
        return __DIR__ . '/../../../SQL';
    }

    private function verifierMotDePasse(\PDO $pdo, array $moi, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $stmt = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
        $stmt->execute([$moi['id']]);
        $row = $stmt->fetch();

        return $row && \SecurePasswordHasher::verify($password, $row['Password']);
    }

    /**
     * Revérifie le mot de passe admin pour la requête POST courante. Retourne
     * une réponse d'erreur JSON à renvoyer immédiatement si invalide, ou null
     * si la vérification est passée — chaque action destructrice appelle ceci
     * en premier (le CSRF a déjà été validé par le filtre global "csrf" avant
     * même l'exécution de la méthode), comme le fait clean.php pour tout ce
     * qui n'est pas liste_sauvegardes/liste_sauvegardes_total.
     */
    private function verifierMdpRequete(): ?ResponseInterface
    {
        $pdo      = getPDO();
        $password = trim($this->request->getPost('password') ?? '');

        if (!$this->verifierMotDePasse($pdo, $this->moi(), $password)) {
            return $this->response->setJSON([
                'ok'  => false,
                'msg' => $password === '' ? 'Mot de passe requis.' : 'Mot de passe incorrect.',
            ]);
        }

        return null;
    }

    /** Fichiers Sauve_*.sql triés du plus récent au plus ancien. */
    private function listeSauvegardes(string $sqlDir): array
    {
        if (!is_dir($sqlDir)) {
            return [];
        }
        $files = glob($sqlDir . '/Sauve_*.sql');
        if (!$files) {
            return [];
        }
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        return array_map(fn ($f) => [
            'nom'    => basename($f),
            'taille' => round(filesize($f) / 1024, 1),
            'date'   => date('d/m/Y H:i', filemtime($f)),
        ], $files);
    }

    /** Fichiers Full_*.sql triés du plus récent au plus ancien. */
    private function listeSauvegardesTotal(string $sqlDir): array
    {
        if (!is_dir($sqlDir)) {
            return [];
        }
        $files = glob($sqlDir . '/Full_*.sql');
        if (!$files) {
            return [];
        }
        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

        return array_map(fn ($f) => [
            'nom'    => basename($f),
            'taille' => round(filesize($f) / 1024, 1),
            'date'   => date('d/m/Y H:i', filemtime($f)),
        ], $files);
    }

    /**
     * Découpe un dump SQL en instructions, en ignorant commentaires/lignes
     * vides — chaque instruction se termine par un ';' en fin de ligne.
     */
    private function decouperInstructions(string $sql): array
    {
        $statements = [];
        $current    = '';
        foreach (explode("\n", $sql) as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current .= $trimmed . "\n";
            if (str_ends_with(rtrim($trimmed), ';')) {
                $s = trim($current);
                if ($s !== '') {
                    $statements[] = $s;
                }
                $current = '';
            }
        }

        return $statements;
    }

    /**
     * Résout et valide le chemin d'un fichier de sauvegarde dans /SQL/,
     * contre un motif de nom strict — protection contre la traversée de
     * répertoire (../). Retourne false si invalide/introuvable.
     */
    private function resoudreFichierSauvegarde(string $nomFichier, string $pattern): string|false
    {
        $nomFichier = basename($nomFichier);
        if (!preg_match($pattern, $nomFichier)) {
            return false;
        }
        $sqlDir   = $this->sqlDir();
        $filepath = realpath($sqlDir . '/' . $nomFichier);
        if ($filepath === false || dirname($filepath) !== realpath($sqlDir)) {
            return false;
        }

        return $filepath;
    }

    public function index()
    {
        $moi = $this->moi();
        $pdo = getPDO();

        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $rows   = $pdo->prepare(
                'SELECT TABLE_NAME, TABLE_COMMENT
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ?
                 ORDER BY TABLE_NAME'
            );
            $rows->execute([$dbName]);
            $tablesBdd = $rows->fetchAll();
        } catch (\Throwable) {
            $tablesBdd = [];
        }

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'tablesBdd'   => $tablesBdd,
        ];

        return view('clean_index', $data);
    }

    public function sauvegardes(): ResponseInterface
    {
        return $this->tryJson(fn () => $this->response->setJSON(['ok' => true, 'fichiers' => $this->listeSauvegardes($this->sqlDir())]));
    }

    public function sauvegardesTotal(): ResponseInterface
    {
        return $this->tryJson(fn () => $this->response->setJSON(['ok' => true, 'fichiers' => $this->listeSauvegardesTotal($this->sqlDir())]));
    }

    /**
     * Fichiers restaurables table par table : sauvegardes totales Full_*.sql
     * ET sauvegardes de table individuelles Table_<nom>_*.sql — les deux
     * portent le marqueur de section "-- ── <table> ──" attendu par
     * restaurerTableFull().
     */
    public function sauvegardesTable(): ResponseInterface
    {
        return $this->tryJson(function () {
            $sqlDir = $this->sqlDir();
            $files  = array_merge(glob($sqlDir . '/Full_*.sql') ?: [], glob($sqlDir . '/Table_*.sql') ?: []);
            usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));

            $liste = array_map(fn ($f) => [
                'nom'    => basename($f),
                'taille' => round(filesize($f) / 1024, 1),
                'date'   => date('d/m/Y H:i', filemtime($f)),
            ], $files);

            return $this->response->setJSON(['ok' => true, 'fichiers' => $liste]);
        });
    }

    public function verifierMdp(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            return $this->response->setJSON(['ok' => true]);
        });
    }

    /**
     * Liste les tables de la base — action définie dans clean.php mais non
     * appelée par son propre JS (le combo de restauration table par table est
     * peuplé côté serveur au rendu de la page). Portée ici en POST plutôt
     * qu'en GET : le mot de passe y est exigé, et clean.php le lit uniquement
     * depuis $_POST, donc cette action n'a jamais pu fonctionner en GET côté
     * legacy non plus.
     */
    public function tables(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $tables = getPDO()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            sort($tables);

            return $this->response->setJSON(['ok' => true, 'tables' => $tables]);
        });
    }

    public function supprimerAnciennes(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $type = $this->request->getPost('type') ?? '';
            if (!in_array($type, ['sauve', 'full'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Type invalide.']);
            }

            $sqlDir  = $this->sqlDir();
            $pattern = $type === 'sauve' ? $sqlDir . '/Sauve_*.sql' : $sqlDir . '/Full_*.sql';
            $files   = glob($pattern) ?: [];
            if (count($files) <= 1) {
                return $this->response->setJSON(['ok' => true, 'msg' => 'Aucune ancienne sauvegarde à supprimer.', 'supprimes' => 0]);
            }

            usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));
            $aSupprimer = array_slice($files, 1);
            $supprimes  = 0;
            foreach ($aSupprimer as $f) {
                if (unlink($f)) {
                    $supprimes++;
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => "$supprimes ancienne(s) sauvegarde(s) supprimée(s).", 'supprimes' => $supprimes]);
        });
    }

    /** Sauvegarde + vidage de la phase en cours (tables de $tables ci-dessous, ordre alphabétique). */
    public function executer(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $pdo    = getPDO();
            $moi    = $this->moi();
            $sqlDir = $this->sqlDir();
            $tables = ['Competition_Regionale', 'Disponible', 'Equipe', 'Equipe_Nationale', 'JA', 'Nomination', 'Rencontre'];

            if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de créer le répertoire /SQL/.']);
            }

            $filename = 'Sauve_' . date('YmdHi') . '.sql';
            $filepath = $sqlDir . '/' . $filename;

            $lines   = [];
            $lines[] = '-- NIJAC sauvegarde phase';
            $lines[] = '-- Fichier  : ' . $filename;
            $lines[] = '-- Date     : ' . date('d/m/Y H:i');
            $lines[] = '-- Opérateur: ' . $moi['nom'] . ' ' . $moi['prenom'];
            $lines[] = '-- Tables   : ' . implode(', ', $tables);
            $lines[] = '';
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
            $lines[] = '';

            foreach ($tables as $table) {
                $lines[] = '-- ── ' . $table . ' ──';
                $cols    = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $col) {
                    $cols[] = $col['Field'];
                }
                $lines[] = "TRUNCATE TABLE `$table`;";
                $rows    = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) {
                    $colList = '`' . implode('`, `', $cols) . '`';
                    foreach ($rows as $r) {
                        $vals    = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($r));
                        $lines[] = "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ');';
                    }
                }
                $lines[] = '';
            }
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $sql     = implode("\n", $lines);

            if (file_put_contents($filepath, $sql) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Impossible d'écrire le fichier de sauvegarde."]);
            }

            // Nettoyage dans l'ordre des dépendances FK
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['Nomination', 'Disponible', 'Rencontre', 'Equipe_Nationale', 'Equipe', 'Competition_Regionale'] as $t) {
                $pdo->exec("DELETE FROM `$t`");
            }
            // JA : désactivation plutôt que suppression
            $jaDesactives = $pdo->exec('UPDATE `ja` SET Actif = 0');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // Suppression des fichiers d'import de la phase précédente
            $xlsxSupprimes = 0;
            foreach (glob(__DIR__ . '/../../../Importation/Rencontres/*.xlsx') as $f) {
                if (unlink($f)) {
                    $xlsxSupprimes++;
                }
            }

            return $this->response->setJSON([
                'ok'             => true,
                'msg'            => 'Sauvegarde effectuée, tables vidées et JA désactivés avec succès.',
                'fichier'        => $filename,
                'lignes'         => substr_count($sql, "\n") + 1,
                'ja_desactives'  => $jaDesactives,
                'xlsx_supprimes' => $xlsxSupprimes,
            ]);
        });
    }

    /**
     * Lignes SQL d'une table pour un dump complet : marqueur de section
     * "-- ── <table> ──", DROP + SHOW CREATE TABLE, puis un INSERT par ligne.
     * Partagé par sauvegardeTotale() (toutes les tables) et sauvegardeTable()
     * (une seule) — même format, donc restaurable via restaurer-table.
     *
     * @return string[]
     */
    private function dumpTableComplete(\PDO $pdo, string $table): array
    {
        $lines   = [];
        $lines[] = '-- ── ' . $table . ' ──';

        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? $createRow[array_key_last($createRow)];
        $lines[]   = "DROP TABLE IF EXISTS `$table`;";
        $lines[]   = $createSql . ';';
        $lines[]   = '';

        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $col) {
            $cols[] = $col['Field'];
        }
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows) {
            $colList = '`' . implode('`, `', $cols) . '`';
            foreach ($rows as $r) {
                $vals    = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($r));
                $lines[] = "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ');';
            }
            $lines[] = '';
        }

        return $lines;
    }

    public function sauvegardeTotale(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $pdo    = getPDO();
            $moi    = $this->moi();
            $sqlDir = $this->sqlDir();

            if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de créer le répertoire /SQL/.']);
            }

            $filename = 'Full_' . date('YmdHi') . '.sql';
            $filepath = $sqlDir . '/' . $filename;

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            $lines   = [];
            $lines[] = '-- NIJAC sauvegarde totale de la base de données';
            $lines[] = '-- Fichier  : ' . $filename;
            $lines[] = '-- Date     : ' . date('d/m/Y H:i');
            $lines[] = '-- Opérateur: ' . $moi['nom'] . ' ' . $moi['prenom'];
            $lines[] = '-- Tables   : ' . implode(', ', $tables);
            $lines[] = '';
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
            $lines[] = '';

            foreach ($tables as $table) {
                array_push($lines, ...$this->dumpTableComplete($pdo, $table));
            }
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $sql     = implode("\n", $lines);

            if (file_put_contents($filepath, $sql) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Impossible d'écrire le fichier de sauvegarde."]);
            }

            // Ne garde que les N sauvegardes totales les plus récentes (paramètre
            // 'backup_full_garder', auto-créé à 5 s'il n'existe pas encore)
            $pdo->exec("INSERT IGNORE INTO configuration (cle, valeur) VALUES ('backup_full_garder', '5')");
            $garder  = (int) getConfig('backup_full_garder', '5');
            $anciens = glob($sqlDir . '/Full_*.sql') ?: [];
            usort($anciens, fn ($a, $b) => filemtime($b) - filemtime($a));
            foreach (array_slice($anciens, $garder) as $f) {
                unlink($f);
            }

            return $this->response->setJSON([
                'ok'      => true,
                'msg'     => 'Sauvegarde totale effectuée avec succès.',
                'fichier' => $filename,
                'tables'  => count($tables),
                'taille'  => round(strlen($sql) / 1024, 1),
            ]);
        });
    }

    /** Sauvegarde d'une seule table dans SQL/Table_<table>_*.sql (non destructif). */
    public function sauvegardeTable(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $table = trim($this->request->getPost('table') ?? '');
            if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de table invalide.']);
            }

            $pdo    = getPDO();
            $moi    = $this->moi();
            $sqlDir = $this->sqlDir();

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array($table, $tables, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Table « $table » introuvable."]);
            }

            if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de créer le répertoire /SQL/.']);
            }

            $filename = 'Table_' . $table . '_' . date('YmdHi') . '.sql';
            $filepath = $sqlDir . '/' . $filename;

            $lines   = [];
            $lines[] = '-- NIJAC sauvegarde de table';
            $lines[] = '-- Fichier  : ' . $filename;
            $lines[] = '-- Date     : ' . date('d/m/Y H:i');
            $lines[] = '-- Opérateur: ' . $moi['nom'] . ' ' . $moi['prenom'];
            $lines[] = '';
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
            $lines[] = '';
            array_push($lines, ...$this->dumpTableComplete($pdo, $table));
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $sql     = implode("\n", $lines);

            if (file_put_contents($filepath, $sql) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Impossible d'écrire le fichier de sauvegarde."]);
            }

            $nbLignes = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();

            return $this->response->setJSON([
                'ok'      => true,
                'msg'     => 'Sauvegarde de table effectuée avec succès.',
                'fichier' => $filename,
                'table'   => $table,
                'lignes'  => $nbLignes,
                'taille'  => round(strlen($sql) / 1024, 1),
            ]);
        });
    }

    public function restaurer(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $nomFichier = trim($this->request->getPost('fichier') ?? '');
            $filepath   = $this->resoudreFichierSauvegarde($nomFichier, '/^Sauve_\d{12}\.sql$/');
            if ($filepath === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
            }

            $pdo = getPDO();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            foreach ($this->decouperInstructions($sql) as $stmt) {
                $pdo->exec($stmt);
                $executed++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            return $this->response->setJSON([
                'ok'       => true,
                'msg'      => 'Restauration effectuée avec succès.',
                'fichier'  => basename($filepath),
                'executed' => $executed,
            ]);
        });
    }

    public function restaurerTotal(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $nomFichier = trim($this->request->getPost('fichier') ?? '');
            $filepath   = $this->resoudreFichierSauvegarde($nomFichier, '/^Full_\d{12}\.sql$/');
            if ($filepath === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
            }

            $pdo = getPDO();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            foreach ($this->decouperInstructions($sql) as $stmt) {
                $pdo->exec($stmt);
                $executed++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            return $this->response->setJSON([
                'ok'       => true,
                'msg'      => 'Restauration totale effectuée avec succès.',
                'fichier'  => basename($filepath),
                'executed' => $executed,
            ]);
        });
    }

    public function restaurerTableFull(): ResponseInterface
    {

        return $this->tryJson(function () {
            if ($err = $this->verifierMdpRequete()) {
                return $err;
            }

            $nomFichier = trim($this->request->getPost('fichier') ?? '');
            $table      = trim($this->request->getPost('table') ?? '');

            $filepath = $this->resoudreFichierSauvegarde($nomFichier, '/^(Full_\d{12}|Table_[A-Za-z0-9_]+_\d{12})\.sql$/');
            if ($filepath === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
            }
            if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nom de table invalide.']);
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
            }

            // Extraire la section de la table : de "-- ── <table> ──" jusqu'à la prochaine section ou fin
            $lines   = explode("\n", $sql);
            $inTable = false;
            $section = [];
            foreach ($lines as $line) {
                $trimmed = rtrim($line);
                if (preg_match('/^-- ── (.+?) ──$/', $trimmed, $m)) {
                    if ($inTable) {
                        break;
                    }
                    if ($m[1] === $table) {
                        $inTable = true;
                    }
                    continue;
                }
                if (!$inTable) {
                    continue;
                }
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $section[] = $trimmed;
            }

            if (empty($section)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Table « $table » introuvable dans le fichier."]);
            }

            $statements = $this->decouperInstructions(implode("\n", $section));

            $pdo = getPDO();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            $log      = [];
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
                $executed++;
                if (preg_match('/^\s*(DROP TABLE[^;]*)/i', $stmt, $m)) {
                    $log[] = ['type' => 'drop', 'label' => rtrim($m[1])];
                } elseif (preg_match('/^\s*CREATE TABLE\s+`?(\w+)`?/i', $stmt, $m)) {
                    $log[] = ['type' => 'create', 'label' => 'CREATE TABLE ' . $m[1]];
                } elseif (preg_match('/^\s*INSERT INTO\s+`?(\w+)`?/i', $stmt, $m)) {
                    $log[] = ['type' => 'insert', 'label' => 'INSERT INTO ' . $m[1]];
                } elseif (preg_match('/^\s*ALTER TABLE\s+`?(\w+)`?/i', $stmt, $m)) {
                    $log[] = ['type' => 'alter', 'label' => 'ALTER TABLE ' . $m[1]];
                } else {
                    $log[] = ['type' => 'other', 'label' => mb_substr(trim($stmt), 0, 60)];
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            return $this->response->setJSON([
                'ok'       => true,
                'msg'      => "Table « $table » restaurée avec succès.",
                'table'    => $table,
                'fichier'  => basename($filepath),
                'executed' => $executed,
                'log'      => $log,
            ]);
        });
    }
}
