<?php
/**
 * NIJAC – Nettoyage / Restauration de phase (E016)
 *
 * Deux fonctions :
 *   1. Sauvegarde + désactivation des JA (Actif=0) + vidage des tables
 *      Disponible, Equipe, Rencontre, Nomination — après confirmation admin.
 *      Génère un fichier SQL dans /SQL/.
 *   2. Restauration à partir d'un fichier de sauvegarde sélectionné dans /SQL/,
 *      après confirmation par mot de passe admin.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-24
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/Classes/SecurePasswordHasher.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Helpers ───────────────────────────────────────────────────────────────────
$sqlDir = __DIR__ . '/SQL';

/** Vérifie le mot de passe de l'admin connecté. */
function verifierMotDePasse(\PDO $pdo, array $moi, string $password): bool {
    if ($password === '') return false;
    $stmt = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
    $stmt->execute([$moi['id']]);
    $row = $stmt->fetch();
    return $row && SecurePasswordHasher::verify($password, $row['Password']);
}

/** Retourne la liste des fichiers Sauve_*.sql triés du plus récent au plus ancien. */
function listeSauvegardes(string $sqlDir): array {
    if (!is_dir($sqlDir)) return [];
    $files = glob($sqlDir . '/Sauve_*.sql');
    if (!$files) return [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    return array_map(fn($f) => [
        'nom'    => basename($f),
        'taille' => round(filesize($f) / 1024, 1),
        'date'   => date('d/m/Y H:i', filemtime($f)),
    ], $files);
}

/** Retourne la liste des fichiers Full_*.sql triés du plus récent au plus ancien. */
function listeSauvegardesTotal(string $sqlDir): array {
    if (!is_dir($sqlDir)) return [];
    $files = glob($sqlDir . '/Full_*.sql');
    if (!$files) return [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    return array_map(fn($f) => [
        'nom'    => basename($f),
        'taille' => round(filesize($f) / 1024, 1),
        'date'   => date('d/m/Y H:i', filemtime($f)),
    ], $files);
}

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Liste des sauvegardes (pas de mot de passe requis) ────────────────
        if ($action === 'liste_sauvegardes') {
            ob_end_clean();
            echo json_encode(['ok' => true, 'fichiers' => listeSauvegardes($sqlDir)]);
            exit;
        }

        if ($action === 'liste_sauvegardes_total') {
            ob_end_clean();
            echo json_encode(['ok' => true, 'fichiers' => listeSauvegardesTotal($sqlDir)]);
            exit;
        }

        // Pour toutes les autres actions, le mot de passe est requis
        $password = trim($_POST['password'] ?? '');
        if (!verifierMotDePasse($pdo, $moi, $password)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'msg' => $password === '' ? 'Mot de passe requis.' : 'Mot de passe incorrect.']);
            exit;
        }

        // ── Suppression des anciennes sauvegardes (sauf la dernière) ─────────
        if ($action === 'supprimer_anciennes') {
            $type = $_POST['type'] ?? '';
            if (!in_array($type, ['sauve', 'full'], true)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Type invalide.']);
                exit;
            }
            $pattern = $type === 'sauve'
                ? $sqlDir . '/Sauve_*.sql'
                : $sqlDir . '/Full_*.sql';
            $files = glob($pattern) ?: [];
            if (count($files) <= 1) {
                ob_end_clean();
                echo json_encode(['ok' => true, 'msg' => 'Aucune ancienne sauvegarde à supprimer.', 'supprimes' => 0]);
                exit;
            }
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $aSupprimer = array_slice($files, 1);
            $supprimes  = 0;
            foreach ($aSupprimer as $f) {
                if (unlink($f)) $supprimes++;
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => "$supprimes ancienne(s) sauvegarde(s) supprimée(s).", 'supprimes' => $supprimes]);
            exit;
        }

        // ── Vérification seule ────────────────────────────────────────────────
        if ($action === 'verifier_mdp') {
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Sauvegarde + vidage ───────────────────────────────────────────────
        if ($action === 'executer') {

            $tables = ['JA', 'Disponible', 'Equipe', 'Rencontre', 'Nomination'];

            if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de créer le répertoire /SQL/.']);
                exit;
            }

            $filename = 'Sauve_' . date('YmdHi') . '.sql';
            $filepath = $sqlDir . '/' . $filename;

            // Génération du dump SQL
            $lines = [];
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
                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $col) {
                    $cols[] = $col['Field'];
                }
                $lines[] = "TRUNCATE TABLE `$table`;";
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) {
                    $colList = '`' . implode('`, `', $cols) . '`';
                    foreach ($rows as $r) {
                        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($r));
                        $lines[] = "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ");";
                    }
                }
                $lines[] = '';
            }
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $sql = implode("\n", $lines);

            if (file_put_contents($filepath, $sql) === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible d\'écrire le fichier de sauvegarde.']);
                exit;
            }

            // Nettoyage dans l'ordre des dépendances FK
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['Nomination', 'Disponible', 'Rencontre', 'Equipe'] as $t) {
                $pdo->exec("DELETE FROM `$t`");
            }
            // JA : désactivation plutôt que suppression
            $jaDesactives = $pdo->exec("UPDATE `ja` SET Actif = 0");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // Suppression des fichiers d'import de la phase précédente
            $xlsxSupprimes = 0;
            foreach (glob(__DIR__ . '/Importation/Rencontres/*.xlsx') as $f) {
                if (unlink($f)) $xlsxSupprimes++;
            }

            ob_end_clean();
            echo json_encode([
                'ok'            => true,
                'msg'           => 'Sauvegarde effectuée, tables vidées et JA désactivés avec succès.',
                'fichier'       => $filename,
                'lignes'        => substr_count($sql, "\n") + 1,
                'ja_desactives' => $jaDesactives,
                'xlsx_supprimes' => $xlsxSupprimes,
            ]);
            exit;
        }

        // ── Sauvegarde totale ─────────────────────────────────────────────────
        if ($action === 'sauvegarde_totale') {

            if (!is_dir($sqlDir) && !mkdir($sqlDir, 0755, true)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de créer le répertoire /SQL/.']);
                exit;
            }

            $filename = 'Full_' . date('YmdHi') . '.sql';
            $filepath = $sqlDir . '/' . $filename;

            // Récupérer toutes les tables de la base
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            $lines = [];
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
                $lines[] = '-- ── ' . $table . ' ──';

                // Structure : CREATE TABLE
                $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_ASSOC);
                $createSql = $createRow['Create Table'] ?? $createRow[array_key_last($createRow)];
                $lines[] = "DROP TABLE IF EXISTS `$table`;";
                $lines[] = $createSql . ';';
                $lines[] = '';

                // Données
                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll() as $col) {
                    $cols[] = $col['Field'];
                }
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) {
                    $colList = '`' . implode('`, `', $cols) . '`';
                    foreach ($rows as $r) {
                        $vals    = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($r));
                        $lines[] = "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ");";
                    }
                    $lines[] = '';
                }
            }
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $sql = implode("\n", $lines);

            if (file_put_contents($filepath, $sql) === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible d\'écrire le fichier de sauvegarde.']);
                exit;
            }

            ob_end_clean();
            echo json_encode([
                'ok'      => true,
                'msg'     => 'Sauvegarde totale effectuée avec succès.',
                'fichier' => $filename,
                'tables'  => count($tables),
                'taille'  => round(strlen($sql) / 1024, 1),
            ]);
            exit;
        }

        // ── Restauration ─────────────────────────────────────────────────────
        if ($action === 'restaurer') {
            $nomFichier = basename(trim($_POST['fichier'] ?? ''));

            // Sécurité : uniquement les fichiers Sauve_*.sql du dossier /SQL/
            if (!preg_match('/^Sauve_\d{12}\.sql$/', $nomFichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
                exit;
            }
            $filepath = realpath($sqlDir . '/' . $nomFichier);
            if ($filepath === false || dirname($filepath) !== realpath($sqlDir)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Fichier introuvable.']);
                exit;
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
                exit;
            }

            // Découpage en instructions SQL (ignorer commentaires et lignes vides)
            $statements = [];
            $current    = '';
            foreach (explode("\n", $sql) as $line) {
                $trimmed = rtrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
                $current .= $trimmed . "\n";
                if (str_ends_with(rtrim($trimmed), ';')) {
                    $s = trim($current);
                    if ($s !== '') $statements[] = $s;
                    $current = '';
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
                $executed++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            ob_end_clean();
            echo json_encode([
                'ok'        => true,
                'msg'       => 'Restauration effectuée avec succès.',
                'fichier'   => $nomFichier,
                'executed'  => $executed,
            ]);
            exit;
        }

        // ── Restauration totale ───────────────────────────────────────────────
        if ($action === 'restaurer_total') {
            $nomFichier = basename(trim($_POST['fichier'] ?? ''));

            if (!preg_match('/^Full_\d{12}\.sql$/', $nomFichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
                exit;
            }
            $filepath = realpath($sqlDir . '/' . $nomFichier);
            if ($filepath === false || dirname($filepath) !== realpath($sqlDir)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Fichier introuvable.']);
                exit;
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
                exit;
            }

            // Découpage multi-lignes : accumule jusqu'au ; terminal (CREATE TABLE peut couvrir plusieurs lignes)
            $statements = [];
            $current    = '';
            foreach (explode("\n", $sql) as $line) {
                $trimmed = rtrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
                $current .= $trimmed . "\n";
                if (str_ends_with(rtrim($trimmed), ';')) {
                    $s = trim($current);
                    if ($s !== '') $statements[] = $s;
                    $current = '';
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
                $executed++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            ob_end_clean();
            echo json_encode([
                'ok'       => true,
                'msg'      => 'Restauration totale effectuée avec succès.',
                'fichier'  => $nomFichier,
                'executed' => $executed,
            ]);
            exit;
        }

        // ── Liste des tables de la base de données ────────────────────────────
        if ($action === 'liste_tables_db') {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            sort($tables);
            ob_end_clean();
            echo json_encode(['ok' => true, 'tables' => $tables]);
            exit;
        }

        // ── Restauration d'une seule table depuis un Full_*.sql ───────────────
        if ($action === 'restaurer_table_full') {
            $nomFichier = basename(trim($_POST['fichier'] ?? ''));
            $table      = trim($_POST['table'] ?? '');

            if (!preg_match('/^Full_\d{12}\.sql$/', $nomFichier)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Nom de fichier invalide.']);
                exit;
            }
            if ($table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Nom de table invalide.']);
                exit;
            }
            $filepath = realpath($sqlDir . '/' . $nomFichier);
            if ($filepath === false || dirname($filepath) !== realpath($sqlDir)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Fichier introuvable.']);
                exit;
            }
            $sql = file_get_contents($filepath);
            if ($sql === false) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
                exit;
            }

            // Extraire la section de la table : de "-- ── <table> ──" jusqu'à la prochaine section ou fin
            $lines    = explode("\n", $sql);
            $inTable  = false;
            $section  = [];
            foreach ($lines as $line) {
                $trimmed = rtrim($line);
                // Détection début de section : "-- ── NomTable ──"
                if (preg_match('/^-- ── (.+?) ──$/', $trimmed, $m)) {
                    if ($inTable) break; // on vient de finir la section cherchée
                    if ($m[1] === $table) $inTable = true;
                    continue;
                }
                if (!$inTable) continue;
                if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
                $section[] = $trimmed;
            }

            if (empty($section)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => "Table « $table » introuvable dans le fichier."]);
                exit;
            }

            // Découper en instructions SQL
            $statements = [];
            $current    = '';
            foreach ($section as $line) {
                $current .= $line . "\n";
                if (str_ends_with(rtrim($line), ';')) {
                    $s = trim($current);
                    if ($s !== '') $statements[] = $s;
                    $current = '';
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $executed = 0;
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
                $executed++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            ob_end_clean();
            echo json_encode([
                'ok'       => true,
                'msg'      => "Table « $table » restaurée avec succès.",
                'table'    => $table,
                'fichier'  => $nomFichier,
                'executed' => $executed,
            ]);
            exit;
        }

    } catch (\PDOException $e) {
        error_log('[NIJAC] clean.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] clean.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);

// Lecture des tables + commentaires pour le combo de restauration unitaire
try {
    $pdo = getPDO();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $rows = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_COMMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME'
    );
    $rows->execute([$dbName]);
    $tablesBdd = $rows->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable) {
    $tablesBdd = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Nettoyage / Restauration (E016)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

/* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Mise en page deux colonnes ── */
        #main-content {
            flex: 1;
            display: flex;
            align-items: stretch;
            justify-content: center;
            padding: 2rem 1rem;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        /* ── Carte commune ── */
        .op-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0,0,0,.12);
            width: 560px;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .op-card .card-body-custom { flex: 1; }

        /* ── Bloc en-tête de carte ── */
        .card-head {
            padding: 1.1rem 1.5rem .9rem;
            border-bottom: 3px solid transparent;
        }
        .card-head h2 {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0 0 .6rem;
        }
        .card-head .warn-icon { margin-right: .5rem; }

        /* ── Nettoyage : fond orange ── */
        .card-clean .card-head   { background: #fff3cd; border-color: #f59e0b; }
        .card-clean .card-head h2 { color: #92400e; }
        .warn-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #78350f;
        }
        .warn-list li { margin-bottom: .25rem; }

        /* ── Restauration : fond bleu clair ── */
        .card-restore .card-head  { background: #dbeafe; border-color: #3b82f6; }
        .card-restore .card-head h2 { color: #1e3a8a; }
        .info-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #1e3a8a;
        }
        .info-list li { margin-bottom: .25rem; }

        /* ── Sauvegarde totale : fond vert ── */
        .card-full .card-head   { background: #d1fae5; border-color: #10b981; }
        .card-full .card-head h2 { color: #065f46; }
        .full-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #065f46;
        }
        .full-list li { margin-bottom: .25rem; }
        .card-full .tables-badge span {
            background: #a7f3d0;
            border-color: #10b981;
            color: #065f46;
        }
        .btn-full { background: #059669; color: #fff; }
        .btn-full:hover:not(:disabled) { background: #047857; }

        /* ── Badges tables ── */
        .tables-badge {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
            margin-top: .75rem;
        }
        .tables-badge span {
            background: #fde68a;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: .12rem .5rem;
            font-size: .78rem;
            font-weight: 700;
            color: #78350f;
            font-family: monospace;
        }
        .card-restore .tables-badge span {
            background: #bfdbfe;
            border-color: #3b82f6;
            color: #1e3a8a;
        }

        /* ── Corps de carte ── */
        .card-body-custom {
            padding: 1.25rem 1.5rem 1.5rem;
        }
        .card-body-custom h3 {
            font-size: .9rem;
            font-weight: 700;
            color: #1a3a6b;
            margin-bottom: .9rem;
        }

        /* ── Champ mot de passe ── */
        .pwd-group { margin-bottom: 1.1rem; }
        .pwd-group label { font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: .25rem; display: block; }
        .pwd-input {
            flex: 1;
            min-width: 0;
            border: 2px solid #c8d4e8;
            border-radius: 6px 0 0 6px;
            padding: .4rem .7rem;
            font-size: .88rem;
            transition: border-color .2s;
        }
        .pwd-input:focus      { outline: none; border-color: #1a3a6b; }
        .pwd-input.is-invalid { border-color: #dc2626; }
        .pwd-msg { font-size: .8rem; margin-top: .3rem; min-height: 16px; }

        /* ── Sélecteur de fichier ── */
        #select-fichier {
            width: 100%;
            border: 2px solid #c8d4e8;
            border-radius: 6px;
            padding: .4rem .7rem;
            font-size: .85rem;
            margin-bottom: 1rem;
            background: #fff;
        }
        #select-fichier:focus { outline: none; border-color: #1a3a6b; }

        .fichier-meta {
            font-size: .78rem;
            color: #6b7280;
            margin-bottom: .9rem;
            min-height: 18px;
        }

        /* ── Boutons d'action ── */
        .btn-action {
            width: 100%;
            padding: .6rem;
            font-size: .9rem;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-action:disabled { opacity: .5; cursor: default; }

        .btn-clean   { background: #dc2626; color: #fff; }
        .btn-clean:hover:not(:disabled)   { background: #b91c1c; }

        .btn-restore { background: #2563eb; color: #fff; }
        .btn-restore:hover:not(:disabled) { background: #1d4ed8; }

        /* ── Zone résultat ── */
        .result-zone { display: none; padding: 1rem 1.5rem 1.25rem; border-top: 1px solid #e5e7eb; }
        .result-zone.show { display: block; }
        .result-ok {
            background: #d1fae5; border: 1px solid #6ee7b7;
            border-radius: 6px; padding: .9rem 1.1rem;
            color: #065f46; font-size: .85rem;
        }
        .result-err {
            background: #fee2e2; border: 1px solid #fca5a5;
            border-radius: 6px; padding: .9rem 1.1rem;
            color: #991b1b; font-size: .85rem;
        }

        /* ── Bandeau mot de passe ── */
        #pwd-banner {
            background: #f0f4fa;
            border-bottom: 2px solid #c8d4e8;
            padding: .45rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-shrink: 0;
            font-size: .88rem;
        }
        #pwd-banner label {
            font-weight: 600;
            color: #1a3a6b;
            white-space: nowrap;
            margin: 0;
        }
        #pwd-banner .pwd-wrap {
            display: flex;
            align-items: center;
            gap: 0;
        }
        #pwd-global {
            border: 2px solid #c8d4e8;
            border-right: none;
            border-radius: 6px 0 0 6px;
            padding: .3rem .7rem;
            font-size: .88rem;
            width: 220px;
            transition: border-color .2s;
        }
        #pwd-global:focus { outline: none; border-color: #1a3a6b; }
        #pwd-global.is-invalid { border-color: #dc2626; }
        #pwd-global.is-valid   { border-color: #16a34a; }
        #pwd-toggle-btn {
            border: 2px solid #c8d4e8;
            border-left: none;
            border-radius: 0 6px 6px 0;
            background: #fff;
            padding: 0 .6rem;
            cursor: pointer;
            transition: border-color .2s;
            display: flex;
            align-items: center;
            align-self: stretch;
        }
        #pwd-global.is-invalid ~ #pwd-toggle-btn { border-color: #dc2626; }
        #pwd-global.is-valid   ~ #pwd-toggle-btn { border-color: #16a34a; }
        #pwd-msg-global {
            font-size: .82rem;
            min-width: 180px;
        }

        /* ── Spinner overlay ── */
        #spinner {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.35); z-index: 99999;
            align-items: center; justify-content: center;
        }
        #spinner.show { display: flex; }


        /* ── Restauration totale : fond rouge foncé ── */
        .card-full-restore .card-head  { background: #fee2e2; border-color: #ef4444; }
        .card-full-restore .card-head h2 { color: #7f1d1d; }
        .btn-restore-total { background: #dc2626; color: #fff; }
        .btn-restore-total:hover:not(:disabled) { background: #b91c1c; }

        /* ── Restauration table unique ── */
        .card-table-restore .card-head  { background: #ede9fe; border-color: #7c3aed; }
        .card-table-restore .card-head h2 { color: #3b0764; }
        .btn-table-restore { background: #7c3aed; color: #fff; }
        .btn-table-restore:hover:not(:disabled) { background: #6d28d9; }

        #select-table-fichier, #select-table-nom {
            width: 100%;
            border: 2px solid #c8d4e8;
            border-radius: 6px;
            padding: .4rem .7rem;
            font-size: .85rem;
            margin-bottom: .75rem;
            background: #fff;
        }
        #select-table-fichier:focus, #select-table-nom:focus { outline: none; border-color: #7c3aed; }

        /* ── Aucune sauvegarde ── */
        .no-backup {
            text-align: center;
            padding: 1.5rem;
            color: #6b7280;
            font-size: .85rem;
        }
        .no-backup i { font-size: 2rem; display: block; margin-bottom: .5rem; color: #d1d5db; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-database-fill-gear'; $pageTitle = 'Nettoyage / Restauration de phase'; $pageCode = 'E016'; $backUrl = 'admin_menu.php'; require __DIR__ . '/includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Bandeau mot de passe -->
<div id="pwd-banner">
    <label for="pwd-global"><i class="bi bi-key-fill me-1"></i>Mot de passe administrateur :</label>
    <div class="pwd-wrap">
        <input type="password" id="pwd-global" autocomplete="current-password" placeholder="Entrez votre mot de passe…">
        <button type="button" id="pwd-toggle-btn" tabindex="-1" title="Afficher / masquer">
            <i id="pwd-eye" class="bi bi-eye-slash"></i>
        </button>
    </div>
    <div id="pwd-msg-global"></div>
</div>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- ════════════════════════ CONTENU PRINCIPAL ════════════════════════ -->
<div id="main-content">

    <!-- ── CARTE 1 : Nettoyage ── -->
    <div class="op-card card-clean">
        <div class="card-head">
            <h2>
                <i class="bi bi-exclamation-triangle-fill warn-icon"></i>
                Nettoyage — Nouvelle phase
            </h2>
            <ul class="warn-list">
                <li>Supprime <strong>définitivement</strong> les données de la phase en cours.</li>
                <li>Un fichier SQL est créé dans <code>/SQL/</code> avant toute suppression.</li>
                <li>Si la sauvegarde échoue, <strong>aucune suppression</strong> n'est effectuée.</li>
            </ul>
            <div class="tables-badge">
                <span>JA</span><span>Disponible</span><span>Equipe</span>
                <span>Rencontre</span><span>Nomination</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-clean">

            <button id="btn-executer" class="btn-action btn-clean" disabled>
                <i class="bi bi-calendar2-plus-fill me-2"></i>Sauvegarder et démarrer nouvelle phase
            </button>
        </div>

        <div id="clean-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 2 : Restauration ── -->
    <div class="op-card card-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-arrow-counterclockwise warn-icon"></i>
                Restauration d'une sauvegarde
            </h2>
            <ul class="info-list">
                <li>Sélectionnez un fichier de sauvegarde dans la liste ci-dessous.</li>
                <li>Les tables actuelles seront <strong>écrasées</strong> par les données du fichier.</li>
                <li>Opération irréversible — assurez-vous du fichier choisi.</li>
            </ul>
            <div class="tables-badge">
                <span>JA</span><span>Disponible</span><span>Equipe</span>
                <span>Rencontre</span><span>Nomination</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-restore">

            <!-- Liste des sauvegardes -->
            <div id="restore-file-zone">
                <div class="no-backup" id="restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes…
                </div>
            </div>

            <!-- Formulaire (masqué jusqu'au chargement de la liste) -->
            <div id="restore-form" style="display:none">
                <div style="margin-bottom:.8rem;">
                    <label for="select-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde
                    </label>
                    <select id="select-fichier"></select>
                    <div id="fichier-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer" class="btn-action btn-restore" disabled>
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restaurer ce fichier
                </button>
                <button id="btn-suppr-sauve" class="btn-action" style="display:none;background:#6b7280;color:#fff;margin-top:.5rem;font-size:.8rem;padding:.3rem .7rem;">
                    <i class="bi bi-trash me-1"></i>
                </button>
            </div>
        </div>

        <div id="restore-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 3 : Sauvegarde totale ── -->
    <div class="op-card card-full">
        <div class="card-head">
            <h2>
                <i class="bi bi-database-fill-down warn-icon"></i>
                Sauvegarde totale de la base de données
            </h2>
            <ul class="full-list">
                <li>Exporte <strong>toutes les tables</strong> (structure + données) dans un fichier SQL.</li>
                <li>Le fichier est créé dans <code>/SQL/</code> sous la forme <code>Full_*.sql</code>.</li>
                <li>Aucune suppression n'est effectuée — opération <strong>non destructive</strong>.</li>
            </ul>
            <div class="tables-badge" id="full-tables-badge">
                <span>Toutes les tables</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-full">

            <button id="btn-full" class="btn-action btn-full" disabled>
                <i class="bi bi-database-fill-down me-2"></i>Sauvegarder toute la base de données
            </button>

            <!-- Liste des sauvegardes totales existantes -->
            <div id="full-liste-zone" style="margin-top:1.1rem;"></div>
        </div>

        <div id="full-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 4 : Restauration totale ── -->
    <div class="op-card card-full-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-database-fill-up warn-icon"></i>
                Restauration totale de la base
            </h2>
            <ul class="warn-list" style="color:#7c2d12;">
                <li>Restaure <strong>toutes les tables</strong> (structure + données) depuis un fichier <code>Full_*.sql</code>.</li>
                <li>Chaque table est <strong>supprimée puis recréée</strong> — opération <strong>irréversible</strong>.</li>
                <li>Utilisez uniquement pour repartir d'une sauvegarde complète validée.</li>
            </ul>
            <div class="tables-badge" id="full-restore-tables-badge">
                <span style="background:#fecaca;border-color:#ef4444;color:#7f1d1d;">Toutes les tables</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-full-restore">

            <div id="full-restore-file-zone">
                <div class="no-backup" id="full-restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes totales…
                </div>
            </div>

            <div id="full-restore-form" style="display:none">
                <div style="margin-bottom:.8rem;">
                    <label for="select-full-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde totale
                    </label>
                    <select id="select-full-fichier" style="width:100%;border:2px solid #c8d4e8;border-radius:6px;padding:.4rem .7rem;font-size:.85rem;margin-bottom:1rem;background:#fff;"></select>
                    <div id="full-fichier-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer-total" class="btn-action" style="background:#dc2626;color:#fff;" disabled>
                    <i class="bi bi-database-fill-up me-2"></i>Restaurer toute la base de données
                </button>
            </div>
        </div>

        <div id="full-restore-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 5 : Restauration d'une table depuis une sauvegarde totale ── -->
    <div class="op-card card-table-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-table warn-icon"></i>
                Restauration d'une table depuis une sauvegarde totale
            </h2>
            <ul class="info-list" style="color:#3b0764;">
                <li>Sélectionnez un fichier <code>Full_*.sql</code>, puis choisissez la table à restaurer.</li>
                <li>Seule la table sélectionnée sera supprimée et recréée.</li>
                <li>Les autres tables ne sont pas affectées.</li>
            </ul>
        </div>

        <div class="card-body-custom" id="section-table-restore">

            <div id="table-restore-file-zone">
                <div class="no-backup" id="table-restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes totales…
                </div>
            </div>

            <div id="table-restore-form" style="display:none">
                <div style="margin-bottom:.25rem;">
                    <label for="select-table-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde totale
                    </label>
                    <select id="select-table-fichier"></select>
                </div>

                <div id="table-select-zone">
                    <label for="select-table-nom" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-table me-1"></i>Table à restaurer
                    </label>
                    <select id="select-table-nom">
                        <option value="">— Choisir une table —</option>
                        <?php foreach ($tablesBdd as $t): ?>
                        <option value="<?= htmlspecialchars($t['TABLE_NAME']) ?>">
                            <?= htmlspecialchars($t['TABLE_NAME']) ?><?= $t['TABLE_COMMENT'] !== '' ? ' — ' . htmlspecialchars($t['TABLE_COMMENT']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="table-restore-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer-table" class="btn-action btn-table-restore" disabled>
                    <i class="bi bi-table me-2"></i>Restaurer cette table
                </button>
            </div>
        </div>

        <div id="table-restore-result" class="result-zone"></div>
    </div>

</div><!-- /main-content -->

<?php $statusInitial = 'Prêt.'; ?>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

function spinner(show) { $('#spinner').toggleClass('show', show); }
function setStatus(msg) { $('#status-bar').text(msg); }

// ═══════════════════════════════════════════════════════════════════
//  Mot de passe partagé
// ═══════════════════════════════════════════════════════════════════
let pwdOk    = false;
let pwdTimer = null;

function majBoutonsAvecPwd() {
    $('#btn-executer').prop('disabled', !pwdOk);
    $('#btn-full').prop('disabled', !pwdOk);
    // Ces boutons ne s'activent que si des fichiers sont déjà chargés
    if ($('#restore-form').is(':visible'))       $('#btn-restaurer').prop('disabled', !pwdOk);
    if ($('#full-restore-form').is(':visible'))  $('#btn-restaurer-total').prop('disabled', !pwdOk);
    if ($('#select-table-nom').val())            $('#btn-restaurer-table').prop('disabled', !pwdOk);
}

$('#pwd-global').on('input', function () {
    const val = $(this).val();
    pwdOk = false;
    majBoutonsAvecPwd();
    $('#pwd-msg-global').text('').removeClass('text-danger text-success');
    $(this).removeClass('is-invalid is-valid');

    clearTimeout(pwdTimer);
    if (val.length < 3) return;

    pwdTimer = setTimeout(() => {
        $.post('clean.php', { action: 'verifier_mdp', password: val }, res => {
            if (res.ok) {
                pwdOk = true;
                $('#pwd-global').removeClass('is-invalid').addClass('is-valid');
                $('#pwd-msg-global').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>Mot de passe correct.</span>');
            } else {
                $('#pwd-global').addClass('is-invalid').removeClass('is-valid');
                $('#pwd-msg-global').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
            }
            majBoutonsAvecPwd();
        }, 'json');
    }, 600);
});

$('#pwd-toggle-btn').on('click', function () {
    const $i = $('#pwd-global');
    const isHidden = $i.attr('type') === 'password';
    $i.attr('type', isHidden ? 'text' : 'password');
    $('#pwd-eye').toggleClass('bi-eye-slash', !isHidden).toggleClass('bi-eye', isHidden);
});

$('#btn-executer').on('click', function () {
    if (!pwdOk) return;
    if (!confirm(
        'DERNIÈRE CONFIRMATION\n\n' +
        '• Les tables Disponible, Equipe, Rencontre et Nomination seront vidées.\n' +
        '• Tous les JA seront désactivés (Actif = 0) mais conservés.\n' +
        '• Les fichiers .xlsx du dossier Importation/Rencontres seront supprimés.\n\n' +
        'Une sauvegarde sera effectuée avant le nettoyage.\n\n' +
        'Cette opération est IRRÉVERSIBLE.\n\nConfirmer ?'
    )) return;

    spinner(true);
    setStatus('Sauvegarde en cours…');
    $(this).prop('disabled', true);

    $.post('clean.php', { action: 'executer', password: $('#pwd-global').val() }, res => {
        spinner(false);
        const $box = $('#clean-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Nouvelle phase démarrée !</strong><br>
                   Sauvegarde&nbsp;: <code>${res.fichier}</code> (${res.lignes} lignes)<br>
                   Tables Disponible, Equipe, Rencontre, Nomination vidées.<br>
                   ${res.ja_desactives} JA désactivé(s) (conservés en base).<br>
                   ${res.xlsx_supprimes} fichier(s) .xlsx supprimé(s) de Importation/Rencontres.
                 </div>`
            );
            $('#section-clean').hide();
            setStatus('Nettoyage terminé — ' + res.fichier);
            chargerListeSauvegardes();
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur nettoyage : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#clean-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
});

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION
// ═══════════════════════════════════════════════════════════════════
let fichiers = [];

function chargerListeSauvegardes() {
    $.get('clean.php', { action: 'liste_sauvegardes' }, res => {
        fichiers = res.fichiers || [];
        const $zone = $('#restore-file-zone');

        if (!fichiers.length) {
            $zone.html(
                '<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde disponible dans <code>/SQL/</code></div>'
            );
            $('#restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-fichier').empty();
        fichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        majFichierMeta();
        $('#restore-loading').hide();
        $('#restore-form').show();
        $('#btn-restaurer').prop('disabled', !pwdOk);
        if (fichiers.length > 1) {
            $('#btn-suppr-sauve').show().text(`Supprimer les ${fichiers.length - 1} ancienne(s) sauvegarde(s)`);
        } else {
            $('#btn-suppr-sauve').hide();
        }
    }, 'json').fail(() => {
        $('#restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}

function majFichierMeta() {
    const nom = $('#select-fichier').val();
    const f   = fichiers.find(x => x.nom === nom);
    if (f) {
        $('#fichier-meta').text(`Taille : ${f.taille} Ko — Créé le ${f.date}`);
    }
}

$('#select-fichier').on('change', majFichierMeta);

$('#btn-restaurer').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-fichier').val();
    if (!fichier) return;

    if (!confirm(
        'RESTAURATION\n\n' +
        'Le fichier « ' + fichier + ' » va écraser\n' +
        'les données actuelles des tables JA, Disponible,\n' +
        'Equipe, Rencontre et Nomination.\n\n' +
        'Cette opération est IRRÉVERSIBLE.\n\nConfirmer ?'
    )) return;

    spinner(true);
    setStatus('Restauration en cours : ' + fichier + ' …');
    $(this).prop('disabled', true);

    $.post('clean.php', {
        action:   'restaurer',
        fichier:  fichier,
        password: $('#pwd-global').val()
    }, res => {
        spinner(false);
        const $box = $('#restore-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Restauration réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code> — ${res.executed} instruction(s) exécutée(s).
                 </div>`
            );
            $('#section-restore').hide();
            setStatus('Restauration terminée — ' + res.fichier);
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur restauration : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#restore-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
});

// ═══════════════════════════════════════════════════════════════════
//  Bloc SAUVEGARDE TOTALE
// ═══════════════════════════════════════════════════════════════════
function chargerListeSauvegardesTotal() {
    $.get('clean.php', { action: 'liste_sauvegardes_total' }, res => {
        const $zone = $('#full-liste-zone');
        if (!res.ok || !res.fichiers.length) {
            $zone.html('<p class="text-muted" style="font-size:.8rem;margin:0;">Aucune sauvegarde totale existante.</p>');
            return;
        }
        let html = '<p style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;"><i class="bi bi-clock-history me-1"></i>Sauvegardes existantes</p><ul style="font-size:.8rem;color:#374151;padding-left:1.1rem;margin:0;">';
        res.fichiers.forEach(f => {
            html += `<li><code>${f.nom}</code> &mdash; ${f.taille} Ko &mdash; ${f.date}</li>`;
        });
        html += '</ul>';
        if (res.fichiers.length > 1) {
            html += `<button id="btn-suppr-full" class="btn-action" style="background:#6b7280;color:#fff;margin-top:.6rem;font-size:.8rem;padding:.3rem .7rem;width:auto;">
                       <i class="bi bi-trash me-1"></i>Supprimer les ${res.fichiers.length - 1} ancienne(s) sauvegarde(s)
                     </button>`;
        }
        $zone.html(html);
    }, 'json');
}

$('#btn-full').on('click', function () {
    if (!pwdOk) return;
    if (!confirm(
        'SAUVEGARDE TOTALE\n\n' +
        'Toutes les tables de la base de données vont être\n' +
        'exportées (structure + données) dans un fichier SQL.\n\n' +
        'Confirmer ?'
    )) return;

    spinner(true);
    setStatus('Sauvegarde totale en cours…');
    $(this).prop('disabled', true);

    $.post('clean.php', { action: 'sauvegarde_totale', password: $('#pwd-global').val() }, res => {
        spinner(false);
        const $box = $('#full-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Sauvegarde totale réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code><br>
                   ${res.tables} table(s) exportée(s) — ${res.taille} Ko
                 </div>`
            );
            setStatus('Sauvegarde totale terminée — ' + res.fichier);
            chargerListeSauvegardesTotal();
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur sauvegarde totale : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#full-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
});

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION TOTALE
// ═══════════════════════════════════════════════════════════════════
let fullFichiers = [];

function chargerListeSauvegardesTotal2() {
    $.get('clean.php', { action: 'liste_sauvegardes_total' }, res => {
        fullFichiers = res.fichiers || [];
        const $zone  = $('#full-restore-file-zone');

        if (!fullFichiers.length) {
            $zone.html('<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde totale disponible dans <code>/SQL/</code></div>');
            $('#full-restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-full-fichier').empty();
        fullFichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        majFullFichierMeta();
        $('#full-restore-loading').hide();
        $('#full-restore-form').show();
        $('#btn-restaurer-total').prop('disabled', !pwdOk);
    }, 'json').fail(() => {
        $('#full-restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}

function majFullFichierMeta() {
    const nom = $('#select-full-fichier').val();
    const f   = fullFichiers.find(x => x.nom === nom);
    if (f) $('#full-fichier-meta').text(`Taille : ${f.taille} Ko — Créé le ${f.date}`);
}

$('#select-full-fichier').on('change', majFullFichierMeta);

$('#btn-restaurer-total').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-full-fichier').val();
    if (!fichier) return;

    if (!confirm(
        'RESTAURATION TOTALE DE LA BASE\n\n' +
        'Le fichier « ' + fichier + ' » va être rejoué.\n' +
        'TOUTES les tables seront supprimées puis recréées\n' +
        'à partir de ce fichier.\n\n' +
        'Cette opération est IRRÉVERSIBLE.\n\nConfirmer ?'
    )) return;

    spinner(true);
    setStatus('Restauration totale en cours : ' + fichier + ' …');
    $(this).prop('disabled', true);

    $.post('clean.php', {
        action:   'restaurer_total',
        fichier:  fichier,
        password: $('#pwd-global').val()
    }, res => {
        spinner(false);
        const $box = $('#full-restore-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Restauration totale réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code> — ${res.executed} instruction(s) exécutée(s).
                 </div>`
            );
            $('#section-full-restore').hide();
            setStatus('Restauration totale terminée — ' + res.fichier);
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur restauration totale : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#full-restore-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
});

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION TABLE UNIQUE
// ═══════════════════════════════════════════════════════════════════
let tableRestoreFichiers = [];

function chargerFichiersTableRestore() {
    $.get('clean.php', { action: 'liste_sauvegardes_total' }, res => {
        tableRestoreFichiers = res.fichiers || [];
        const $zone = $('#table-restore-file-zone');

        if (!tableRestoreFichiers.length) {
            $zone.html('<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde totale disponible dans <code>/SQL/</code></div>');
            $('#table-restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-table-fichier').empty();
        tableRestoreFichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        $('#table-restore-loading').hide();
        $('#table-restore-form').show();
    }, 'json').fail(() => {
        $('#table-restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}


function majTableRestoreMeta() {
    const table = $('#select-table-nom').val();
    if (table) {
        $('#table-restore-meta').text(`La table « ${table} » sera supprimée et recréée depuis la sauvegarde.`);
        $('#btn-restaurer-table').prop('disabled', !pwdOk);
    } else {
        $('#table-restore-meta').text('');
        $('#btn-restaurer-table').prop('disabled', true);
    }
}

$('#select-table-fichier').on('change', function () { /* le combo de tables reste inchangé */ });

$('#select-table-nom').on('change', majTableRestoreMeta);

$('#btn-restaurer-table').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-table-fichier').val();
    const table   = $('#select-table-nom').val();
    if (!fichier || !table) return;

    if (!confirm(
        'RESTAURATION DE TABLE\n\n' +
        'La table « ' + table + ' » va être\n' +
        'supprimée puis recréée depuis le fichier\n' +
        '« ' + fichier + ' ».\n\n' +
        'Cette opération est IRRÉVERSIBLE.\n\nConfirmer ?'
    )) return;

    spinner(true);
    setStatus('Restauration de la table ' + table + ' en cours…');
    $(this).prop('disabled', true);

    $.post('clean.php', {
        action:   'restaurer_table_full',
        fichier,
        table,
        password: $('#pwd-global').val()
    }, res => {
        spinner(false);
        const $box = $('#table-restore-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Table restaurée !</strong><br>
                   Table&nbsp;: <code>${res.table}</code> — ${res.executed} instruction(s) exécutée(s).<br>
                   Source&nbsp;: <code>${res.fichier}</code>
                 </div>`
            );
            setStatus('Restauration de la table ' + res.table + ' terminée.');
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majTableRestoreMeta();
            setStatus('Erreur restauration table : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#table-restore-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majTableRestoreMeta();
    });
});

// ═══════════════════════════════════════════════════════════════════
//  Suppression des anciennes sauvegardes
// ═══════════════════════════════════════════════════════════════════
$('#btn-suppr-sauve').on('click', function () {
    if (!pwdOk) { alert('Mot de passe requis.'); return; }
    if (!confirm('Supprimer toutes les sauvegardes Sauve_*.sql sauf la plus récente ?\n\nCette opération est irréversible.')) return;
    spinner(true);
    $.post('clean.php', { action: 'supprimer_anciennes', type: 'sauve', password: $('#pwd-global').val() }, res => {
        spinner(false);
        if (res.ok) {
            setStatus(res.msg);
            chargerListeSauvegardes();
        } else {
            alert('Erreur : ' + res.msg);
        }
    }, 'json').fail(() => { spinner(false); alert('Erreur réseau.'); });
});

$(document).on('click', '#btn-suppr-full', function () {
    if (!pwdOk) { alert('Mot de passe requis.'); return; }
    if (!confirm('Supprimer toutes les sauvegardes Full_*.sql sauf la plus récente ?\n\nCette opération est irréversible.')) return;
    spinner(true);
    $.post('clean.php', { action: 'supprimer_anciennes', type: 'full', password: $('#pwd-global').val() }, res => {
        spinner(false);
        if (res.ok) {
            setStatus(res.msg);
            chargerListeSauvegardesTotal();
            chargerListeSauvegardesTotal2();
            chargerFichiersTableRestore();
        } else {
            alert('Erreur : ' + res.msg);
        }
    }, 'json').fail(() => { spinner(false); alert('Erreur réseau.'); });
});

// ── Initialisation ────────────────────────────────────────────────
$(function () {
    chargerListeSauvegardes();
    chargerListeSauvegardesTotal();
    chargerListeSauvegardesTotal2();
    chargerFichiersTableRestore();
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
