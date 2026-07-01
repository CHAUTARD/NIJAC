<?php
/**
 * NIJAC – Migration : retrait des identifiants SMTP en clair de la table `configuration`
 *
 * `smtp_user` et `smtp_password` sont désormais lus depuis `.env` (SMTP_USER / SMTP_PASSWORD,
 * encodés ROT47 comme DB_USER/DB_PASS/FFTT_APP_ID/FFTT_APP_KEY). Ce script supprime les deux
 * lignes correspondantes de la table `configuration` une fois que .env est correctement renseigné.
 *
 * Sécurité : le script refuse de supprimer les lignes si .env n'a pas SMTP_USER/SMTP_PASSWORD
 * configurés, pour ne jamais couper l'envoi d'email par accident.
 *
 * Script idempotent : vérifie l'état actuel avant d'agir, peut être exécuté plusieurs fois.
 *
 * Usage : ouvrir ce fichier dans un navigateur sur le serveur cible
 * (ex: https://monsite/migration_secrets_smtp_env.php) puis le supprimer une fois effectué.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app_config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getPDO();
    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n\n";

    if (getSmtpUser() === '' || getSmtpPassword() === '') {
        echo "SMTP_USER / SMTP_PASSWORD non renseignés (ou non décodables) dans .env.\n";
        echo "Rien n'a été supprimé de la table configuration pour ne pas couper l'envoi d'email.\n";
        echo "Renseignez .env (voir .env.example, encodage via tools/rot47.php) puis relancez ce script.\n";
        exit;
    }

    foreach (['smtp_user', 'smtp_password'] as $cle) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM configuration WHERE cle = ?');
        $stmt->execute([$cle]);
        if ((int)$stmt->fetchColumn() === 0) {
            echo "configuration.$cle : déjà absente, rien à faire.\n";
        } else {
            $pdo->prepare('DELETE FROM configuration WHERE cle = ?')->execute([$cle]);
            echo "configuration.$cle : ligne supprimée (valeur désormais lue depuis .env).\n";
        }
    }

    echo "\nMigration terminée avec succès.\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "\nErreur : " . $e->getMessage() . "\n";
}
