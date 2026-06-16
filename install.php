<?php
/**
 * NIJAC – Script d'initialisation de la base de données
 *
 * À exécuter UNE SEULE FOIS sur le serveur distant pour créer
 * les tables et insérer les données de référence.
 *
 * SÉCURITÉ : protégé par un jeton URL. Supprimer ce fichier
 * après exécution réussie.
 *
 * Usage : https://votre-domaine/nijac/install.php?token=NIJAC_INSTALL_2026
 */

// ── Jeton de sécurité ────────────────────────────────────────────────────────
define('INSTALL_TOKEN', 'NIJAC_INSTALL_2026');

if (($_GET['token'] ?? '') !== INSTALL_TOKEN) {
    http_response_code(403);
    die('Accès refusé. Ajoutez ?token=' . INSTALL_TOKEN . ' à l\'URL.');
}

// Connexion directe — indépendante de la détection prod/local de db.php
// Modifier ici si nécessaire avant de déployer.
define('INSTALL_DB_HOST',    'localhost');
define('INSTALL_DB_PORT',    '3306');
define('INSTALL_DB_NAME',    'n42cfyle_nijac');
define('INSTALL_DB_USER',    'n42cfyle_nijac');
define('INSTALL_DB_PASS',    'A!h!Y4wG3Ka4Yj¡f');
define('INSTALL_DB_CHARSET', 'utf8mb4');

function getInstallPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            INSTALL_DB_HOST, INSTALL_DB_PORT, INSTALL_DB_NAME, INSTALL_DB_CHARSET);
        $pdo = new PDO($dsn, INSTALL_DB_USER, INSTALL_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Charger app_config uniquement pour initTableConfiguration()
// (getPDO() n'est pas utilisé ici — on utilise getInstallPDO())
if (!function_exists('initTableConfiguration')) {
    require_once __DIR__ . '/config/app_config.php';
}

// ── Helpers d'affichage ──────────────────────────────────────────────────────
$steps  = [];
$errors = [];

function step(string $label, callable $fn): void {
    global $steps, $errors;
    try {
        $fn();
        $steps[] = ['ok' => true,  'label' => $label, 'msg' => ''];
    } catch (Throwable $e) {
        $steps[] = ['ok' => false, 'label' => $label, 'msg' => $e->getMessage()];
        $errors[] = $label;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>NIJAC – Installation</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f4fa; margin: 0; padding: 2rem; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.1); max-width: 860px; margin: auto; overflow: hidden; }
        .card-header { background: #1a3a6b; color: #fff; padding: 1rem 1.5rem; font-size: 1.1rem; font-weight: 700; }
        .card-body { padding: 1.5rem; }
        .step { display: flex; align-items: flex-start; gap: .75rem; padding: .45rem 0; border-bottom: 1px solid #f0f0f0; font-size: .9rem; }
        .step:last-child { border-bottom: none; }
        .icon-ok  { color: #2e7d32; font-size: 1.1rem; flex-shrink: 0; }
        .icon-err { color: #c62828; font-size: 1.1rem; flex-shrink: 0; }
        .err-msg  { color: #c62828; font-size: .8rem; margin-top: .2rem; font-family: monospace; }
        .summary  { margin-top: 1.5rem; padding: 1rem 1.25rem; border-radius: 8px; font-weight: 700; }
        .summary.ok  { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .summary.err { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
        .warn { background: #fff8e1; color: #e65100; border-left: 4px solid #ffa000; padding: .75rem 1rem; border-radius: 6px; margin-top: 1rem; font-size: .875rem; }
        h3 { margin: 1.25rem 0 .5rem; color: #1a3a6b; font-size: .95rem; text-transform: uppercase; letter-spacing: .05em; }
        pre { background: #f8fafc; border: 1px solid #dde5f0; border-radius: 6px; padding: .75rem; font-size: .78rem; overflow-x: auto; margin: 0; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">⚙️ NIJAC – Initialisation de la base de données</div>
    <div class="card-body">
<?php

try {
    $pdo = getInstallPDO();
} catch (Throwable $e) {
    echo '<div class="summary err">❌ Impossible de se connecter à la base de données :<br>'
       . '<code>' . htmlspecialchars($e->getMessage()) . '</code><br><br>'
       . 'Vérifiez les constantes INSTALL_DB_* en haut du fichier install.php.</div>';
    echo '</div></div></body></html>';
    exit;
}

echo '<div style="background:#e3f2fd;border-left:4px solid #1a3a6b;padding:.6rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.875rem;">'
   . '🔌 Connecté à <strong>' . INSTALL_DB_HOST . ':' . INSTALL_DB_PORT . '</strong> → <strong>' . INSTALL_DB_NAME . '</strong></div>';

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// ════════════════════════════════════════════════════════════════════════════
// 1. STRUCTURE DES TABLES
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>1. Création des tables</h3>';

step('Table region', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `region` (
        `code`      varchar(2)   NOT NULL,
        `nom`       varchar(255) NOT NULL,
        `chef_lieu` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table departement', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `departement` (
        `code`        varchar(3)   NOT NULL,
        `nom`         varchar(255) NOT NULL,
        `code_region` varchar(2)   DEFAULT NULL,
        PRIMARY KEY (`code`),
        KEY `Departement_Region` (`code_region`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table club', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `club` (
        `Id_Club` int(11)     NOT NULL AUTO_INCREMENT,
        `Nom`     varchar(50) NOT NULL,
        PRIMARY KEY (`Id_Club`),
        UNIQUE KEY `Nom` (`Nom`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table competition', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `competition` (
        `Id_Competition`      int(11)        NOT NULL AUTO_INCREMENT,
        `Nom`                 varchar(100)   DEFAULT NULL,
        `IndemniteForfaitaire` decimal(6,2)  DEFAULT NULL,
        `FraisKilometrique`   decimal(4,2)   DEFAULT NULL,
        PRIMARY KEY (`Id_Competition`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table division', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `division` (
        `Id_Division`          int(11)      NOT NULL AUTO_INCREMENT,
        `Division`             varchar(50)  DEFAULT NULL,
        `Nom`                  varchar(100) DEFAULT NULL,
        `ArbitrageObligatoire` tinyint(1)   NOT NULL DEFAULT 1,
        `Ord`                  int(11)      DEFAULT NULL,
        PRIMARY KEY (`Id_Division`),
        UNIQUE KEY `Nom` (`Nom`),
        UNIQUE KEY `Ord` (`Ord`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table laposte', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `laposte` (
        `Id_LaPoste`  int(11)      NOT NULL AUTO_INCREMENT,
        `CodePostal`  varchar(10)  DEFAULT NULL,
        `Nom`         varchar(255) DEFAULT NULL,
        `Latitude`    decimal(10,7) DEFAULT NULL,
        `Longitude`   decimal(10,7) DEFAULT NULL,
        PRIMARY KEY (`Id_LaPoste`),
        KEY `idx_cp` (`CodePostal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table ja', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `ja` (
        `Id_JA`          int(11)      NOT NULL AUTO_INCREMENT,
        `Nom`            varchar(50)  DEFAULT NULL,
        `Prenom`         varchar(50)  DEFAULT NULL,
        `Email`          varchar(100) DEFAULT NULL,
        `Telephone`      varchar(20)  DEFAULT NULL,
        `Grade`          varchar(3)   DEFAULT NULL,
        `Actif`          tinyint(1)   DEFAULT 1,
        `Id_Club`        int(11)      DEFAULT NULL,
        `DistanceMaxKm`  int(11)      DEFAULT NULL,
        `Defiscalisation` tinyint(1)  NOT NULL DEFAULT 0,
        `Note`           text         DEFAULT NULL,
        `Id_LaPoste`     int(11)      DEFAULT NULL,
        `Cp`             varchar(10)  DEFAULT NULL,
        `Ville`          varchar(100) DEFAULT NULL,
        PRIMARY KEY (`Id_JA`),
        KEY `fk_ja_club`    (`Id_Club`),
        KEY `fk_ja_laposte` (`Id_LaPoste`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table salle', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `salle` (
        `Id_Salle`      int(11)      NOT NULL AUTO_INCREMENT,
        `Nom`           varchar(100) NOT NULL,
        `Adresse`       varchar(255) DEFAULT NULL,
        `Id_Laposte`    int(11)      DEFAULT NULL,
        `Id_Club`       int(11)      DEFAULT NULL,
        `EstPrincipale` tinyint(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (`Id_Salle`),
        KEY `fk_salle_laposte` (`Id_Laposte`),
        KEY `fk_salle_club`    (`Id_Club`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table equipe', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `equipe` (
        `Id_Equipe`   int(11)      NOT NULL AUTO_INCREMENT,
        `Nom`         varchar(100) DEFAULT NULL,
        `Id_Division` int(11)      DEFAULT NULL,
        `Id_Club`     int(11)      NOT NULL,
        PRIMARY KEY (`Id_Equipe`),
        UNIQUE KEY `uq_equipe_nom_div` (`Nom`(80), `Id_Division`),
        KEY `fk_equipe_division` (`Id_Division`),
        KEY `fk_equipe_club`     (`Id_Club`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table rencontre', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `rencontre` (
        `Id_Rencontre`         int(11)      NOT NULL AUTO_INCREMENT,
        `Date`                 date         NOT NULL,
        `Heure`                time         NOT NULL,
        `Id_Division`          int(11)      DEFAULT NULL,
        `Poule`                tinyint(11)  NOT NULL,
        `Id_EquipeDom`         int(11)      NOT NULL,
        `Id_EquipeExt`         int(11)      DEFAULT NULL,
        `Phase`                tinyint(1)   NOT NULL,
        `Id_Competition`       int(11)      DEFAULT NULL,
        `Saison`               varchar(9)   DEFAULT NULL,
        `Journee`              tinyint(4)   DEFAULT NULL,
        `id_Salle`             int(11)      DEFAULT NULL,
        `ArbitrageObligatoire` tinyint(1)   NOT NULL DEFAULT 1,
        `Commentaire`          varchar(500) DEFAULT NULL,
        PRIMARY KEY (`Id_Rencontre`),
        KEY `fk_rencontre_division`   (`Id_Division`),
        KEY `fk_rencontre_equipe_dom` (`Id_EquipeDom`),
        KEY `fk_rencontre_equipe_ext` (`Id_EquipeExt`),
        KEY `fk_rencontre_competition`(`Id_Competition`),
        KEY `fk_rencontre_salle`      (`id_Salle`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table correspondant', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `correspondant` (
        `Id_Correspondant` int(11)      NOT NULL AUTO_INCREMENT,
        `Id_Club`          int(11)      NOT NULL,
        `Nom`              varchar(100) NOT NULL,
        `Email`            varchar(100) DEFAULT NULL,
        `Telephone`        varchar(20)  DEFAULT NULL,
        `Fonction`         varchar(50)  DEFAULT NULL,
        PRIMARY KEY (`Id_Correspondant`),
        KEY `fk_correspondant_club` (`Id_Club`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table disponible', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `disponible` (
        `Id_Disponible` int(11)              NOT NULL AUTO_INCREMENT,
        `Id_JA`         int(11)              DEFAULT NULL,
        `DateCompetition` date               DEFAULT NULL,
        `Reponse`       enum('O','P','N')    DEFAULT 'N',
        `DateReponse`   date                 DEFAULT NULL,
        `Id_Rencontre`  int(11)              DEFAULT NULL,
        `Preference`    tinyint(4)           DEFAULT NULL COMMENT '1=faible … 10=forte',
        PRIMARY KEY (`Id_Disponible`),
        UNIQUE KEY `uq_dispo` (`Id_JA`, `Id_Rencontre`),
        KEY `Id_Rencontre` (`Id_Rencontre`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table nomination', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `nomination` (
        `id_Nomination`      int(11)      NOT NULL AUTO_INCREMENT,
        `Id_JA`              int(11)      DEFAULT NULL,
        `Id_Rencontre`       int(11)      DEFAULT NULL,
        `Peages`             decimal(8,2) DEFAULT NULL,
        `Kilometres`         int(11)      DEFAULT NULL,
        `RapportAccueil`     text         DEFAULT NULL,
        `RapportEquipements` text         DEFAULT NULL,
        `DateSaisie`         date         DEFAULT NULL,
        `DateNomination`     date         NOT NULL,
        `Valide`             tinyint(1)   NOT NULL DEFAULT 0,
        `EmailEnvoye`        tinyint(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (`id_Nomination`),
        UNIQUE KEY `uq_ja_renc` (`Id_JA`, `Id_Rencontre`),
        KEY `fk_nomination_rencontre` (`Id_Rencontre`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table utilisateur', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `utilisateur` (
        `Id_Utilisateur` int(11)      NOT NULL AUTO_INCREMENT,
        `Login`          varchar(50)  DEFAULT NULL,
        `Password`       varchar(255) DEFAULT NULL,
        `Nom`            varchar(50)  DEFAULT NULL,
        `Prenom`         varchar(50)  DEFAULT NULL,
        `Role`           varchar(30)  DEFAULT NULL,
        `Actif`          tinyint(1)   DEFAULT 1,
        `Id_Departement` int(11)      DEFAULT NULL,
        `ChangeLogin`    tinyint(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (`Id_Utilisateur`),
        KEY `fk_utilisateur_departement` (`Id_Departement`) USING BTREE
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table messagerie', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `messagerie` (
        `Id_Messagerie`  int(11)      NOT NULL AUTO_INCREMENT,
        `Id_Utilisateur` int(11)      NOT NULL DEFAULT 0,
        `Type`           ENUM('Disponibilites','Convocation','Rappel dispo','Liste nomination') NOT NULL DEFAULT 'Disponibilites',
        `Sujet`          varchar(150) NOT NULL,
        `Message`        text         NOT NULL,
        PRIMARY KEY (`Id_Messagerie`),
        KEY `Id_Utilisateur` (`Id_Utilisateur`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

step('Table configuration', fn() => $pdo->exec("
    CREATE TABLE IF NOT EXISTS `configuration` (
        `cle`         varchar(50)  NOT NULL,
        `valeur`      text         NOT NULL,
        `libelle`     varchar(255) NOT NULL DEFAULT '',
        `description` text         DEFAULT NULL,
        PRIMARY KEY (`cle`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
"));

// ════════════════════════════════════════════════════════════════════════════
// 2. CLÉS ÉTRANGÈRES
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>2. Contraintes d\'intégrité</h3>';

step('FK departement → region', function() use ($pdo) {
    try { $pdo->exec("ALTER TABLE `departement` ADD CONSTRAINT `Departement_Region` FOREIGN KEY (`code_region`) REFERENCES `region` (`code`) ON UPDATE CASCADE"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
});

step('FK correspondant → club', function() use ($pdo) {
    try { $pdo->exec("ALTER TABLE `correspondant` ADD CONSTRAINT `fk_correspondant_club` FOREIGN KEY (`Id_Club`) REFERENCES `club` (`Id_Club`) ON DELETE CASCADE ON UPDATE CASCADE"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
});

step('FK equipe → club, division', function() use ($pdo) {
    try { $pdo->exec("ALTER TABLE `equipe` ADD CONSTRAINT `fk_equipe_club` FOREIGN KEY (`Id_Club`) REFERENCES `club` (`Id_Club`) ON DELETE CASCADE ON UPDATE CASCADE"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
    try { $pdo->exec("ALTER TABLE `equipe` ADD CONSTRAINT `fk_equipe_division` FOREIGN KEY (`Id_Division`) REFERENCES `division` (`Id_Division`) ON DELETE SET NULL ON UPDATE CASCADE"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
});

step('FK disponible → ja, rencontre', function() use ($pdo) {
    try { $pdo->exec("ALTER TABLE `disponible` ADD CONSTRAINT `fk_disponible_ja` FOREIGN KEY (`Id_JA`) REFERENCES `ja` (`Id_JA`) ON DELETE CASCADE ON UPDATE CASCADE"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
    try { $pdo->exec("ALTER TABLE `disponible` ADD CONSTRAINT `disponible_ibfk_1` FOREIGN KEY (`Id_Rencontre`) REFERENCES `rencontre` (`Id_Rencontre`)"); }
    catch (Throwable $e) { if (!str_contains($e->getMessage(), 'Duplicate')) throw $e; }
});

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ════════════════════════════════════════════════════════════════════════════
// 3. DONNÉES DE RÉFÉRENCE
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>3. Données de référence</h3>';

step('Régions', fn() => $pdo->exec("
    INSERT IGNORE INTO `region` (`code`, `nom`, `chef_lieu`) VALUES
    ('01','Guadeloupe','Basse-Terre'),('02','Martinique','Fort-de-France'),
    ('03','Guyane','Cayenne'),('04','La Réunion','Saint-Denis'),
    ('06','Mayotte','Mamoudzou'),('11','Île-de-France','Paris'),
    ('24','Centre-Val de Loire','Orléans'),('27','Bourgogne-Franche-Comté','Dijon'),
    ('28','Normandie','Rouen'),('32','Hauts-de-France','Lille'),
    ('44','Grand Est','Strasbourg'),('52','Pays de la Loire','Nantes'),
    ('53','Bretagne','Rennes'),('75','Nouvelle-Aquitaine','Bordeaux'),
    ('76','Occitanie','Toulouse'),('84','Auvergne-Rhône-Alpes','Lyon'),
    ('93','Provence-Alpes-Côte d\\'Azur','Marseille'),('94','Corse','Ajaccio')
"));

step('Départements', fn() => $pdo->exec("
    INSERT IGNORE INTO `departement` (`code`, `nom`, `code_region`) VALUES
    ('01','Ain','84'),('02','Aisne','32'),('03','Allier','84'),
    ('04','Alpes-de-Haute-Provence','93'),('05','Hautes-Alpes','93'),
    ('06','Alpes-Maritimes','93'),('07','Ardèche','84'),('08','Ardennes','44'),
    ('09','Ariège','76'),('10','Aube','44'),('11','Aude','76'),('12','Aveyron','76'),
    ('13','Bouches-du-Rhône','93'),('14','Calvados','28'),('15','Cantal','84'),
    ('16','Charente','75'),('17','Charente-Maritime','75'),('18','Cher','24'),
    ('19','Corrèze','75'),('21','Côte-d\\'Or','27'),('22','Côtes-d\\'Armor','53'),
    ('23','Creuse','75'),('24','Dordogne','75'),('25','Doubs','27'),('26','Drôme','84'),
    ('27','Eure','28'),('28','Eure-et-Loir','24'),('29','Finistère','53'),
    ('2A','Corse-du-Sud','94'),('2B','Haute-Corse','94'),
    ('30','Gard','76'),('31','Haute-Garonne','76'),('32','Gers','76'),
    ('33','Gironde','75'),('34','Hérault','76'),('35','Ille-et-Vilaine','53'),
    ('36','Indre','24'),('37','Indre-et-Loire','24'),('38','Isère','84'),
    ('39','Jura','27'),('40','Landes','75'),('41','Loir-et-Cher','24'),
    ('42','Loire','84'),('43','Haute-Loire','84'),('44','Loire-Atlantique','52'),
    ('45','Loiret','24'),('46','Lot','76'),('47','Lot-et-Garonne','75'),
    ('48','Lozère','76'),('49','Maine-et-Loire','52'),('50','Manche','28'),
    ('51','Marne','44'),('52','Haute-Marne','44'),('53','Mayenne','52'),
    ('54','Meurthe-et-Moselle','44'),('55','Meuse','44'),('56','Morbihan','53'),
    ('57','Moselle','44'),('58','Nièvre','27'),('59','Nord','32'),('60','Oise','32'),
    ('61','Orne','28'),('62','Pas-de-Calais','32'),('63','Puy-de-Dôme','84'),
    ('64','Pyrénées-Atlantiques','75'),('65','Hautes-Pyrénées','76'),
    ('66','Pyrénées-Orientales','76'),('67','Bas-Rhin','44'),('68','Haut-Rhin','44'),
    ('69','Rhône','84'),('70','Haute-Saône','27'),('71','Saône-et-Loire','27'),
    ('72','Sarthe','52'),('73','Savoie','84'),('74','Haute-Savoie','84'),
    ('75','Paris','11'),('76','Seine-Maritime','28'),('77','Seine-et-Marne','11'),
    ('78','Yvelines','11'),('79','Deux-Sèvres','75'),('80','Somme','32'),
    ('81','Tarn','76'),('82','Tarn-et-Garonne','76'),('83','Var','93'),
    ('84','Vaucluse','93'),('85','Vendée','52'),('86','Vienne','75'),
    ('87','Haute-Vienne','75'),('88','Vosges','44'),('89','Yonne','27'),
    ('90','Territoire de Belfort','27'),('91','Essonne','11'),
    ('92','Hauts-de-Seine','11'),('93','Seine-Saint-Denis','11'),
    ('94','Val-de-Marne','11'),('95','Val-d\\'Oise','11'),
    ('971','Guadeloupe','01'),('972','Martinique','02'),
    ('973','Guyane','03'),('974','La Réunion','04'),('976','Mayotte','06')
"));

step('Compétition', fn() => $pdo->exec("
    INSERT IGNORE INTO `competition` (`Id_Competition`, `Nom`, `IndemniteForfaitaire`, `FraisKilometrique`)
    VALUES (1, 'CHAMPIONNAT DE FRANCE PAR EQUIPE', 25.00, 0.30)
"));

step('Divisions', fn() => $pdo->exec("
    INSERT IGNORE INTO `division` (`Id_Division`, `Division`, `Nom`, `ArbitrageObligatoire`, `Ord`) VALUES
    (1,'R3M','Régionale 3',0,10),(2,'R2M','Régionale 2',1,20),
    (3,'R1M','Régionale 1',1,30),(4,'PNM','Prés Nationale',1,40),
    (5,'N3M','Nationale 3',1,50),(6,'N2M','Nationale 2',1,60),
    (7,'N1M','Nationale 1',1,70),(8,'R1F','Régionale Dame',1,200),
    (9,'PNF','Prés-nationale Dame',1,210),(10,'R4M','Régionale 4',0,5)
"));

// ════════════════════════════════════════════════════════════════════════════
// 4. COMPTE ADMINISTRATEUR
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>4. Compte administrateur</h3>';

step('Utilisateur administrateur (CHAUTARD)', fn() => $pdo->exec("
    INSERT IGNORE INTO `utilisateur`
        (`Id_Utilisateur`, `Login`, `Password`, `Nom`, `Prenom`, `Role`, `Actif`, `Id_Departement`, `ChangeLogin`)
    VALUES
        (1, 'CHAUTARD', 'grxPpZc5xgElwa51a9GgMaApjHUDphoEx9BZ0gpYa+qU70i+x5wwNklmH7LdZiIw',
         'CHAUTARD', 'Patrick', 'Administrateur', 1, 76, 0)
"));

// ════════════════════════════════════════════════════════════════════════════
// 5. MODÈLES DE MESSAGES
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>5. Modèles de messagerie</h3>';

step('Modèles de messages (4)', function() use ($pdo) {
    $pdo->exec("
        INSERT IGNORE INTO `messagerie` (`Id_Messagerie`, `Id_Utilisateur`, `Type`, `Sujet`, `Message`) VALUES
        (1, 1, 'Disponibilites',
         'Arbitrage de la nouvelle phase du championnat',
         'Bonjour {PRENOM} {NOM},\n\nCi-joint le lien pour la saisie de vos disponibilités pour les arbitrages du Championnat Phase 1 2026.\n\nhttp://nijac/Nominateur/disponibilite_ja.php?ja={ID_JA}}\n\nCordialement\n\n{UTI_PRENOM}  {UTI_NOM}'),
        (2, 1, 'Rappel dispo',
         'Relance : Arbitrage de la nouvelle phase du championnat',
         'Bonjour {PRENOM} {NOM},\n\nCi-joint le lien pour la saisie de vos disponibilités pour les arbitrages du Championnat Phase 1 2026.\n\nhttp://nijac/Nominateur/disponibilite_ja.php?ja={ID_JA}}\n\nCordialement\n\n{UTI_PRENOM} {UTI_NOM}'),
        (3, 1, 'Convocation',
         'Convocation pour la journée  {JOURNEE} Division : {DIVISION} du Championnat',
         'Convocation\n\nNom du JUGE ARBITRE : {NOMPRENOM}\n\nJ''ai l''avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {Sexe}.\n\nJournée n° {JOURNEE}      Division : {DIVISION}    Poule : {POULE}\nOpposant : {DOM} à {EXT}\nle {DATE} à {HEURE}\n\nAdresse : {SALLE_NOM} {SALLE_ADRESSE} {SALLE_CP} {SALLE_VILLE}\n\nNom, PRÉNOM du CORRESPONDANT, {CORR_NOM}\n\n Tél : {CORR_TEL}                               Courriel : {CORR_EMAIL}\n\nVeuillez agréer mes meilleurs sentiments.\n{UTI_PRENOM} {UTI_NOM}'),
        (4, 1, 'Liste nomination',
         'Liste des nominations pour le Championnat de France par Equipe',
         'Bonjour {PRENOM} {NOM},\n\nci-joint le récapitulatif des arbitrages par cette phase :\n\n{LISTE_NOMINATIONS}\n\nCordialement\n{UTI_PRENOM} {UTI_NOM}')
    ");
});

// ════════════════════════════════════════════════════════════════════════════
// 6. CONFIGURATION APPLICATIVE
// ════════════════════════════════════════════════════════════════════════════
echo '<h3>6. Configuration applicative</h3>';

step('Paramètres de configuration (SMTP, limites, état)', function() use ($pdo) {
    // initTableConfiguration() utilise getPDO() en interne — on la réécrit inline
    // pour utiliser notre connexion dédiée au lieu de celle de db.php.
    $pdo->exec("
        INSERT IGNORE INTO `configuration` (`cle`, `valeur`, `libelle`, `description`) VALUES
        ('etat_logiciel',       'Developpement',           'État du logiciel',              'Opérationnel : emails envoyés aux destinataires réels.\nDéveloppement : emails redirigés vers l''adresse de développement.'),
        ('email_developpement', 'patrick.chautard@free.fr','Email de développement',        'Adresse email vers laquelle tous les envois sont redirigés en mode Développement.'),
        ('departements_actifs', '14,27,50,61,76',          'Départements concernés',        'Liste des numéros de départements gérés par la ligue, séparés par des virgules.'),
        ('frais_max_peages',    '80',                      'Plafond péages (€)',            'Montant maximum accepté pour les péages d''un JA (en euros).'),
        ('frais_max_km',        '200',                     'Plafond kilométrage',           'Nombre de kilomètres maximum accepté pour un déplacement JA.'),
        ('rate_limit_max',      '100',                     'Limite emails par fenêtre',     'Nombre maximum d''emails envoyables sur la fenêtre glissante.'),
        ('rate_limit_fenetre',  '10',                      'Fenêtre rate limit (minutes)',  'Durée en minutes de la fenêtre glissante pour le rate limiting.'),
        ('regles_departements', '{\"76\":[\"27\"]}',       'Règles d''association entre départements', 'JSON : pour chaque département source, liste des départements automatiquement inclus.'),
        ('smtp_host',           'smtp.free.fr',            'Serveur SMTP',                  'Adresse du serveur SMTP sortant.'),
        ('smtp_port',           '587',                     'Port SMTP',                     '587 pour STARTTLS, 465 pour SSL, 25 sans chiffrement.'),
        ('smtp_secure',         'tls',                     'Chiffrement SMTP',              'tls (STARTTLS), ssl ou vide.'),
        ('smtp_auth',           '1',                       'Authentification SMTP',         '1 = authentification requise, 0 = anonyme.'),
        ('smtp_user',           'patrick.chautard@free.fr','Utilisateur SMTP',              'Login du compte SMTP.'),
        ('smtp_password',       '#Henri.1957',             'Mot de passe SMTP',             'Mot de passe du compte SMTP.'),
        ('smtp_from',           'patrick.chautard@free.fr','Email expéditeur',              'Adresse From des emails envoyés.'),
        ('smtp_from_name',      'NIJAC – Arbitrage Normandie','Nom expéditeur',             'Nom affiché dans le champ From.')
    ");
});

// ════════════════════════════════════════════════════════════════════════════
// RENDU DES RÉSULTATS
// ════════════════════════════════════════════════════════════════════════════
foreach ($steps as $s):
?>
        <div class="step">
            <?php if ($s['ok']): ?>
                <span class="icon-ok">✔</span>
                <div><?= htmlspecialchars($s['label']) ?></div>
            <?php else: ?>
                <span class="icon-err">✖</span>
                <div>
                    <?= htmlspecialchars($s['label']) ?>
                    <div class="err-msg"><?= htmlspecialchars($s['msg']) ?></div>
                </div>
            <?php endif; ?>
        </div>
<?php endforeach; ?>

<?php if (empty($errors)): ?>
        <div class="summary ok">
            ✅ Installation terminée avec succès — <?= count($steps) ?> étapes réussies.
        </div>
        <div class="warn">
            ⚠️ <strong>Sécurité :</strong> supprimez ce fichier <code>install.php</code> du serveur immédiatement.<br>
            Les données opérationnelles (JA, salles, équipes, rencontres, laposte) doivent être restaurées
            depuis une sauvegarde via l'écran <strong>Saison → Restaurer</strong> de l'application.
        </div>
<?php else: ?>
        <div class="summary err">
            ❌ <?= count($errors) ?> erreur(s) lors de l'installation :
            <?= implode(', ', array_map('htmlspecialchars', $errors)) ?>
        </div>
<?php endif; ?>

    </div>
</div>
</body>
</html>
