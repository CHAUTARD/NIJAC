<?php
/**
 * NIJAC – Migration : nomination.Id_JA → nomination.Id_Disponible
 *
 * Règle métier : pour être nominé, un JA doit être disponible. Jusqu'ici `nomination`
 * référençait `ja.Id_JA` directement, sans aucune garantie qu'une ligne `disponible`
 * existe pour ce JA/cette rencontre. Cette migration fait pointer `nomination` vers
 * `disponible.Id_Disponible` à la place, ce qui impose la règle au niveau base de données.
 *
 * Cas particulier : une disponibilité peut être déclarée "toute la journée"
 * (disponible.Id_Rencontre = NULL, Reponse='O'), sans ligne liée à une rencontre précise.
 * Pour chaque nomination existante, on retrouve/matérialise la ligne disponible
 * correspondant à SA rencontre précise, dans cet ordre :
 *   1. Ligne disponible exacte (même Id_JA + Id_Rencontre), quelle que soit la Reponse
 *      (couvre aussi bien "Partiel + coché" (O) que l'auto-désignation JA (P)).
 *   2. Sinon, ligne "disponible toute la journée" (Id_Rencontre NULL, Reponse='O',
 *      même date) → une ligne précise est matérialisée (Reponse='O') pour cette rencontre.
 *   3. Sinon (nomination créée sans aucune déclaration de disponibilité, cas historique
 *      possible côté nominateur) → une ligne disponible est matérialisée rétroactivement
 *      (Reponse='O'), pour ne pas perdre l'historique de nomination déjà validé/facturé.
 *
 * Script idempotent : peut être relancé sans risque, ne retraite que ce qui manque encore.
 *
 * Usage : ouvrir ce fichier dans un navigateur sur le serveur cible
 * (ex: https://monsite/migration_nomination_disponible.php) puis le supprimer une fois effectué.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getPDO();
    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n\n";

    // ── 1. Ajout colonne Id_Disponible (nullable pour le temps du backfill) ─────
    $cols = array_column($pdo->query('SHOW COLUMNS FROM nomination')->fetchAll(), 'Field');
    if (!in_array('Id_Disponible', $cols)) {
        $pdo->exec("ALTER TABLE nomination ADD COLUMN Id_Disponible INT NULL AFTER Id_Rencontre");
        echo "[1] nomination.Id_Disponible : colonne ajoutée.\n";
    } else {
        echo "[1] nomination.Id_Disponible : colonne déjà présente.\n";
    }

    // ── 2. Backfill des lignes non encore associées (seulement si Id_JA existe encore) ──
    $colsActuelles = array_column($pdo->query('SHOW COLUMNS FROM nomination')->fetchAll(), 'Field');
    $aTraiter = in_array('Id_JA', $colsActuelles) ? $pdo->query("
        SELECT n.Id_Nomination, n.Id_JA, n.Id_Rencontre, n.DateNomination, r.Date AS DateRencontre
        FROM nomination n
        LEFT JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
        WHERE n.Id_Disponible IS NULL
    ")->fetchAll() : [];

    $nbExact = 0; $nbJournee = 0; $nbMaterialise = 0; $nbIgnore = 0;

    foreach ($aTraiter as $n) {
        if (!$n['Id_JA'] || !$n['Id_Rencontre']) {
            echo "  ! Nomination {$n['Id_Nomination']} : Id_JA ou Id_Rencontre manquant, ignorée (à traiter manuellement).\n";
            $nbIgnore++;
            continue;
        }

        // 2a. Ligne disponible exacte (même JA + même rencontre), quelle que soit Reponse
        $stmt = $pdo->prepare("SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ? LIMIT 1");
        $stmt->execute([$n['Id_JA'], $n['Id_Rencontre']]);
        $idDispo = $stmt->fetchColumn();

        if ($idDispo) {
            $nbExact++;
        } else {
            // 2b. Ligne "disponible toute la journée" (Id_Rencontre NULL, Reponse='O', même date)
            $idDispo = null;
            if ($n['DateRencontre']) {
                $stmtJ = $pdo->prepare("
                    SELECT Id_Disponible, DateReponse FROM disponible
                    WHERE Id_JA = ? AND Id_Rencontre IS NULL AND DateCompetition = ? AND Reponse = 'O'
                    LIMIT 1
                ");
                $stmtJ->execute([$n['Id_JA'], $n['DateRencontre']]);
                $journee = $stmtJ->fetch();
                if ($journee) {
                    $pdo->prepare("
                        INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
                        VALUES (?, ?, ?, 'O', ?)
                    ")->execute([$n['Id_JA'], $n['Id_Rencontre'], $n['DateRencontre'], $journee['DateReponse']]);
                    $idDispo = (int)$pdo->lastInsertId();
                    $nbJournee++;
                }
            }

            // 2c. Aucune déclaration de disponibilité trouvée : matérialisation rétroactive
            if (!$idDispo) {
                $dateReponse = $n['DateNomination'] ?: date('Y-m-d');
                $pdo->prepare("
                    INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
                    VALUES (?, ?, ?, 'O', ?)
                ")->execute([$n['Id_JA'], $n['Id_Rencontre'], $n['DateRencontre'], $dateReponse]);
                $idDispo = (int)$pdo->lastInsertId();
                $nbMaterialise++;
            }
        }

        $pdo->prepare("UPDATE nomination SET Id_Disponible = ? WHERE Id_Nomination = ?")
            ->execute([$idDispo, $n['Id_Nomination']]);
    }

    echo "[2] Backfill : $nbExact ligne(s) déjà disponible exacte, $nbJournee matérialisée(s) depuis dispo journée, "
       . "$nbMaterialise matérialisée(s) rétroactivement, $nbIgnore ignorée(s).\n";

    // ── 3. Vérification avant de verrouiller le schéma ──────────────────────────
    $reste = (int)$pdo->query("SELECT COUNT(*) FROM nomination WHERE Id_Disponible IS NULL")->fetchColumn();
    if ($reste > 0) {
        echo "\n$reste nomination(s) sans Id_Disponible (voir lignes ignorées ci-dessus).\n";
        echo "Le schéma n'est PAS verrouillé (Id_JA reste en place) tant que ces lignes ne sont pas résolues.\n";
        exit;
    }

    // ── 4. Verrouillage du schéma : NOT NULL + UNIQUE + FK, puis suppression Id_JA ──
    $col = $pdo->query("SHOW COLUMNS FROM nomination LIKE 'Id_Disponible'")->fetch();
    if (stripos($col['Null'], 'YES') !== false || $col['Null'] === 'YES') {
        $pdo->exec("ALTER TABLE nomination MODIFY COLUMN Id_Disponible INT NOT NULL");
        echo "[4] nomination.Id_Disponible : passé en NOT NULL.\n";
    } else {
        echo "[4] nomination.Id_Disponible : déjà NOT NULL.\n";
    }

    $indexes = array_column($pdo->query('SHOW INDEX FROM nomination')->fetchAll(), 'Key_name');
    if (!in_array('uq_nomination_disponible', $indexes)) {
        $pdo->exec("ALTER TABLE nomination ADD UNIQUE KEY uq_nomination_disponible (Id_Disponible)");
        echo "[4] uq_nomination_disponible : contrainte UNIQUE ajoutée.\n";
    } else {
        echo "[4] uq_nomination_disponible : déjà présente.\n";
    }

    $fkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination'
          AND CONSTRAINT_NAME = 'fk_nomination_disponible' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $fkStmt->execute();
    if ((int)$fkStmt->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE nomination
            ADD CONSTRAINT fk_nomination_disponible FOREIGN KEY (Id_Disponible)
            REFERENCES disponible(Id_Disponible) ON DELETE RESTRICT ON UPDATE CASCADE
        ");
        echo "[4] fk_nomination_disponible : FK ajoutée.\n";
    } else {
        echo "[4] fk_nomination_disponible : déjà présente.\n";
    }

    // Suppression de l'ancienne FK / UNIQUE / colonne Id_JA
    if (in_array('fk_nomination_ja', array_column($pdo->query("
        SELECT CONSTRAINT_NAME AS Key_name FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'nomination' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ")->fetchAll(), 'Key_name'))) {
        $pdo->exec("ALTER TABLE nomination DROP FOREIGN KEY fk_nomination_ja");
        echo "[4] fk_nomination_ja : supprimée.\n";
    } else {
        echo "[4] fk_nomination_ja : déjà absente.\n";
    }

    $indexes = array_column($pdo->query('SHOW INDEX FROM nomination')->fetchAll(), 'Key_name');
    if (in_array('uq_ja_renc', $indexes)) {
        $pdo->exec("ALTER TABLE nomination DROP INDEX uq_ja_renc");
        echo "[4] uq_ja_renc : ancien index supprimé.\n";
    } else {
        echo "[4] uq_ja_renc : déjà absent.\n";
    }

    $cols = array_column($pdo->query('SHOW COLUMNS FROM nomination')->fetchAll(), 'Field');
    if (in_array('Id_JA', $cols)) {
        $pdo->exec("ALTER TABLE nomination DROP COLUMN Id_JA");
        echo "[4] nomination.Id_JA : colonne supprimée.\n";
    } else {
        echo "[4] nomination.Id_JA : déjà absente.\n";
    }

    echo "\nMigration terminée avec succès.\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "\nErreur : " . $e->getMessage() . "\n";
}
