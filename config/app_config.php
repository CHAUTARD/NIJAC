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
            `valeur`      VARCHAR(255) NOT NULL DEFAULT '',
            `libelle`     VARCHAR(255) NOT NULL DEFAULT '',
            `description` TEXT         DEFAULT NULL,
            PRIMARY KEY (`cle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        INSERT IGNORE INTO `configuration` (`cle`, `valeur`, `libelle`, `description`) VALUES
        ('etat_logiciel', 'Developpement', 'État du logiciel',
         'Opérationnel : emails envoyés aux destinataires réels.\nDéveloppement : emails redirigés vers l''adresse de développement.'),
        ('email_developpement', 'patrick.chautard@free.fr', 'Email de développement',
         'Adresse email vers laquelle tous les envois sont redirigés en mode Développement.')
    ");
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
