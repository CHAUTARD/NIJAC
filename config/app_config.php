<?php
/**
 * Helpers de configuration applicative NIJAC.
 *
 * Nécessite que getPDO() soit disponible (config/db.php déjà chargé).
 * Cache statique : la BDD n'est interrogée qu'une seule fois par requête.
 */

/** Termine une action AJAX avec succès. */
function jsonOk(array $data = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data);
    exit;
}

/** Termine une action AJAX avec une erreur. */
function jsonError(string $msg, int $httpCode = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    if ($httpCode !== 200) http_response_code($httpCode);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

/**
 * Crée la table configuration si absente et insère les paramètres par défaut.
 */
function initTableConfiguration(\PDO $pdo): void
{
    // Ajout colonne Gentile sur table region (gentilé de la région, ex: "Normand(e)")
    $cols = array_column($pdo->query('SHOW COLUMNS FROM region')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    if (!in_array('Gentile', $cols)) {
        $pdo->exec("ALTER TABLE region ADD COLUMN Gentile VARCHAR(100) NULL AFTER nom");
        $pdo->exec("
            UPDATE region SET Gentile = CASE nom
                WHEN 'Normandie'              THEN 'Normand(e)'
                WHEN 'Île-de-France'          THEN 'Francilien(ne)'
                WHEN 'Bretagne'               THEN 'Breton(ne)'
                WHEN 'Pays de la Loire'       THEN 'Ligérien(ne)'
                WHEN 'Nouvelle-Aquitaine'     THEN 'Néo-Aquitain(e)'
                WHEN 'Occitanie'              THEN 'Occitan(e)'
                WHEN 'Auvergne-Rhône-Alpes'  THEN 'Auvergnat(e)-Rhônalpin(e)'
                WHEN 'Provence-Alpes-Côte d''Azur' THEN 'Provençal(e)'
                WHEN 'Grand Est'              THEN 'Grand-Estien(ne)'
                WHEN 'Hauts-de-France'        THEN 'Haut-de-Français(e)'
                WHEN 'Bourgogne-Franche-Comté' THEN 'Bourguignon(ne)-Franc-Comtois(e)'
                WHEN 'Centre-Val de Loire'    THEN 'Centrevalloirien(ne)'
                WHEN 'Corse'                  THEN 'Corse'
                WHEN 'Guadeloupe'             THEN 'Guadeloupéen(ne)'
                WHEN 'Martinique'             THEN 'Martiniquais(e)'
                WHEN 'Guyane'                 THEN 'Guyanais(e)'
                WHEN 'La Réunion'             THEN 'Réunionnais(e)'
                WHEN 'Mayotte'                THEN 'Mahorais(e)'
                ELSE nom
            END
        ");
    }

    // Renommage colonne ArbitrageObligatoire -> ArbitrageCRA sur la table division
    $divCols = array_column($pdo->query('SHOW COLUMNS FROM division')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    if (in_array('ArbitrageObligatoire', $divCols) && !in_array('ArbitrageCRA', $divCols)) {
        $pdo->exec("ALTER TABLE division CHANGE COLUMN ArbitrageObligatoire ArbitrageCRA TINYINT(1) NOT NULL DEFAULT 1");
    }

    // Colonnes pour le formulaire de désidératas club (E023, remplace le questionnaire Excel)
    $colsClub = array_column($pdo->query('SHOW COLUMNS FROM club')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    foreach ([
        'NbAiresJeu'          => 'INT NULL',
        'DesiderataNote'      => 'TEXT NULL',
        'DesiderataSaison'    => 'VARCHAR(9) NULL',
        'DesiderataDate'      => 'DATETIME NULL',
        'DesiderataEmailDate' => 'DATETIME NULL',
    ] as $col => $def) {
        if (!in_array($col, $colsClub)) $pdo->exec("ALTER TABLE club ADD COLUMN $col $def");
    }

    $colsSalle = array_column($pdo->query('SHOW COLUMNS FROM salle')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    if (!in_array('Telephone', $colsSalle)) {
        $pdo->exec("ALTER TABLE salle ADD COLUMN Telephone VARCHAR(20) NULL");
    }

    $colsEquipe = array_column($pdo->query('SHOW COLUMNS FROM equipe')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
    foreach ([
        'ReEngagement'     => "ENUM('O','N') NULL",
        'JourSouhaite'     => "ENUM('Samedi','Dimanche') NULL",
        'SouhaitJA'        => "ENUM('CRA','Club') NULL",
        'DesiderataSaison' => 'VARCHAR(9) NULL',
    ] as $col => $def) {
        if (!in_array($col, $colsEquipe)) $pdo->exec("ALTER TABLE equipe ADD COLUMN $col $def");
    }
}

/**
 * Retourne la valeur du marqueur {YEAR_PHASE} : les 4 premiers caractères de
 * la saison en Phase 1 (ex "2026"), la saison complète en Phase 2 (ex "2026-2027").
 * Phase courante déterminée à partir des bornes de config phase2_debut/phase2_fin (MM-JJ).
 */
function getAnneePhase(): string
{
    $saison = getConfig('saison', date('Y') . '-' . (date('Y') + 1));

    $today = new \DateTime();
    $md    = (int)$today->format('m') * 100 + (int)$today->format('d');
    $toMd  = fn(string $s) => (int)substr($s, 0, 2) * 100 + (int)substr($s, 3, 2);

    $p2Debut = $toMd(getConfig('phase2_debut', '02-01'));
    $p2Fin   = $toMd(getConfig('phase2_fin',   '06-30'));

    $enPhase2 = $md >= $p2Debut && $md <= $p2Fin;

    return $enPhase2 ? $saison : substr($saison, 0, 4);
}

/**
 * Retourne le gentilé de la région configurée (clé 'region' dans configuration).
 * Ex: "Normand(e)" pour "Normandie". Fallback sur le nom de la région.
 */
function getRegionGentile(): string
{
    $nom = getConfig('region', '');
    if ($nom === '') return '';
    try {
        $stmt = getPDO()->prepare("SELECT COALESCE(Gentile, nom) FROM region WHERE nom = ? LIMIT 1");
        $stmt->execute([$nom]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $nom;
    } catch (\Throwable $e) {
        return $nom;
    }
}

/**
 * Retourne toute la configuration sous forme de tableau associatif cle => valeur.
 */
function getAllConfig(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = [];
            $rows  = getPDO()->query('SELECT cle, valeur FROM configuration')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['cle']] = $r['valeur'];
            }
        } catch (\Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Retourne la valeur d'une clé de configuration.
 */
function getConfig(string $cle, string $defaut = ''): string
{
    return getAllConfig()[$cle] ?? $defaut;
}

/**
 * Retourne l'email effectif selon l'état du logiciel.
 * En mode Développement, redirige vers l'adresse configurée dans email_developpement.
 */
function getEmailDestinataire(string $email): string
{
    if (getConfig('etat_logiciel', 'Developpement') === 'Developpement') {
        return getConfig('email_developpement', 'patrick.chautard@free.fr');
    }
    return $email;
}

/**
 * Vérifie si l'utilisateur peut envoyer $nb emails supplémentaires.
 * Utilise une fenêtre glissante stockée en session.
 *
 * Retourne null si l'envoi est autorisé, ou un message d'erreur sinon.
 * Appeler enregistrerEnvois() après chaque email effectivement envoyé.
 */
function checkRateLimit(int $nb): ?string
{
    $max     = (int)getConfig('rate_limit_max',     '100');
    $fenetre = (int)getConfig('rate_limit_fenetre',  '10');
    $now     = time();
    $debut   = $now - $fenetre * 60;

    // Purger les horodatages expirés
    $_SESSION['nijac_rate_limit'] = array_values(array_filter(
        $_SESSION['nijac_rate_limit'] ?? [],
        fn(int $ts) => $ts > $debut
    ));

    $deja = count($_SESSION['nijac_rate_limit']);
    if ($deja + $nb > $max) {
        $plus_ancien  = $_SESSION['nijac_rate_limit'][0] ?? $now;
        $attente      = (int)ceil(($plus_ancien + $fenetre * 60 - $now) / 60);
        return "Limite d'envoi atteinte ({$max} emails / {$fenetre} min). "
             . "Il reste {$deja} email(s) comptabilisé(s). "
             . "Réessayez dans environ {$attente} minute(s).";
    }
    return null;
}

/**
 * Enregistre $nb envois réussis dans la fenêtre glissante.
 */
function enregistrerEnvois(int $nb): void
{
    $now = time();
    for ($i = 0; $i < $nb; $i++) {
        $_SESSION['nijac_rate_limit'][] = $now;
    }
}

/**
 * Retourne la liste des départements qu'un utilisateur est autorisé à voir,
 * en appliquant les règles d'association définies dans configuration.php
 * (paramètre 'regles_departements', ex: {"76":["27"]} → un utilisateur du 76
 * voit aussi les rencontres du 27).
 */
function getDepartementsAutorises(?string $deptUtilisateur): array
{
    if (!$deptUtilisateur) return [];
    $regles = json_decode(getConfig('regles_departements', '{}'), true) ?: [];
    $autorises = [$deptUtilisateur];
    if (!empty($regles[$deptUtilisateur]) && is_array($regles[$deptUtilisateur])) {
        $autorises = array_merge($autorises, $regles[$deptUtilisateur]);
    }
    return array_values(array_unique($autorises));
}

/**
 * Retourne les départements limitrophes de la région (Normandie) configurés.
 * Ces départements appartiennent aux régions voisines et peuvent être concernés
 * par des rencontres inter-régionales.
 * Chaque entrée : ['code' => '80', 'nom' => 'Somme', 'region' => 'Hauts-de-France']
 */
function getDepartementsLimitrophes(): array
{
    static $tous = [
        ['code' => '60', 'nom' => 'Oise',          'region' => 'Hauts-de-France'],
        ['code' => '80', 'nom' => 'Somme',          'region' => 'Hauts-de-France'],
        ['code' => '95', 'nom' => "Val-d'Oise",     'region' => 'Île-de-France'],
        ['code' => '78', 'nom' => 'Yvelines',       'region' => 'Île-de-France'],
        ['code' => '28', 'nom' => 'Eure-et-Loir',   'region' => 'Centre-Val de Loire'],
        ['code' => '53', 'nom' => 'Mayenne',         'region' => 'Pays de la Loire'],
        ['code' => '72', 'nom' => 'Sarthe',          'region' => 'Pays de la Loire'],
        ['code' => '35', 'nom' => 'Ille-et-Vilaine', 'region' => 'Bretagne'],
    ];

    $actifs = array_filter(array_map('trim', explode(',', getConfig('departements_limitrophes', '28,35,53,60,72,78,80,95'))));
    if (!$actifs) return [];

    return array_values(array_filter($tous, fn($d) => in_array($d['code'], $actifs, true)));
}

/**
 * Retourne les départements actifs (depuis departements_actifs en configuration)
 * avec leur nom (depuis la table departement), triés par code numérique.
 * Chaque entrée : ['code' => '14', 'nom' => 'Calvados']
 */
function getDeptActifs(): array
{
    $codesActifs = array_filter(array_map('trim', explode(',', getConfig('departements_actifs', ''))));
    if (!$codesActifs) return [];
    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare(
            "SELECT code, nom FROM departement
             WHERE CAST(code AS UNSIGNED) IN ($ph)
             ORDER BY CAST(code AS UNSIGNED)"
        );
        $stmt->execute(array_map('intval', $codesActifs));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Retourne une instance FfttApi configurée depuis .env (credentials obfusqués ROT47).
 * Le serial applicatif est généré une fois et stocké dans la table configuration.
 * Lance une exception si les credentials ne sont pas renseignés.
 */
function getFfttApi(): \FfttApi
{
    require_once __DIR__ . '/../Classes/FfttApi.php';

    $appId  = getFfttAppId();
    $appKey = getFfttAppKey();
    if ($appId === '' || $appKey === '') {
        throw new \RuntimeException('API FFTT non configurée. Renseignez FFTT_APP_ID et FFTT_APP_KEY dans .env.');
    }

    // Serial applicatif : généré une fois, persisté en configuration
    $serial    = getConfig('fftt_serial', '');
    $nouveauSerial = ($serial === '');
    if ($nouveauSerial) {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $serial = '';
        for ($i = 0; $i < 15; $i++) $serial .= $chars[random_int(0, 35)];
        getPDO()->prepare('INSERT INTO configuration (cle, valeur, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)')
                ->execute(['fftt_serial', $serial, 'Numéro de série applicatif généré pour l\'API FFTT.']);
    }

    $api = new \FfttApi($appId, $appKey, $serial);

    // Enregistrement du serial auprès de l'API FFTT lors de la première utilisation
    if ($nouveauSerial) {
        $api->initialize();
    }

    return $api;
}

/**
 * Indique si le logiciel est en mode développement.
 */
function isModeDeveloppement(): bool
{
    return getConfig('etat_logiciel', 'Developpement') === 'Developpement';
}

/**
 * Retourne une instance PHPMailer préconfigurée avec les paramètres SMTP.
 * Host/port/sécurité/expéditeur viennent de la table `configuration` ; l'utilisateur
 * et le mot de passe viennent de .env (SMTP_USER / SMTP_PASSWORD, encodés ROT47).
 * Lance une exception en cas d'erreur de configuration.
 */
function getNijacMailer(string $forcedPrefix = null): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet   = 'UTF-8';
    $mail->Encoding  = 'quoted-printable';

    $p = $forcedPrefix ?? 'smtp_';

    $debugLevel = (int)getConfig($p . 'debug', '0');
    $mail->SMTPDebug = $debugLevel;
    if ($debugLevel > 0) {
        $logFile = __DIR__ . '/../logs/smtp_debug.log';
        @mkdir(dirname($logFile), 0755, true);
        $mail->Debugoutput = function (string $str, int $level) use ($logFile) {
            file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $str . PHP_EOL, FILE_APPEND);
        };
    }
    $mail->Hostname  = gethostname() ?: 'nijac.ligue-normandie-tt.fr';

    $host   = getConfig($p . 'host', '');
    $secure = getConfig($p . 'secure', 'tls');
    $port   = (int)getConfig($p . 'port', '587');
    $auth   = getConfig($p . 'auth', '1') === '1';

    if ($host !== '') {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = $auth;
        $mail->SMTPSecure = $secure === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        if ($auth) {
            $mail->Username = getSmtpUser();
            $mail->Password = getSmtpPassword();
        }
    } else {
        throw new \RuntimeException('SMTP non configuré. Veuillez renseigner les paramètres SMTP dans la configuration.');
    }

    $mail->setFrom(
        getConfig($p . 'from', 'patrick.chautard@free.fr'),
        getConfig($p . 'from_name', 'NIJAC')
    );

    return $mail;
}
