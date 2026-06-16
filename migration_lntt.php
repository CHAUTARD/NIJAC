<?php
/**
 * NIJAC – Migration : ajout de la colonne DateCompetition à la table disponible
 *
 * Script à exécuter une seule fois sur la base distante (ou locale).
 * Sans danger en cas de ré-exécution : vérifie l'existence de la colonne avant de l'ajouter.
 *
 * Usage : ouvrir ce fichier dans un navigateur (ex: https://monsite/migration_lntt.php)
 * puis le supprimer du serveur une fois la migration effectuée.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getPDO();

    $cols = $pdo->query("SHOW COLUMNS FROM division LIKE 'Color'")->fetchAll();

    if (count($cols) > 0) {
        echo "La colonne Color existe déjà sur la table division. Rien à faire.\n";
    } else {
        $pdo->exec("ALTER TABLE division ADD COLUMN Color VARCHAR(7) DEFAULT NULL");
        echo "Colonne Color ajoutée avec succès à la table division.\n";

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
    }

    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Erreur : " . $e->getMessage() . "\n";
}
