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

    $cols = $pdo->query("SHOW COLUMNS FROM disponible LIKE 'DateCompetition'")->fetchAll();

    if (count($cols) > 0) {
        echo "La colonne DateCompetition existe déjà sur la table disponible. Rien à faire.\n";
    } else {
        $pdo->exec("ALTER TABLE disponible ADD COLUMN DateCompetition DATE DEFAULT NULL");
        echo "Colonne DateCompetition ajoutée avec succès à la table disponible.\n";
    }

    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Erreur : " . $e->getMessage() . "\n";
}
