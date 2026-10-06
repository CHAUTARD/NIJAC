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
 * Migrations de schéma appliquées à l'ouverture de l'écran EA98 (seul appelant).
 * Chaque bloc est idempotent : on ne (re)crée que ce qui manque.
 */
function initTableConfiguration(\PDO $pdo): void
{
    // Renommage departement.code -> departement.CodeDept : PK référencée par 2 FK,
    // il faut les retirer, renommer, puis les recréer à l'identique.
    try {
        $ancienne = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departement' AND COLUMN_NAME = 'code'"
        )->fetchColumn();
        if ($ancienne) {
            $pdo->exec('ALTER TABLE equipe_nationale DROP FOREIGN KEY fk_equipenat_dept');
            $pdo->exec('ALTER TABLE utilisateur DROP FOREIGN KEY fk_utilisateur_departement_code');
            $pdo->exec("ALTER TABLE departement CHANGE code CodeDept varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL");
            $pdo->exec("ALTER TABLE equipe_nationale ADD CONSTRAINT fk_equipenat_dept FOREIGN KEY (CodeDept) REFERENCES departement (CodeDept) ON DELETE SET NULL ON UPDATE CASCADE");
            $pdo->exec("ALTER TABLE utilisateur ADD CONSTRAINT fk_utilisateur_departement_code FOREIGN KEY (Id_Departement) REFERENCES departement (CodeDept) ON DELETE SET NULL ON UPDATE CASCADE");
        }
    } catch (\PDOException $e) {
        // best-effort — voir le SQL manuel dans la doc si l'ALTER échoue ici.
    }

    // FK Division -> division.Division sur equipe et equipe_nationale (aucune
    // valeur orpheline en base). ON UPDATE CASCADE : renommage d'un code division
    // propagé ; ON DELETE RESTRICT : impossible de supprimer une division encore
    // référencée.
    foreach ([
        'equipe'           => 'fk_equipe_division',
        'equipe_nationale' => 'fk_equipenat_division',
    ] as $table => $contrainte) {
        try {
            $existe = $pdo->query(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = " . $pdo->quote($table) . "
                   AND CONSTRAINT_NAME = " . $pdo->quote($contrainte)
            )->fetchColumn();
            if (!$existe) {
                $pdo->exec(
                    "ALTER TABLE $table
                     ADD CONSTRAINT $contrainte FOREIGN KEY (Division) REFERENCES division (Division)
                     ON DELETE RESTRICT ON UPDATE CASCADE"
                );
            }
        } catch (\PDOException $e) {
            // best-effort : ne bloque pas EA98 si l'ALTER échoue (droits, données incohérentes…)
        }
    }

    // Coefficients des prestations dues par club (EN26 « Prestations par club ») : créés
    // seulement s'ils manquent, jamais écrasés ; modifiables dans EA91 (table brute).
    try {
        $pdo->exec("INSERT IGNORE INTO configuration (cle, valeur, description) VALUES
            ('nombre_arbitrage_national', '7', 'EN26 — prestations JA dues par équipe en nationale'),
            ('nombre_arbitrage_regional', '5', 'EN26 — prestations JA dues par équipe en régionale')");
    } catch (\PDOException $e) {
        // best-effort
    }

    // Un nom d'équipe ne doit désigner qu'un seul club (affectation automatique
    // EA82/EA83). Best-effort : si des doublons existent déjà en base, la
    // contrainte reste non posée jusqu'à correction manuelle (écran EN27).
    try {
        if (!$pdo->query("SHOW INDEX FROM Club WHERE Key_name = 'uq_club_equipenom'")->fetch()) {
            $pdo->exec('ALTER TABLE Club ADD UNIQUE KEY uq_club_equipenom (EquipeNom)');
        }
    } catch (\PDOException $e) {
        // doublons existants — voir ci-dessus
    }

    // Calendrier régional (EA84 / EN13 / EN22) : créé avec un seed initial (Régionale 3/4, non couvertes
    // par un import FFTT) UNIQUEMENT si la table n'existe pas encore — jamais re-seedé ensuite.
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'competition_regionale'")->fetchColumn()) {
            $pdo->exec('
                CREATE TABLE competition_regionale (
                    Id_CompetitionRegionale INT AUTO_INCREMENT PRIMARY KEY,
                    Date                    DATE NOT NULL,
                    Heure                   TIME NOT NULL,
                    Commentaire             VARCHAR(255) NULL,
                    UNIQUE KEY uq_date_heure (Date, Heure)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
            $stmtCr = $pdo->prepare('INSERT INTO competition_regionale (Date, Heure) VALUES (?, ?)');
            foreach ([
                ['2026-09-19', '16:00'], ['2026-09-20', '14:00'],
                ['2026-10-03', '16:00'], ['2026-10-04', '14:00'],
                ['2026-10-17', '16:00'], ['2026-10-18', '14:00'],
                ['2026-11-07', '16:00'], ['2026-11-08', '14:00'],
                ['2026-11-21', '16:00'], ['2026-11-22', '14:00'],
                ['2026-12-05', '16:00'], ['2026-12-06', '14:00'],
                ['2026-12-12', '16:00'], ['2026-12-13', '14:00'],
            ] as [$dateCr, $heureCr]) {
                $stmtCr->execute([$dateCr, $heureCr]);
            }
        }
    } catch (\PDOException $e) {
        // best-effort
    }

    // Calendrier des compétitions régionales (CRA) 2026-2027 : seed initial UNIQUEMENT si la table est vide.
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS CRA_Competition (
                Id_CRA_Competition INT AUTO_INCREMENT PRIMARY KEY,
                Numero             INT NOT NULL,
                DateDebut          DATE NOT NULL,
                DateFin            DATE NULL,
                Libelle            VARCHAR(150) NOT NULL,
                NbTablesMin        INT NULL,
                NbTablesMax        INT NULL,
                Lieu               VARCHAR(100) NOT NULL,
                NiveauJA           VARCHAR(20) NOT NULL,
                NbrJA              TINYINT UNSIGNED NOT NULL DEFAULT 1,
                NbrAdjoint         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_cra_numero (Numero)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        if (!(int) $pdo->query('SELECT COUNT(*) FROM CRA_Competition')->fetchColumn()) {
            $stmtCra = $pdo->prepare('INSERT INTO CRA_Competition
                (Numero, DateDebut, DateFin, Libelle, NbTablesMin, NbTablesMax, Lieu, NiveauJA)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ([
                [1,  '2026-10-10', null, '1er tour Critérium Féminin et Benjamins Normandie', 16, null, 'Saint-Pierre-lès-Elbeuf', 'JA2'],
                [2,  '2026-10-11', null, '1er tour Jeunes et Seniors Régionale 1 Normandie', 16, null, 'Saint-Pierre-lès-Elbeuf', 'JA2'],
                [3,  '2026-10-11', null, '1er tour Seniors Régionale 2 Zone 1', 8, null, 'Cormelles-le-Royal', 'JA2'],
                [4,  '2026-10-11', null, '1er tour Seniors Régionale 2 Zone 2', 8, null, 'AS Stéphanaise', 'JA2'],
                [5,  '2026-11-14', '2026-11-15', '2e tour Critérium fédéral Nationale 2', 24, null, 'Flers', 'JAN JA3'],
                [6,  '2026-11-14', null, '2e tour Critérium Féminin et Benjamins Normandie', 16, null, 'Vire', 'JA2'],
                [7,  '2026-11-15', null, '2e tour Jeunes et Seniors Régionale 1 Normandie', 16, null, 'Vire', 'JA2'],
                [8,  '2026-11-15', null, '2e tour Seniors Régionale 2 Zone 1', 8, null, 'Cormelles-le-Royal', 'JA2'],
                [9,  '2026-11-15', null, '2e tour Seniors Régionale 2 Zone 2', 8, null, 'Caudebec-lès-Elbeuf', 'JA2'],
                [10, '2026-11-28', null, 'Tournoi régional féminin', 16, 24, 'À déterminer', 'JA3'],
                [11, '2027-01-09', null, 'Journée qualificative Championnats de France Vétérans', 24, null, 'Bolbec', 'JA3'],
                [12, '2027-01-10', null, 'Top Détection', 22, 24, 'Bolbec', 'JA3'],
                [13, '2027-01-30', null, '3e tour Critérium Féminin et Benjamins Normandie', 16, null, 'Le Havre', 'JA2'],
                [14, '2027-01-31', null, '3e tour Jeunes et Seniors Régionale 1 Normandie', 16, null, 'Le Havre', 'JA2'],
                [15, '2027-01-31', null, '3e tour Seniors Régionale 2 - Cormelles', 8, null, 'Cormelles-le-Royal', 'JA2'],
                [16, '2027-01-31', null, '3e tour Seniors Régionale 2 - Zone 2', 8, null, 'Caudebec-lès-Elbeuf ou Saint-Étienne-du-Rouvray', 'JA2'],
                [17, '2027-02-20', null, 'Coupe nationale Vétérans - échelon régional', 14, 16, 'Caudebec-lès-Elbeuf', 'JA3'],
                [18, '2027-02-21', null, 'Titres individuels Normandie', 24, null, 'Saint-Lô', 'JA3'],
                [19, '2027-03-06', '2027-03-07', '4e tour Critérium fédéral Nationale 2', 24, null, 'Saint-Pierre-lès-Elbeuf', 'JAN JA3'],
                [20, '2027-03-06', null, '4e tour Critérium Féminin et Benjamins Normandie', 16, null, 'Flers', 'JA2'],
                [21, '2027-03-07', null, '4e tour Jeunes et Seniors Régionale 1 Normandie', 16, null, 'Flers', 'JA2'],
                [22, '2027-03-07', null, '4e tour Seniors Régionale 2 Zone 1', 8, null, 'Cormelles-le-Royal', 'JA2'],
                [23, '2027-03-07', null, '4e tour Seniors Régionale 2 Zone 2', 8, null, 'Pacy-sur-Eure', 'JA2'],
                [24, '2027-04-17', '2027-04-18', 'Finale régionale par classements', 24, null, 'Le Havre', 'JA3'],
                [25, '2027-05-15', '2027-05-16', 'Grand Prix Jeunes Crédit Agricole', 40, 50, 'Saint-Pierre-lès-Elbeuf', 'JA3'],
                [26, '2027-06-12', '2027-06-13', 'Titres régionaux Championnat par équipes', 16, null, 'Ducey', 'JA3'],
                [27, '2027-06-12', '2027-06-13', 'Inter-comités', 20, 24, 'À déterminer', 'JA3'],
            ] as $ligneCra) {
                $stmtCra->execute($ligneCra);
            }
        }
    } catch (\PDOException $e) {
        // best-effort
    }

    // EC71 : club organisateur (Id_Club = Club.Id_Club, NomClub = copie dénormalisée de Club.Nom),
    // renseigné à la main via la recherche « club à partir du Lieu » — pas de backfill.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM CRA_Competition LIKE 'Id_Club'")->fetch()) {
            $pdo->exec("ALTER TABLE CRA_Competition ADD COLUMN Id_Club CHAR(8) COLLATE utf8mb4_unicode_ci NULL AFTER Lieu");
        }
        if (!$pdo->query("SHOW COLUMNS FROM CRA_Competition LIKE 'NomClub'")->fetch()) {
            $pdo->exec("ALTER TABLE CRA_Competition ADD COLUMN NomClub VARCHAR(100) COLLATE utf8mb4_unicode_ci NULL AFTER Id_Club");
        }
    } catch (\PDOException $e) {
        // best-effort
    }
    // EC71 : nombre de juges-arbitres et d'adjoints requis (lignes existantes → défauts 1 / 0).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM CRA_Competition LIKE 'NbrJA'")->fetch()) {
            $pdo->exec("ALTER TABLE CRA_Competition ADD COLUMN NbrJA TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER NiveauJA");
        }
        if (!$pdo->query("SHOW COLUMNS FROM CRA_Competition LIKE 'NbrAdjoint'")->fetch()) {
            $pdo->exec("ALTER TABLE CRA_Competition ADD COLUMN NbrAdjoint TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER NbrJA");
        }
    } catch (\PDOException $e) {
        // best-effort
    }
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'cra_competition'
               AND CONSTRAINT_NAME = 'fk_cracompet_club'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                'ALTER TABLE CRA_Competition
                 ADD CONSTRAINT fk_cracompet_club FOREIGN KEY (Id_Club) REFERENCES Club (Id_Club)
                 ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }
    } catch (\PDOException $e) {
        // best-effort : ne bloque pas EA98 si l'ALTER échoue
    }

    // Référentiel des degrés de la filière juge-arbitre (sans lien FK avec ja.Grade) : seed UNIQUEMENT si la table est vide.
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS JugeArbitre (
                Id_JugeArbitre INT AUTO_INCREMENT PRIMARY KEY,
                Code           CHAR(3) NOT NULL,
                Libelle        VARCHAR(26) NOT NULL,
                Description    TEXT NOT NULL,
                UNIQUE KEY uq_jugearbitre_code (Code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // Colonne Ordre supprimée : retire-la si la table a déjà été créée avec.
        if ($pdo->query("SHOW COLUMNS FROM JugeArbitre LIKE 'Ordre'")->fetch()) {
            $pdo->exec('ALTER TABLE JugeArbitre DROP COLUMN Ordre');
        }
        // Anciens types (VARCHAR(10)/(60)/(255)) : mise à niveau si la table a déjà été créée avec.
        $typesJug = [];
        foreach ($pdo->query('SHOW COLUMNS FROM JugeArbitre')->fetchAll(\PDO::FETCH_ASSOC) as $colJug) {
            $typesJug[$colJug['Field']] = strtolower($colJug['Type']);
        }
        if (($typesJug['Code'] ?? '') !== 'char(3)' || ($typesJug['Libelle'] ?? '') !== 'varchar(26)' || ($typesJug['Description'] ?? '') !== 'text') {
            $pdo->exec('ALTER TABLE JugeArbitre MODIFY Code CHAR(3) NOT NULL, MODIFY Libelle VARCHAR(26) NOT NULL, MODIFY Description TEXT NOT NULL');
        }
        if (!(int) $pdo->query('SELECT COUNT(*) FROM JugeArbitre')->fetchColumn()) {
            $stmtJug = $pdo->prepare('INSERT INTO JugeArbitre (Code, Libelle, Description) VALUES (?, ?, ?)');
            foreach ([
                ['JA1', 'Juge-Arbitre 1er degré', 'Responsable du déroulement des rencontres par équipes.'],
                ['JA2', 'Juge-Arbitre 2ème degré', 'Chargé de diriger le critérium fédéral et les épreuves individuelles.'],
                ['JA3', 'Juge-Arbitre 3ème degré', 'Dirige les compétitions régionales et les tournois.'],
                ['JAN', 'Juge-Arbitre National', 'A la charge de l\'organisation des épreuves nationales.'],
                ['JAI', 'Juge-Arbitre International', 'Supervise l\'ensemble des arbitres lors d\'épreuves internationales.'],
            ] as $ligneJug) {
                $stmtJug->execute($ligneJug);
            }
        }
    } catch (\PDOException $e) {
        // best-effort
    }

    // EC73 : désignation des JA / adjoints d'une compétition CRA (remplacée en bloc par l'écran).
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS CRA_Designation (
                Id_CRA_Designation INT AUTO_INCREMENT PRIMARY KEY,
                Id_CRA_Competition INT NOT NULL,
                Role               ENUM('JA','Adjoint') NOT NULL,
                Rang               TINYINT UNSIGNED NOT NULL,
                Id_JA              INT NOT NULL,
                DateSaisie         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                Id_Utilisateur     INT NULL,
                DateConvocation    DATETIME NULL,
                UNIQUE KEY uq_cradesig_rang (Id_CRA_Competition, Role, Rang),
                UNIQUE KEY uq_cradesig_ja (Id_CRA_Competition, Id_JA),
                KEY idx_cradesig_ja (Id_JA),
                CONSTRAINT fk_cradesig_compet FOREIGN KEY (Id_CRA_Competition) REFERENCES CRA_Competition (Id_CRA_Competition)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\PDOException $e) {
        // best-effort
    }
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'cra_designation'
               AND CONSTRAINT_NAME = 'fk_cradesig_ja'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                'ALTER TABLE CRA_Designation
                 ADD CONSTRAINT fk_cradesig_ja FOREIGN KEY (Id_JA) REFERENCES ja (Id_JA)
                 ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }
    } catch (\PDOException $e) {
        // best-effort : ne bloque pas EA98 si l'ALTER échoue
    }
    // EC73 : date du dernier envoi réussi de la convocation (cra-designation/convocations), NULL = jamais convoqué.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM CRA_Designation LIKE 'DateConvocation'")->fetch()) {
            $pdo->exec('ALTER TABLE CRA_Designation ADD COLUMN DateConvocation DATETIME NULL AFTER Id_Utilisateur');
        }
    } catch (\PDOException $e) {
        // best-effort
    }

    // EC74 : disponibilités des JA pour les compétitions CRA (demande par email, réponse via dispo-cra?ja=TOKEN
    // ou saisie manuelle). Disponible = les 5 statuts de la matrice de suivi ; 'Non renseigné' = demandé sans réponse.
    $enumDispo = "ENUM('Non renseigné','Indisponible','Disponible','À confirmer','Disponible sous condition') NOT NULL DEFAULT 'Non renseigné'";
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS CRA_Dispo (
                Id_CRA_Dispo       INT AUTO_INCREMENT PRIMARY KEY,
                Id_CRA_Competition INT NOT NULL,
                Id_JA              INT NOT NULL,
                Disponible         $enumDispo,
                Commentaire        VARCHAR(255) NULL,
                DateDemande        DATETIME NULL,
                DateReponse        DATETIME NULL,
                Source             ENUM('JA','Saisie') NULL,
                Id_Utilisateur     INT NULL,
                UNIQUE KEY uq_cradispo (Id_CRA_Competition, Id_JA),
                KEY idx_cradispo_ja (Id_JA),
                CONSTRAINT fk_cradispo_compet FOREIGN KEY (Id_CRA_Competition) REFERENCES CRA_Competition (Id_CRA_Competition)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\PDOException $e) {
        // best-effort
    }
    // Migration (10/2026) d'une table créée avec l'ancien Disponible TINYINT(1) NULL (NULL / 1 / 0) vers l'ENUM.
    // Étapes séquentielles (DDL = commit implicite, pas de transaction possible) : la première qui échoue arrête
    // la migration et la journalise. Reprise sûre au passage EA98 suivant : la colonne n'étant toujours pas un
    // ENUM, les étapes sont rejouées (l'UPDATE ne touche que les anciennes valeurs).
    try {
        $type = $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'cra_dispo' AND COLUMN_NAME = 'Disponible'"
        )->fetchColumn();
        if ($type !== false && strtolower((string) $type) !== 'enum') {
            // (a) texte libre le temps de la conversion
            $pdo->exec('ALTER TABLE CRA_Dispo MODIFY Disponible VARCHAR(30) NULL');
            // (b) conversion : commentaires de substitution de l'ancien import d'abord (commentaire vidé), puis 1 / 0 / NULL
            $pdo->exec("UPDATE CRA_Dispo SET Disponible = Commentaire, Commentaire = NULL
                        WHERE Commentaire IN ('À confirmer', 'Disponible sous condition')");
            $pdo->exec("UPDATE CRA_Dispo SET Disponible = CASE
                            WHEN Disponible IS NULL THEN 'Non renseigné'
                            WHEN Disponible = '1' THEN 'Disponible'
                            WHEN Disponible = '0' THEN 'Indisponible'
                            ELSE Disponible END");
            // garde-fou : jamais d'ENUM posé sur une valeur hors liste (tronquée en '' en mode non strict)
            $restant = (int) $pdo->query("SELECT COUNT(*) FROM CRA_Dispo WHERE Disponible NOT IN
                ('Non renseigné','Indisponible','Disponible','À confirmer','Disponible sous condition')")->fetchColumn();
            if ($restant > 0) {
                throw new \RuntimeException("$restant valeur(s) de Disponible non convertible(s)");
            }
            // (c) type définitif
            $pdo->exec("ALTER TABLE CRA_Dispo MODIFY Disponible $enumDispo");
        }
    } catch (\Throwable $e) {
        // Arrêt de la migration de CRA_Dispo (colonne laissée en VARCHAR si (a) est passée) : rejouée au prochain EA98.
        error_log('[NIJAC] Migration CRA_Dispo.Disponible -> ENUM interrompue : ' . $e->getMessage());
    }
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'cra_dispo'
               AND CONSTRAINT_NAME = 'fk_cradispo_ja'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                'ALTER TABLE CRA_Dispo
                 ADD CONSTRAINT fk_cradispo_ja FOREIGN KEY (Id_JA) REFERENCES ja (Id_JA)
                 ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }
    } catch (\PDOException $e) {
        // best-effort : ne bloque pas EA98 si l'ALTER échoue
    }

    // Une seule réponse par (JA, rencontre) — EN22 / EN14. Best-effort : doublons existants => reste non posée.
    try {
        if (!$pdo->query("SHOW INDEX FROM disponible WHERE Key_name = 'uq_dispo'")->fetch()) {
            $pdo->exec('ALTER TABLE disponible ADD UNIQUE KEY uq_dispo (Id_JA, Id_Rencontre)');
        }
    } catch (\PDOException $e) {
        // doublons existants
    }

    // FK implicites ajoutées (09/2026) : ententes de clubs (equipe.Id_Club2 /
    // Id_Club3) et département de rattachement du JA (ja.CodeDept). Idempotent
    // (garde information_schema). ON DELETE SET NULL (un club ou un département
    // qui disparaît ne doit pas effacer l'équipe / le JA). Aucune valeur
    // orpheline en base au moment de l'ajout — voir SQL/2026-09_fk_manquantes.sql
    // pour le détail / les contrôles de pré-vol côté prod.
    foreach ([
        ['equipe',              'fk_equipe_club2',       'Id_Club2',                'club',                  'Id_Club',                 'SET NULL'],
        ['equipe',              'fk_equipe_club3',       'Id_Club3',                'club',                  'Id_Club',                 'SET NULL'],
        ['ja',                  'fk_ja_departement',     'CodeDept',                'departement',           'CodeDept',                'SET NULL'],
    ] as [$table, $contrainte, $col, $refTable, $refCol, $onDelete]) {
        try {
            $existe = $pdo->query(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = " . $pdo->quote($table) . "
                   AND CONSTRAINT_NAME = " . $pdo->quote($contrainte)
            )->fetchColumn();
            if (!$existe) {
                if ($onDelete === 'SET NULL') {
                    // chaîne vide -> NULL, sinon la FK échoue sur ces lignes
                    $pdo->exec("UPDATE $table SET $col = NULL WHERE $col = ''");
                }
                $pdo->exec(
                    "ALTER TABLE $table
                     ADD CONSTRAINT $contrainte FOREIGN KEY ($col) REFERENCES $refTable ($refCol)
                     ON DELETE $onDelete ON UPDATE CASCADE"
                );
            }
        } catch (\PDOException $e) {
            // best-effort : ne bloque pas EA98 si l'ALTER échoue (droits, données incohérentes…)
        }
    }

    // Unicité d'une affiche : les imports de rencontres (EA82 FFTT direct + EA83
    // national) dédupliquaient uniquement via un SELECT applicatif, sans verrou —
    // deux exécutions concurrentes (double-clic, rejeu réseau, import FFTT +
    // national sur la même division N*) inséraient deux lignes identiques à l'Id
    // près. Même clé que RencontreAdminController::doublons() traite déjà comme
    // l'unicité d'une affiche. Id_EquipeExt NULL (exempt / bye) : MySQL autorise
    // plusieurs NULL dans un index UNIQUE, pas de collision. best-effort : si des
    // doublons subsistent en base, l'ALTER échoue — les nettoyer via EN23 d'abord.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'rencontre'
               AND INDEX_NAME = 'uq_rencontre_affiche'
             LIMIT 1"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                'ALTER TABLE rencontre
                 ADD UNIQUE KEY uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt, Phase)'
            );
        }
    } catch (\PDOException $e) {
        // best-effort : doublons pré-existants (à purger via EN23) ou droits insuffisants.
    }

    // rencontre.Frais : qui supporte les frais du JA (Dom = club recevant, Ext = club visiteur) — EN23.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'Frais'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec("ALTER TABLE rencontre ADD COLUMN Frais ENUM('Dom','Ext') NOT NULL DEFAULT 'Dom' AFTER ArbitrageCRA");
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // ja.Actif -> ja.JA1 (même type/défaut, données conservées) + degrés JA2/JA3/JAN/JAI
    // (codes de la table de référence JugeArbitre, sans FK). Index idx_ja_actif -> idx_ja_ja1.
    try {
        $colsJa = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ja'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        if (in_array('Actif', $colsJa, true) && !in_array('JA1', $colsJa, true)) {
            $pdo->exec("ALTER TABLE ja CHANGE Actif JA1 TINYINT(1) DEFAULT '1'");
            $colsJa[] = 'JA1';
        }
        $apres = 'JA1';
        foreach (['JA2', 'JA3', 'JAN', 'JAI'] as $colJa) {
            if (!in_array($colJa, $colsJa, true)) {
                $pdo->exec("ALTER TABLE ja ADD COLUMN $colJa TINYINT(1) NOT NULL DEFAULT 0 AFTER $apres");
            }
            $apres = $colJa;
        }
        if ($pdo->query("SHOW INDEX FROM ja WHERE Key_name = 'idx_ja_actif'")->fetch()) {
            $pdo->exec('ALTER TABLE ja RENAME INDEX idx_ja_actif TO idx_ja_ja1');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // ja.Nationale : défaut 1 (Oui) + passage unique de tous les JA existants à 1.
    // Le défaut sert de garde : une fois à 1, l'UPDATE ne se rejoue plus (les JA
    // remis ensuite à Non dans la modale EN11 sont préservés). Type et nullabilité
    // existants conservés. Les imports n'écrivent jamais cette colonne.
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ja' AND COLUMN_NAME = 'Nationale'"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($col && trim((string) $col['COLUMN_DEFAULT'], "'") !== '1') {
            $null = $col['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $pdo->exec("ALTER TABLE ja MODIFY Nationale {$col['COLUMN_TYPE']} $null DEFAULT 1");
            $pdo->exec('UPDATE ja SET Nationale = 1');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // nomination.Valide : défaut 1 — un JA nommé sur une rencontre la rend valide
    // d'office (plus de validation manuelle depuis la suppression du bouton
    // « Valider les nominations » d'EN14). Passage unique des nominations restées
    // à 0 ; le défaut sert de garde (l'UPDATE ne se rejoue plus). Type et
    // nullabilité existants conservés. EmailEnvoye non touché.
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'Valide'"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($col && trim((string) $col['COLUMN_DEFAULT'], "'") !== '1') {
            $null = $col['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $pdo->exec("ALTER TABLE nomination MODIFY Valide {$col['COLUMN_TYPE']} $null DEFAULT 1");
            $pdo->exec('UPDATE nomination SET Valide = 1 WHERE Valide = 0');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // nomination.AccuseReception : date/heure de l'accusé de réception de la
    // convocation par le JA (EN21, POST convocation-ja/accuse) ; NULL = pas encore.
    // Lue par EN21 et EN28 seulement si présente (tolérance avant passage EA98).
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'AccuseReception'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec('ALTER TABLE nomination ADD COLUMN AccuseReception DATETIME NULL DEFAULT NULL AFTER EmailEnvoye');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // Colonnes "référent" du club : 2e contact, mis en copie (Cc) des emails
    // envoyés au correspondant. Mêmes types que CorNom / CorEmail / CorTelephone.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'club' AND COLUMN_NAME = 'RefNom'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                "ALTER TABLE Club
                   ADD COLUMN RefNom       varchar(100) DEFAULT NULL AFTER CorTelephone,
                   ADD COLUMN RefMail      varchar(150) DEFAULT NULL AFTER RefNom,
                   ADD COLUMN RefTelephone varchar(20)  DEFAULT NULL AFTER RefMail"
            );
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // Note : le barème kilométrique (table ComptaDefiscalisation + config
    // comptadefisc_majoration_electrique) et les colonnes ja.PuissanceFiscale /
    // ja.VehiculeElectrique (ED51/ED52) sont déjà déployés en dev et en prod par
    // ALTER manuel — pas de bloc de migration ici (voir SPECIFICATION.md ED51/ED52).

    // equipe.SouhaitJA : enum('CRA','Club') NULLABLE -> TINYINT(1) NOT NULL (1=CRA, 0=Club).
    // Valeur redondante avec division.ArbitrageCRA pour tout ce qui n'est pas R3M/R4M —
    // désormais un booléen toujours renseigné, initialisé à la création de l'équipe
    // depuis division.ArbitrageCRA (même règle : 1 partout sauf R3M/R4M) et modifiable
    // ensuite par le club (EN18) ou l'admin, en pratique seulement pour R3M/R4M.
    // Les lignes NULL existantes (équipes hors R3M/R4M jamais concernées par le choix)
    // sont backfillées avec le défaut de leur division avant de poser NOT NULL.
    try {
        $type = $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipe' AND COLUMN_NAME = 'SouhaitJA'"
        )->fetchColumn();
        if ($type === 'enum') {
            $pdo->exec(
                "ALTER TABLE equipe ADD COLUMN SouhaitJaTmp TINYINT(1) NULL DEFAULT NULL AFTER SouhaitJA"
            );
            $pdo->exec("UPDATE equipe SET SouhaitJaTmp = CASE SouhaitJA WHEN 'CRA' THEN 1 WHEN 'Club' THEN 0 END");
            $pdo->exec(
                "UPDATE equipe e
                 LEFT JOIN division d ON d.Division = e.Division
                 SET e.SouhaitJaTmp = COALESCE(d.ArbitrageCRA, CASE WHEN e.Division IN ('R3M', 'R4M') THEN 0 ELSE 1 END)
                 WHERE e.SouhaitJaTmp IS NULL"
            );
            $pdo->exec('ALTER TABLE equipe DROP COLUMN SouhaitJA');
            $pdo->exec('ALTER TABLE equipe CHANGE SouhaitJaTmp SouhaitJA TINYINT(1) NOT NULL DEFAULT 1');
        }
    } catch (\PDOException $e) {
        // best-effort — droits insuffisants ou colonne déjà migrée.
    }

    // Renommage/consolidation en ArbitrageCRA : equipe.SouhaitJA (enum 'CRA'/'Club' nullable)
    // et rencontre.ArbitrageObligatoire représentent tous deux la même notion que
    // division.ArbitrageCRA (1 = arbitrage fourni par la CRA, 0 = à la charge du club,
    // R3M/R4M uniquement) — un seul nom, un seul type booléen NOT NULL, partout.
    try {
        $type = $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipe' AND COLUMN_NAME = 'SouhaitJA'"
        )->fetchColumn();
        if ($type === 'enum') {
            $pdo->exec('ALTER TABLE equipe ADD COLUMN ArbitrageCRA TINYINT(1) NULL DEFAULT NULL AFTER SouhaitJA');
            $pdo->exec("UPDATE equipe SET ArbitrageCRA = CASE SouhaitJA WHEN 'CRA' THEN 1 WHEN 'Club' THEN 0 END");
            // NULL restant = équipes hors R3M/R4M jamais concernées par le choix : backfill
            // depuis division.ArbitrageCRA (repli sur le code division si la ligne division
            // est absente).
            $pdo->exec(
                "UPDATE equipe e
                 LEFT JOIN division d ON d.Division = e.Division
                 SET e.ArbitrageCRA = COALESCE(d.ArbitrageCRA, CASE WHEN e.Division IN ('R3M', 'R4M') THEN 0 ELSE 1 END)
                 WHERE e.ArbitrageCRA IS NULL"
            );
            $pdo->exec('ALTER TABLE equipe DROP COLUMN SouhaitJA');
            $pdo->exec('ALTER TABLE equipe MODIFY ArbitrageCRA TINYINT(1) NOT NULL DEFAULT 1');
        }
    } catch (\PDOException $e) {
        // best-effort — droits insuffisants ou colonne déjà migrée.
    }
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'ArbitrageObligatoire'"
        )->fetchColumn();
        if ($existe) {
            $pdo->exec('ALTER TABLE rencontre CHANGE ArbitrageObligatoire ArbitrageCRA TINYINT(1) NOT NULL DEFAULT 1');
            // Corrige la désynchronisation historique : l'ancien mécanisme ne recopiait pas
            // toujours le souhait CRA/Club de l'équipe sur ses rencontres (voir commit de
            // suppression du bloc de sync dans DesiderataClubController, 09/2026). Photo unique
            // au moment de ce renommage — au-delà, chaque écran qui modifie
            // equipe.ArbitrageCRA resynchronise lui-même les rencontres à venir de l'équipe
            // (EN18, ES33, EA92, EN29), jamais l'historique déjà joué.
            $pdo->exec(
                'UPDATE rencontre r
                 JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                 SET r.ArbitrageCRA = ed.ArbitrageCRA'
            );
        }
    } catch (\PDOException $e) {
        // best-effort — droits insuffisants.
    }

    // messagerie.Message TEXT (64 Ko) -> MEDIUMTEXT : les convocations CRA (EC73) font ~53 Ko
    // (images base64), une simple retouche les ferait dépasser TEXT.
    try {
        $type = $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messagerie' AND COLUMN_NAME = 'Message'"
        )->fetchColumn();
        if (in_array($type, ['tinytext', 'text'], true)) {
            $pdo->exec('ALTER TABLE messagerie MODIFY Message MEDIUMTEXT COLLATE utf8mb4_unicode_ci NOT NULL');
        }
    } catch (\PDOException $e) {
        error_log('[NIJAC] messagerie.Message -> MEDIUMTEXT : ' . $e->getMessage());
    }

    // EC73 : les 3 modèles de convocation CRA deviennent des messages système (voir assurerModelesConvocationCra()).
    try {
        assurerModelesConvocationCra($pdo);
    } catch (\PDOException $e) {
        error_log('[NIJAC] Amorçage des modèles de convocation CRA : ' . $e->getMessage());
    }

    // Message n°3 « Convocation » (EN14) : ancien texte brut par défaut -> modèle HTML avec
    // bouton vers EN21 (modeleHtmlConvocationJa()). Jamais si le corps a été personnalisé via
    // EA93 ou est déjà à jour (une version HTML précédente non modifiée est mise à niveau) ; Sujet, Cc/ReplyTo et copies perso des nominateurs intacts.
    try {
        $corps = $pdo->query('SELECT Message FROM messagerie WHERE Id_Messagerie = 3')->fetchColumn();
        if ($corps !== false && corpsConvocationJaMigrable((string) $corps)) {
            $pdo->prepare('UPDATE messagerie SET Message = ? WHERE Id_Messagerie = 3')->execute([modeleHtmlConvocationJa()]);
        } elseif ($corps !== false && $corps !== modeleHtmlConvocationJa()) {
            error_log('[NIJAC] message n°3 personnalisé : modèle HTML non appliqué');
        }
    } catch (\PDOException $e) {
        error_log('[NIJAC] Message n°3 -> modèle HTML : ' . $e->getMessage());
    }

    // EN14 : message système dédié à la copie de convocation aux correspondants/référents des clubs.
    try {
        assurerModeleCopieConvocationClubs($pdo);
    } catch (\PDOException $e) {
        error_log('[NIJAC] Amorçage du message « ' . TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS . ' » : ' . $e->getMessage());
    }

    // E010 : message système du code de sécurité (double authentification par email).
    try {
        assurerModeleCodeSecurite($pdo);
    } catch (\PDOException $e) {
        error_log('[NIJAC] Amorçage du message « ' . TYPE_MESSAGE_CODE_SECURITE . ' » : ' . $e->getMessage());
    }

    // Coupe-circuit de la double authentification (1 = active, 0 = mot de passe seul) : jamais écrasé.
    try {
        $pdo->exec("INSERT IGNORE INTO configuration (cle, valeur, description) VALUES
            ('double_authentification', '1', 'E001/E010 — double authentification par email : 1 = active, 0 = coupe-circuit (mot de passe seul)')");
    } catch (\PDOException $e) {
        error_log('[NIJAC] Clé double_authentification : ' . $e->getMessage());
    }

    // utilisateur.Email obligatoire (code de sécurité E010) : NOT NULL, type/longueur/collation conservés,
    // NULL -> '' (aucune adresse fabriquée ; ces comptes ne peuvent pas se connecter tant que la
    // double authentification est active — à corriger en EA86). Ne s'exécute que si la colonne est NULL-able.
    try {
        $col = $pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'utilisateur' AND COLUMN_NAME = 'Email'"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($col && (int) $col['CHARACTER_MAXIMUM_LENGTH'] < 100) {
            error_log("[NIJAC] utilisateur.Email ({$col['COLUMN_TYPE']}) trop court pour une adresse réelle : à agrandir manuellement");
        }
        foreach ($col ? sqlEmailUtilisateurObligatoire($col, $pdo) : [] as $sql) {
            $pdo->exec($sql);
        }
    } catch (\PDOException $e) {
        error_log('[NIJAC] utilisateur.Email NOT NULL : ' . $e->getMessage());
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
 * Retourne le nombre de jours restants avant l'expiration des identifiants API FFTT
 * (clé de config 'fftt_api_expiration', format YYYY-MM-DD — voir EA91/EA96), ou null si
 * la clé n'est pas configurée. Négatif si la date est déjà dépassée.
 */
function getFfttApiJoursAvantExpiration(): ?int
{
    $exp = getConfig('fftt_api_expiration', '');
    if ($exp === '') {
        return null;
    }
    $date = \DateTime::createFromFormat('Y-m-d', $exp);
    if (!$date) {
        return null;
    }

    return (int) (new \DateTime('today'))->diff($date)->format('%r%a');
}

/**
 * nomination.AccuseReception existe-t-elle ? (ajoutée par EA98 — EN21/EN28 la lisent
 * seulement si présente, pour ne pas planter avant la migration). Cache statique : une
 * seule vérification par requête.
 */
function nominationAAccuseReception(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'AccuseReception'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * Règle stricte de disponibilité pour nommer un JA en arbitrage CRA, identique à EN14
 * (NominationController::sqlDispoRencontre()) : une réponse 'O' sur la rencontre ou la journée
 * (Id_Rencontre NULL, même date) ET aucune réponse 'N' ni sur la rencontre ni sur la journée.
 * Sans réponse / 'P' seul → refus. Renvoie null si nommable, sinon le message d'EN14.
 * Utilisée par EN28 ; un test d'équivalence la compare à sqlDispoRencontre() d'EN14.
 */
function jaDisponiblePourNomination(\PDO $pdo, int $idJa, int $idRenc, string $date): ?string
{
    $rep = fn(string $r) => "EXISTS (SELECT 1 FROM disponible dx WHERE dx.Id_JA = ? AND dx.Reponse = '$r'
            AND (dx.Id_Rencontre = ? OR (dx.Id_Rencontre IS NULL AND dx.DateCompetition = ?)))";
    $st = $pdo->prepare('SELECT ' . $rep('O') . ', ' . $rep('N'));
    $st->execute([$idJa, $idRenc, $date, $idJa, $idRenc, $date]);
    [$aOui, $aNon] = array_map('intval', $st->fetch(\PDO::FETCH_NUM));

    if ($aNon) {
        return 'Ce JA n\'est plus disponible pour cette rencontre/journée (il a répondu « non »).';
    }

    return $aOui ? null : 'Ce JA n\'est pas disponible pour cette rencontre (aucune réponse « oui »).';
}

/**
 * La convocation peut-elle recevoir un accusé de réception (EN21) ? Nomination existante,
 * rattachée à un JA, valide (Valide = 1) et dont la rencontre est aujourd'hui ou à venir.
 */
function convocationAccusable(\PDO $pdo, int $idNomination): bool
{
    $st = $pdo->prepare(
        'SELECT 1
         FROM nomination n
         JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
         JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
         WHERE n.Id_Nomination = ? AND d.Id_JA IS NOT NULL AND n.Valide = 1 AND r.Date >= CURDATE()'
    );
    $st->execute([$idNomination]);

    return (bool) $st->fetchColumn();
}

/**
 * Accusé de réception de convocation par le JA (EN21, POST convocation-ja/accuse).
 * $idNomination : nomination du lien (jeton cnv déjà vérifié par l'appelant) — seule
 * nomination accusable par ce lien. $cible : vide ou cet Id_Nomination ; toute autre
 * valeur est refusée. Idempotent : une date déjà posée n'est jamais réécrite.
 * Retourne ['ok', 'nb' (1 si nouvellement accusée, sinon 0), 'date' (jj/mm/aaaa hh:mm)]
 * ou ['ok' => false, 'err'].
 */
function accuserReceptionConvocation(\PDO $pdo, int $idNomination, string $cible = ''): array
{
    if ($cible !== '' && $cible !== (string) $idNomination) {
        return ['ok' => false, 'err' => 'Action non disponible.'];
    }
    if (!convocationAccusable($pdo, $idNomination)) {
        return ['ok' => false, 'err' => "Cette convocation ne peut pas faire l'objet d'un accusé de réception "
            . '(convocation introuvable, rencontre passée ou nomination annulée).'];
    }

    $upd = $pdo->prepare('UPDATE nomination SET AccuseReception = NOW() WHERE Id_Nomination = ? AND AccuseReception IS NULL');
    $upd->execute([$idNomination]);

    $sel = $pdo->prepare('SELECT AccuseReception FROM nomination WHERE Id_Nomination = ?');
    $sel->execute([$idNomination]);
    $dt = $sel->fetchColumn();

    return [
        'ok'   => true,
        'nb'   => $upd->rowCount(),
        'date' => $dt ? date('d/m/Y H:i', strtotime($dt)) : null,
    ];
}

/**
 * Ajoute une valeur à l'ENUM d'une colonne si elle n'y est pas déjà — relit l'ENUM existant via
 * SHOW COLUMNS pour ne perdre aucune valeur déjà en place. $defaut doit correspondre au DEFAULT
 * actuel de la colonne (MySQL exige de le repréciser sur un MODIFY COLUMN). $table/$colonne ne
 * sont jamais fournis par l'utilisateur (toujours des littéraux au point d'appel).
 */
function ajouterValeurEnum(\PDO $pdo, string $table, string $colonne, string $valeur, string $defaut): void
{
    $col = $pdo->query("SHOW COLUMNS FROM $table WHERE Field = '$colonne'")->fetch();
    if (!$col || !preg_match("/^enum\((.+)\)$/i", $col['Type'], $m)) {
        return;
    }
    $valeurs = array_map('trim', str_getcsv($m[1], ',', "'"));
    if (in_array($valeur, $valeurs, true)) {
        return;
    }
    $valeurs[] = $valeur;
    $enumList  = implode(',', array_map(fn ($v) => $pdo->quote($v), $valeurs));
    $pdo->exec("ALTER TABLE $table MODIFY COLUMN $colonne ENUM($enumList) NOT NULL DEFAULT " . $pdo->quote($defaut));
}

/**
 * Ajoute une valeur à l'ENUM messagerie.Type si elle n'y est pas déjà (ex. pour un nouveau type
 * de message système), même lecture dynamique que MessagerieController::typesValides().
 */
function ajouterTypeMessagerie(\PDO $pdo, string $type): void
{
    ajouterValeurEnum($pdo, 'messagerie', 'Type', $type, 'Disponibilites');
}

/**
 * Ajoute le rôle 'CSR' (Commission Sportive Régionale) à l'ENUM utilisateur.Role s'il n'y est pas
 * déjà — permet de créer des comptes CSR depuis EA86 (Gestion des utilisateurs) sans migration
 * manuelle. Idempotente, appelée par UtilisateurController.
 */
function assurerRoleCsr(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'CSR', 'JA');
}

/**
 * Ajoute le rôle 'Defiscalisateur' à l'ENUM utilisateur.Role s'il n'y est pas déjà — permet de
 * créer des comptes Défiscalisateur depuis EA86 sans migration manuelle. Idempotente, appelée
 * par UtilisateurController, même principe que assurerRoleCsr().
 */
function assurerRoleDefiscalisateur(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'Defiscalisateur', 'Nominateur');
}

/**
 * Ajoute le rôle 'CRA Convoc' à l'ENUM utilisateur.Role s'il n'y est pas déjà — même principe
 * que assurerRoleCsr(). Pas encore de menu/route/filtre dédié : à la connexion, ce rôle tombe
 * dans le cas par défaut de AuthController::redirectForRole() (menu Nominateur).
 */
function assurerRoleCraConvoc(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'CRA Convoc', 'Nominateur');
}

/**
 * Garantit l'existence du type de message système "Expiration FFTT API" (ENUM messagerie.Type +
 * une ligne de gabarit par défaut, marqueurs {DATE_EXPIRATION}/{DELAI}) — éditable ensuite comme
 * les autres modèles système via EA93 (Id_Utilisateur NULL = protégé en écriture pour les non-admin,
 * voir MessagerieController). Idempotente, appelée à la fois par MessagerieController (pour que le
 * gabarit apparaisse dès l'ouverture de l'écran) et par verifierRappelExpirationFfttApi() (filet de
 * sécurité si EA93 n'a jamais été ouvert avant la fenêtre des 60 jours).
 */
function assurerTemplateExpirationFfttApi(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'Expiration FFTT API');

    $existe = $pdo->query("SELECT 1 FROM messagerie WHERE Type = 'Expiration FFTT API' LIMIT 1")->fetch();
    if ($existe) {
        return;
    }

    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc) VALUES (?, ?, ?, NULL, 0)')
        ->execute([
            'Expiration FFTT API',
            'Expiration des identifiants API FFTT le {DATE_EXPIRATION}',
            "Les identifiants de l'API FFTT (Code Appli / Mot de passe) utilisés par NIJAC expirent le {DATE_EXPIRATION} ({DELAI}).\n\n"
            . "Merci de faire la demande de prolongation auprès de la FFTT, puis de mettre à jour :\n"
            . "- le fichier .env (FFTT_APP_ID / FFTT_APP_KEY)\n"
            . "- la clé de configuration « fftt_api_expiration » (écran Configuration générale, EA91)",
        ]);
}

/**
 * Modèle « JA Club » (Id_Messagerie = 7) : email envoyé au correspondant d'un
 * club recevant en arbitrage club (EN14), demandant le nom du JA qui arbitrera
 * la rencontre via la page publique {URL_ARBITRE_CLUB} (EN25). Remplit la ligne
 * si elle est absente ou vide, sans écraser un contenu déjà saisi via EA93.
 */
function assurerTemplateArbitreClub(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'JA Club');

    $sujet   = 'Championnat Régional - Juge-arbitre pour {DOM} / {EXT} du {DATE}';
    $message = "Bonjour {CORR_NOM},\n\n"
        . "Votre équipe {DOM} reçoit {EXT} le {DATE} à {HEURE}"
        . " (Division {DIVISION}, journée {JOURNEE}, poule {POULE})"
        . " à la salle : {SALLE_NOM} {SALLE_CP} {SALLE_VILLE}.\n\n"
        . "Cette rencontre est en arbitrage club. Merci de nous indiquer le nom du"
        . " juge-arbitre qui la dirigera, en suivant ce lien :\n{URL_ARBITRE_CLUB}\n\n"
        . "À renseigner dans les 5 jours qui suivent la rencontre au maximum.\n\n"
        . "Sportivement,\n{UTI_PRENOM} {UTI_NOM}";

    $row = $pdo->query('SELECT Message FROM messagerie WHERE Id_Messagerie = 7')->fetch();
    if (!$row) {
        $pdo->prepare('INSERT INTO messagerie (Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (7, ?, ?, ?, NULL, 1, 1)')
            ->execute(['JA Club', $sujet, $message]);
    } elseif (trim((string) $row['Message']) === '') {
        $pdo->prepare('UPDATE messagerie SET Type = ?, Sujet = ?, Message = ?, Cc = 1, ReplyTo = 1 WHERE Id_Messagerie = 7')
            ->execute(['JA Club', $sujet, $message]);
    }
}

/**
 * Envoie le message n°7 (« JA Club ») au correspondant du club recevant de la
 * rencontre $idRenc (référent Club.RefMail en Cc), avec le lien EN25 pour que
 * le club désigne son juge-arbitre. Partagé par EN14 (« Envoyer la demande au
 * club », sujet/corps éventuellement retouchés dans le panneau) et EN28
 * (« Relancer le club »). Le périmètre du nominateur est vérifié par l'appelant.
 * Refus (rencontre introuvable, pas en arbitrage club, JA déjà désigné, club
 * sans email de correspondant) → ['ok' => false, 'msg' => ...], sans envoi ;
 * succès → ['ok' => true, 'msg' => ...]. Une erreur d'envoi lève l'exception
 * PHPMailer. Pas de rate limit ni de journalisation ici (laissés à l'appelant).
 */
function envoyerDemandeJaClub(\PDO $pdo, int $idRenc, array $moi, string $sujetEdit = '', string $msgEdit = ''): array
{
    $stmt = $pdo->prepare(
        "SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                ed.Division, CASE WHEN r.ArbitrageCRA = 1 THEN 'CRA' ELSE 'Club' END AS SouhaitJA,
                ed.Nom AS NomDom, ev.Nom AS NomExt,
                cl.CorNom, cl.CorEmail, cl.RefNom, cl.RefMail,
                COALESCE(sr.Nom, sc.Nom) AS SalleNom, COALESCE(sr.Adresse, sc.Adresse) AS SalleAdresse,
                COALESCE(sr.Cp, sc.Cp) AS SalleCp, COALESCE(sr.Ville, sc.Ville) AS SalleVille
         FROM rencontre r
         JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
         LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
         LEFT JOIN club cl ON cl.Id_Club = ed.Id_Club
         LEFT JOIN salle sr ON sr.Id_Salle = r.id_Salle
         LEFT JOIN salle sc ON sc.Id_Club = ed.Id_Club AND sc.EstPrincipale = 1
         WHERE r.Id_Rencontre = ?"
    );
    $stmt->execute([$idRenc]);
    $rc = $stmt->fetch();
    if (!$rc) {
        return ['ok' => false, 'msg' => 'Rencontre introuvable.'];
    }
    if ($rc['SouhaitJA'] !== 'Club') {
        return ['ok' => false, 'msg' => "Cette rencontre n'est pas en arbitrage club."];
    }
    $dejaNom = $pdo->prepare('SELECT COUNT(*) FROM nomination WHERE Id_Rencontre = ?');
    $dejaNom->execute([$idRenc]);
    if ((int) $dejaNom->fetchColumn() > 0) {
        return ['ok' => false, 'msg' => 'Un JA est déjà désigné pour cette rencontre.'];
    }
    if (empty($rc['CorEmail'])) {
        return ['ok' => false, 'msg' => "Le club recevant n'a pas d'email de correspondant (à compléter en EN27)."];
    }

    assurerTemplateArbitreClub($pdo);
    $tpl = resoudreModeleMessagerie($pdo, 7, (int) ($moi['id'] ?? 0))
        ?: ['Sujet' => 'Juge-arbitre pour {DOM} / {EXT}', 'Message' => '', 'Cc' => 0, 'ReplyTo' => 0];

    // Sujet / corps éventuellement retouchés dans le panneau EN14.
    if ($sujetEdit !== '') {
        $tpl['Sujet'] = $sujetEdit;
    }
    if ($msgEdit !== '') {
        $tpl['Message'] = $msgEdit;
    }

    $marqueurs = construireMarqueursMessage([], $moi, [
        'id_rencontre'  => $idRenc,
        'date'          => $rc['Date'],
        'heure'         => $rc['Heure'],
        'journee'       => $rc['Journee'],
        'poule'         => $rc['Poule'],
        'division'      => $rc['Division'],
        'dom'           => $rc['NomDom'],
        'ext'           => $rc['NomExt'],
        'salle_nom'     => $rc['SalleNom'],
        'salle_adresse' => $rc['SalleAdresse'],
        'salle_cp'      => $rc['SalleCp'],
        'salle_ville'   => $rc['SalleVille'],
        'corr_nom'      => $rc['CorNom'],
        'corr_email'    => $rc['CorEmail'],
    ]);
    $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);
    $corps = $rendu['corps'] !== '' ? $rendu['corps']
        : "Bonjour,\r\n\r\nMerci d'indiquer le juge-arbitre de la rencontre {$rc['NomDom']} / {$rc['NomExt']} du "
          . date('d/m/Y', strtotime($rc['Date'])) . " :\r\n" . $marqueurs['{URL_ARBITRE_CLUB}'];

    $modeDev = isModeDeveloppement();
    $dest    = getEmailDestinataire($rc['CorEmail']);
    $isHtml  = strip_tags($corps) !== $corps;

    $mail = getNijacMailer();
    $mail->isHTML($isHtml);
    $mail->addAddress($dest, (string) $rc['CorNom']);
    // Référent du club en copie s'il est renseigné (colonne Club.RefMail, EN27).
    if (!empty($rc['RefMail'])) {
        $mail->addCC(getEmailDestinataire($rc['RefMail']), (string) ($rc['RefNom'] ?? ''));
    }
    if (!empty($tpl['ReplyTo']) && !empty($moi['email'])) {
        $mail->addReplyTo($moi['email'], trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
    }
    if (!empty($tpl['Cc']) && !empty($moi['email'])) {
        $mail->addCC(getEmailDestinataire($moi['email']), trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
    }
    $mail->Subject = ($modeDev && $dest !== $rc['CorEmail']) ? "[DEV → {$rc['CorEmail']}] {$rendu['sujet']}" : $rendu['sujet'];
    $mail->Body    = $corps;
    if ($isHtml) {
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
    }
    $mail->send();

    $nb = count($mail->getToAddresses()) + count($mail->getCcAddresses());
    return ['ok' => true, 'msg' => 'Demande envoyée à ' . ($rc['CorNom'] ?: $rc['CorEmail'])
        . ($nb > 1 ? " ($nb destinataires)." : '.')];
}

/**
 * Modèles de convocation CRA (EC73) : Type messagerie => [fichier de Convocation/ (source
 * d'amorçage et repli à l'exécution), sujet par défaut]. Messages système identifiés par leur
 * Type (Id_Utilisateur NULL), pas par un Id_Messagerie fixe : les ids suivants sont déjà pris
 * par des copies personnelles sur certains environnements (AUTO_INCREMENT).
 */
function modelesConvocationCra(): array
{
    return [
        'CRA Convocation JA'           => ['Convocation_1JA.html',          'CRA – Convocation – {EPREUVE} – {DATE_LONGUE}'],
        'CRA Convocation JA + adjoint' => ['Convocation_1JA_1Adjoint.html', 'CRA – Convocation – {EPREUVE} – {DATE_LONGUE}'],
        'CRA Convocation adjoint'      => ['Convocation_Adjoint.html',      'CRA – Convocation adjoint – {EPREUVE} – {DATE_LONGUE}'],
    ];
}

/**
 * Amorce (initTableConfiguration(), EA98) les 3 messages système de convocation CRA : Type ENUM
 * étendu, puis ligne créée seulement si aucun message système de ce Type n'existe (jamais
 * d'écrasement d'un contenu retouché via EA93), corps = fichier de Convocation/ tel quel,
 * Cc/ReplyTo = 1 comme le message n°3. Fichier introuvable : message ignoré + error_log.
 */
function assurerModelesConvocationCra(\PDO $pdo): void
{
    foreach (array_keys(modelesConvocationCra()) as $type) {
        ajouterTypeMessagerie($pdo, $type);
    }
    $existe = $pdo->prepare('SELECT COUNT(*) FROM messagerie WHERE Type = ? AND Id_Utilisateur IS NULL');
    $ins    = $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (?, ?, ?, NULL, 1, 1)');
    foreach (modelesConvocationCra() as $type => [$fichier, $sujet]) {
        $existe->execute([$type]);
        if ((int) $existe->fetchColumn() > 0) {
            continue;
        }
        $corps = @file_get_contents(__DIR__ . '/../Convocation/' . $fichier);
        if ($corps === false) {
            error_log("[NIJAC] Modèle Convocation/$fichier introuvable : message « $type » non créé.");
            continue;
        }
        $ins->execute([$type, $sujet, $corps]);
    }
}

/**
 * Comme resoudreModeleMessagerie() mais par Type (convocations CRA, EC73) : copie personnelle
 * de l'utilisateur courant (Id_Utilisateur = lui) en priorité, sinon le message système
 * (Id_Utilisateur NULL, le plus ancien). Pas de rapprochement par Sujet : « CRA Convocation JA »
 * et « CRA Convocation JA + adjoint » ont le même sujet.
 */
function resoudreModeleMessagerieParType(\PDO $pdo, string $type, int $idUtilisateurCourant): ?array
{
    $stmt = $pdo->prepare('SELECT Sujet, Message, Cc, ReplyTo FROM messagerie
        WHERE Type = ? AND (Id_Utilisateur IS NULL OR Id_Utilisateur = ?)
        ORDER BY Id_Utilisateur IS NULL, Id_Messagerie LIMIT 1');
    $stmt->execute([$type, $idUtilisateurCourant]);

    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** Type messagerie du message système « copie de convocation aux clubs » (EN14, envoyerCopieClubs()). */
defined('TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS') || define('TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS', 'Convocation clubs');

/**
 * Amorce (initTableConfiguration(), EA98) le message système « Convocation clubs » : copie pour
 * information de la convocation d'un JA, envoyée par EN14 aux correspondants/référents des deux
 * clubs (NominationController::envoyerCopieClubs()). Type ENUM étendu, ligne créée seulement si
 * aucun message système de ce Type n'existe (jamais d'écrasement ni de copie perso touchée).
 * Sujet utilisé tel quel (préfixe « Copie – » inclus) ; Cc = 0 (le nominateur est déjà en Cc de
 * la convocation du JA), ReplyTo = 1 comme le message n°3.
 */
function assurerModeleCopieConvocationClubs(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS);

    $existe = $pdo->prepare('SELECT Id_Messagerie, Message FROM messagerie WHERE Type = ? AND Id_Utilisateur IS NULL');
    $existe->execute([TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS]);
    $lignes = $existe->fetchAll(\PDO::FETCH_ASSOC);
    if ($lignes) {
        // Mise à niveau : corps système resté à une ancienne version par défaut → version courante
        // (coordonnées du JA). Corps personnalisé en EA93 : laissé tel quel. Copies perso jamais touchées.
        $norm    = fn ($s): string => trim(str_replace("\r\n", "\n", (string) $s));
        $anciens = array_map($norm, ancienCorpsCopieConvocationClubs());
        foreach ($lignes as $l) {
            if (in_array($norm($l['Message']), $anciens, true)) {
                $pdo->prepare('UPDATE messagerie SET Message = ? WHERE Id_Messagerie = ?')
                    ->execute([modeleHtmlCopieConvocationClubs(), $l['Id_Messagerie']]);
            } elseif (!str_contains((string) $l['Message'], '{COORDONNEES_JA}')) {
                error_log('[NIJAC] message Convocation clubs personnalisé : coordonnées JA non ajoutées');
            }
        }
        return;
    }
    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (?, ?, ?, NULL, 0, 1)')
        ->execute([
            TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS,
            'Copie – Convocation JA du {DATE} à {HEURE} à {NOM_CLUB}',
            modeleHtmlCopieConvocationClubs(),
        ]);
}

/**
 * Anciennes versions par défaut du corps « Convocation clubs », remplacées par
 * assurerModeleCopieConvocationClubs() si la base les contient encore à l'identique.
 * v1 = version courante sans {COORDONNEES_JA} (seule différence). ponytail: dérivée du modèle
 * courant ; à figer en dur avant toute autre modification de modeleHtmlCopieConvocationClubs().
 *
 * @return string[]
 */
function ancienCorpsCopieConvocationClubs(): array
{
    return [str_replace('{COORDONNEES_JA}', '', modeleHtmlCopieConvocationClubs())];
}

/**
 * Corps par défaut du message « Convocation clubs » : même charte que modeleHtmlConvocationJa(),
 * destiné à un correspondant de club — aucun lien personnel du JA ni consigne qui lui est destinée.
 */
function modeleHtmlCopieConvocationClubs(): string
{
    return <<<'NIJAC_COPIE_CLUBS_HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Copie de convocation — Juge-Arbitre</title>
</head>
<body style="margin:0;padding:0;background-color:#eef1f6;color:#1f2937;">
<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Juge-arbitre désigné : {PRENOM} {NOM} — {DOM} – {EXT}, le {DATE_LONGUE} à {HEURE}.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef1f6" style="background-color:#eef1f6;">
<tr>
<td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #d5dbe5;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">

<!-- En-tête -->
<tr>
<td bgcolor="#1a3a6b" style="background-color:#1a3a6b;padding:22px 28px;border-radius:8px 8px 0 0;">
<span style="display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#c9d6ea;">Ligue de Normandie de Tennis de Table</span>
<span style="display:block;font-size:22px;font-weight:bold;line-height:30px;color:#ffffff;">Copie de convocation — Juge-Arbitre</span>
</td>
</tr>

<!-- Introduction -->
<tr>
<td style="padding:26px 28px 6px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Bonjour,<br><br>
Ceci est une copie, pour information, de la convocation adressée à <strong>{PRENOM} {NOM}</strong> (juge-arbitre), désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {SEXE}.
</td>
</tr>

<!-- Récapitulatif de la rencontre -->
<tr>
<td style="padding:16px 28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f6fb" style="background-color:#f3f6fb;border-left:4px solid #1a3a6b;border-radius:4px;font-size:14px;line-height:21px;color:#1f2937;">
<tr><td colspan="2" style="padding:14px 16px 4px 16px;font-size:17px;font-weight:bold;color:#1a3a6b;">{DOM} <span style="color:#6b7280;font-weight:normal;">à</span> {EXT}</td></tr>
<tr><td width="34%" valign="top" style="padding:6px 16px;color:#4b5563;">Date</td><td valign="top" style="padding:6px 16px 6px 0;font-weight:bold;color:#1f2937;">{DATE_LONGUE} à {HEURE}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Compétition</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">Journée n° {JOURNEE} — Division : {DIVISION} — Poule : {POULE}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Club recevant</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{NOM_CLUB}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Salle</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{SALLE_NOM}<br>{SALLE_ADRESSE}<br>{SALLE_CP} {SALLE_VILLE}</td></tr>
<tr><td valign="top" style="padding:6px 16px 14px 16px;color:#4b5563;">Juge-arbitre</td><td valign="top" style="padding:6px 16px 14px 0;font-weight:bold;color:#1f2937;">{PRENOM} {NOM}{COORDONNEES_JA}</td></tr>
</table>
</td>
</tr>

<!-- Signature -->
<tr>
<td style="padding:18px 28px 24px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Sportivement,<br><br>
<strong>{UTI_PRENOM} {UTI_NOM}</strong><br>
<span style="font-size:13px;color:#4b5563;">Ligue de Normandie de Tennis de Table</span>
</td>
</tr>

<!-- Pied de page -->
<tr>
<td bgcolor="#f3f4f6" style="background-color:#f3f4f6;padding:14px 28px;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;font-size:11px;line-height:17px;color:#6b7280;">
Message envoyé automatiquement par l'application de nomination des juges-arbitres de la Ligue, pour information des correspondants et référents des clubs concernés. Pour toute question, répondez à ce message.<br>
<a href="{URL_LIGUE}" style="color:#1a3a6b;">{URL_LIGUE}</a>
</td>
</tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>

NIJAC_COPIE_CLUBS_HTML;
}

/**
 * Garantit l'existence du type de message système « Mot de passe oublié » (ENUM messagerie.Type +
 * une ligne de gabarit par défaut, marqueur {URL_RESET_MDP}) — éditable ensuite comme les autres
 * modèles système via EA93 (Id_Utilisateur NULL = protégé en écriture pour les non-admin).
 * Idempotente, appelée par MessagerieController à l'ouverture de l'écran.
 */
function assurerTemplateMotDePasseOublie(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'Mot de passe oublié');

    $existe = $pdo->query("SELECT 1 FROM messagerie WHERE Type = 'Mot de passe oublié' LIMIT 1")->fetch();
    if ($existe) {
        return;
    }

    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc) VALUES (?, ?, ?, NULL, 0)')
        ->execute([
            'Mot de passe oublié',
            'NIJAC - Réinitialisation de votre mot de passe',
            "Bonjour {UTI_PRENOM} {UTI_NOM},\n\n"
            . "Une réinitialisation de mot de passe a été demandée pour votre compte NIJAC.\n\n"
            . "Cliquez sur le lien suivant pour choisir un nouveau mot de passe :\n{URL_RESET_MDP}\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.",
        ]);
}

// ── Double authentification par email (E001 → E010) ──────────────────────────────────────────

/** Type messagerie du message système « code de sécurité » (E010), jamais personnalisable par un nominateur. */
defined('TYPE_MESSAGE_CODE_SECURITE') || define('TYPE_MESSAGE_CODE_SECURITE', 'Code de sécurité');
/** Sujet par défaut du message « Code de sécurité » (amorçage EA98 et repli). */
defined('SUJET_CODE_SECURITE') || define('SUJET_CODE_SECURITE', 'Votre code de sécurité NIJAC');

const CODE_SECURITE_DUREE      = 600; // durée de vie du code (s) — expiration absolue
const CODE_SECURITE_ESSAIS     = 5;   // essais maximum, puis retour à E001
const CODE_SECURITE_DELAI      = 60;  // délai minimal entre deux envois (s)
const CODE_SECURITE_ENVOIS_MAX = 3;   // envois maximum par fenêtre de CODE_SECURITE_DUREE

/**
 * Coupe-circuit : '0' dans configuration.double_authentification = connexion par mot de passe
 * seul (panne SMTP, adresse erronée). Clé absente = active.
 */
function doubleAuthentificationActive(): bool
{
    return getConfig('double_authentification', '1') !== '0';
}

/** Code de sécurité : 6 chiffres exactement, zéros de tête conservés. */
function genererCodeSecurite(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Seul le hash du code est conservé (session) — jamais le code en clair. */
function hacherCodeSecurite(string $code): string
{
    return password_hash($code, PASSWORD_DEFAULT);
}

/**
 * Nouvel état d'attente après un envoi réussi : remplace le code précédent et remet les essais à 0.
 * $etat['envois'] (horodatages des envois) est conservé pour la limite de renvoi.
 */
function emettreCodeSecurite(array $etat, string $hash, int $maintenant): array
{
    return ['hash' => $hash, 'emis' => $maintenant, 'essais' => 0] + $etat;
}

/**
 * Vérifie une saisie contre l'état d'attente (fonction pure : aucune session, aucune base).
 * Retourne ['ok' => bool, 'erreur' => null|'invalide'|'epuise', 'etat' => ?array] :
 *  - ok : code consommé (etat = null, usage unique) ;
 *  - 'invalide' : code faux, expiré (> CODE_SECURITE_DUREE) ou déjà consommé — etat mis à jour ;
 *  - 'epuise' : CODE_SECURITE_ESSAIS échecs atteints — etat = null, l'utilisateur doit se reconnecter.
 * Toute saisie non conforme (autre chose que 6 chiffres après retrait des espaces) compte comme un échec.
 */
function verifierCodeSecurite(array $etat, string $saisie, int $maintenant): array
{
    $saisie = preg_replace('/\s+/', '', $saisie);
    $hash   = $etat['hash'] ?? null;
    $valide = is_string($hash)
        && $maintenant - (int) ($etat['emis'] ?? 0) <= CODE_SECURITE_DUREE
        && preg_match('/^\d{6}$/', $saisie) === 1;

    // password_verify : comparaison en temps constant
    if ($valide && password_verify($saisie, $hash)) {
        return ['ok' => true, 'erreur' => null, 'etat' => null];
    }

    $etat['essais'] = (int) ($etat['essais'] ?? 0) + 1;
    if ($etat['essais'] >= CODE_SECURITE_ESSAIS) {
        return ['ok' => false, 'erreur' => 'epuise', 'etat' => null];
    }

    return ['ok' => false, 'erreur' => 'invalide', 'etat' => $etat];
}

/**
 * Renvoi d'un code autorisé ? (fonction pure). Au plus CODE_SECURITE_ENVOIS_MAX envois par fenêtre
 * de CODE_SECURITE_DUREE et CODE_SECURITE_DELAI secondes entre deux envois.
 * Retourne ['ok' => bool, 'attente' => secondes avant le prochain envoi possible, 'etat' => etat
 * avec les envois hors fenêtre purgés].
 */
function peutRenvoyerCode(array $etat, int $maintenant): array
{
    $envois = array_values(array_filter(
        $etat['envois'] ?? [],
        fn ($ts) => $maintenant - (int) $ts < CODE_SECURITE_DUREE
    ));
    $etat['envois'] = $envois;

    $attente = 0;
    if ($envois) {
        $attente = max($attente, (int) end($envois) + CODE_SECURITE_DELAI - $maintenant);
    }
    if (count($envois) >= CODE_SECURITE_ENVOIS_MAX) {
        $attente = max($attente, (int) $envois[0] + CODE_SECURITE_DUREE - $maintenant);
    }

    return ['ok' => $attente <= 0, 'attente' => max(0, $attente), 'etat' => $etat];
}

/** Corps par défaut (texte brut, LF) du message « Code de sécurité » : amorçage EA98 et repli avant EA98. */
function corpsParDefautCodeSecurite(): string
{
    return "Bonjour,\n\n"
        . "Votre code de sécurité est le suivant :\n"
        . "{CODE}\n"
        . "Il est valable 10 minutes. Au-delà de ce délai, vous devrez demander un nouveau code.\n\n"
        . "Si vous n’êtes pas à l’origine de cette demande, veuillez-vous rapprocher de votre nominateur dans les plus brefs délais.\n\n"
        . "Ce message est généré automatiquement, ne pas répondre.\n\n"
        . "Nous vous remercions de votre attention.";
}

/**
 * Amorce (initTableConfiguration(), EA98) le message système « Code de sécurité » : Type ENUM étendu,
 * ligne créée seulement si aucun message système de ce Type n'existe (jamais d'écrasement).
 * Cc = 0, ReplyTo = 0 : envoyé au seul titulaire du compte.
 */
function assurerModeleCodeSecurite(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, TYPE_MESSAGE_CODE_SECURITE);

    $existe = $pdo->prepare('SELECT COUNT(*) FROM messagerie WHERE Type = ? AND Id_Utilisateur IS NULL');
    $existe->execute([TYPE_MESSAGE_CODE_SECURITE]);
    if ((int) $existe->fetchColumn() > 0) {
        return;
    }
    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (?, ?, ?, NULL, 0, 0)')
        ->execute([TYPE_MESSAGE_CODE_SECURITE, SUJET_CODE_SECURITE, corpsParDefautCodeSecurite()]);
}

/**
 * Sujet/corps de l'email du code. $modele = ligne messagerie système (Sujet/Message) ou null
 * (avant EA98 : repli sur le texte par défaut). {CODE} remplacé par le code échappé (6 chiffres).
 *
 * @return array{sujet: string, corps: string, html: bool}
 */
function rendreMessageCodeSecurite(?array $modele, string $code): array
{
    $sujet = trim((string) ($modele['Sujet'] ?? '')) ?: SUJET_CODE_SECURITE;
    $corps = trim((string) ($modele['Message'] ?? '')) !== '' ? (string) $modele['Message'] : corpsParDefautCodeSecurite();
    $rendu = remplacerMarqueursMessage($sujet, $corps, ['{CODE}' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8')]);

    return $rendu + ['html' => strip_tags($rendu['corps']) !== $rendu['corps']];
}

/**
 * Requêtes rendant utilisateur.Email NOT NULL à partir de sa définition information_schema
 * (COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME) : type, longueur,
 * jeu de caractères et défaut conservés ; NULL -> '' (aucune adresse fabriquée). [] si déjà NOT NULL.
 *
 * @return string[]
 */
function sqlEmailUtilisateurObligatoire(array $col, \PDO $pdo): array
{
    if (($col['IS_NULLABLE'] ?? 'NO') !== 'YES') {
        return [];
    }
    $charset = !empty($col['CHARACTER_SET_NAME']) ? " CHARACTER SET {$col['CHARACTER_SET_NAME']} COLLATE {$col['COLLATION_NAME']}" : '';
    $defaut  = $col['COLUMN_DEFAULT'] ?? null;
    $defaut  = ($defaut === null || strtoupper($defaut) === 'NULL') ? '' : ' DEFAULT ' . $pdo->quote(trim($defaut, "'"));

    return [
        "UPDATE utilisateur SET Email = '' WHERE Email IS NULL",
        "ALTER TABLE utilisateur MODIFY Email {$col['COLUMN_TYPE']}{$charset} NOT NULL{$defaut}",
    ];
}

/**
 * Règle de robustesse du mot de passe (E008 « mot de passe oublié ») : 10 caractères
 * minimum, avec au moins une minuscule, une majuscule, un chiffre et un caractère
 * spécial. Retourne null si conforme, sinon le message d'erreur à afficher.
 */
function validerRobustesseMotDePasse(string $mdp): ?string
{
    if (strlen($mdp) < 10)                     return 'Le mot de passe doit contenir au moins 10 caractères.';
    if (!preg_match('/[a-z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre minuscule.';
    if (!preg_match('/[A-Z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre majuscule.';
    if (!preg_match('/\d/', $mdp))             return 'Le mot de passe doit contenir au moins un chiffre.';
    if (!preg_match('/[^a-zA-Z0-9]/', $mdp))   return 'Le mot de passe doit contenir au moins un caractère spécial.';
    return null;
}

/**
 * Génère un mot de passe aléatoire conforme à validerRobustesseMotDePasse()
 * (au moins un minuscule, un majuscule, un chiffre, un caractère spécial).
 * Caractères ambigus (l/1/I, O/0…) exclus pour la lisibilité. Utilisé par EA86
 * quand l'admin coche « Écraser » (mot de passe provisoire à communiquer).
 */
function genererMotDePasseAleatoire(int $longueur = 12): string
{
    $minu = 'abcdefghijkmnpqrstuvwxyz';
    $maju = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $chif = '23456789';
    $spec = '!@#$%*-_=+';
    $tous = $minu . $maju . $chif . $spec;

    $car = [
        $minu[random_int(0, strlen($minu) - 1)],
        $maju[random_int(0, strlen($maju) - 1)],
        $chif[random_int(0, strlen($chif) - 1)],
        $spec[random_int(0, strlen($spec) - 1)],
    ];
    for ($i = count($car); $i < max($longueur, 10); $i++) {
        $car[] = $tous[random_int(0, strlen($tous) - 1)];
    }
    for ($i = count($car) - 1; $i > 0; $i--) {           // mélange Fisher-Yates
        $j = random_int(0, $i);
        [$car[$i], $car[$j]] = [$car[$j], $car[$i]];
    }

    return implode('', $car);
}

/**
 * Jeton de réinitialisation de mot de passe SANS stockage BDD : la signature HMAC
 * est calculée avec le hash du mot de passe actuel comme clé, donc le jeton devient
 * automatiquement invalide dès que le mot de passe a été changé (usage unique).
 * Format : "<idUtilisateur>-<timestampExpiration>-<hmacSha256>" (tout URL-safe).
 */
function genererJetonResetMdp(int $idUtilisateur, string $hashMdpActuel, int $dureeSecondes = 3600): string
{
    $exp = time() + $dureeSecondes;
    $sig = hash_hmac('sha256', $idUtilisateur . '.' . $exp, $hashMdpActuel . OBFUSCATOR_SEED);
    return $idUtilisateur . '-' . $exp . '-' . $sig;
}

/**
 * Vérifie un jeton produit par genererJetonResetMdp() : retourne l'Id_Utilisateur si
 * le jeton est bien formé, non expiré et signé avec le hash de mot de passe COURANT
 * de l'utilisateur, sinon null. $lookupHash(int $id): ?string doit rendre le hash
 * Password actuel (null si utilisateur inconnu/inactif).
 */
function verifierJetonResetMdp(string $jeton, callable $lookupHash): ?int
{
    if (!preg_match('/^(\d+)-(\d+)-([0-9a-f]{64})$/', $jeton, $m)) {
        return null;
    }
    [, $id, $exp, $sig] = $m;
    if ((int) $exp < time()) {
        return null;
    }
    $hash = $lookupHash((int) $id);
    if (!$hash) {
        return null;
    }
    $attendu = hash_hmac('sha256', $id . '.' . $exp, $hash . OBFUSCATOR_SEED);
    return hash_equals($attendu, $sig) ? (int) $id : null;
}

/**
 * Envoie un rappel par email aux administrateurs actifs quand l'expiration des identifiants
 * API FFTT approche (2 mois, soit 60 jours) ou est dépassée — à demander à prolonger auprès de
 * la FFTT, puis à reporter dans .env (FFTT_APP_ID/FFTT_APP_KEY) et dans la clé de config
 * 'fftt_api_expiration'. Sujet/corps viennent du gabarit système "Expiration FFTT API" de la
 * table `messagerie` (éditable via EA93), avec les marqueurs {DATE_EXPIRATION}/{DELAI} — voir
 * assurerTemplateExpirationFfttApi(). Envoyé au plus une fois par date d'expiration (mémorisé
 * dans la clé 'fftt_api_expiration_email_envoye') pour ne pas spammer à chaque connexion admin
 * (voir AuthController::index(), seul appelant). Best-effort : erreurs SMTP/BDD avalées, ce
 * rappel ne doit jamais faire échouer la connexion.
 */
function verifierRappelExpirationFfttApi(): void
{
    try {
        $exp = getConfig('fftt_api_expiration', '');
        if ($exp === '') {
            return;
        }
        $joursRestants = getFfttApiJoursAvantExpiration();
        if ($joursRestants === null || $joursRestants > 60) {
            return;
        }
        if (getConfig('fftt_api_expiration_email_envoye', '') === $exp) {
            return; // déjà envoyé pour cette date d'expiration
        }

        $pdo = getPDO();
        assurerTemplateExpirationFfttApi($pdo);

        $tpl = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Expiration FFTT API' LIMIT 1")->fetch();
        if (!$tpl) {
            return;
        }

        $dateFmt   = (\DateTime::createFromFormat('Y-m-d', $exp))->format('d/m/Y');
        $delai     = $joursRestants >= 0 ? "dans $joursRestants jour(s)" : ('depuis ' . abs($joursRestants) . ' jour(s)');
        $marqueurs = ['{DATE_EXPIRATION}' => $dateFmt, '{DELAI}' => $delai];
        $rendu     = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);

        $emails = $pdo->query(
            "SELECT Email FROM utilisateur WHERE Role = 'Administrateur' AND Actif = 1 AND Email IS NOT NULL AND Email <> ''"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($emails as $email) {
            try {
                $mail = getNijacMailer();
                $mail->isHTML(strip_tags($rendu['corps']) !== $rendu['corps']);
                $mail->addAddress(getEmailDestinataire($email));
                $mail->Subject = $rendu['sujet'];
                $mail->Body    = $rendu['corps'];
                $mail->send();
            } catch (\Throwable $e) {
            }
        }

        $pdo->prepare('INSERT INTO configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
            ->execute(['fftt_api_expiration_email_envoye', $exp]);
    } catch (\Throwable $e) {
    }
}

/**
 * Jeton du lien public EN18 (désidératas club) : "<Id_Club>-<MAC>". Le numéro FFTT du club est public et
 * devinable : il ne protège rien à lui seul ; le MAC (HMAC-SHA256 tronqué, clé = seed + pepper Obfuscator)
 * rend le lien infalsifiable. Sans pepper configuré (.env), même limite que l'Obfuscator (voir CLAUDE.md).
 */
function tokenDesiderataClub(string $idClub): string
{
    return $idClub . '-' . substr(hash_hmac('sha256', 'desiderata-club|' . $idClub, OBFUSCATOR_SEED . '|' . getObfuscatorPepper()), 0, 16);
}

/** Id_Club contenu dans un jeton EN18, ou null si le jeton est absent / falsifié. */
function idClubDepuisTokenDesiderata(string $token): ?string
{
    $pos = strrpos($token, '-');
    if ($pos === false || $pos === 0) {
        return null;
    }
    $idClub = substr($token, 0, $pos);

    return hash_equals(tokenDesiderataClub($idClub), $token) ? $idClub : null;
}

/**
 * Construit la table de correspondance des marqueurs {XXX} des modèles de
 * message (table `messagerie`) — source unique remplaçant les listes de
 * marqueurs dupliquées et divergentes qui existaient dans
 * CentrenvoyeController::marqueurs() (EN15), NominationController::
 * envoyerConvocations() (EN14) et AdresseJaController::
 * envoyerDemandeAdresse() (EN19). C'est cette divergence qui causait le bug
 * "{URL_CONVOCATION_JA} non remplacé" (EN14 ne connaissait que
 * {LIEN_CONVOCATION}).
 *
 * @param array $ja   Ligne JA : au moins Id_JA, Nom, Prenom ; Telephone/Email optionnels → {TEL_JA}, {EMAIL_JA}
 *                    (texte brut) et {COORDONNEES_JA} (HTML échappé, vide si ni téléphone ni email).
 * @param array $moi  Utilisateur connecté ($_SESSION['utilisateur']) ; tableau vide pour un envoi sans session.
 * @param array $ctx  Contexte optionnel de la rencontre/convocation — toute clé absente laisse
 *                    le(s) marqueur(s) correspondant(s) vide(s) :
 *                    {PHASE} : numéro de phase (config `phase`, saisi manuellement — distinct de {YEAR_PHASE}
 *                    qui est calculé à partir de la date du jour, voir getAnneePhase()).
 *                    id_nomination, id_rencontre (pour {URL_ARBITRE_CLUB}), sexe_code ('F'|'M'), date, date_fin (plage de {DATE_LONGUE}, EC73), heure, journee, poule, division, dom, ext,
 *                    nom_club, salle_nom, salle_adresse, salle_cp, salle_ville,
 *                    epreuve ({EPREUVE}), nb_tables ({NB_TABLES}) — aucune source en base à ce jour,
 *                    corr_nom, corr_email, corr_tel, liste_nominations (HTML de {LISTE_NOMINATIONS}),
 *                    ja_disponibles (liste de lignes Nom/Prenom/Telephone/Email → <tr> de {LISTE_JA_DISPONIBLES}),
 *                    nb_adjoints (EC73 : {NB_ADJOINTS}, {TITRE_ADJOINTS}, {ADJOINTS_TEXTE}, {ADJOINTS_A_SOLLICITER},
 *                    {ADJOINTS_RETENUS}, {ADJOINTS_SOLLICITES}, {VOTRE_ADJOINT}, {LUI_LEUR} ; absent → singulier),
 *                    adjoints_valides + liste_adjoints_valides (EC73 : adjoints désignés, lignes Nom/Prenom/Telephone/Email/Rang ;
 *                    0/absent → texte et {LISTE_JA_DISPONIBLES} d'origine ; ≥ nb_adjoints → validés seuls, sans JA disponibles ;
 *                    entre les deux → validés puis restants à solliciter) → {ADJOINTS_INTRO}, {ADJOINTS_CONTACT}, {TITRE_LISTE_ADJOINTS},
 *                    ja_principaux (EC73, Convocation_Adjoint.html : liste de ['nom','prenom','telephone','email'] →
 *                    {NOM_JA_PRINCIPAL} « Prénom NOM », {TEL_JA_PRINCIPAL}, {EMAIL_JA_PRINCIPAL} ; plusieurs = « A, B et C »,
 *                    valeurs vides ignorées ; texte brut, échappé par l'appelant comme les autres marqueurs).
 * @return array<string,string> Table marqueur => valeur, prête pour remplacerMarqueursMessage().
 */
function construireMarqueursMessage(array $ja, array $moi = [], array $ctx = []): array
{
    require_once __DIR__ . '/../Classes/Obfuscator.php';

    $idJa         = (int)($ja['Id_JA'] ?? 0);
    $token        = $idJa > 0 ? (new Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper()))->obfuscate($idJa) : '';
    $idNomination = $ctx['id_nomination'] ?? null;
    $tokenNomination = !empty($idNomination) ? (new Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper()))->obfuscate((int) $idNomination) : '';
    $idRencontre  = $ctx['id_rencontre'] ?? null;
    $tokenRenc    = !empty($idRencontre) ? (new Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper()))->obfuscate((int) $idRencontre) : '';

    $sexe = match ($ctx['sexe_code'] ?? '') {
        'F'     => 'Féminin',
        'M'     => 'Masculin',
        default => '',
    };

    // Date longue FR sans locale/intl (mêmes tableaux que ConvocationJaController).
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $dateLongue = '';
    if (!empty($ctx['date'])) {
        $ts    = strtotime($ctx['date']);
        $dateLongue = $jours[(int) date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts);
        // Plage (EC73) : « samedi 14 – dimanche 15 novembre 2026 » ; mois / année du début répétés seulement s'ils diffèrent.
        if (!empty($ctx['date_fin']) && $ctx['date_fin'] !== $ctx['date']) {
            $tf    = strtotime($ctx['date_fin']);
            $debut = $jours[(int) date('w', $ts)] . ' ' . date('j', $ts)
                . (date('Y-n', $ts) !== date('Y-n', $tf) ? ' ' . $mois[(int) date('n', $ts)] : '')
                . (date('Y', $ts) !== date('Y', $tf) ? ' ' . date('Y', $ts) : '');
            $dateLongue = $debut . ' – ' . $jours[(int) date('w', $tf)] . ' ' . date('j', $tf) . ' ' . $mois[(int) date('n', $tf)] . ' ' . date('Y', $tf);
        }
    }
    // Date du jour d'édition/envoi, sans jour de semaine (ex. "1 octobre 2026").
    $dateEdition = date('j') . ' ' . $mois[(int) date('n')] . ' ' . date('Y');

    // {LISTE_JA_DISPONIBLES} : lignes <tr> seules (l'en-tête "Nom et prénom /
    // Coordonnées" reste dans le modèle), même style que Convocation_1JA_1Adjoint.html.
    $td     = 'border:1px solid #999;text-align:left;';
    $lignes = function (array $rows) use ($td): string {
        $html = '';
        foreach (array_values($rows) as $idx => $d) {
            $style  = $td . ($idx % 2 === 0 ? 'background:#D9E2F3;' : '');
            $coords = implode(' — ', array_filter([trim((string) ($d['Telephone'] ?? '')), trim((string) ($d['Email'] ?? ''))]));
            $html  .= sprintf(
                '<tr><td style="%s">%s</td><td style="%s">%s</td></tr>',
                $style,
                htmlspecialchars(trim(($d['Prenom'] ?? '') . ' ' . ($d['Nom'] ?? ''))),
                $style,
                htmlspecialchars($coords)
            );
        }

        return $html;
    };

    // Pluralisation de Convocation_1JA_1Adjoint.html (EC73) : nb_adjoints absent ou < 2 → forme singulière d'origine.
    $nbAdj  = max(1, (int) ($ctx['nb_adjoints'] ?? 1));
    $pluriel = $nbAdj > 1;
    $lettres = fn (int $n): string => [2 => 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix'][$n] ?? (string) $n;
    $nbLettres = $lettres($nbAdj);

    // Adjoints validés (EC73 : désignés dans CRA_Designation) : 0 → (a) texte et liste des JA disponibles d'origine ;
    // tous les attendus (≥ nb_adjoints) → (b) seuls les validés, jamais de JA disponibles ;
    // sinon → (c) validés, puis « restant(s) à solliciter » parmi les JA disponibles.
    $nbVal    = max(0, (int) ($ctx['adjoints_valides'] ?? 0));
    $complet  = $nbVal > 0 && $nbVal >= $nbAdj;
    $partiel  = $nbVal > 0 && !$complet;
    $nbSol    = $partiel ? $nbAdj - $nbVal : $nbAdj; // adjoints encore à solliciter (a, c)
    $aSollic  = fn (int $n): string => $n > 1 ? $lettres($n) . ' JA2 ou JA3' : 'un JA2 ou un JA3';
    $retenus  = fn (int $n): string => $n > 1 ? 'les personnes retenues afin de confirmer leur disponibilité et leur accord'
                                              : 'la personne retenue afin de confirmer sa disponibilité et son accord';
    $valPl    = $nbVal > 1;
    $valides  = $valPl ? 'Juges-Arbitres adjoints désignés' : 'Juge-Arbitre adjoint désigné';

    $listeJaDispo = '';
    $vide = '<tr><td colspan="2" style="' . $td . '"><em>(aucun autre JA disponible)</em></td></tr>';
    if ($nbVal > 0) {
        $listeJaDispo = $lignes((array) ($ctx['liste_adjoints_valides'] ?? []));
        if ($partiel) {
            $listeJaDispo .= '<tr><th colspan="2" style="' . $td . 'background:#4472C4;color:#FFFFFF;">'
                . ($nbSol > 1 ? 'Adjoints restants' : 'Adjoint restant') . ' à solliciter parmi les personnes disponibles</th></tr>'
                . ($lignes((array) ($ctx['ja_disponibles'] ?? [])) ?: $vide);
        }
    } elseif (isset($ctx['ja_disponibles'])) {
        $listeJaDispo = $lignes($ctx['ja_disponibles']) ?: $vide;
    }

    // JA principaux présentés à l'adjoint (Convocation_Adjoint.html) : « A, B et C », valeurs vides ignorées.
    $principaux = (array) ($ctx['ja_principaux'] ?? []);
    $liste = function (callable $f) use ($principaux): string {
        $v = array_values(array_filter(array_map(fn ($p) => trim((string) $f((array) $p)), $principaux), 'strlen'));
        $dernier = array_pop($v);

        return $v ? implode(', ', $v) . ' et ' . $dernier : (string) $dernier;
    };

    // Même format que JugearbitreController::formaterTelephone() : 10 chiffres → 06.12.34.56.78, sinon tel quel.
    $fmtTel = fn ($tel): string => strlen($t = preg_replace('/\D/', '', (string) $tel)) === 10
        ? implode('.', str_split($t, 2)) : trim((string) $tel);

    // Coordonnées du JA convoqué (copie aux clubs, EN14) : {COORDONNEES_JA} = bloc HTML déjà échappé,
    // « Téléphone : … · Email : <mailto> », parties vides omises, chaîne vide si aucune.
    $telJa   = $fmtTel($ja['Telephone'] ?? '');
    $emailJa = trim((string) ($ja['Email'] ?? ''));
    $h       = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $coordJa = implode(' · ', array_filter([
        $telJa !== ''   ? 'Téléphone : ' . $h($telJa) : '',
        $emailJa !== '' ? 'Email : <a href="mailto:' . $h($emailJa) . '" style="color:#1a3a6b;">' . $h($emailJa) . '</a>' : '',
    ]));

    return [
        '{NOM_JA_PRINCIPAL}'     => $liste(fn ($p) => trim(($p['prenom'] ?? '') . ' ' . mb_strtoupper((string) ($p['nom'] ?? ''), 'UTF-8'))),
        '{TEL_JA_PRINCIPAL}'     => $liste(fn ($p) => $fmtTel($p['telephone'] ?? '')),
        '{EMAIL_JA_PRINCIPAL}'   => $liste(fn ($p) => $p['email'] ?? ''),
        '{TEL_JA}'               => $telJa,
        '{EMAIL_JA}'             => $emailJa,
        '{COORDONNEES_JA}'       => $coordJa !== '' ? '<br><span style="font-weight:normal;font-size:13px;color:#4b5563;">' . $coordJa . '</span>' : '',
        '{NB_ADJOINTS}'          => (string) $nbAdj,
        '{TITRE_ADJOINTS}'       => $nbAdj . ($pluriel ? ' ADJOINTS' : ' ADJOINT'),
        '{ADJOINTS_TEXTE}'       => $pluriel ? "$nbLettres Juges-Arbitres adjoints" : 'un seul Juge-Arbitre adjoint',
        '{ADJOINTS_A_SOLLICITER}' => $aSollic($nbSol),
        '{ADJOINTS_RETENUS}'     => $retenus($nbSol),
        '{ADJOINTS_SOLLICITES}'  => $complet
            ? 'la confirmation de votre prise de contact avec ' . ($valPl
                ? 'les Juges-Arbitres adjoints désignés ; leur convocation leur est adressée directement par la CRA'
                : 'le Juge-Arbitre adjoint désigné ; sa convocation lui est adressée directement par la CRA')
            : ($nbSol > 1 ? 'les noms et prénoms des Juges-Arbitres adjoints sollicités ainsi que la confirmation de leur accord. Après validation par la CRA, leur convocation leur sera adressée'
                          : 'le nom et le prénom du Juge-Arbitre adjoint sollicité ainsi que la confirmation de son accord. Après validation par la CRA, sa convocation lui sera adressée'),
        // Phrases de Convocation_1JA_1Adjoint.html qui changent selon les adjoints validés (a / b / c ci-dessus).
        '{ADJOINTS_INTRO}'       => match (true) {
            $complet => $valPl ? 'Les Juges-Arbitres adjoints désignés par la CRA sont indiqués ci-dessous.'
                               : 'Le Juge-Arbitre adjoint désigné par la CRA est indiqué ci-dessous.',
            $partiel => ($valPl ? 'Les ' . $lettres($nbVal) . ' Juges-Arbitres adjoints déjà désignés par la CRA sont indiqués'
                                : 'Le Juge-Arbitre adjoint déjà désigné par la CRA est indiqué')
                . ' ci-dessous ; vous devez encore solliciter ' . $aSollic($nbSol)
                . ' parmi les personnes disponibles listées à la suite, puis transmettre votre proposition à la CRA pour validation.',
            default  => 'Vous devez solliciter ' . $aSollic($nbSol) . ' parmi les personnes disponibles ci-dessous, puis transmettre votre proposition à la CRA pour validation.',
        },
        '{ADJOINTS_CONTACT}'     => match (true) {
            $complet => 'Vous voudrez bien ' . ($valPl ? 'les' : 'le') . ' contacter pour convenir de l’horaire de présence et de la répartition des missions.',
            $partiel => 'Vous voudrez bien contacter ' . ($valPl ? 'les Juges-Arbitres adjoints déjà désignés' : 'le Juge-Arbitre adjoint déjà désigné')
                . ' pour convenir de l’horaire de présence et de la répartition des missions ; pour '
                . ($nbSol > 1 ? 'les adjoints restants' : 'l’adjoint restant') . ', merci de prendre directement contact avec '
                . $retenus($nbSol) . ' avant d’en informer la CRA.',
            default  => 'Merci de prendre directement contact avec ' . $retenus($nbSol) . ' avant d’en informer la CRA.',
        },
        '{TITRE_LISTE_ADJOINTS}' => $complet ? $valides : ($partiel ? "$valides et Juges-Arbitres disponibles" : 'Juges-Arbitres disponibles'),
        '{VOTRE_ADJOINT}'        => $pluriel ? 'vos adjoints' : 'votre adjoint',
        '{LUI_LEUR}'             => $pluriel ? 'leur' : 'lui',
        '{NOM}'                  => $ja['Nom'] ?? '',
        '{PRENOM}'               => $ja['Prenom'] ?? '',
        '{NOM_COMPLET}'          => trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')),
        '{ID_JA}'                => $token,
        '{ID_CONVOCATION}'       => $idNomination !== null ? (string)$idNomination : '',
        '{SEXE}'                 => $sexe,
        '{UTI_NOM}'              => $moi['nom'] ?? '',
        '{UTI_PRENOM}'           => $moi['prenom'] ?? '',
        '{URL_LIGUE}'            => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
        '{URL_ADRESSE_JA}'       => $token !== '' ? (site_url('adresse-ja') . '?ja=' . $token) : '',
        '{URL_DISPONIBILITE_JA}' => $token !== '' ? (site_url('disponibilite-ja') . '?ja=' . $token) : '',
        '{URL_ATTESTATION_JA}'   => $token !== '' ? (site_url('attestation-defisc') . '?ja=' . $token) : '',
        // Forme "chemin" (sans ?, = ni &) : robuste aux emails texte brut /
        // quoted-printable où l'ancienne query string se faisait tronquer.
        '{URL_CONVOCATION_JA}'   => !empty($idNomination) ? site_url('convocation-ja/' . (int) $idNomination . '/' . $tokenNomination) : '',
        '{URL_ARBITRE_CLUB}'     => $tokenRenc !== '' ? (site_url('arbitre-club') . '?renc=' . $tokenRenc) : '',
        '{YEAR_PHASE}'           => getAnneePhase(),
        '{PHASE}'                => getConfig('phase', '1'),
        '{DATE}'                 => !empty($ctx['date']) ? date('d/m/Y', strtotime($ctx['date'])) : '',
        '{DATE_LONGUE}'          => $dateLongue,
        '{DATE_EDITION}'         => $dateEdition,
        '{SAISON}'               => getConfig('saison', ''),
        '{EPREUVE}'              => $ctx['epreuve']   ?? '',
        '{NB_TABLES}'            => isset($ctx['nb_tables']) ? (string) $ctx['nb_tables'] : '',
        '{HEURE}'                => substr((string)($ctx['heure'] ?? ''), 0, 5),
        '{JOURNEE}'              => $ctx['journee']  ?? '',
        '{POULE}'                => $ctx['poule']    ?? '',
        '{DIVISION}'             => $ctx['division'] ?? '',
        '{DOM}'                  => $ctx['dom']       ?? '',
        '{EXT}'                  => $ctx['ext']       ?? '',
        '{NOM_CLUB}'             => $ctx['nom_club']  ?? '',
        '{SALLE_NOM}'            => $ctx['salle_nom']     ?? '',
        '{SALLE_ADRESSE}'        => $ctx['salle_adresse'] ?? '',
        '{SALLE_CP}'             => $ctx['salle_cp']      ?? '',
        '{SALLE_VILLE}'          => $ctx['salle_ville']   ?? '',
        '{CORR_NOM}'             => $ctx['corr_nom']   ?? '',
        '{CORR_EMAIL}'           => $ctx['corr_email'] ?? '',
        '{CORR_TEL}'             => $ctx['corr_tel']   ?? '',
        '{LISTE_NOMINATIONS}'    => $ctx['liste_nominations'] ?? '',
        '{LISTE_JA_DISPONIBLES}' => $listeJaDispo,
    ];
}

/**
 * Substitue les marqueurs {XXX} dans un couple sujet/corps de message, à
 * partir de la table retournée par construireMarqueursMessage().
 *
 * @return array{sujet: string, corps: string}
 */
function remplacerMarqueursMessage(string $sujet, string $corps, array $marqueurs): array
{
    return [
        'sujet' => strtr($sujet, $marqueurs),
        'corps' => strtr($corps, $marqueurs),
    ];
}

/**
 * Retire d'un modèle de message (AVANT substitution des marqueurs) les liens
 * personnels d'un JA — {URL_CONVOCATION_JA}, alias {LIEN_CONVOCATION},
 * {URL_ADRESSE_JA}, {URL_DISPONIBILITE_JA}, {URL_ATTESTATION_JA} — ainsi que
 * la phrase qui les introduit. Utilisé pour la copie « sans lien » de la
 * convocation envoyée aux clubs (EN14).
 *
 * HTML : supprime le bloc <p>/<li>/<div> (sans bloc imbriqué) contenant le
 * marqueur et, s'il le précède immédiatement, le bloc d'introduction (« lien »,
 * « cliquez », « suivant », ou finissant par « : ») ; un <a> isolé contenant le
 * marqueur est retiré. Texte : supprime la ligne du marqueur et la ligne
 * d'introduction qui la précède (mêmes critères).
 */
function retirerLiensPersonnelsModele(string $modele): string
{
    $marqueurs = ['{URL_CONVOCATION_JA}', '{LIEN_CONVOCATION}', '{URL_ADRESSE_JA}', '{URL_DISPONIBILITE_JA}', '{URL_ATTESTATION_JA}'];
    $alt       = implode('|', array_map(fn ($m) => preg_quote($m, '/'), $marqueurs));
    // Phrase d'introduction d'un lien : « lien », « cliquez », « suivant : », « ci-dessous », ou finissant par « : ».
    $estIntro  = fn (string $t): bool => mb_strlen($t = trim($t)) <= 300
        && (bool) preg_match('/(?:\blien|cliqu|suivante?s?\s*:|ci-dessous|:\s*$)/iu', $t);

    // 1. HTML : bloc <p>/<li>/<div> (sans bloc imbriqué) portant un marqueur → sentinelle \x00,
    //    puis le bloc d'introduction juste avant (paragraphes vides intercalés compris) est retiré.
    $sansBloc = '(?:(?!<\/?(?:p|li|div)\b).)*?';
    $modele   = preg_replace("/<(p|li|div)\\b[^>]*>$sansBloc(?:$alt)$sansBloc<\\/\\1>/is", "\x00", $modele) ?? $modele;
    $vides    = '(?:\s*<p\b[^>]*>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>)*\s*';
    $modele   = preg_replace_callback(
        "/<(p|li|div)\\b[^>]*>($sansBloc)<\\/\\1>$vides\x00/is",
        fn ($m) => $estIntro(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ? '' : $m[0],
        $modele
    ) ?? $modele;
    $modele = str_replace("\x00", '', $modele);
    // Lien <a> isolé (hors bloc) portant un marqueur.
    $modele = preg_replace_callback('/<a\b[^>]*>.*?<\/a>/is', fn ($m) => preg_match("/$alt/", $m[0]) ? '' : $m[0], $modele) ?? $modele;

    // 2. Texte brut, ligne par ligne.
    $lignes = preg_split('/\R/', $modele);
    $sortie = [];
    foreach ($lignes as $ligne) {
        if (!preg_match("/$alt/", $ligne)) {
            $sortie[] = $ligne;
            continue;
        }
        if (strlen(strip_tags($ligne)) !== strlen($ligne) && strlen($ligne) > 300) {
            // ponytail: longue ligne HTML monobloc — on retire le marqueur seul plutôt que tout le corps.
            $sortie[] = preg_replace("/$alt/", '', $ligne);
            continue;
        }
        // Retire la ligne d'introduction précédente (en sautant les lignes vides).
        for ($i = count($sortie) - 1; $i >= 0 && trim(strip_tags($sortie[$i])) === ''; $i--);
        if ($i >= 0 && $estIntro(html_entity_decode(strip_tags($sortie[$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
            array_splice($sortie, $i);
        }
    }

    // Pas plus d'une ligne vide consécutive là où le lien a été retiré.
    return preg_replace('/(\R[ \t]*){3,}/', "\n\n", implode("\n", $sortie));
}

/**
 * Destinataires de la copie de convocation aux clubs (EN14) : correspondant et
 * référent (Club.CorEmail / Club.RefMail) de chaque club passé, adresses
 * valides seulement, dédoublonnées sans casse, l'adresse du JA exclue.
 *
 * @param array<array{CorNom?:?string,CorEmail?:?string,RefNom?:?string,RefMail?:?string}> $clubs
 * @return array<string,string> email => nom affiché
 */
function destinatairesCopieClubs(array $clubs, ?string $emailJa): array
{
    $exclus = [strtolower(trim((string) $emailJa)) => true];
    $dest   = [];
    foreach ($clubs as $c) {
        foreach ([['CorEmail', 'CorNom', 'Correspondant'], ['RefMail', 'RefNom', 'Référent']] as [$cE, $cN, $defaut]) {
            $email = trim((string) ($c[$cE] ?? ''));
            $cle   = strtolower($email);
            if ($email === '' || isset($exclus[$cle]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $exclus[$cle]  = true;
            $dest[$email] = trim((string) ($c[$cN] ?? '')) ?: $defaut;
        }
    }

    return $dest;
}

/**
 * Corps par défaut du message système n°3 « Convocation » (EN14) : e-mail HTML
 * (tableaux + styles en ligne) avec le bouton « Consulter et confirmer ma
 * convocation » vers {URL_CONVOCATION_JA} (EN21). Le bloc <div> du bouton
 * (phrase d'introduction + bouton + lien de secours) est retiré en entier par
 * retirerLiensPersonnelsModele() pour la copie aux clubs. Appliqué aux bases
 * existantes par initTableConfiguration() (EA98) seulement si le corps est
 * encore un ancien texte par défaut (corpsConvocationJaMigrable()).
 */
function modeleHtmlConvocationJa(): string
{
    return <<<'NIJAC_CONVOCATION_HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
<!-- Sujet proposé (champ Sujet, inchangé : la version perso du nominateur est retrouvée par Sujet) : Convocation JA du {DATE} à {HEURE} à {NOM_CLUB} -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Convocation — Juge-Arbitre</title>
</head>
<body style="margin:0;padding:0;background-color:#eef1f6;color:#1f2937;">
<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Vous êtes désigné(e) juge-arbitre : {DOM} – {EXT}, le {DATE_LONGUE} à {HEURE}.</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef1f6" style="background-color:#eef1f6;">
<tr>
<td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #d5dbe5;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">

<!-- En-tête -->
<tr>
<td bgcolor="#1a3a6b" style="background-color:#1a3a6b;padding:22px 28px;border-radius:8px 8px 0 0;">
<span style="display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#c9d6ea;">Ligue de Normandie de Tennis de Table</span>
<span style="display:block;font-size:22px;font-weight:bold;line-height:30px;color:#ffffff;">Convocation — Juge-Arbitre</span>
</td>
</tr>

<!-- Introduction -->
<tr>
<td style="padding:26px 28px 6px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Bonjour {PRENOM},<br><br>
Nom du JUGE ARBITRE : <strong>{PRENOM} {NOM}</strong><br><br>
J'ai l'avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {SEXE}.
</td>
</tr>

<!-- Récapitulatif de la rencontre -->
<tr>
<td style="padding:16px 28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f6fb" style="background-color:#f3f6fb;border-left:4px solid #1a3a6b;border-radius:4px;font-size:14px;line-height:21px;color:#1f2937;">
<tr><td colspan="2" style="padding:14px 16px 4px 16px;font-size:17px;font-weight:bold;color:#1a3a6b;">{DOM} <span style="color:#6b7280;font-weight:normal;">à</span> {EXT}</td></tr>
<tr><td width="34%" valign="top" style="padding:6px 16px;color:#4b5563;">Date</td><td valign="top" style="padding:6px 16px 6px 0;font-weight:bold;color:#1f2937;">{DATE_LONGUE} à {HEURE}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Compétition</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">Journée n° {JOURNEE} — Division : {DIVISION} — Poule : {POULE}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Club recevant</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{NOM_CLUB}</td></tr>
<tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Adresse</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{SALLE_NOM}<br>{SALLE_ADRESSE}<br>{SALLE_CP} {SALLE_VILLE}</td></tr>
<tr><td valign="top" style="padding:6px 16px 14px 16px;color:#4b5563;">Correspondant</td><td valign="top" style="padding:6px 16px 14px 0;color:#1f2937;">{CORR_NOM}<br>Tél : {CORR_TEL}<br>Courriel : <a href="mailto:{CORR_EMAIL}" style="color:#1a3a6b;">{CORR_EMAIL}</a></td></tr>
</table>
</td>
</tr>

<!-- Bouton : bloc <div> unique (sans <p>/<div>/<li> imbriqué) retiré en entier par retirerLiensPersonnelsModele() pour la copie aux clubs -->
<tr>
<td style="padding:0;">
<div style="padding:12px 28px 8px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Ci-joint le lien vers la convocation pour la saisie de vos frais d'arbitrages de cette rencontre.<br><br>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;">
<tr>
<td align="center" bgcolor="#1a3a6b" style="background-color:#1a3a6b;border-radius:6px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{URL_CONVOCATION_JA}" style="height:48px;v-text-anchor:middle;width:380px;" arcsize="12%" strokecolor="#1a3a6b" fillcolor="#1a3a6b"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Consulter et confirmer ma convocation</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a href="{URL_CONVOCATION_JA}" target="_blank" style="display:inline-block;padding:14px 28px;min-height:20px;line-height:20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;background-color:#1a3a6b;">Consulter et confirmer ma convocation</a><!--<![endif]-->
</td>
</tr>
</table>
<br>
<span style="font-size:12px;line-height:18px;color:#6b7280;">Si le bouton ne fonctionne pas, valider ce lien :<br><a href="{URL_CONVOCATION_JA}" style="color:#1a3a6b;word-break:break-all;">{URL_CONVOCATION_JA}</a></span>
</div>
</td>
</tr>

<!-- Consignes (reprises du message n°3 actuel) -->
<tr>
<td style="padding:16px 28px 6px 28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fff6e5" style="background-color:#fff6e5;border:1px solid #f0c36d;border-radius:4px;font-size:14px;line-height:21px;color:#5c3d00;">
<tr><td style="padding:12px 16px;">
<strong style="color:#8a5a00;">IMPORTANT :</strong><br>
• Merci de m’accuser réception du présent envoi.<br>
• Veuillez me retourner obligatoirement la Convocation complétée de vos kms, dans les 5 jours qui suivent la rencontre.
</td></tr>
</table>
</td>
</tr>

<!-- Signature -->
<tr>
<td style="padding:18px 28px 24px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Veuillez agréer mes meilleurs sentiments.<br><br>
<strong>{UTI_PRENOM} {UTI_NOM}</strong><br>
<span style="font-size:13px;color:#4b5563;">Ligue de Normandie de Tennis de Table</span>
</td>
</tr>

<!-- Pied de page -->
<tr>
<td bgcolor="#f3f4f6" style="background-color:#f3f4f6;padding:14px 28px;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;font-size:11px;line-height:17px;color:#6b7280;">
Message envoyé automatiquement par l'application de nomination des juges-arbitres de la Ligue. Pour toute question, répondez à ce message.<br>
<a href="{URL_LIGUE}" style="color:#1a3a6b;">{URL_LIGUE}</a>
</td>
</tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>

NIJAC_CONVOCATION_HTML;
}

/**
 * Anciens corps par défaut (texte brut) du message n°3, toutes variantes
 * historiques des dumps SQL : seul un de ceux-là est remplacé par le modèle HTML.
 *
 * @return string[]
 */
function ancienCorpsConvocationJa(): array
{
    $commun = <<<'NIJAC_CONVOCATION_TXT'
Convocation

Nom du JUGE ARBITRE : {PRENOM} {NOM}

J'ai l'avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {SEXE}.

Journée n° {JOURNEE}      Division : {DIVISION}    Poule : {POULE}
Opposant : {DOM} à {EXT}
le {DATE} à {HEURE}

Adresse : {SALLE_NOM} {SALLE_ADRESSE} {SALLE_CP} {SALLE_VILLE}

Nom, PRÉNOM du CORRESPONDANT, {CORR_NOM}

 Tél : {CORR_TEL}                               Courriel : {CORR_EMAIL}

Veuillez agréer mes meilleurs sentiments.
{UTI_PRENOM} {UTI_NOM}


NIJAC_CONVOCATION_TXT;

    return [
        $commun . <<<'NIJAC_CONVOCATION_TXT'
Ci-joint le lien vers la convocation pour la saisie de vos frais d'arbitrages de cette rencontre.
{URL_CONVOCATION_JA}

IMPORTANT :

Merci de m’accuser réception du présent envoi

Veuillez me retourner obligatoirement la Convocation complétée de vos kms.
Dans les 5 jours qui suivent la rencontre.
NIJAC_CONVOCATION_TXT,
        $commun . <<<'NIJAC_CONVOCATION_TXT'
Ci-joint le lien pour la saisie de vos frais pour les arbitrages du Championnat.
{URL_LIGUE}//nijac/Nominateur/convocation_ja.php?nomination={ID_CONVOCATION}
NIJAC_CONVOCATION_TXT,
        $commun . <<<'NIJAC_CONVOCATION_TXT'
Ci-joint le lien pour la saisie de vos frais pour les arbitrages du Championnat.
{URL_LIGUE}/nijac/Nominateur/convocation_ja.php?nomination={ID_CONVOCATION}
NIJAC_CONVOCATION_TXT,
    ];
}

/**
 * Versions HTML précédentes du modèle par défaut du message n°3 (déjà appliquées
 * à des bases par EA98) : le modèle actuel avec l'ancien libellé du lien de secours.
 *
 * @return string[]
 */
function ancienneVersionHtmlConvocationJa(): array
{
    return [str_replace(
        'Si le bouton ne fonctionne pas, valider ce lien :',
        'Si le bouton ne fonctionne pas, copiez ce lien :',
        modeleHtmlConvocationJa()
    )];
}

/**
 * Vrai si $corps (message n°3) est encore un ancien texte par défaut ou une version
 * HTML précédente non modifiée — comparaison tolérante (trim + fins de ligne \r\n → \n).
 * Faux s'il a été personnalisé (EA93) ou s'il est déjà à jour : il ne doit alors pas être écrasé.
 */
function corpsConvocationJaMigrable(string $corps): bool
{
    $norm = fn (string $s): string => trim(str_replace("\r\n", "\n", $s));

    return in_array($norm($corps), array_map($norm, [...ancienCorpsConvocationJa(), ...ancienneVersionHtmlConvocationJa()]), true);
}

/**
 * Résout le modèle de messagerie à utiliser pour un envoi automatique : d'abord
 * la version personnalisée du Nominateur courant (même Sujet que le modèle
 * système $idMessagerieSysteme, mais Id_Utilisateur = lui), sinon le modèle
 * système par défaut (Id_Utilisateur = 1). Les versions personnalisées sont
 * créées via le bouton "Dupliquer" de EN15 (MessagerieController::duplicate).
 */
function resoudreModeleMessagerie(\PDO $pdo, int $idMessagerieSysteme, int $idUtilisateurCourant): ?array
{
    $stmt = $pdo->prepare('SELECT Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Id_Messagerie = ?');
    $stmt->execute([$idMessagerieSysteme]);
    $systeme = $stmt->fetch();
    if (!$systeme) {
        return null;
    }

    if ($idUtilisateurCourant > 0) {
        $stmt = $pdo->prepare('SELECT Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Sujet = ? AND Id_Utilisateur = ?');
        $stmt->execute([$systeme['Sujet'], $idUtilisateurCourant]);
        $perso = $stmt->fetch();
        if ($perso) {
            return $perso;
        }
    }

    return $systeme;
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
 * Anti brute-force générique : verrou par clé arbitraire (IP, Id_Utilisateur...)
 * dans un fichier JSON — pas $_SESSION comme checkRateLimit() : un tiers qui
 * tente de deviner un mot de passe peut simplement ignorer le cookie de
 * session à chaque essai et repartir avec un compteur à zéro.
 *
 * Retourne null si la tentative est autorisée, ou un message d'erreur sinon.
 * Appeler enregistrerTentative() après chaque tentative à comptabiliser
 * (échec de mot de passe, ou envoi d'email pour un cooldown type mail-bombing).
 */
function checkTentativesRateLimit(string $cle, int $max, int $fenetreMinutes): ?string
{
    $now   = time();
    $debut = $now - $fenetreMinutes * 60;

    $tentatives = array_filter(tentativesLire()[$cle] ?? [], fn (int $ts) => $ts > $debut);
    if (count($tentatives) >= $max) {
        $attente = (int) ceil((min($tentatives) + $fenetreMinutes * 60 - $now) / 60);
        return "Trop de tentatives. Réessayez dans environ {$attente} minute(s).";
    }
    return null;
}

/** Enregistre une tentative pour la clé donnée. */
function enregistrerTentative(string $cle, int $fenetreMinutes): void
{
    $now   = time();
    $debut = $now - $fenetreMinutes * 60;

    $fp = fopen(__DIR__ . '/../logs/login_attempts.json', 'c+');
    if (!$fp) return;

    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp) ?: '', true) ?: [];

    // Purge des horodatages expirés (toutes clés) pour éviter que le fichier ne grossisse indéfiniment.
    foreach ($data as $k => $timestamps) {
        $data[$k] = array_values(array_filter($timestamps, fn (int $ts) => $ts > $debut));
        if (!$data[$k]) unset($data[$k]);
    }
    $data[$cle][] = $now;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Lecture brute du fichier de tentatives (séparée pour rester appelable sans écrire). */
function tentativesLire(): array
{
    $fichier = __DIR__ . '/../logs/login_attempts.json';
    if (!is_file($fichier)) return [];
    return json_decode(file_get_contents($fichier) ?: '', true) ?: [];
}

/**
 * Anti brute-force sur la connexion (E001). Verrou par IP.
 *
 * ponytail: verrou par IP seule (pas IP+login) — un attaquant derrière un
 * NAT/proxy partagé peut bloquer d'autres utilisateurs légitimes du même
 * point de sortie ; passer à IP+login si ça devient un souci réel.
 */
function checkLoginRateLimit(): ?string
{
    $max     = (int) getConfig('login_rate_limit_max',     '5');
    $fenetre = (int) getConfig('login_rate_limit_fenetre', '15'); // minutes

    return checkTentativesRateLimit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $max, $fenetre);
}

/** Enregistre un échec de connexion pour l'IP courante. */
function enregistrerEchecLogin(): void
{
    $fenetre = (int) getConfig('login_rate_limit_fenetre', '15');

    enregistrerTentative('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $fenetre);
}

/**
 * Message d'erreur à afficher à l'écran pour une exception : le détail réel (SQL, SMTP, PHP…) en mode
 * Développement (etat_logiciel) ou pour un administrateur connecté — pour pouvoir corriger — sinon le message
 * générique, afin qu'un visiteur (page publique JA / club) ne voie jamais de détail technique. Le détail est de
 * toute façon écrit dans le journal d'erreurs par l'appelant.
 */
function messageErreur(\Throwable $e, string $generique): string
{
    try {
        $detail = isModeDeveloppement() || !empty($_SESSION['utilisateur']['is_admin']);
    } catch (\Throwable) {
        $detail = false;   // configuration illisible (base injoignable, par ex.) : par prudence, message générique
    }

    return $detail ? $generique . ' — ' . $e->getMessage() : $generique;
}

/**
 * Neutralise l'injection de formule dans un export CSV/Excel (recommandation
 * OWASP) : un champ texte dont la valeur commence par =, +, -, @, tab ou CR
 * est interprété comme une formule par Excel/LibreOffice à l'ouverture du
 * fichier — un club ou un JA dont le nom serait ainsi préfixé (import FFTT,
 * saisie admin) exécuterait ou exfiltrerait des données chez qui l'ouvre.
 * À utiliser sur les colonnes texte uniquement (pas sur des nombres calculés,
 * où un "-" est un simple signe négatif légitime).
 */
function csvSafe($valeur): string
{
    $valeur = (string) $valeur;

    return preg_match('/^[=+@\t\r-]/', $valeur) ? "'" . $valeur : $valeur;
}

/**
 * Revérifie le mot de passe d'un utilisateur déjà authentifié (garde-fou
 * "sudo" des écrans admin destructeurs : CleanController, DbAdminController).
 */
function verifierMotDePasseUtilisateur(int $idUtilisateur, string $password): bool
{
    if ($password === '' || $idUtilisateur <= 0) {
        return false;
    }
    require_once __DIR__ . '/../Classes/SecurePasswordHasher.php';

    $stmt = getPDO()->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
    $stmt->execute([$idUtilisateur]);
    $row = $stmt->fetch();

    return $row && \SecurePasswordHasher::verify($password, $row['Password']);
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
 * Retourne les départements limitrophes de la région, calculés (plus de
 * paramètre 'departements_limitrophes' en configuration) à partir de la
 * colonne departement.Limitrophe des départements actifs (getDeptActifs) :
 * union des codes limitrophes de chaque département actif, en excluant ceux
 * qui sont eux-mêmes actifs. Chaque entrée : ['CodeDept' => '80', 'nom' =>
 * 'Somme', 'region' => 'Hauts-de-France'].
 */
function getDepartementsLimitrophes(): array
{
    $actifs = getDeptActifs();
    if (!$actifs) return [];
    $codesActifs = array_column($actifs, 'CodeDept');

    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare("SELECT Limitrophe FROM departement WHERE CodeDept IN ($ph)");
        $stmt->execute($codesActifs);

        $codesVoisins = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $val) {
            if ($val) {
                $codesVoisins = array_merge($codesVoisins, array_filter(array_map('trim', explode(';', $val))));
            }
        }
        $codesVoisins = array_values(array_diff(array_unique($codesVoisins), $codesActifs));
        if (!$codesVoisins) return [];

        $ph2 = implode(',', array_fill(0, count($codesVoisins), '?'));
        $stmt2 = $pdo->prepare(
            "SELECT d.CodeDept, d.nom, r.nom AS region
             FROM departement d
             LEFT JOIN region r ON r.code = d.code_region
             WHERE d.CodeDept IN ($ph2)
             ORDER BY CAST(d.CodeDept AS UNSIGNED), d.CodeDept"
        );
        $stmt2->execute($codesVoisins);

        return $stmt2->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Départements de la même région que $dept qui lui sont limitrophes :
 * colonne departement.Limitrophe ∩ départements de même code_region.
 * Chaque entrée : ['CodeDept' => '27', 'nom' => 'Eure']. Utilisé par EN13 pour
 * proposer d'étendre l'affichage aux départements voisins.
 */
function getLimitrophesRegion(string $dept): array
{
    $dept = trim($dept);
    if ($dept === '') return [];
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT code_region, Limitrophe FROM departement WHERE CodeDept = ?');
        $stmt->execute([$dept]);
        $r = $stmt->fetch();
        if (!$r || $r['code_region'] === null || $r['code_region'] === '' || empty($r['Limitrophe'])) {
            return [];
        }
        $voisins = array_values(array_filter(array_map('trim', explode(';', $r['Limitrophe']))));
        if (!$voisins) return [];

        $ph   = implode(',', array_fill(0, count($voisins), '?'));
        $stmt = $pdo->prepare(
            "SELECT CodeDept, nom FROM departement
             WHERE CodeDept IN ($ph) AND code_region = ?
             ORDER BY CAST(CodeDept AS UNSIGNED), CodeDept"
        );
        $stmt->execute([...$voisins, $r['code_region']]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Retourne les départements actifs (depuis departements_actifs en configuration)
 * avec leur nom (depuis la table departement), triés par code numérique.
 * Chaque entrée : ['CodeDept' => '14', 'nom' => 'Calvados']
 */
function getDeptActifs(): array
{
    $codesActifs = array_filter(array_map('trim', explode(',', getConfig('departements_actifs', ''))));
    if (!$codesActifs) return [];
    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare(
            "SELECT CodeDept, nom FROM departement
             WHERE CAST(CodeDept AS UNSIGNED) IN ($ph)
             ORDER BY CAST(CodeDept AS UNSIGNED)"
        );
        $stmt->execute(array_map('intval', $codesActifs));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Table de correspondance code division => libellé (colonne division.Nom), triée
 * par division.Ord. Alimente les listes déroulantes « Division » (EA82, EA83,
 * EA92, EN29, EN23), affichées « code — Nom ». Cache statique par requête.
 *
 * @return array<string, string>
 */
function getDivisionNoms(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = getPDO()->query('SELECT Division, Nom FROM division ORDER BY Ord')->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
            $cache = [];
        }
    }

    return $cache;
}

/**
 * Retourne un client FFTT bas niveau (App\Libraries\FfttRawClient) pour les
 * endpoints non couverts par la façade FFTTApi de alamirault/fftt-api
 * (xml_division, xml_result_equ avec cx_poule, xml_licence,
 * xml_liste_joueur_o avec le champ echelon…). Remplace getFfttApi()/
 * Classes/FfttApi.php (supprimés) — plus de serial applicatif séparé, le
 * même schéma d'authentification (id/appKey) que la façade est réutilisé
 * partout. Lance une exception si les credentials ne sont pas renseignés.
 *
 * N'est utilisable que depuis un contrôleur CI4 (App\Libraries\FfttRawClient
 * n'est autoloadable qu'une fois l'autoloader Composer de ci4/ chargé).
 */
function getFfttRawClient(): \App\Libraries\FfttRawClient
{
    $appId  = getFfttAppId();
    $appKey = getFfttAppKey();
    if ($appId === '' || $appKey === '') {
        throw new \RuntimeException('API FFTT non configurée. Renseignez FFTT_APP_ID et FFTT_APP_KEY dans .env.');
    }

    return new \App\Libraries\FfttRawClient($appId, $appKey);
}

/**
 * Indique si le logiciel est en mode développement.
 */
function isModeDeveloppement(): bool
{
    return getConfig('etat_logiciel', 'Developpement') === 'Developpement';
}

/**
 * Retourne une instance PHPMailer (sous-classe NijacMailer, voir Classes/NijacMailer.php)
 * préconfigurée avec les paramètres SMTP.
 * Host/port/sécurité/expéditeur viennent de la table `configuration` ; l'utilisateur
 * et le mot de passe viennent de .env (SMTP_USER / SMTP_PASSWORD, encodés ROT47).
 * Lance une exception en cas d'erreur de configuration.
 */
function getNijacMailer(string $forcedPrefix = null): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../Classes/NijacMailer.php';

    // Garantie centrale du mode Développement : NijacMailer remplace tout destinataire
    // (To/Cc/Bcc/Reply-To) par l'adresse dev, et bloque l'envoi si elle est vide.
    // En Production (null), comportement PHPMailer strictement identique.
    $mail = new \NijacMailer(true, isModeDeveloppement() ? getEmailDestinataire('') : null);
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
