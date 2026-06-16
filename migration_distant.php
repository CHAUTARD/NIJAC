<?php
/**
 * NIJAC – Migration de la base distante (synchronisation avec la base locale)
 *
 * Regroupe toutes les migrations de schéma effectuées en local et pas encore
 * appliquées en production :
 *   1. division.Color            (ajout colonne + couleurs par défaut)
 *   2. disponible.DateCompetition (ajout colonne)
 *   3. Suppression de la table `competition` et de la colonne
 *      rencontre.Id_Competition (remplacés par les paramètres
 *      indemnite_forfaitaire / frais_kilometrique dans `configuration`)
 *
 * Script idempotent : chaque étape vérifie l'état actuel avant d'agir,
 * il peut donc être exécuté plusieurs fois sans risque.
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

    // ── 1. division.Color ───────────────────────────────────────────────────
    $cols = $pdo->query("SHOW COLUMNS FROM division LIKE 'Color'")->fetchAll();
    if (count($cols) > 0) {
        echo "[1/3] division.Color : déjà présente, rien à faire.\n";
    } else {
        $pdo->exec("ALTER TABLE division ADD COLUMN Color VARCHAR(7) DEFAULT NULL");
        echo "[1/3] division.Color : colonne ajoutée.\n";

        $pdo->exec("UPDATE division SET Color = '#ffffff' WHERE Id_Division = 1");
        $pdo->exec("UPDATE division SET Color = '#82c8e5' WHERE Id_Division = 2");
        $pdo->exec("UPDATE division SET Color = '#ff7e70' WHERE Id_Division = 3");
        $pdo->exec("UPDATE division SET Color = '#fdfbd4' WHERE Id_Division = 4");
        $pdo->exec("UPDATE division SET Color = '#ffee8c' WHERE Id_Division = 5");
        $pdo->exec("UPDATE division SET Color = '#ffde21' WHERE Id_Division = 6");
        $pdo->exec("UPDATE division SET Color = '#efbf04' WHERE Id_Division = 7");
        $pdo->exec("UPDATE division SET Color = '#ff7e70' WHERE Id_Division = 8");
        $pdo->exec("UPDATE division SET Color = '#fdfbd4' WHERE Id_Division = 9");
        $pdo->exec("UPDATE division SET Color = '#D3D3D3' WHERE Id_Division = 10");
        echo "      Couleurs par défaut appliquées (Id_Division 1 à 10).\n";
    }

    // ── 2. disponible.DateCompetition ───────────────────────────────────────
    $cols = $pdo->query("SHOW COLUMNS FROM disponible LIKE 'DateCompetition'")->fetchAll();
    if (count($cols) > 0) {
        echo "[2/3] disponible.DateCompetition : déjà présente, rien à faire.\n";
    } else {
        $pdo->exec("ALTER TABLE disponible ADD COLUMN DateCompetition DATE DEFAULT NULL");
        echo "[2/3] disponible.DateCompetition : colonne ajoutée.\n";
    }

    // ── 3. Suppression rencontre.Id_Competition + table competition ────────
    $cols = array_column($pdo->query('DESCRIBE rencontre')->fetchAll(), 'Field');
    if (!in_array('Id_Competition', $cols, true)) {
        echo "[3/3] rencontre.Id_Competition : déjà absente, rien à faire.\n";
    } else {
        $fks = $pdo->query("
            SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre'
              AND COLUMN_NAME = 'Id_Competition' AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fks as $fk) {
            $pdo->exec("ALTER TABLE rencontre DROP FOREIGN KEY `$fk`");
            echo "      Contrainte FK $fk supprimée.\n";
        }

        $keys = $pdo->query("SHOW KEYS FROM rencontre WHERE Key_name = 'fk_rencontre_competition'")->fetchAll();
        if ($keys) {
            $pdo->exec("ALTER TABLE rencontre DROP INDEX fk_rencontre_competition");
            echo "      Index fk_rencontre_competition supprimé.\n";
        }

        $pdo->exec("ALTER TABLE rencontre DROP COLUMN Id_Competition");
        echo "[3/3] rencontre.Id_Competition : colonne supprimée.\n";
    }

    $pdo->exec("DROP TABLE IF EXISTS competition");
    echo "      Table competition : supprimée (si elle existait).\n";

    echo "\nMigration terminée avec succès.\n";
    echo "Les paramètres indemnite_forfaitaire / frais_kilometrique / url_ligue seront\n";
    echo "créés automatiquement dans `configuration` au premier accès à configuration.php.\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "\nErreur : " . $e->getMessage() . "\n";
}
