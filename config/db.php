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
define('APP_VERSION', '0.2.10');

// Seed secret pour l'obfuscation des identifiants JA dans les URL publiques
// (doit rester identique entre génération et décodage)
define('OBFUSCATOR_SEED', 167);

function getFfttAppId(): string  { return rot47($_ENV['FFTT_APP_ID']  ?? ''); }
function getFfttAppKey(): string { return rot47($_ENV['FFTT_APP_KEY'] ?? ''); }

function getSmtpUser(): string     { return rot47($_ENV['SMTP_USER']     ?? ''); }
function getSmtpPassword(): string { return rot47($_ENV['SMTP_PASSWORD'] ?? ''); }

/**
 * Démarre (ou reprend) la session PHP native, avec une durée de vie
 * prolongée à la place du défaut PHP (session.gc_maxlifetime ~1440s/24min).
 * Sans ça, une simple pause dans la saisie (désidératas, disponibilités,
 * formulaire long...) suffit à faire nettoyer le fichier de session par le
 * garbage collector PHP, et l'utilisateur se retrouve déconnecté et renvoyé
 * vers /login à la requête suivante. À appeler à la place de session_start()
 * partout où la session native est utilisée (filtres Auth/AdminAuth, actions
 * publiques tokenisées...).
 *
 * Isole aussi les fichiers de session NIJAC dans un sous-dossier dédié
 * plutôt que le session.save_path par défaut de PHP (F:/wamp64/tmp en local
 * WAMP), qui est PARTAGÉ par tous les vhosts de la machine. Chacun de ces
 * autres projets utilise le gc_maxlifetime par défaut (~1440s) : quand LEUR
 * garbage collector se déclenche (même sans rapport avec NIJAC), il supprime
 * tout fichier de ce dossier partagé plus vieux que LEUR propre
 * gc_maxlifetime — y compris les sessions NIJAC, malgré l'ini_set()
 * ci-dessous qui ne protège que les requêtes NIJAC elles-mêmes. C'était la
 * cause des déconnexions aléatoires vers /login en pleine utilisation. Le
 * GC fichier de PHP ne parcourt pas les sous-dossiers d'un save_path
 * "classique" (sans préfixe "N;"), donc un sous-dossier dédié met NIJAC à
 * l'abri du GC des autres projets.
 */
function demarrerSessionNijac(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $base = rtrim((string) ini_get('session.save_path'), '/\\');
    if ($base === '') {
        $base = sys_get_temp_dir();
    }
    $sessionDir = $base . DIRECTORY_SEPARATOR . 'nijac_sessions';
    if (is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) {
        session_save_path($sessionDir);
    }
    // Sinon (dossier non créable) : on continue avec le save_path par défaut
    // plutôt que de bloquer l'application.

    $dureeSecondes = 6 * 3600; // 6 heures
    ini_set('session.gc_maxlifetime', (string) $dureeSecondes);
    session_set_cookie_params(['lifetime' => $dureeSecondes]);
    session_start();
}

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
