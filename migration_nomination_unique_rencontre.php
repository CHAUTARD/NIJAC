<?php
/**
 * NIJAC – Migration : une seule nomination par rencontre
 *
 * Jusqu'ici rien n'empêchait deux JA différents d'être nominés sur la même
 * rencontre (deux lignes `nomination` avec le même Id_Rencontre) : l'ancien
 * "ON DUPLICATE KEY UPDATE" ne se déclenchait que si le même JA était réaffecté.
 * Cette migration ajoute une contrainte UNIQUE sur nomination.Id_Rencontre.
 *
 * Avant de verrouiller le schéma, on résout les doublons existants :
 *   - Si un seul des doublons a des frais saisis (Peage/Kilometre/Rapports),
 *     on le garde et on supprime les autres (considérés comme des affectations
 *     obsolètes jamais traitées).
 *   - Si aucun n'a de frais saisis, on garde le plus récent (Id_Nomination le
 *     plus élevé) et on supprime les autres.
 *   - Si PLUSIEURS doublons ont des frais saisis, on ne touche à rien pour ce
 *     groupe (ambigu, nécessite un arbitrage manuel) et la contrainte n'est
 *     pas posée tant qu'il en reste.
 *
 * Script idempotent : peut être relancé sans risque.
 *
 * Usage : ouvrir ce fichier dans un navigateur sur le serveur cible
 * (ex: https://monsite/migration_nomination_unique_rencontre.php) puis le
 * supprimer une fois effectué.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = getPDO();
    echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n\n";

    // ── 1. Détection et résolution des doublons ─────────────────────────────
    $groupes = $pdo->query("
        SELECT Id_Rencontre FROM nomination
        WHERE Id_Rencontre IS NOT NULL
        GROUP BY Id_Rencontre HAVING COUNT(*) > 1
    ")->fetchAll(\PDO::FETCH_COLUMN);

    $nbSupprimees = 0; $nbAmbigues = 0;

    foreach ($groupes as $idRenc) {
        $stmt = $pdo->prepare("SELECT * FROM nomination WHERE Id_Rencontre = ? ORDER BY Id_Nomination DESC");
        $stmt->execute([$idRenc]);
        $lignes = $stmt->fetchAll();

        $avecFrais = array_filter($lignes, function ($l) {
            return (float)$l['Peage'] > 0 || (int)$l['Kilometre'] > 0
                || trim((string)$l['RapportAccueil']) !== '' || trim((string)$l['RapportEquipements']) !== '';
        });

        if (count($avecFrais) > 1) {
            echo "  ! Rencontre $idRenc : " . count($avecFrais) . " nominations ont des frais saisis, ambigu — ignoré (arbitrage manuel requis).\n";
            $nbAmbigues++;
            continue;
        }

        $aGarder = count($avecFrais) === 1 ? reset($avecFrais) : $lignes[0]; // sinon la plus récente
        foreach ($lignes as $l) {
            if ($l['Id_Nomination'] == $aGarder['Id_Nomination']) continue;
            $pdo->prepare("DELETE FROM nomination WHERE Id_Nomination = ?")->execute([$l['Id_Nomination']]);
            $nbSupprimees++;
        }
        echo "  Rencontre $idRenc : conservé Id_Nomination {$aGarder['Id_Nomination']}, doublon(s) supprimé(s).\n";
    }

    echo "[1] Doublons : " . count($groupes) . " rencontre(s) concernée(s), $nbSupprimees ligne(s) supprimée(s), $nbAmbigues ambiguë(s) laissée(s) en l'état.\n";

    // ── 2. Vérification avant verrouillage ──────────────────────────────────
    $reste = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT Id_Rencontre FROM nomination WHERE Id_Rencontre IS NOT NULL
            GROUP BY Id_Rencontre HAVING COUNT(*) > 1
        ) t
    ")->fetchColumn();
    if ($reste > 0) {
        echo "\n$reste rencontre(s) encore en doublon (voir lignes ambiguës ci-dessus).\n";
        echo "La contrainte UNIQUE n'est PAS posée tant qu'elles ne sont pas résolues manuellement.\n";
        exit;
    }

    // ── 3. Contrainte UNIQUE sur Id_Rencontre ───────────────────────────────
    $indexes = array_column($pdo->query('SHOW INDEX FROM nomination')->fetchAll(), 'Key_name');
    if (!in_array('uq_nomination_rencontre', $indexes)) {
        $pdo->exec("ALTER TABLE nomination ADD UNIQUE KEY uq_nomination_rencontre (Id_Rencontre)");
        echo "[3] uq_nomination_rencontre : contrainte UNIQUE ajoutée.\n";
    } else {
        echo "[3] uq_nomination_rencontre : déjà présente.\n";
    }

    echo "\nMigration terminée avec succès.\n";

} catch (\Throwable $e) {
    http_response_code(500);
    echo "\nErreur : " . $e->getMessage() . "\n";
}
