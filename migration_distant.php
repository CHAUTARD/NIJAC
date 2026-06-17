<?php
/**
 * NIJAC – Migration de la base distante (synchronisation avec la base locale)
 *
 * Suppression de la colonne `libelle` de la table `configuration`
 * (champ jugé redondant avec la clé, remplacé par `description`).
 *
 * Script idempotent : vérifie l'état actuel avant d'agir,
 * peut être exécuté plusieurs fois sans risque.
 *
 * Usage : ouvrir ce fichier dans un navigateur sur le serveur de production
 * (ex: https://monsite/migration_distant.php) puis le supprimer du serveur
 * une fois la migration effectuée.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getPDO();
    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n\n";

    // ── Suppression configuration.libelle ───────────────────────────────────
    $cols = $pdo->query("SHOW COLUMNS FROM configuration LIKE 'libelle'")->fetchAll();
    if (count($cols) === 0) {
        echo "[1/2] configuration.libelle : déjà absente, rien à faire.\n";
    } else {
        $pdo->exec("ALTER TABLE `configuration` DROP COLUMN `libelle`");
        echo "[1/2] configuration.libelle : colonne supprimée.\n";
    }

    // ── Suppression de la clé regle_dept_76 (remplacée par regles_departements) ──
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `configuration` WHERE `cle` = 'regle_dept_76'");
    $stmt->execute();
    if ($stmt->fetchColumn() === 0) {
        echo "[2/2] configuration.regle_dept_76 : déjà absente, rien à faire.\n";
    } else {
        $pdo->exec("DELETE FROM `configuration` WHERE `cle` = 'regle_dept_76'");
        echo "[2/2] configuration.regle_dept_76 : ligne supprimée.\n";
    }

    echo "\nMigration terminée avec succès.\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "\nErreur : " . $e->getMessage() . "\n";
}
