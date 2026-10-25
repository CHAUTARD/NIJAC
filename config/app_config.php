<?php
/**
 * Helpers de configuration applicative NIJAC.
 *
 * Nécessite que getPDO() soit disponible (config/db.php déjà chargé).
 * Cache statique : la BDD n'est interrogée qu'une seule fois par requête.
 */

/**
 * Migrations de schéma appliquées à l'ouverture de l'écran EA98 (seul appelant).
 * Chaque bloc est idempotent : on ne (re)crée que ce qui manque.
 */
function initTableConfiguration(\PDO $pdo): void
{
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

    // rencontre.Forfait : 0 = Non, 1 = forfait équipe domicile, 2 = forfait équipe extérieure — EN23.
    // Placée après Frais si présente, sinon en fin de table. Lue / écrite seulement si présente
    // (rencontreAForfait(), tolérance avant passage EA98).
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'Forfait'"
        )->fetchColumn();
        if (!$existe) {
            $apresFrais = $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'Frais'"
            )->fetchColumn();
            $pdo->exec('ALTER TABLE rencontre ADD COLUMN Forfait TINYINT(1) NOT NULL DEFAULT 0' . ($apresFrais ? ' AFTER Frais' : ''));
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // rencontre.DateDemandeClub : date/heure du dernier envoi (ou relance) de la demande
    // d'arbitrage au club — envoyerDemandeJaClub() (EN14 / EN28). NULL = jamais envoyée.
    // Lue / écrite seulement si présente (rencontreADateDemandeClub(), tolérance avant EA98).
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'DateDemandeClub'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec('ALTER TABLE rencontre ADD COLUMN DateDemandeClub DATETIME NULL DEFAULT NULL');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // Degrés JA2/JA3/JAN/JAI après ja.JA1 (codes de la table de référence JugeArbitre, sans FK).
    try {
        $colsJa = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ja'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $apres = 'JA1';
        foreach (['JA2', 'JA3', 'JAN', 'JAI'] as $colJa) {
            if (!in_array($colJa, $colsJa, true)) {
                $pdo->exec("ALTER TABLE ja ADD COLUMN $colJa TINYINT(1) NOT NULL DEFAULT 0 AFTER $apres");
            }
            $apres = $colJa;
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

    // nomination.F131 : 1 = la dernière modification de cette nomination vient du fichier
    // FFTT 131 (EN28 « Mise à jour FFTT 131 ») ; remis à 0 par une modification manuelle (EN14/EN28).
    // Lue / écrite seulement si présente (nominationAF131(), tolérance avant passage EA98).
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'F131'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec('ALTER TABLE nomination ADD COLUMN F131 TINYINT(1) NOT NULL DEFAULT 0 AFTER EmailEnvoye');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // nomination.FichierPeage : nom du fichier justificatif de péage, NULL = aucun.
    // Format : YYYYMMJJHHMMSS + millisecondes (3 chiffres) + '.' + extension (4 car. max),
    // soit 22 car. max (ex. 20261020143005123.jpeg), VARCHAR(25) pour la marge.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'FichierPeage'"
        )->fetchColumn();
        if (!$existe) {
            $aPeage = $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'Peage'"
            )->fetchColumn();
            $pdo->exec('ALTER TABLE nomination ADD COLUMN FichierPeage VARCHAR(25) NULL DEFAULT NULL'
                . ($aPeage ? ' AFTER Peage' : ''));
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // nomination.Valide : colonne supprimée (plus de notion de validité, une nomination existante
    // est toujours valide). Le code ne la lit ni ne l'écrit plus : il fonctionne avant et après ce
    // bloc. Clés étrangères / index qui la référencent supprimés d'abord, puis la colonne.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'Valide'"
        )->fetchColumn();
        if ($existe) {
            $fks = $pdo->query(
                "SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'Valide'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($fks as $fk) {
                $pdo->exec('ALTER TABLE nomination DROP FOREIGN KEY `' . str_replace('`', '``', $fk) . '`');
            }
            $index = $pdo->query(
                "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'Valide'
                   AND INDEX_NAME <> 'PRIMARY'"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($index as $ix) {
                $pdo->exec('ALTER TABLE nomination DROP INDEX `' . str_replace('`', '``', $ix) . '`');
            }
            $pdo->exec('ALTER TABLE nomination DROP COLUMN Valide');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…) : ALTER TABLE nomination DROP COLUMN Valide;
        error_log('[NIJAC] Suppression de la colonne nomination.Valide : ' . $e->getMessage());
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

    // EC73 : Types ENUM des 3 convocations CRA (messages de la table messagerie, créés/édités en EA93, plus de source fichier).
    try {
        assurerTypesConvocationCra($pdo);
    } catch (\PDOException $e) {
        error_log('[NIJAC] Types messagerie des convocations CRA : ' . $e->getMessage());
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

    // utilisateur.PwdVersion : version du mot de passe, incrémentée à chaque changement (E006, E008,
    // EA86 « Écraser ») et copiée en session à la connexion — une session dont la version diffère est
    // détruite par revaliderSessionUtilisateur(). Lue / écrite seulement si présente
    // (utilisateurAPwdVersion(), tolérance avant passage EA98 : pas de contrôle de version).
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'PwdVersion'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec('ALTER TABLE utilisateur ADD COLUMN PwdVersion INT UNSIGNED NOT NULL DEFAULT 0 AFTER ChangeLogin');
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
        error_log('[NIJAC] Ajout de la colonne utilisateur.PwdVersion : ' . $e->getMessage());
    }

    // 2FA TOTP des écrans CHAUTARD (EA96/97/98, activation EA99) : secret base32 chiffré
    // (AES-256-GCM, chiffrerSecretTotp()), dernier pas accepté (anti-rejeu), JSON des codes de
    // secours hachés. NULL = 2FA non activée. Lues seulement si présentes (utilisateurATotp()).
    // Réinitialisation d'urgence : voir la procédure en tête des fonctions TOTP (fin de fichier).
    foreach ([
        'TotpSecret'     => 'VARCHAR(255) NULL',
        'TotpDernierPas' => 'BIGINT NULL',
        'TotpSecours'    => 'TEXT NULL',
    ] as $colonne => $definition) {
        try {
            $existe = $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = " . $pdo->quote($colonne)
            )->fetchColumn();
            if (!$existe) {
                $pdo->exec('ALTER TABLE utilisateur ADD COLUMN ' . $colonne . ' ' . $definition);
            }
        } catch (\PDOException $e) {
            error_log('[NIJAC] Ajout de la colonne utilisateur.' . $colonne . ' : ' . $e->getMessage());
        }
    }

    // Salles ↔ clubs en plusieurs-à-plusieurs : table club_salle (salle.Id_Club / EstPrincipale
    // obsolètes, conservées). Gère son propre try/catch (voir assurerTableClubSalle()).
    assurerTableClubSalle($pdo);
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
 * Expression SQL : salle effective de la rencontre $r (équipe domicile $e) — salle de la
 * rencontre (r.id_Salle) si elle existe, sinon salle principale du club recevant (même règle
 * que le lieu affiché/convoqué d'EN14). NULL si aucune.
 */
function sqlSalleEffective(string $r, string $e): string
{
    return "COALESCE(
                (SELECT sx.Id_Salle FROM salle sx WHERE sx.Id_Salle = $r.id_Salle),
                " . sqlIdSallePrincipale("$e.Id_Club") . ")";
}

/**
 * Table de liaison club_salle (plusieurs-à-plusieurs club ↔ salle, créée par EA98) présente ?
 * Source de vérité des salles d'un club et de SA salle principale. Avant EA98, tous les helpers
 * ci-dessous retombent sur l'ancien modèle salle.Id_Club / salle.EstPrincipale (colonnes
 * obsolètes conservées en base, plus lues ni écrites une fois club_salle créée).
 * Cache statique ($rafraichir = true après création de la table par la migration).
 */
function clubSalleExiste(?\PDO $pdo = null, bool $rafraichir = false): bool
{
    static $existe = null;
    if ($existe === null || $rafraichir) {
        try {
            $existe = (bool) ($pdo ?? getPDO())->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'club_salle'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * Sous-requête SQL scalaire : Id_Salle de la salle principale du club $colClub (NULL si aucune) ;
 * $repliPremiere : à défaut de principale, sa première salle (plus petit Id_Salle).
 */
function sqlIdSallePrincipale(string $colClub, bool $repliPremiere = false): string
{
    if ($repliPremiere) {
        return clubSalleExiste()
            ? "(SELECT csx.Id_Salle FROM club_salle csx WHERE csx.Id_Club = $colClub ORDER BY csx.EstPrincipale DESC, csx.Id_Salle LIMIT 1)"
            : "(SELECT sx.Id_Salle FROM salle sx WHERE sx.Id_Club = $colClub ORDER BY sx.EstPrincipale DESC, sx.Id_Salle LIMIT 1)";
    }

    return clubSalleExiste()
        ? "(SELECT MIN(csx.Id_Salle) FROM club_salle csx WHERE csx.Id_Club = $colClub AND csx.EstPrincipale = 1)"
        : "(SELECT MIN(sx.Id_Salle) FROM salle sx WHERE sx.Id_Club = $colClub AND sx.EstPrincipale = 1)";
}

/**
 * Jointure SQL « salle principale du club $colClub » sous l'alias $s ($jointure = 'LEFT JOIN' ou
 * 'JOIN'). Au plus une principale par club (garde-fou definirSallePrincipale() + migration EA98) :
 * la jointure ne duplique pas de ligne. Alias technique de la liaison : cs_<$s>.
 */
function sqlJoinSallePrincipale(string $colClub, string $s, string $jointure = 'LEFT JOIN'): string
{
    if (!clubSalleExiste()) {
        return "$jointure salle $s ON $s.Id_Club = $colClub AND $s.EstPrincipale = 1";
    }
    $cs = 'cs_' . $s;

    return "$jointure club_salle $cs ON $cs.Id_Club = $colClub AND $cs.EstPrincipale = 1
            $jointure salle $s ON $s.Id_Salle = $cs.Id_Salle";
}

/**
 * Jointure SQL « toutes les salles du club $colClub » sous l'alias $s (une ligne par salle) ;
 * le drapeau principale de chaque ligne est sqlColPrincipale($s).
 */
function sqlJoinSallesClub(string $colClub, string $s, string $jointure = 'LEFT JOIN'): string
{
    if (!clubSalleExiste()) {
        return "$jointure salle $s ON $s.Id_Club = $colClub";
    }
    $cs = 'cs_' . $s;

    return "$jointure club_salle $cs ON $cs.Id_Club = $colClub
            $jointure salle $s ON $s.Id_Salle = $cs.Id_Salle";
}

/** Colonne SQL « salle principale du club » d'une jointure sqlJoinSallesClub()/sqlJoinSallePrincipale() d'alias $s. */
function sqlColPrincipale(string $s): string
{
    return clubSalleExiste() ? "cs_$s.EstPrincipale" : "$s.EstPrincipale";
}

/** Sous-requête SQL scalaire : nombre de salles liées au club $colClub. */
function sqlNbSallesClub(string $colClub): string
{
    return clubSalleExiste()
        ? "(SELECT COUNT(*) FROM club_salle csn WHERE csn.Id_Club = $colClub)"
        : "(SELECT COUNT(*) FROM salle sn WHERE sn.Id_Club = $colClub)";
}

/** Sous-requête SQL (liste) : Id_Salle des salles liées au club $colClub — pour « IN (…) ». */
function sqlIdsSallesClub(string $colClub): string
{
    return clubSalleExiste()
        ? "SELECT csl.Id_Salle FROM club_salle csl WHERE csl.Id_Club = $colClub"
        : "SELECT sl.Id_Salle FROM salle sl WHERE sl.Id_Club = $colClub";
}

/** Salles liées au club $idClub, principale d'abord : [['Id_Salle', 'Nom', 'EstPrincipale'], …]. */
function sallesDuClub(\PDO $pdo, string $idClub): array
{
    $st = $pdo->prepare(clubSalleExiste($pdo)
        ? 'SELECT s.Id_Salle, s.Nom, cs.EstPrincipale FROM club_salle cs JOIN salle s ON s.Id_Salle = cs.Id_Salle
           WHERE cs.Id_Club = ? ORDER BY cs.EstPrincipale DESC, s.Id_Salle'
        : 'SELECT s.Id_Salle, s.Nom, s.EstPrincipale FROM salle s WHERE s.Id_Club = ? ORDER BY s.EstPrincipale DESC, s.Id_Salle');
    $st->execute([$idClub]);

    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

/** Clubs liés à chaque salle : [Id_Salle => [['Id_Club', 'Nom', 'EstPrincipale'], …]] (principale d'abord, puis nom). */
function liensClubsSalles(\PDO $pdo): array
{
    $rows = $pdo->query(clubSalleExiste($pdo)
        ? 'SELECT cs.Id_Salle, cs.Id_Club, c.Nom, cs.EstPrincipale FROM club_salle cs JOIN club c ON c.Id_Club = cs.Id_Club
           ORDER BY cs.Id_Salle, cs.EstPrincipale DESC, c.Nom'
        : 'SELECT s.Id_Salle, s.Id_Club, c.Nom, s.EstPrincipale FROM salle s JOIN club c ON c.Id_Club = s.Id_Club
           ORDER BY s.Id_Salle')->fetchAll(\PDO::FETCH_ASSOC);
    $liens = [];
    foreach ($rows as $r) {
        $liens[(int) $r['Id_Salle']][] = ['Id_Club' => $r['Id_Club'], 'Nom' => $r['Nom'], 'EstPrincipale' => (int) $r['EstPrincipale']];
    }

    return $liens;
}

/**
 * Lie le club $idClub à la salle $idSalle (sans effet si déjà lié). $principale : true = devient
 * LA salle principale du club (l'ancienne cesse de l'être), false = n'est pas/plus principale,
 * null = drapeau inchangé (nouvelle liaison : non principale).
 * Ancien modèle (avant EA98) : une salle n'a qu'un club, salle.Id_Club est remplacé.
 */
function lierClubSalle(\PDO $pdo, string $idClub, int $idSalle, ?bool $principale = null): void
{
    if (!clubSalleExiste($pdo)) {
        // EstPrincipale évalué AVANT Id_Club (affectations MySQL de gauche à droite) : un
        // changement de club ne garde pas le drapeau de l'ancien.
        $pdo->prepare('UPDATE salle SET EstPrincipale = CASE WHEN Id_Club <=> ? THEN EstPrincipale ELSE 0 END, Id_Club = ? WHERE Id_Salle = ?')
            ->execute([$idClub, $idClub, $idSalle]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO club_salle (Id_Club, Id_Salle, EstPrincipale) VALUES (?, ?, 0)')
            ->execute([$idClub, $idSalle]);
    }
    if ($principale === true) {
        definirSallePrincipale($pdo, $idClub, $idSalle);
    } elseif ($principale === false) {
        $pdo->prepare(clubSalleExiste($pdo)
            ? 'UPDATE club_salle SET EstPrincipale = 0 WHERE Id_Club = ? AND Id_Salle = ?'
            : 'UPDATE salle SET EstPrincipale = 0 WHERE Id_Club = ? AND Id_Salle = ?')->execute([$idClub, $idSalle]);
    }
}

/** Retire le lien club ↔ salle (la salle n'est jamais supprimée). */
function delierClubSalle(\PDO $pdo, string $idClub, int $idSalle): void
{
    $pdo->prepare(clubSalleExiste($pdo)
        ? 'DELETE FROM club_salle WHERE Id_Club = ? AND Id_Salle = ?'
        : 'UPDATE salle SET Id_Club = NULL, EstPrincipale = 0 WHERE Id_Club = ? AND Id_Salle = ?')->execute([$idClub, $idSalle]);
}

/**
 * Garde-fou « une seule salle principale par club » : $idSalle devient la principale du club
 * (salle déjà liée), toutes ses autres salles cessent de l'être — en UNE requête, jamais deux
 * principales même transitoirement. $idSalle null = le club n'a plus de salle principale.
 */
function definirSallePrincipale(\PDO $pdo, string $idClub, ?int $idSalle): void
{
    $pdo->prepare(clubSalleExiste($pdo)
        ? 'UPDATE club_salle SET EstPrincipale = CASE WHEN Id_Salle = ? THEN 1 ELSE 0 END WHERE Id_Club = ?'
        : 'UPDATE salle SET EstPrincipale = CASE WHEN Id_Salle = ? THEN 1 ELSE 0 END WHERE Id_Club = ?')->execute([$idSalle, $idClub]);
}

/**
 * Crée une fiche salle, ou réutilise une fiche existante au MÊME LIEU (classesLieuxSalles() :
 * même commune + même nom ou même adresse normalisés) plutôt que de la dupliquer.
 * $champs : Nom (obligatoire), Adresse, Cp, Ville, Id_Laposte, Telephone. $idClub : fiche déjà
 * liée à ce club préférée en cas de plusieurs candidates. Ne crée/modifie aucune liaison club.
 * Renvoie ['id' => Id_Salle, 'cree' => bool].
 */
function trouverOuCreerSalle(\PDO $pdo, array $champs, ?string $idClub = null): array
{
    $cols = array_values(array_intersect(['Nom', 'Adresse', 'Cp', 'Ville', 'Id_Laposte', 'Telephone'], array_keys($champs)));
    $pdo->prepare('INSERT INTO salle (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_map(fn ($c) => $champs[$c], $cols));
    $id = (int) $pdo->lastInsertId();

    // Même logique « même lieu » que la règle du jour EN14 : la fiche tout juste insérée est
    // comparée aux autres, puis retirée si une fiche existante désigne le même lieu.
    classesLieuxSalles($pdo, true);
    $autres = array_values(array_diff(sallesMemeLieu($pdo, $id), [$id]));
    if ($autres && !clubSalleExiste($pdo)) {
        // Ancien modèle (avant EA98) : une fiche n'a qu'un club — jamais celle d'un autre club.
        $st = $pdo->prepare('SELECT Id_Salle FROM salle WHERE Id_Salle IN (' . implode(',', array_map('intval', $autres)) . ')
                             AND (Id_Club IS NULL OR Id_Club = ?)');
        $st->execute([(string) $idClub]);
        $autres = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
    if (!$autres) {
        return ['id' => $id, 'cree' => true];
    }
    sort($autres);
    $choix = $autres[0];
    if ($idClub !== null) {
        $duClub = array_map('intval', array_column(sallesDuClub($pdo, $idClub), 'Id_Salle'));
        $communs = array_values(array_intersect($autres, $duClub));
        $choix = $communs[0] ?? $choix;
    }
    $pdo->prepare('DELETE FROM salle WHERE Id_Salle = ?')->execute([$id]);
    classesLieuxSalles($pdo, true);

    return ['id' => $choix, 'cree' => false];
}

/**
 * Colonnes qui référencent salle.Id_Salle (hors salle et club_salle) : FK déclarées
 * (information_schema.KEY_COLUMN_USAGE) + toute colonne de table nommée « Id_Salle » (casse
 * indifférente), + rencontre.id_Salle en dur. [[table, colonne], …].
 */
function colonnesReferencantSalle(\PDO $pdo): array
{
    $refs = ['rencontre|id_salle' => ['rencontre', 'id_Salle']];
    try {
        $rows = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(REFERENCED_TABLE_NAME) = 'salle'
             UNION
             SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE() AND LOWER(c.COLUMN_NAME) = 'id_salle' AND t.TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as [$table, $col]) {
            if (!in_array(strtolower($table), ['salle', 'club_salle'], true)) {
                $refs[strtolower("$table|$col")] = [$table, $col];
            }
        }
    } catch (\PDOException $e) {
        error_log('[NIJAC] Références à salle (information_schema) : ' . $e->getMessage());
    }

    return array_values($refs);
}

/** Nombre de lignes (toutes tables de colonnesReferencantSalle()) qui référencent la salle $idSalle. */
function nbReferencesSalle(\PDO $pdo, int $idSalle, ?array $refs = null): int
{
    $n = 0;
    foreach ($refs ?? colonnesReferencantSalle($pdo) as [$table, $col]) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?");
        $st->execute([$idSalle]);
        $n += (int) $st->fetchColumn();
    }

    return $n;
}

/**
 * Table de liaison club_salle « une salle ↔ plusieurs clubs, un club ↔ plusieurs salles » (appelée
 * par initTableConfiguration(), EA98, idempotente) : créée seulement si absente (types/collation
 * copiés de club.Id_Club et salle.Id_Salle, FK CASCADE si possible, sinon sans FK avec index —
 * error_log). Colonnes salle.Id_Club / salle.EstPrincipale obsolètes (plus lues ni écrites).
 */
function assurerTableClubSalle(\PDO $pdo): void
{
    try {
        if (clubSalleExiste($pdo, true)) {
            return;
        }
        $typeClub = $pdo->query(
            "SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'club' AND COLUMN_NAME = 'Id_Club'"
        )->fetch(\PDO::FETCH_ASSOC);
        $typeSalle = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'salle' AND COLUMN_NAME = 'Id_Salle'"
        )->fetchColumn();
        if (!$typeClub || !$typeSalle) {
            throw new \PDOException('club.Id_Club ou salle.Id_Salle introuvable');
        }
        $defClub = $typeClub['COLUMN_TYPE']
            . ($typeClub['COLLATION_NAME'] ? " CHARACTER SET {$typeClub['CHARACTER_SET_NAME']} COLLATE {$typeClub['COLLATION_NAME']}" : '');
        $ddl = "CREATE TABLE club_salle (
                    Id_Club       $defClub NOT NULL,
                    Id_Salle      $typeSalle NOT NULL,
                    EstPrincipale TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Salle principale du club (au plus une par club)',
                    PRIMARY KEY (Id_Club, Id_Salle),
                    KEY idx_club_salle_salle (Id_Salle),
                    KEY idx_club_salle_principale (Id_Club, EstPrincipale)%s
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='Salles des clubs (plusieurs-à-plusieurs) — remplace salle.Id_Club/EstPrincipale'";
        try {
            $pdo->exec(sprintf($ddl, ',
                    CONSTRAINT fk_club_salle_club  FOREIGN KEY (Id_Club)  REFERENCES club (Id_Club)   ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_club_salle_salle FOREIGN KEY (Id_Salle) REFERENCES salle (Id_Salle) ON DELETE CASCADE ON UPDATE CASCADE'));
        } catch (\PDOException $e) {
            error_log('[NIJAC] club_salle créée SANS clés étrangères (index seuls) : ' . $e->getMessage());
            $pdo->exec(sprintf($ddl, ''));
        }
        clubSalleExiste($pdo, true);
    } catch (\PDOException $e) {
        error_log('[NIJAC] Création de club_salle : ' . $e->getMessage());
    }
}


/**
 * Texte de lieu normalisé (comparaison de salles) : sans accents, minuscules, ponctuation →
 * espace, abréviations développées ($abrev), mots vides retirés ($vides), puis sans espaces.
 */
function normTexteLieu(?string $s, array $vides = [], array $abrev = []): string
{
    $s = (string) $s;
    if (class_exists('Normalizer')) {
        $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $s);
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
                    'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    $mots = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $garde = [];
    foreach ($mots as $m) {
        $m = $abrev[$m] ?? $m;
        if (!in_array($m, $vides, true)) {
            $garde[] = $m;
        }
    }

    return implode('', $garde);
}

/**
 * Regroupe les fiches `salle` désignant le même lieu physique (règle « même salle » du jour,
 * EN14/EN25/EN28). Deux fiches sont le même lieu si, dans la même commune (CP + ville
 * normalisés, repli laposte si salle.Cp/Ville vides) :
 *   - leur NOM normalisé est identique (sans accents/casse/ponctuation, sans les mots
 *     « gymnase », « salle », « complexe », « sportif »… ni articles), ou
 *   - leur ADRESSE normalisée est identique (abréviations av/bd/pl/st… développées).
 * Renvoie ['classe' => [Id_Salle => Id_Salle représentant], 'libelle' => [Id_Salle => « Nom (Ville) »]].
 * Cache statique : une seule lecture de la table salle par requête HTTP ($rafraichir = true
 * relit la table : réutilisation d'une salle au même lieu en synchro FFTT).
 */
function classesLieuxSalles(\PDO $pdo, bool $rafraichir = false): array
{
    static $cache = null;
    if ($cache !== null && !$rafraichir) {
        return $cache;
    }
    $articles = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'a', 'au', 'aux'];
    $videsNom = array_merge($articles, ['gymnase', 'gym', 'salle', 'salles', 'complexe', 'sportif', 'sportive',
        'sportifs', 'sport', 'sports', 'omnisport', 'omnisports', 'espace', 'centre', 'tennis', 'table', 'tt', 'ping']);
    $abrevAdr = ['av' => 'avenue', 'ave' => 'avenue', 'bd' => 'boulevard', 'bld' => 'boulevard', 'boul' => 'boulevard',
        'pl' => 'place', 'r' => 'rue', 'ch' => 'chemin', 'che' => 'chemin', 'rte' => 'route', 'imp' => 'impasse',
        'all' => 'allee', 'sq' => 'square', 'fbg' => 'faubourg', 'st' => 'saint', 'ste' => 'sainte', 'pt' => 'pont'];
    $abrevVille = ['st' => 'saint', 'ste' => 'sainte'];

    $rows = $pdo->query('
        SELECT s.Id_Salle, s.Nom, s.Adresse,
               COALESCE(NULLIF(TRIM(s.Cp), \'\'), lp.CodePostal) AS Cp,
               COALESCE(NULLIF(TRIM(s.Ville), \'\'), lp.Nom)      AS Ville
        FROM salle s LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
        ORDER BY s.Id_Salle
    ')->fetchAll(\PDO::FETCH_ASSOC);

    $parent = [];
    $racine = function (int $x) use (&$parent): int {
        while ($parent[$x] !== $x) {
            $x = $parent[$x] = $parent[$parent[$x]];
        }

        return $x;
    };
    $premier = [];
    $libelle = [];
    foreach ($rows as $s) {
        $id = (int) $s['Id_Salle'];
        $parent[$id] = $id;
        $libelle[$id] = trim($s['Nom'] . ' (' . trim((string) $s['Ville']) . ')');
        $commune = preg_replace('/\D/', '', (string) $s['Cp']) . '|' . normTexteLieu($s['Ville'], ['sur', 'en', 'les', 'le', 'la'], $abrevVille);
        if ($commune === '|') {
            continue;   // commune inconnue : seul l'Id_Salle identique vaut « même lieu »
        }
        foreach (['N' => normTexteLieu($s['Nom'], $videsNom, $abrevVille), 'A' => normTexteLieu($s['Adresse'], $articles, $abrevAdr)] as $t => $v) {
            if ($v === '') {
                continue;
            }
            $cle = "$t|$commune|$v";
            if (isset($premier[$cle])) {
                $a = $racine($premier[$cle]);
                $b = $racine($id);
                if ($a !== $b) {
                    $parent[max($a, $b)] = min($a, $b);
                }
            } else {
                $premier[$cle] = $id;
            }
        }
    }
    $classe = [];
    foreach (array_keys($parent) as $id) {
        $classe[$id] = $racine($id);
    }

    return $cache = ['classe' => $classe, 'libelle' => $libelle];
}

/** Ids des fiches salle au même lieu que $idSalle (lui compris) ; [] si salle inconnue. */
function sallesMemeLieu(\PDO $pdo, ?int $idSalle): array
{
    $c = classesLieuxSalles($pdo)['classe'];
    if (!$idSalle || !isset($c[$idSalle])) {
        return [];
    }

    return array_keys($c, $c[$idSalle], true);
}

/** Salle effective (sqlSalleEffective()) de la rencontre $idRenc : ['salle' => ?int], ou null si rencontre inconnue. */
function lieuRencontre(\PDO $pdo, int $idRenc): ?array
{
    $st = $pdo->prepare('SELECT ' . sqlSalleEffective('r', 'e') . ' AS Id_Salle
        FROM rencontre r JOIN equipe e ON e.Id_Equipe = r.Id_EquipeDom WHERE r.Id_Rencontre = ?');
    $st->execute([$idRenc]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);

    return $row ? ['salle' => $row['Id_Salle'] !== null ? (int) $row['Id_Salle'] : null] : null;
}

/**
 * Condition SQL « autre lieu » (règle du jour EN14/EN25/EN28) : la rencontre $r2 (équipe
 * domicile $e2) n'est pas dans la même salle que la rencontre $idRencCible (sallesMemeLieu() de sa
 * salle effective) — le club recevant n'intervient pas. Salle inconnue = différente.
 * Id_Salle castés en int avant intégration.
 */
function sqlAutreLieu(\PDO $pdo, string $r2, string $e2, int $idRencCible): string
{
    $lieu = lieuRencontre($pdo, $idRencCible);
    if (!$lieu) {
        return '1';
    }
    $ids = array_map('intval', sallesMemeLieu($pdo, $lieu['salle']));
    $memeSalle = $ids ? 'COALESCE(' . sqlSalleEffective($r2, $e2) . ' IN (' . implode(',', $ids) . '), 0)' : '0';

    return "(NOT $memeSalle)";
}

/**
 * Autres nominations de $idJa le jour de la rencontre $idRenc (hors celle-ci) :
 * ['nb' => total, 'autres_lieux' => nb dans une autre salle (sallesMemeLieu() ; le club recevant
 * n'intervient pas), 'autre_lieu' => « Nom de salle (Ville) » de la 1re d'entre elles, ou null].
 * Règle partagée EN14/EN25/EN28 : refus si nb >= 2 ou autres_lieux > 0.
 */
function nominationsJourJa(\PDO $pdo, int $idJa, int $idRenc): array
{
    $res  = ['nb' => 0, 'autres_lieux' => 0, 'autre_lieu' => null];
    $lieu = lieuRencontre($pdo, $idRenc);
    if (!$lieu) {
        return $res;
    }
    $st = $pdo->prepare('
        SELECT ' . sqlSalleEffective('r2', 'ed2') . ' AS Id_Salle
        FROM nomination n
        JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
        JOIN rencontre  r2 ON r2.Id_Rencontre = n.Id_Rencontre
        JOIN equipe    ed2 ON ed2.Id_Equipe   = r2.Id_EquipeDom
        JOIN rencontre  r  ON r.Id_Rencontre  = ?
        WHERE d.Id_JA = ? AND n.Id_Rencontre != r.Id_Rencontre AND r2.Date = r.Date
    ');
    $st->execute([$idRenc, $idJa]);
    $memes = sallesMemeLieu($pdo, $lieu['salle']);
    $lib   = classesLieuxSalles($pdo)['libelle'];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $o) {
        $res['nb']++;
        $salle = $o['Id_Salle'] !== null ? (int) $o['Id_Salle'] : null;
        if (!in_array($salle, $memes, true)) {
            $res['autres_lieux']++;
            $res['autre_lieu'] ??= $salle !== null ? ($lib[$salle] ?? null) : 'salle inconnue';
        }
    }

    return $res;
}

/**
 * utilisateur.PwdVersion existe-t-elle ? (ajoutée par EA98 — connexion, revalidation de session,
 * E006/E008/EA86 la lisent / l'incrémentent seulement si présente ; absente = pas de contrôle de
 * version). Cache statique : une seule vérification par requête.
 */
function utilisateurAPwdVersion(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'PwdVersion'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/** Fragment SET à ajouter à un UPDATE utilisateur qui change le mot de passe ('' avant EA98). */
function sqlIncrementPwdVersion(\PDO $pdo): string
{
    return utilisateurAPwdVersion($pdo) ? ', PwdVersion = PwdVersion + 1' : '';
}

/**
 * Colonnes de la table utilisateur lues pour construire / revalider $_SESSION['utilisateur']
 * (voir construireSessionUtilisateur()) — PwdVersion seulement si présente.
 */
function colonnesSessionUtilisateur(\PDO $pdo): string
{
    return 'Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin, Email'
        . (utilisateurAPwdVersion($pdo) ? ', PwdVersion' : '');
}

/**
 * nomination.F131 existe-t-elle ? (ajoutée par EA98 — EN14/EN28 la lisent / l'écrivent
 * seulement si présente). Cache statique : une seule vérification par requête.
 */
function nominationAF131(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'F131'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * nomination.FichierPeage existe-t-elle ? (ajoutée par EA98 — EN28 la lit / l'écrit seulement
 * si présente ; sinon lecture = NULL et champ justificatif désactivé). Cache statique : une seule
 * vérification par requête.
 */
function nominationAFichierPeage(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND COLUMN_NAME = 'FichierPeage'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * rencontre.Forfait existe-t-elle ? (ajoutée par EA98 — EN23 la lit / l'écrit seulement
 * si présente ; sinon lecture = 0 et liste désactivée). Cache statique : une seule
 * vérification par requête.
 */
function rencontreAForfait(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'Forfait'"
            )->fetchColumn();
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * rencontre.DateDemandeClub existe-t-elle ? (ajoutée par EA98 — envoyerDemandeJaClub()
 * l'écrit et EN14 la lit seulement si présente ; sinon lecture = NULL). Cache statique :
 * une seule vérification par requête.
 */
function rencontreADateDemandeClub(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rencontre' AND COLUMN_NAME = 'DateDemandeClub'"
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
 * Date du jour (J) des règles de dates d'EN21, au format Y-m-d. Toujours l'heure PHP du serveur
 * (fuseau de l'application), jamais CURDATE() MySQL : les deux règles (accusé / frais) comparent
 * ainsi la même date J, sans décalage possible entre fuseau PHP et fuseau MySQL.
 */
function jourConvocation(): string
{
    return date('Y-m-d');
}

/**
 * Normalise une date de rencontre (D) en Y-m-d ('2026-10-14' ou '2026-10-14 00:00:00'),
 * null si vide ou invalide.
 */
function dateRencontreNormalisee(?string $date): ?string
{
    $d = substr(trim((string) $date), 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }

    return $d;
}

/**
 * Règle de date de l'accusé de réception (EN21) : possible seulement si J <= D (rencontre du jour
 * ou à venir). Date invalide → false. Fonction pure ($jour par défaut = jourConvocation()).
 */
function dateConvocationAccusable(?string $dateRencontre, ?string $jour = null): bool
{
    $d = dateRencontreNormalisee($dateRencontre);

    return $d !== null && ($jour ?? jourConvocation()) <= $d;
}

/**
 * Règle de date de la saisie des frais (EN21) : possible seulement si J >= D. Le jour même (J = D),
 * où l'accusé reste possible, la saisie reste en plus conditionnée à l'accusé de réception
 * ($accuseManquant = colonne AccuseReception présente (EA98), NULL, nomination rattachée
 * à un JA). Après D, saisie libre (l'accusé n'est plus possible). Fonction pure.
 * Retourne ['ok' => bool, 'err' => ?string, 'attendAccuse' => bool (seul l'accusé bloque)].
 */
function fraisSaisissables(?string $dateRencontre, bool $accuseManquant, ?string $jour = null): array
{
    $d = dateRencontreNormalisee($dateRencontre);
    $j = $jour ?? jourConvocation();
    if ($d === null) {
        return ['ok' => false, 'err' => 'Date de la rencontre inconnue : la saisie des frais est impossible.', 'attendAccuse' => false];
    }
    if ($j < $d) {
        return ['ok' => false, 'err' => 'La saisie des frais sera possible à partir du '
            . date('d/m/Y', strtotime($d)) . '.', 'attendAccuse' => false];
    }
    if ($j === $d && $accuseManquant) {
        return ['ok' => false, 'err' => 'Pour saisir vos frais, accusez d\'abord réception de cette convocation.', 'attendAccuse' => true];
    }

    return ['ok' => true, 'err' => null, 'attendAccuse' => false];
}

/**
 * La convocation peut-elle recevoir un accusé de réception (EN21) ? Nomination existante,
 * rattachée à un JA et dont la rencontre est aujourd'hui ou à venir
 * (J <= D, dateConvocationAccusable() — date du jour PHP, pas CURDATE()).
 */
function convocationAccusable(\PDO $pdo, int $idNomination): bool
{
    $st = $pdo->prepare(
        'SELECT r.Date
         FROM nomination n
         JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
         JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
         WHERE n.Id_Nomination = ? AND d.Id_JA IS NOT NULL'
    );
    $st->execute([$idNomination]);
    $date = $st->fetchColumn();

    return $date !== false && dateConvocationAccusable((string) $date);
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
 * que assurerRoleCsr(). À la connexion, ce rôle est dirigé vers le menu E009
 * (AuthController::redirectForRole(), filtre "craconvocauth").
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
 * PHPMailer. Pas de rate limit ici (laissé à l'appelant). Après un envoi réussi
 * (demande EN14 ou relance EN28), rencontre.DateDemandeClub = NOW() si la colonne
 * existe (EA98) ; un échec de cette mise à jour est seulement journalisé (error_log),
 * l'envoi reste un succès.
 */
function envoyerDemandeJaClub(\PDO $pdo, int $idRenc, array $moi, string $sujetEdit = '', string $msgEdit = ''): array
{
    $stmt = $pdo->prepare(
        "SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                ed.Division, CASE WHEN r.ArbitrageCRA = 1 THEN 'CRA' ELSE 'Club' END AS SouhaitJA,
                ed.Nom AS NomDom, ev.Nom AS NomExt,
                cl.CorNom, cl.CorEmail, cl.RefNom, cl.RefMail,
                -- Salle de la rencontre si elle existe, sinon salle principale du club
                -- recevant : tous les champs viennent de la MÊME salle (règle EN14).
                CASE WHEN r.id_Salle IS NOT NULL AND sr.Id_Salle IS NOT NULL
                     THEN sr.Nom ELSE sc.Nom END AS SalleNom,
                CASE WHEN r.id_Salle IS NOT NULL AND sr.Id_Salle IS NOT NULL
                     THEN sr.Adresse ELSE sc.Adresse END AS SalleAdresse,
                CASE WHEN r.id_Salle IS NOT NULL AND sr.Id_Salle IS NOT NULL
                     THEN COALESCE(lpr.CodePostal, sr.Cp)
                     ELSE COALESCE(lpc.CodePostal, sc.Cp) END AS SalleCp,
                CASE WHEN r.id_Salle IS NOT NULL AND sr.Id_Salle IS NOT NULL
                     THEN COALESCE(lpr.Nom, sr.Ville)
                     ELSE COALESCE(lpc.Nom, sc.Ville) END AS SalleVille
         FROM rencontre r
         JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
         LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
         LEFT JOIN club cl ON cl.Id_Club = ed.Id_Club
         LEFT JOIN salle sr ON sr.Id_Salle = r.id_Salle
         LEFT JOIN laposte lpr ON lpr.Id_LaPoste = sr.Id_Laposte
         " . sqlJoinSallePrincipale('ed.Id_Club', 'sc') . "
         LEFT JOIN laposte lpc ON lpc.Id_LaPoste = sc.Id_Laposte
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
        $mail->AltBody = altBodyDepuisHtml($corps);
    }
    $mail->send();

    // Trace de l'envoi (une relance écrase la date précédente). date_demande = date
    // enregistrée (Y-m-d H:i:s), null si colonne absente ou UPDATE en échec.
    $dateDemande = null;
    if (rencontreADateDemandeClub($pdo)) {
        try {
            $pdo->prepare('UPDATE rencontre SET DateDemandeClub = NOW() WHERE Id_Rencontre = ?')
                ->execute([$idRenc]);
            $lue = $pdo->prepare('SELECT DateDemandeClub FROM rencontre WHERE Id_Rencontre = ?');
            $lue->execute([$idRenc]);
            $dateDemande = $lue->fetchColumn() ?: null;
        } catch (\PDOException $e) {
            error_log('[NIJAC] DateDemandeClub non mise à jour (rencontre ' . $idRenc . ') : ' . $e->getMessage());
        }
    }

    $nb = count($mail->getToAddresses()) + count($mail->getCcAddresses());
    return ['ok' => true, 'msg' => 'Demande envoyée à ' . ($rc['CorNom'] ?: $rc['CorEmail'])
        . ($nb > 1 ? " ($nb destinataires)." : '.'), 'date_demande' => $dateDemande];
}

/**
 * Étend (initTableConfiguration(), EA98) l'ENUM messagerie.Type avec les 3 Types des convocations
 * CRA (EC73), pour qu'ils existent même sur une base sans ces lignes. Les messages eux-mêmes sont
 * des enregistrements de la table messagerie (identifiés par Type, pas par un Id fixe), créés/édités
 * en EA93 : plus de source fichier. Aucune ligne n'est créée ni modifiée ici.
 */
function assurerTypesConvocationCra(\PDO $pdo): void
{
    foreach (['CRA Convocation JA', 'CRA Convocation JA + adjoint', 'CRA Convocation adjoint'] as $type) {
        ajouterTypeMessagerie($pdo, $type);
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

    $existe = $pdo->prepare('SELECT 1 FROM messagerie WHERE Type = ? AND Id_Utilisateur IS NULL LIMIT 1');
    $existe->execute([TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS]);
    if ($existe->fetchColumn()) {
        return;
    }
    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (?, ?, ?, NULL, 0, 1)')
        ->execute([
            TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS,
            sujetParDefautCopieConvocationClubs(),
            modeleHtmlCopieConvocationClubs(),
        ]);
}

/** Sujet par défaut du message « Convocation clubs » (amorçage EA98 et repli d'EN14 avant EA98). */
function sujetParDefautCopieConvocationClubs(): string
{
    return 'Copie – Convocation JA du {DATE} à {HEURE} à {NOM_CLUB}';
}

/**
 * Corps par défaut du message « Convocation clubs » : même charte que modeleHtmlConvocationJa(),
 * destiné à un correspondant de club — aucun lien personnel du JA ni consigne qui lui est destinée.
 */
function modeleHtmlCopieConvocationClubs(): string
{
    return <<<'NIJAC_COPIE_CLUBS_HTML'
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>Copie de convocation — Juge-Arbitre</title></head><body style="margin:0;padding:0;background-color:#eef1f6;color:#1f2937;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef1f6" style="background-color:#eef1f6;"><tr><td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #d5dbe5;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;"><tr><td bgcolor="#1a3a6b" style="background-color:#1a3a6b;padding:22px 28px;border-radius:8px 8px 0 0;">
<span style="display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#c9d6ea;">Ligue de Normandie de Tennis de Table</span>
<span style="display:block;font-size:22px;font-weight:bold;line-height:30px;color:#ffffff;">Copie de convocation — Juge-Arbitre</span>
</td></tr><tr><td style="padding:26px 28px 6px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Bonjour,<br><br>
Ceci est une copie, pour information, de la convocation adressée à <strong>{PRENOM} {NOM}</strong> (juge-arbitre), désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {SEXE}.
</td></tr><tr><td style="padding:16px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f6fb" style="background-color:#f3f6fb;border-left:4px solid #1a3a6b;border-radius:4px;font-size:14px;line-height:21px;color:#1f2937;"><tr><td colspan="2" style="padding:14px 16px 4px 16px;font-size:17px;font-weight:bold;color:#1a3a6b;">{DOM} <span style="color:#6b7280;font-weight:normal;">à</span> {EXT}</td></tr><tr><td width="34%" valign="top" style="padding:6px 16px;color:#4b5563;">Date</td><td valign="top" style="padding:6px 16px 6px 0;font-weight:bold;color:#1f2937;">{DATE_LONGUE} à {HEURE}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Compétition</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">Journée n° {JOURNEE} — Division : {DIVISION} — Poule : {POULE}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Club recevant</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{NOM_CLUB}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Salle</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{SALLE_NOM}<br>{SALLE_ADRESSE}<br>{SALLE_CP} {SALLE_VILLE}</td></tr><tr><td valign="top" style="padding:6px 16px 14px 16px;color:#4b5563;">Juge-arbitre</td><td valign="top" style="padding:6px 16px 14px 0;font-weight:bold;color:#1f2937;">{PRENOM} {NOM}{COORDONNEES_JA}</td></tr></table></td></tr><tr><td style="padding:18px 28px 24px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Sportivement,<br><br>
<strong>{UTI_PRENOM} {UTI_NOM}</strong><br>
<span style="font-size:13px;color:#4b5563;">Ligue de Normandie de Tennis de Table</span>
</td></tr><tr><td bgcolor="#f3f4f6" style="background-color:#f3f4f6;padding:14px 28px;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;font-size:11px;line-height:17px;color:#6b7280;">
Message envoyé automatiquement par l'application de nomination des juges-arbitres de la Ligue, pour information des correspondants et référents des clubs concernés. Pour toute question, répondez à ce message.<br>
<a href="{URL_LIGUE}" style="color:#1a3a6b;">{URL_LIGUE}</a>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
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
 * Adresse affichée sur E010 (fonction pure, UTF-8) : 2 premiers caractères de la partie locale, puis
 * autant d'astérisques que de caractères restants (plafonné à 6 ; '***' si la partie locale fait 2
 * caractères ou moins), puis '@' et le domaine complet (dernier '@'). Vide ou sans '@' :
 * « (adresse non renseignée) ». Résultat NON échappé : la vue l'échappe.
 */
function masquerEmailAffichage(string $email): string
{
    $email = trim($email);
    $pos   = mb_strrpos($email, '@', 0, 'UTF-8');
    if ($pos === false) {
        return '(adresse non renseignée)';
    }
    $local = mb_substr($email, 0, $pos, 'UTF-8');
    $reste = mb_strlen($local, 'UTF-8') - 2;

    return mb_substr($local, 0, 2, 'UTF-8')
        . str_repeat('*', $reste > 0 ? min($reste, 6) : 3)
        . mb_substr($email, $pos, null, 'UTF-8');
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

/**
 * Corps par défaut (HTML, « Proposition A – Bleu institutionnel ») du message « Code de sécurité » :
 * amorçage EA98 et repli avant EA98. Tableaux + styles en ligne, marqueur {CODE}.
 */
function corpsParDefautCodeSecurite(): string
{
    return <<<'NIJAC_CODE_SECURITE_HTML'
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;"><tr><td align="center"><table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:100%;"><tr><td style="background:#1f4e8c;color:#ffffff;padding:20px 28px;font-size:20px;font-weight:bold;">🔒 NIJAC – Code de sécurité</td></tr><tr><td style="padding:28px;color:#333333;font-size:14px;line-height:1.6;"><p style="margin:0 0 16px;">Votre code de sécurité est le suivant :</p><p style="margin:0 0 20px;text-align:center;"><span style="display:inline-block;background:#e8f0fb;border:2px dashed #1f4e8c;color:#1f4e8c;font-size:32px;font-weight:bold;letter-spacing:8px;padding:14px 28px;border-radius:8px;">{CODE}</span></p><p style="margin:0 0 16px;">Il est valable <b>10 minutes</b>. Au-delà de ce délai, vous devrez demander un nouveau code.</p><p style="margin:0 0 16px;background:#fff4e5;border-left:4px solid #f0a020;padding:10px 14px;">Si vous n’êtes pas à l’origine de cette demande, veuillez-vous rapprocher de votre nominateur dans les plus brefs délais.</p><p style="margin:0;">Nous vous remercions de votre attention.</p></td></tr><tr><td style="background:#f0f3f8;color:#888888;font-size:12px;text-align:center;padding:12px;">Ce message est généré automatiquement, ne pas répondre.</td></tr></table></td></tr></table>
NIJAC_CODE_SECURITE_HTML;
}

/**
 * Amorce (initTableConfiguration(), EA98) le message système « Code de sécurité » : Type ENUM étendu,
 * ligne créée seulement si aucun message système de ce Type n'existe ; corps existant jamais écrasé.
 * Cc = 0, ReplyTo = 0 : envoyé au seul titulaire du compte.
 */
function assurerModeleCodeSecurite(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, TYPE_MESSAGE_CODE_SECURITE);

    $existe = $pdo->prepare('SELECT 1 FROM messagerie WHERE Type = ? AND Id_Utilisateur IS NULL LIMIT 1');
    $existe->execute([TYPE_MESSAGE_CODE_SECURITE]);
    if ($existe->fetchColumn()) {
        return;
    }
    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (?, ?, ?, NULL, 0, 0)')
        ->execute([TYPE_MESSAGE_CODE_SECURITE, SUJET_CODE_SECURITE, corpsParDefautCodeSecurite()]);
}

/**
 * Sujet/corps de l'email du code. $modele = ligne messagerie système (Sujet/Message) ou null
 * (avant EA98 : repli sur le corps HTML par défaut). {CODE} remplacé par le code échappé (6 chiffres).
 * html = corps HTML (balises détectées) ; texte = version texte brut (AltBody si HTML, sinon le corps).
 * Un ancien corps texte brut reste accepté (envoyé en texte, html = false).
 *
 * @return array{sujet: string, corps: string, html: bool, texte: string}
 */
function rendreMessageCodeSecurite(?array $modele, string $code): array
{
    $sujet = trim((string) ($modele['Sujet'] ?? '')) ?: SUJET_CODE_SECURITE;
    $corps = trim((string) ($modele['Message'] ?? '')) !== '' ? (string) $modele['Message'] : corpsParDefautCodeSecurite();
    $rendu = remplacerMarqueursMessage($sujet, $corps, ['{CODE}' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8')]);
    $html  = strip_tags($rendu['corps']) !== $rendu['corps'];

    // Texte brut propre (AltBody) via le helper commun.
    $texte = $html ? altBodyDepuisHtml($rendu['corps']) : $rendu['corps'];

    return $rendu + ['html' => $html, 'texte' => $texte];
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
    $sig = hash_hmac('sha256', $idUtilisateur . '.' . $exp, cleJetonResetMdp($hashMdpActuel));
    return $idUtilisateur . '-' . $exp . '-' . $sig;
}

/**
 * Clé HMAC du jeton E008 : hash du mot de passe courant + OBFUSCATOR_SEED (public, documenté)
 * + pepper secret getObfuscatorPepper() (.env, OBFUSCATOR_PEPPER). Le pepper rend le jeton
 * infalsifiable par qui lirait les hash en base (fuite de sauvegarde SQL…) sans le .env.
 * Pepper vide : la clé est EXACTEMENT l'ancienne (hash . seed), donc les liens déjà émis restent
 * valables, mais le gain est nul (seule protection = secret du hash). Pepper renseigné : seule la
 * nouvelle clé est acceptée — les liens émis avant sa mise en place (valables 1 h) sont perdus.
 */
function cleJetonResetMdp(string $hashMdpActuel): string
{
    $pepper = getObfuscatorPepper();

    return $hashMdpActuel . OBFUSCATOR_SEED . ($pepper !== '' ? '|reset-mdp|' . $pepper : '');
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
    $attendu = hash_hmac('sha256', $id . '.' . $exp, cleJetonResetMdp($hash));
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
                $estHtml = strip_tags($rendu['corps']) !== $rendu['corps'];
                $mail->isHTML($estHtml);
                $mail->addAddress(getEmailDestinataire($email));
                $mail->Subject = $rendu['sujet'];
                $mail->Body    = $rendu['corps'];
                if ($estHtml) {
                    $mail->AltBody = altBodyDepuisHtml($rendu['corps']);
                }
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
 *                    ja_principaux (EC73, message « CRA Convocation adjoint » : liste de ['nom','prenom','telephone','email'] →
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
    // Coordonnées" reste dans le modèle), même style que le message « CRA Convocation JA + adjoint ».
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

    // Pluralisation du message « CRA Convocation JA + adjoint » (EC73) : nb_adjoints absent ou < 2 → forme singulière d'origine.
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

    // JA principaux présentés à l'adjoint (message « CRA Convocation adjoint ») : « A, B et C », valeurs vides ignorées.
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
        // Phrases du message « CRA Convocation JA + adjoint » qui changent selon les adjoints validés (a / b / c ci-dessus).
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
 * convocation » vers {URL_CONVOCATION_JA} (EN21). La copie aux clubs n'en
 * dérive pas (message dédié modeleHtmlCopieConvocationClubs()). Plus appelé par le
 * code (la migration EA98 du message n°3 a été retirée) : conservé comme référence
 * du corps par défaut.
 */
function modeleHtmlConvocationJa(): string
{
    return <<<'NIJAC_CONVOCATION_HTML'
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>Convocation — Juge-Arbitre</title></head><body style="margin:0;padding:0;background-color:#eef1f6;color:#1f2937;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef1f6" style="background-color:#eef1f6;"><tr><td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border:1px solid #d5dbe5;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;"><tr><td bgcolor="#1a3a6b" style="background-color:#1a3a6b;padding:22px 28px;border-radius:8px 8px 0 0;">
<span style="display:block;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#c9d6ea;">Ligue de Normandie de Tennis de Table</span>
<span style="display:block;font-size:22px;font-weight:bold;line-height:30px;color:#ffffff;">Convocation — Juge-Arbitre</span>
</td></tr><tr><td style="padding:26px 28px 6px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Bonjour {PRENOM},<br><br>
Nous avons l'avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante du Championnat de France par Équipes {SEXE}.
</td></tr><tr><td style="padding:16px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f6fb" style="background-color:#f3f6fb;border-left:4px solid #1a3a6b;border-radius:4px;font-size:14px;line-height:21px;color:#1f2937;"><tr><td colspan="2" style="padding:14px 16px 4px 16px;font-size:17px;font-weight:bold;color:#1a3a6b;">{DOM} <span style="color:#6b7280;font-weight:normal;">à</span> {EXT}</td></tr><tr><td width="34%" valign="top" style="padding:6px 16px;color:#4b5563;">Date</td><td valign="top" style="padding:6px 16px 6px 0;font-weight:bold;color:#1f2937;">{DATE_LONGUE} à {HEURE}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Compétition</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">Journée n° {JOURNEE} — Division : {DIVISION} — Poule : {POULE}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Juge-arbitre</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{PRENOM} {NOM}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Club recevant</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{NOM_CLUB}</td></tr><tr><td valign="top" style="padding:6px 16px;color:#4b5563;">Adresse</td><td valign="top" style="padding:6px 16px 6px 0;color:#1f2937;">{SALLE_NOM}<br>{SALLE_ADRESSE}<br>{SALLE_CP} {SALLE_VILLE}</td></tr><tr><td valign="top" style="padding:6px 16px 14px 16px;color:#4b5563;">Correspondant</td><td valign="top" style="padding:6px 16px 14px 0;color:#1f2937;">{CORR_NOM}<br>Tél : {CORR_TEL}<br>Courriel : <a href="mailto:{CORR_EMAIL}" style="color:#1a3a6b;">{CORR_EMAIL}</a></td></tr></table></td></tr><tr><td style="padding:0 28px 6px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#fff6e5" style="background-color:#fff6e5;border:1px solid #f0c36d;border-radius:4px;font-size:14px;line-height:21px;color:#5c3d00;"><tr><td style="padding:12px 16px;">
<strong style="color:#8a5a00;">Actions attendues :</strong><br>
• Confirmer votre désignation en cliquant sur le bouton ci-dessous ou Signaler au plus vite toute indisponibilité ou difficulté particulière.<br>
• Conserver les justificatifs nécessaires à l'établissement de vos frais et compléter votre convocation dans un délai de 5 jours après la rencontre.
</td></tr></table></td></tr><tr><td style="padding:0;"><div style="padding:12px 28px 8px 28px;font-size:15px;line-height:23px;color:#1f2937;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;"><tr><td align="center" bgcolor="#1a3a6b" style="background-color:#1a3a6b;border-radius:6px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{URL_CONVOCATION_JA}" style="height:48px;v-text-anchor:middle;width:380px;" arcsize="12%" strokecolor="#1a3a6b" fillcolor="#1a3a6b"><w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Consulter et confirmer ma convocation</center></v:roundrect><![endif]-->
<!--[if !mso]><!--><a href="{URL_CONVOCATION_JA}" target="_blank" style="display:inline-block;padding:14px 28px;min-height:20px;line-height:20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;background-color:#1a3a6b;">Consulter et confirmer ma convocation</a><!--<![endif]-->
</td></tr></table>
<br>
<span style="font-size:12px;line-height:18px;color:#6b7280;">Si le bouton ne fonctionne pas, valider ce lien :<br><a href="{URL_CONVOCATION_JA}" style="color:#1a3a6b;word-break:break-all;">{URL_CONVOCATION_JA}</a></span>
</div></td></tr><tr><td style="padding:18px 28px 24px 28px;font-size:15px;line-height:23px;color:#1f2937;">
Nous vous remercions pour votre investissement au service du tennis de table normand et vous souhaitons une excellente rencontre.<br><br>
<strong>{UTI_PRENOM} {UTI_NOM}</strong><br>
<span style="font-size:13px;color:#4b5563;">Ligue de Normandie de Tennis de Table</span>
</td></tr><tr><td bgcolor="#f3f4f6" style="background-color:#f3f4f6;padding:14px 28px;border-top:1px solid #e5e7eb;border-radius:0 0 8px 8px;font-size:11px;line-height:17px;color:#6b7280;">
Message envoyé automatiquement par l'application de nomination des juges-arbitres de la Ligue. Pour toute question, répondez à ce message.<br>
<a href="{URL_LIGUE}" style="color:#1a3a6b;">{URL_LIGUE}</a>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>
NIJAC_CONVOCATION_HTML;
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

/**
 * Durée de conservation (minutes) des horodatages du fichier de tentatives, toutes clés
 * confondues : au moins la plus longue fenêtre utilisée (plafond journalier mfa_essai:<id>).
 * Avant, la purge utilisait la fenêtre de la clé en cours d'écriture (15 min), ce qui effaçait
 * les compteurs à fenêtre plus longue (login_compte 60 min) à chaque échec par IP.
 */
const TENTATIVES_RETENTION_MINUTES = 1440;

/**
 * Chemin du fichier JSON des tentatives (verrou flock, partagé par toutes les clés).
 * Constante NIJAC_FICHIER_TENTATIVES : surcharge réservée aux tests CLI (fichier temporaire).
 */
function tentativesFichier(): string
{
    return defined('NIJAC_FICHIER_TENTATIVES') ? NIJAC_FICHIER_TENTATIVES : __DIR__ . '/../logs/login_attempts.json';
}

/** Enregistre une tentative pour la clé donnée. */
function enregistrerTentative(string $cle, int $fenetreMinutes): void
{
    $now   = time();
    $debut = $now - max($fenetreMinutes, TENTATIVES_RETENTION_MINUTES) * 60;

    $fp = fopen(tentativesFichier(), 'c+');
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
    $fichier = tentativesFichier();
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

// Limite par compte (E001) : complète la limite par IP contre une attaque distribuée sur UN login.
// Appliquée aussi aux logins inconnus (clé = login SAISI) : jamais d'oracle d'existence. Le blocage
// ne dure que la fenêtre glissante (pas de verrouillage permanent du compte légitime).
const LOGIN_COMPTE_MAX     = 10;  // échecs de mot de passe…
const LOGIN_COMPTE_FENETRE = 60;  // …par fenêtre glissante (minutes)
// Plafond journalier des codes de sécurité faux (E010) par compte, toutes sessions/IP confondues.
const MFA_ESSAIS_MAX       = 20;
const MFA_ESSAIS_FENETRE   = 1440;
// E007 : demandes de lien de réinitialisation par IP (réponse neutre identique au-delà).
const MDPRESET_IP_MAX      = 5;
const MDPRESET_IP_FENETRE  = 15;

/** Clé de limitation par compte : login saisi normalisé (trim, minuscules), tronqué à 64 caractères. */
function cleLoginCompte(string $login): string
{
    return 'login_compte:' . mb_substr(mb_strtolower(trim($login)), 0, 64);
}

/** Limite par compte (E001) : null si autorisé, sinon le même message « Trop de tentatives » que par IP. */
function checkLoginCompteRateLimit(string $login): ?string
{
    return checkTentativesRateLimit(cleLoginCompte($login), LOGIN_COMPTE_MAX, LOGIN_COMPTE_FENETRE);
}

/** Enregistre un échec de mot de passe pour le login saisi (existant ou non). */
function enregistrerEchecLoginCompte(string $login): void
{
    enregistrerTentative(cleLoginCompte($login), LOGIN_COMPTE_FENETRE);
}

/** Plafond journalier des codes de sécurité faux pour un compte (E010). */
function checkMfaEssaisRateLimit(int $idUtilisateur): ?string
{
    return checkTentativesRateLimit('mfa_essai:' . $idUtilisateur, MFA_ESSAIS_MAX, MFA_ESSAIS_FENETRE);
}

/** Enregistre un code de sécurité faux pour le compte. */
function enregistrerEchecMfa(int $idUtilisateur): void
{
    enregistrerTentative('mfa_essai:' . $idUtilisateur, MFA_ESSAIS_FENETRE);
}

/** E007 : la demande de lien depuis cette IP est-elle encore autorisée ? (compte chaque demande) */
function autoriserDemandeResetMdpIp(): bool
{
    $cle = 'mdpreset_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (checkTentativesRateLimit($cle, MDPRESET_IP_MAX, MDPRESET_IP_FENETRE) !== null) {
        return false;
    }
    enregistrerTentative($cle, MDPRESET_IP_FENETRE);

    return true;
}

/** Durée (s) entre deux relectures en base de la session (revaliderSessionUtilisateur()). */
const SESSION_REVALIDATION_DELAI = 60;

/** Routes accessibles tant que $_SESSION['utilisateur']['change_login'] est vrai (changement forcé). */
const ROUTES_CHANGE_LOGIN = ['changer-mot-de-passe', 'logout'];

/**
 * Revalidation de $_SESSION['utilisateur'] par les filtres d'authentification (auth, adminauth,
 * nominauth, csrauth, defiscauth, craconvocauth), session ACTIVE (avant session_write_close()).
 *
 * Au plus une fois par SESSION_REVALIDATION_DELAI (horodatage 'verifie_le' ; absent = session
 * ouverte avant cette version, revalidée tout de suite) relit Actif, Role, ChangeLogin et
 * PwdVersion (si la colonne existe) du compte, ainsi que Login : ligne absente, Actif = 0, rôle
 * changé, login changé ou version du mot de passe différente → session détruite (cookie compris, detruireSessionNijac())
 * et retour à E001. Une session sans 'pwd_version' (ouverte avant EA98 / cette version) adopte
 * la version courante. ChangeLogin passé à 1 en base (EA86 « Écraser » avant EA98) est reporté.
 * Base injoignable : pas de destruction (journalisé), nouvel essai à la requête suivante.
 *
 * Puis, si 'change_login' est vrai, seules ROUTES_CHANGE_LOGIN restent accessibles.
 *
 * Retourne la route (pour site_url()) vers laquelle rediriger, ou null si l'accès continue.
 */
function revaliderSessionUtilisateur(string $chemin = ''): ?string
{
    $u = $_SESSION['utilisateur'] ?? null;
    if (!is_array($u) || empty($u['id'])) {
        return 'login';
    }

    if (time() - (int) ($u['verifie_le'] ?? 0) >= SESSION_REVALIDATION_DELAI) {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare(
                'SELECT Login, Actif, Role, ChangeLogin' . (utilisateurAPwdVersion($pdo) ? ', PwdVersion' : '')
                . ' FROM utilisateur WHERE Id_Utilisateur = ? LIMIT 1'
            );
            $stmt->execute([(int) $u['id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $motif = null;
            if (!$row) {
                $motif = 'compte supprimé';
            } elseif (!(int) $row['Actif']) {
                $motif = 'compte désactivé';
            } elseif ((string) $row['Role'] !== (string) ($u['role'] ?? '')) {
                $motif = 'rôle modifié';
            } elseif ((string) $row['Login'] !== (string) ($u['login'] ?? '')) {
                // Login renommé (EA86) : la session ne doit pas garder un login (ex. 'CHAUTARD',
                // accès EA96/EA97/EA98) que le compte n'a plus. Son propre renommage via EA86
                // réécrit déjà la session depuis la base (rafraichirSessionPropre()).
                $motif = 'login modifié';
            } elseif (array_key_exists('PwdVersion', $row) && isset($u['pwd_version'])
                && (int) $row['PwdVersion'] !== (int) $u['pwd_version']) {
                $motif = 'mot de passe modifié';
            }
            if ($motif !== null) {
                error_log('[NIJAC][SEC] session invalidée (' . $motif . ', Id_Utilisateur ' . (int) $u['id'] . ')');
                detruireSessionNijac();
                return 'login';
            }

            if (array_key_exists('PwdVersion', $row) && !isset($u['pwd_version'])) {
                $_SESSION['utilisateur']['pwd_version'] = (int) $row['PwdVersion'];
            }
            if ((int) $row['ChangeLogin'] === 1) {
                $_SESSION['utilisateur']['change_login'] = true;
            }
            $_SESSION['utilisateur']['is_admin']   = ($row['Role'] === 'Administrateur');
            $_SESSION['utilisateur']['verifie_le'] = time();
        } catch (\Throwable $e) {
            error_log('[NIJAC] revalidation de session impossible : ' . $e->getMessage());
        }
    }

    if (!empty($_SESSION['utilisateur']['change_login'])
        && !in_array(trim($chemin, '/'), ROUTES_CHANGE_LOGIN, true)) {
        return 'changer-mot-de-passe';
    }

    return null;
}

/**
 * Cœur commun des filtres d'authentification : démarre la session native, vérifie qu'un
 * utilisateur est connecté puis le revalide (revaliderSessionUtilisateur()). Retourne null si
 * l'accès continue (session TOUJOURS active : le filtre teste ensuite le rôle puis appelle
 * session_write_close()), sinon la route de redirection (session déjà refermée).
 */
function controlerSessionFiltre(string $chemin): ?string
{
    demarrerSessionNijac();
    $cible = empty($_SESSION['utilisateur']) ? 'login' : revaliderSessionUtilisateur($chemin);
    if ($cible !== null && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return $cible;
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
 * Construit le tableau $_SESSION['utilisateur'] à partir d'une ligne de la table
 * utilisateur (Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, ChangeLogin, Email).
 * Source unique du format de session : connexion (AuthController) et rafraîchissement du
 * profil de l'administrateur qui modifie son propre compte (UtilisateurController, EA86).
 */
function construireSessionUtilisateur(array $row): array
{
    return [
        'id'             => $row['Id_Utilisateur'],
        // Casse canonique de la base (Login a une collation *_ci, insensible à la casse) :
        // pas la saisie brute, qui casserait les comparaisons strictes === 'CHAUTARD'.
        'login'          => $row['Login'],
        'nom'            => $row['Nom'],
        'prenom'         => $row['Prenom'],
        'role'           => $row['Role'],
        'id_departement' => $row['Id_Departement'],
        'change_login'   => (bool) $row['ChangeLogin'],
        'is_admin'       => ($row['Role'] === 'Administrateur'),
        'email'          => $row['Email'] ?? '',
        // Version du mot de passe (null si la colonne n'existe pas encore, avant EA98) et
        // horodatage de la dernière relecture en base — voir revaliderSessionUtilisateur().
        'pwd_version'    => array_key_exists('PwdVersion', $row) ? (int) $row['PwdVersion'] : null,
        'verifie_le'     => time(),
    ];
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
function getNijacMailer(?string $forcedPrefix = null): \PHPMailer\PHPMailer\PHPMailer
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
    $host  = getConfig($p . 'host', '');
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
        // Envois en boucle : connexion SMTP réutilisée (KeepAlive) + pause 0,3 s entre deux envois (NijacMailer).
        $mail->activerConnexionPartagee();
    } else {
        throw new \RuntimeException('SMTP non configuré. Veuillez renseigner les paramètres SMTP dans la configuration.');
    }

    // Délivrabilité : expéditeur du domaine de la ligue (jamais d'adresse d'un autre domaine en repli),
    // Return-Path (Sender) aligné sur le From, EHLO/Message-ID sur le domaine du From, X-Mailer masqué.
    $from = trim((string) getConfig($p . 'from', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'nijac@ligue-normandie-tt.fr';
    }
    $mail->setFrom($from, getConfig($p . 'from_name', 'NIJAC'));
    $mail->Sender   = $from;
    $domaineFrom    = strtolower(substr(strrchr($from, '@'), 1));
    $mail->Hostname = $domaineFrom !== '' ? $domaineFrom : 'ligue-normandie-tt.fr';
    $mail->XMailer  = ' ';

    return $mail;
}

/**
 * Version texte brut (AltBody) d'un corps HTML, propre pour les filtres anti-spam :
 * - <head>/<style>/<script>/<title> et commentaires (dont conditionnels mso) exclus ;
 * - espaces du source HTML non significatifs (comme au rendu) : seuls <br>, blocs et lignes
 *   de tableau produisent des sauts de ligne ;
 * - ligne de tableau à plusieurs cellules = une ligne « libellé : valeur » (lignes consécutives
 *   sans ligne vide) ; cellule seule (mise en page), <p>, <h*>, <table>, listes = paragraphe ;
 * - liens http(s) : « texte : url » (url seule si le texte est vide ou est déjà l'url) ;
 *   mailto/tel/ancres : texte seul (adresse si texte vide) ;
 * - au plus UNE ligne vide consécutive, aucune en tête/fin, ni espace/tab/insécable en fin de ligne.
 * Fins de ligne en \n : PHPMailer (quoted-printable) les normalise lui-même en CRLF.
 * À utiliser pour tout AltBody.
 */
function altBodyDepuisHtml(string $html): string
{
    $P = "\x1D"; // saut de paragraphe (une ligne vide)
    $S = "\x1E"; // saut de ligne simple

    $t = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
    $t = preg_replace('#<!--.*?-->#s', '', $t) ?? $t; // y compris <!--[if mso]>…<![endif]-->
    $t = str_replace([$P, $S], '', $t);
    $t = preg_replace('/[ \t\r\n\f]+/', ' ', $t) ?? $t; // blancs du source : non significatifs en HTML

    // Liens : l'URL reste lisible en texte brut.
    $t = preg_replace_callback('#<a\b([^>]*)>(.*?)</a\s*>#is', static function (array $m): string {
        $texte = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \u{00A0}");
        $href  = preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#is', $m[1], $h)
            ? trim(html_entity_decode($h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        if (preg_match('#^mailto:([^?]*)#i', $href, $mt)) {
            $href = rawurldecode($mt[1]);
        } elseif (!preg_match('#^https?://#i', $href)) {
            $href = ''; // tel:, #ancre, javascript:… : texte seul
        }
        if ($href === '' || $texte === $href || str_contains($texte, $href)) {
            $res = $texte !== '' ? $texte : $href;
        } elseif ($texte === '' || rtrim($texte, '/') === rtrim(preg_replace('#^https?://#i', '', $href), '/')) {
            $res = $href;
        } else {
            $res = str_starts_with($href, 'http') ? "$texte : $href" : $texte;
        }
        return htmlspecialchars($res, ENT_QUOTES, 'UTF-8');
    }, $t) ?? $t;

    // Lignes de tableau les plus internes : plusieurs cellules → « a : b » sur une ligne,
    // une seule cellule → paragraphe.
    $t = preg_replace_callback('#<tr\b[^>]*>((?:(?!<tr\b|</tr\s*>|<table\b).)*)</tr\s*>#is', static function (array $m) use ($P, $S): string {
        $cellules = [];
        foreach (preg_split('#</t[dh]\s*>#i', $m[1]) as $c) {
            $c = trim(preg_replace('#<t[dh]\b[^>]*>#i', '', $c) ?? $c);
            if (trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \u{00A0}") !== '') {
                $cellules[] = $c;
            }
        }
        if (count($cellules) <= 1) {
            return $P . ($cellules[0] ?? '') . $P;
        }
        $ligne = '';
        foreach ($cellules as $i => $c) {
            $prec  = rtrim(html_entity_decode(strip_tags($ligne), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \u{00A0}");
            $ligne .= $i === 0 ? $c : (str_ends_with($prec, ':') ? ' ' : ' : ') . $c;
        }
        return $S . $ligne . $S;
    }, $t) ?? $t;

    $t = preg_replace('#<br\s*/?>#i', "\n", $t) ?? $t;
    $t = preg_replace('#<li\b[^>]*>#i', $S . '- ', $t) ?? $t;
    $t = preg_replace('#</?(p|h[1-6]|table|tbody|thead|tfoot|tr|t[dh]|ul|ol|blockquote|hr|center)\b[^>]*>#i', $P, $t) ?? $t;
    $t = preg_replace('#</?(div|li|dd|dt|dl|address)\b[^>]*>#i', $S, $t) ?? $t;
    $t = preg_replace('#<span\b[^>]*display\s*:\s*block[^>]*>(.*?)</span\s*>#is', $S . '$1' . $S, $t) ?? $t;

    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}", "\t", "\r"], ' ', $t);

    // Chaque suite de blancs/sauts → espace, saut simple ou UNE ligne vide (jamais plus).
    $t = preg_replace_callback('/[ \n\x1D\x1E]+/', static function (array $m) use ($P, $S): string {
        $s  = $m[0];
        $nb = substr_count($s, "\n");
        if (str_contains($s, $P) || $nb >= 2) {
            return "\n\n";
        }
        return ($nb === 1 || str_contains($s, $S)) ? "\n" : ' ';
    }, $t) ?? $t;

    return trim($t, " \n");
}

// ─────────────────────────────────────────────────────────────────────────────
// 2FA TOTP (RFC 6238 — SHA-1, 6 chiffres, pas de 30 s, compatible Google Authenticator) des
// écrans réservés au login CHAUTARD (EA96 / EA97 / EA98), activée / saisie sur EA99
// (TotpController, App\Libraries\TotpChautard). Fonctions pures ci-dessous (PHP natif, aucune
// dépendance) ; stockage : utilisateur.TotpSecret (secret base32 chiffré AES-256-GCM),
// utilisateur.TotpDernierPas (anti-rejeu), utilisateur.TotpSecours (JSON des codes de secours
// hachés) — colonnes créées par initTableConfiguration() (EA98).
//
// PROCÉDURE D'URGENCE (téléphone perdu, pepper changé, codes de secours épuisés) — via la
// console SQL de l'hébergeur (phpMyAdmin), ou EA98 après déverrouillage sur EA99 par un code de
// secours (puis mot de passe dans EA98) :
//   UPDATE utilisateur SET TotpSecret=NULL, TotpDernierPas=NULL, TotpSecours=NULL WHERE Login='CHAUTARD';
// (réinitialise la 2FA : la prochaine ouverture d'EA96/97/98 renvoie vers l'activation EA99).
// ─────────────────────────────────────────────────────────────────────────────

const TOTP_PAS_SECONDES  = 30;
const TOTP_CHIFFRES      = 6;
const TOTP_TOLERANCE_PAS = 1;     // ±1 pas (décalage d'horloge du téléphone)
const TOTP_SESSION_DUREE = 1800;  // validité glissante (s) du déverrouillage EA96/97/98 en session
const TOTP_NB_SECOURS    = 8;     // codes de secours à usage unique générés à l'activation
const TOTP_EMETTEUR      = 'NIJAC';
// Sel constant de la clé de chiffrement du secret TOTP : clé = SHA-256(sel + pepper
// getObfuscatorPepper(), .env non versionné). Pepper vide : clé dérivée du SEUL sel constant,
// présent dans le dépôt — le chiffrement ne protège alors plus le secret d'un lecteur de la base
// ayant aussi le code source. Changer le pepper rend le secret indéchiffrable (procédure d'urgence
// ci-dessus, ou déverrouillage par code de secours).
const TOTP_SEL_CLE       = 'NIJAC|totp-secret|v1|';

/** Encodage base32 RFC 4648 (A-Z2-7), sans padding « = » (format attendu par Google Authenticator). */
function base32Encoder(string $binaire): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits     = '';
    foreach (str_split($binaire) as $c) {
        if ($c !== '') {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
    }
    if ($bits === '') {
        return '';
    }
    $sortie = '';
    foreach (str_split($bits, 5) as $bloc) {
        $sortie .= $alphabet[bindec(str_pad($bloc, 5, '0', STR_PAD_RIGHT))];
    }

    return $sortie;
}

/** Décodage base32 (casse, espaces, tirets et padding ignorés) ; false si caractère invalide. */
function base32Decoder(string $texte): string|false
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $texte    = strtoupper(str_replace([' ', '-', '='], '', $texte));
    $bits     = '';
    foreach (str_split($texte) as $c) {
        if ($c === '') {
            continue;
        }
        $v = strpos($alphabet, $c);
        if ($v === false) {
            return false;
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) {
            $sortie .= chr(bindec($octet));
        }
    }

    return $sortie;
}

/** Nouveau secret TOTP : 20 octets aléatoires (160 bits, taille recommandée pour HMAC-SHA1), en base32. */
function genererSecretTotp(): string
{
    return base32Encoder(random_bytes(20));
}

/** Pas TOTP courant (ou de l'instant $t). */
function totpPasCourant(?int $t = null): int
{
    return intdiv($t ?? time(), TOTP_PAS_SECONDES);
}

/**
 * Code TOTP/HOTP (RFC 4226 §5.3 + RFC 6238) pour le secret BINAIRE $secret (base32 décodé) et le
 * pas $pas, sur $chiffres chiffres (zéros de tête conservés).
 */
function totpCode(string $secret, int $pas, int $chiffres = TOTP_CHIFFRES): string
{
    $compteur = pack('N2', ($pas >> 32) & 0xFFFFFFFF, $pas & 0xFFFFFFFF);
    $hmac     = hash_hmac('sha1', $compteur, $secret, true);
    $offset   = ord($hmac[19]) & 0x0F;
    $valeur   = ((ord($hmac[$offset]) & 0x7F) << 24)
              | (ord($hmac[$offset + 1]) << 16)
              | (ord($hmac[$offset + 2]) << 8)
              | ord($hmac[$offset + 3]);

    return str_pad((string) ($valeur % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
}

/**
 * Pas (dans la tolérance ±TOTP_TOLERANCE_PAS autour de $t) pour lequel $code est valide, ou null.
 * Comparaison hash_equals sur chaque pas (pas de sortie anticipée) ; le pas le plus récent gagne.
 */
function totpPasCorrespondant(string $secret, string $code, ?int $t = null): ?int
{
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{' . TOTP_CHIFFRES . '}$/', $code)) {
        return null;
    }
    $courant = totpPasCourant($t);
    $trouve  = null;
    for ($d = -TOTP_TOLERANCE_PAS; $d <= TOTP_TOLERANCE_PAS; $d++) {
        if (hash_equals(totpCode($secret, $courant + $d), $code)) {
            $trouve = $courant + $d;
        }
    }

    return $trouve;
}

/**
 * Vérifie un code TOTP avec anti-rejeu : le pas accepté doit être STRICTEMENT supérieur à
 * $dernierPas (dernier pas déjà utilisé, null = aucun). En cas de succès $dernierPas reçoit le
 * pas accepté (à enregistrer en base par l'appelant) ; inchangé sinon.
 */
function verifierTotp(string $secret, string $code, ?int &$dernierPas, ?int $t = null): bool
{
    $pas = totpPasCorrespondant($secret, $code, $t);
    if ($pas === null || ($dernierPas !== null && $pas <= $dernierPas)) {
        return false;
    }
    $dernierPas = $pas;

    return true;
}

/** URI otpauth:// à encoder dans le QR code (libellé NIJAC:<login>, émetteur NIJAC). */
function uriOtpauth(string $secretBase32, string $login): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_EMETTEUR) . ':' . rawurlencode($login)
        . '?secret=' . $secretBase32 . '&issuer=' . rawurlencode(TOTP_EMETTEUR);
}

/** Clé AES-256 du secret TOTP : SHA-256(sel constant + pepper) — voir TOTP_SEL_CLE. */
function cleChiffrementTotp(string $pepper): string
{
    return hash('sha256', TOTP_SEL_CLE . $pepper, true);
}

/** Chiffre le secret (base32) en AES-256-GCM : « v1: » + base64(iv 12 o + tag 16 o + chiffré). */
function chiffrerSecretTotp(string $secretBase32, string $pepper): string
{
    $iv      = random_bytes(12);
    $tag     = '';
    $chiffre = openssl_encrypt($secretBase32, 'aes-256-gcm', cleChiffrementTotp($pepper), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($chiffre === false) {
        throw new \RuntimeException('Chiffrement du secret TOTP impossible.');
    }

    return 'v1:' . base64_encode($iv . $tag . $chiffre);
}

/** Déchiffre le secret (base32) ; null si format inconnu, données altérées ou mauvaise clé (pepper changé). */
function dechiffrerSecretTotp(string $stocke, string $pepper): ?string
{
    if (!str_starts_with($stocke, 'v1:')) {
        return null;
    }
    $brut = base64_decode(substr($stocke, 3), true);
    if ($brut === false || strlen($brut) <= 28) {
        return null;
    }
    $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', cleChiffrementTotp($pepper), OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));

    return $clair === false ? null : $clair;
}

/** Normalise un code de secours saisi (majuscules, sans espaces ni tirets). */
function normaliserCodeSecours(string $code): string
{
    return strtoupper(str_replace([' ', '-'], '', trim($code)));
}

/** La saisie a-t-elle la forme d'un code de secours (10 caractères base32) ? */
function estFormatCodeSecours(string $code): bool
{
    return (bool) preg_match('/^[A-Z2-7]{10}$/', normaliserCodeSecours($code));
}

/**
 * Génère TOTP_NB_SECOURS codes de secours (10 caractères base32 = 50 bits, affichés « XXXXX-XXXXX »).
 * Hachés par password_hash() (bcrypt) : SecurePasswordHasher (PBKDF2 600 000 itérations) rendrait
 * la vérification des 8 codes beaucoup trop lente, et ces codes aléatoires n'en ont pas besoin.
 *
 * @return array{codes: string[], haches: string[]} codes en clair (affichés UNE fois) + hachés (stockés)
 */
function genererCodesSecours(): array
{
    $codes  = [];
    $haches = [];
    for ($i = 0; $i < TOTP_NB_SECOURS; $i++) {
        $c        = substr(base32Encoder(random_bytes(7)), 0, 10);
        $codes[]  = substr($c, 0, 5) . '-' . substr($c, 5);
        $haches[] = password_hash($c, PASSWORD_DEFAULT);
    }

    return ['codes' => $codes, 'haches' => $haches];
}

/**
 * Consomme un code de secours : retourne la liste des hachés restants (sans celui utilisé),
 * ou null si le code ne correspond à aucun.
 *
 * @param string[] $haches
 * @return string[]|null
 */
function consommerCodeSecours(array $haches, string $code): ?array
{
    if (!estFormatCodeSecours($code)) {
        return null;
    }
    $code = normaliserCodeSecours($code);
    foreach ($haches as $i => $h) {
        if (is_string($h) && password_verify($code, $h)) {
            unset($haches[$i]);

            return array_values($haches);
        }
    }

    return null;
}

/**
 * Colonnes utilisateur.TotpSecret / TotpDernierPas / TotpSecours présentes ? (ajoutées par EA98 —
 * absentes : EA96/97/98 restent accessibles sans TOTP, journalisé). Cache statique par requête.
 */
function utilisateurATotp(\PDO $pdo): bool
{
    static $existe = null;
    if ($existe === null) {
        try {
            $existe = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur'
                   AND COLUMN_NAME IN ('TotpSecret', 'TotpDernierPas', 'TotpSecours')"
            )->fetchColumn() === 3;
        } catch (\PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}
