<?php
// ── Lecture du fichier .env (encodage ROT47) ─────────────────────────────────
// Le fichier .env est à la racine du projet, non versionné (voir .gitignore).
// ROT47 est son propre inverse : rot47(rot47($val)) === $val.
(function () {
    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
})();

function rot47(string $s): string {
    $out = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $c = ord($s[$i]);
        if ($c >= 33 && $c <= 126) $c = (($c - 33 + 47) % 94) + 33;
        $out .= chr($c);
    }
    return $out;
}

// ── Détection de l'environnement ─────────────────────────────────────────────
// Mettre NIJAC_ENV=production dans les variables d'environnement Apache
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
    define('DB_PORT',    '3306');
    define('DB_NAME',    rot47($_ENV['DB_NAME'] ?? ''));
    define('DB_USER',    rot47($_ENV['DB_USER'] ?? ''));
    define('DB_PASS',    rot47($_ENV['DB_PASS'] ?? ''));
    define('APP_DEBUG',  false);
}

// ── Constantes communes ───────────────────────────────────────────────────────
define('DB_CHARSET',   'utf8mb4');
define('APP_VERSION',  '0.1.02');

// Seed secret pour l'obfuscation des identifiants JA dans les URL publiques
// (doit rester identique entre génération et décodage)
define('OBFUSCATOR_SEED', 167);

function getFfttAppId(): string  { return rot47($_ENV['FFTT_APP_ID']  ?? ''); }
function getFfttAppKey(): string { return rot47($_ENV['FFTT_APP_KEY'] ?? ''); }

function getSmtpUser(): string     { return rot47($_ENV['SMTP_USER']     ?? ''); }
function getSmtpPassword(): string { return rot47($_ENV['SMTP_PASSWORD'] ?? ''); }

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
