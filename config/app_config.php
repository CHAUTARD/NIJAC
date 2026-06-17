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
            `description` TEXT         DEFAULT NULL,
            PRIMARY KEY (`cle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migration : élargir valeur si elle est encore VARCHAR(255)
    $pdo->exec("ALTER TABLE `configuration` MODIFY `valeur` TEXT NOT NULL");
    // Migration : supprimer la colonne libelle devenue inutile
    try {
        $pdo->exec("ALTER TABLE `configuration` DROP COLUMN `libelle`");
    } catch (\Throwable $e) { /* colonne déjà absente */ }

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

    // ── Migration : fusionner nomination_frais dans nomination ─────────────
    $pdo->exec("ALTER TABLE `nomination`
        ADD COLUMN IF NOT EXISTS `Peages`             decimal(8,2) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `Kilometres`         int(11)      DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `RapportAccueil`     text         DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `RapportEquipements` text         DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `DateSaisie`         date         DEFAULT NULL
    ");
    try {
        $pdo->exec("ALTER TABLE `nomination` ADD UNIQUE KEY `uq_ja_renc` (`Id_JA`,`Id_Rencontre`)");
    } catch (\Throwable $e) { /* clé déjà présente */ }
    // Copier les frais existants puis supprimer l'ancienne table
    try {
        $pdo->exec("
            UPDATE `nomination` n
            JOIN `nomination_frais` nf ON nf.Id_JA = n.Id_JA AND nf.Id_Rencontre = n.Id_Rencontre
            SET n.Peages             = nf.Peages,
                n.Kilometres         = nf.Kilometres,
                n.RapportAccueil     = nf.RapportAccueil,
                n.RapportEquipements = nf.RapportEquipements,
                n.DateSaisie         = nf.DateSaisie
            WHERE n.Peages IS NULL
        ");
        $pdo->exec("DROP TABLE IF EXISTS `nomination_frais`");
    } catch (\Throwable $e) { /* table déjà supprimée */ }

    $pdo->exec("
        INSERT IGNORE INTO `configuration` (`cle`, `valeur`, `description`) VALUES
        ('etat_logiciel', 'Developpement',
         'Opérationnel : emails envoyés aux destinataires réels.\nDéveloppement : emails redirigés vers l''adresse de développement.'),
        ('email_developpement', 'patrick.chautard@free.fr',
         'Adresse email vers laquelle tous les envois sont redirigés en mode Développement.'),
        ('departements_actifs', '14,27,50,61,76',
         'Liste des numéros de départements gérés par la ligue, séparés par des virgules.'),
        ('frais_max_peages',    '80',  'Montant maximum accepté pour les péages d''un JA (en euros).'),
        ('frais_max_km',        '200', 'Nombre de kilomètres maximum accepté pour un déplacement JA.'),
        ('rate_limit_max',      '100', 'Nombre maximum d''emails envoyables sur la fenêtre glissante.'),
        ('rate_limit_fenetre',  '10',  'Durée en minutes de la fenêtre glissante pour le rate limiting.'),
        ('regles_departements', '{\"76\":[\"27\"]}',
         'JSON : pour chaque département source, liste des départements automatiquement inclus.'),
        ('url_ligue', 'https://www.ligue-normandie-tt.fr', 'Adresse du serveur de la Ligue de Normandie de Tennis de Table.'),
        ('indemnite_forfaitaire', '25.00', 'Indemnité forfaitaire versée au JA pour une rencontre (anciennement table Competition).'),
        ('frais_kilometrique', '0.30', 'Barème kilométrique appliqué aux frais de déplacement du JA (anciennement table Competition).'),
        ('smtp_host',     'smtp.free.fr',                 'Adresse du serveur SMTP sortant.'),
        ('smtp_port',     '587',                          '587 pour STARTTLS, 465 pour SSL, 25 sans chiffrement.'),
        ('smtp_secure',   'tls',                          'tls (STARTTLS), ssl ou vide.'),
        ('smtp_auth',     '1',                            '1 = authentification requise, 0 = anonyme.'),
        ('smtp_user',     'patrick.chautard@free.fr',     'Login du compte SMTP.'),
        ('smtp_password', '#Henri.1957',                  'Mot de passe du compte SMTP.'),
        ('smtp_from',     'patrick.chautard@free.fr',     'Adresse From des emails envoyés.'),
        ('smtp_from_name','NIJAC – Arbitrage Normandie',  'Nom affiché dans le champ From.')
    ");

    // Forcer la mise à jour des paramètres SMTP (INSERT IGNORE ne met pas à jour les lignes existantes)
    $pdo->exec("
        INSERT INTO `configuration` (`cle`, `valeur`, `description`) VALUES
        ('smtp_host',     'smtp.free.fr',                 'Adresse du serveur SMTP sortant.'),
        ('smtp_port',     '587',                          '587 pour STARTTLS, 465 pour SSL, 25 sans chiffrement.'),
        ('smtp_secure',   'tls',                          'tls (STARTTLS), ssl ou vide.'),
        ('smtp_auth',     '1',                            '1 = authentification requise, 0 = anonyme.'),
        ('smtp_user',     'patrick.chautard@free.fr',     'Login du compte SMTP.'),
        ('smtp_password', '#Henri.1957',                  'Mot de passe du compte SMTP.'),
        ('smtp_from',     'patrick.chautard@free.fr',     'Adresse From des emails envoyés.'),
        ('smtp_from_name','NIJAC – Arbitrage Normandie',  'Nom affiché dans le champ From.')
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
