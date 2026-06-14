<?php
/**
 * Helpers de configuration applicative NIJAC.
 *
 * Nécessite que getPDO() soit disponible (config/db.php déjà chargé).
 * Cache statique : la BDD n'est interrogée qu'une seule fois par requête.
 */

/**
 * Crée la table configuration si absente et insère les paramètres par défaut.
 */
function initTableConfiguration(\PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `configuration` (
            `cle`         VARCHAR(50)  NOT NULL,
            `valeur`      TEXT         NOT NULL,
            `libelle`     VARCHAR(255) NOT NULL DEFAULT '',
            `description` TEXT         DEFAULT NULL,
            PRIMARY KEY (`cle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migration : élargir valeur si elle est encore VARCHAR(255)
    $pdo->exec("ALTER TABLE `configuration` MODIFY `valeur` TEXT NOT NULL");

    // ── Table messagerie ───────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `messagerie` (
            `Id_Messagerie`  int(11)      NOT NULL AUTO_INCREMENT,
            `Id_Utilisateur` int(11)      NOT NULL DEFAULT 0,
            `Type`           ENUM('Disponibilites','Convocation','Rappel dispo','Liste nomination') NOT NULL DEFAULT 'Disponibilites',
            `Sujet`          varchar(150) NOT NULL,
            `Message`        text         NOT NULL,
            PRIMARY KEY (`Id_Messagerie`),
            KEY `Id_Utilisateur` (`Id_Utilisateur`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migration : convertir Type en ENUM si c'était encore un CHAR (ou ajouter 'Liste nomination')
    $pdo->exec("ALTER TABLE `messagerie`
        MODIFY `Id_Utilisateur` int(11) NOT NULL DEFAULT 0,
        MODIFY `Type` ENUM('Disponibilites','Convocation','Rappel dispo','Liste nomination') NOT NULL DEFAULT 'Disponibilites'
    ");

    $pdo->exec("
        INSERT IGNORE INTO `configuration` (`cle`, `valeur`, `libelle`, `description`) VALUES
        ('etat_logiciel', 'Developpement', 'État du logiciel',
         'Opérationnel : emails envoyés aux destinataires réels.\nDéveloppement : emails redirigés vers l''adresse de développement.'),
        ('email_developpement', 'patrick.chautard@free.fr', 'Email de développement',
         'Adresse email vers laquelle tous les envois sont redirigés en mode Développement.'),
        ('departements_actifs', '14,27,50,61,76', 'Départements concernés',
         'Liste des numéros de départements gérés par la ligue, séparés par des virgules.'),
        ('regles_departements', '{\"76\":[\"27\"]}', 'Règles d\'association entre départements',
         'JSON : pour chaque département source, liste des départements automatiquement inclus.'),
        ('smtp_host',     'smtp.free.fr',                 'Serveur SMTP',         'Adresse du serveur SMTP sortant.'),
        ('smtp_port',     '587',                          'Port SMTP',            '587 pour STARTTLS, 465 pour SSL, 25 sans chiffrement.'),
        ('smtp_secure',   'tls',                          'Chiffrement SMTP',     'tls (STARTTLS), ssl ou vide.'),
        ('smtp_auth',     '1',                            'Authentification SMTP', '1 = authentification requise, 0 = anonyme.'),
        ('smtp_user',     'patrick.chautard@free.fr',     'Utilisateur SMTP',     'Login du compte SMTP.'),
        ('smtp_password', '#Henri.1957',                  'Mot de passe SMTP',    'Mot de passe du compte SMTP.'),
        ('smtp_from',     'patrick.chautard@free.fr',     'Email expéditeur',     'Adresse From des emails envoyés.'),
        ('smtp_from_name','NIJAC – Arbitrage Normandie',  'Nom expéditeur',       'Nom affiché dans le champ From.')
    ");

    // Forcer la mise à jour des paramètres SMTP (INSERT IGNORE ne met pas à jour les lignes existantes)
    $pdo->exec("
        INSERT INTO `configuration` (`cle`, `valeur`, `libelle`, `description`) VALUES
        ('smtp_host',     'smtp.free.fr',                 'Serveur SMTP',          'Adresse du serveur SMTP sortant.'),
        ('smtp_port',     '587',                          'Port SMTP',             '587 pour STARTTLS, 465 pour SSL, 25 sans chiffrement.'),
        ('smtp_secure',   'tls',                          'Chiffrement SMTP',      'tls (STARTTLS), ssl ou vide.'),
        ('smtp_auth',     '1',                            'Authentification SMTP',  '1 = authentification requise, 0 = anonyme.'),
        ('smtp_user',     'patrick.chautard@free.fr',     'Utilisateur SMTP',      'Login du compte SMTP.'),
        ('smtp_password', '#Henri.1957',                  'Mot de passe SMTP',     'Mot de passe du compte SMTP.'),
        ('smtp_from',     'patrick.chautard@free.fr',     'Email expéditeur',      'Adresse From des emails envoyés.'),
        ('smtp_from_name','NIJAC – Arbitrage Normandie',  'Nom expéditeur',        'Nom affiché dans le champ From.')
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)
    ");
    // Note : ON DUPLICATE KEY UPDATE ne s'applique qu'aux clés smtp_* — les autres params métier
    // utilisent INSERT IGNORE pour ne pas écraser les valeurs modifiées par l'utilisateur.
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
 * Indique si le logiciel est en mode développement.
 */
function isModeDeveloppement(): bool
{
    return getConfig('etat_logiciel', 'Developpement') === 'Developpement';
}

/**
 * Retourne une instance PHPMailer préconfigurée avec les paramètres SMTP de la table configuration.
 * Lance une exception en cas d'erreur de configuration.
 */
function getNijacMailer(): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet  = 'UTF-8';
    $mail->SMTPDebug = 0;

    $host   = getConfig('smtp_host', '');
    $secure = getConfig('smtp_secure', 'tls');
    $port   = (int)getConfig('smtp_port', '587');
    $auth   = getConfig('smtp_auth', '1') === '1';

    if ($host !== '') {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = $auth;
        $mail->SMTPSecure = $secure === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        if ($auth) {
            $mail->Username = getConfig('smtp_user', '');
            $mail->Password = getConfig('smtp_password', '');
        }
    } else {
        $mail->isMail(); // fallback mail()
    }

    $mail->setFrom(
        getConfig('smtp_from', 'patric.chautard@free.fr'),
        getConfig('smtp_from_name', 'Patrick C.')
    );

    return $mail;
}
