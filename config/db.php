<?php
// ── Détection de l'environnement ─────────────────────────────────────────────
// Mettre ENV=production dans les variables d'environnement du serveur (Apache/IIS)
// ou créer un fichier .env.production à la racine pour basculer automatiquement.
$isProduction = (getenv('NIJAC_ENV') === 'production')
             || file_exists(__DIR__ . '/../.env.production');
define('IS_PRODUCTION', $isProduction);

// ── Configuration locale (WAMP / développement) ───────────────────────────────
if (!$isProduction) {
    define('DB_HOST',    '127.0.0.1');
    define('DB_PORT',    '3307');
    define('DB_NAME',    'nijac');
    define('DB_USER',    'root');
    define('DB_PASS',    '');
    define('APP_DEBUG',  true);

// ── Configuration serveur (production) ───────────────────────────────────────
} else {
    define('DB_HOST',    'localhost');
    define('DB_PORT',    '3306');          // Port MySQL standard en production
    define('DB_NAME',    'n42cfyle_nijac');
    define('DB_USER',    'n42cfyle_nijac');    // Utilisateur dédié (pas root)
    define('DB_PASS',    'A!h!Y4wG3Ka4Yj¡f');     // À remplacer par le vrai mot de passe
    define('APP_DEBUG',  false);
}

// ── Constantes communes ───────────────────────────────────────────────────────
define('DB_CHARSET',   'utf8mb4');
define('APP_VERSION',  '0.0.22');

// Seed secret pour l'obfuscation des identifiants JA dans les URL publiques
// (doit rester identique entre génération et décodage)
define('OBFUSCATOR_SEED', 167);

/**
 * Retourne une instance PDO partagée (singleton).
 */
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
