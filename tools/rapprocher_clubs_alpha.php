<?php
/**
 * NIJAC – Recherche les clubs FFTT réels (Id_Club numérique commençant par
 * '0') correspondant aux clubs "fantômes" créés par l'import (Id_Club
 * alphabétique) quand le nom du club de la rencontre ne matchait aucun
 * Club.EquipeNom existant (voir ImportRencontresController.php, génération
 * du code de repli : strtoupper(substr(preg_replace('/[^A-Z0-9]/i','',$nom),0,8))).
 *
 * Pour chaque club alpha, ce code EST déjà les 8 premiers caractères
 * alphanumériques de son propre Nom en majuscules (c'est comme ça qu'il a
 * été fabriqué) — le script recalcule cette même transformation sur le Nom
 * de chaque club réel pour retrouver une correspondance exacte.
 *
 * Usage : php tools/rapprocher_clubs_alpha.php
 */
require_once __DIR__ . '/../config/db.php';

function cle8(string $nom): string {
    return strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nom), 0, 8));
}

$pdo = getPDO();

$alphas = $pdo->query("SELECT Id_Club, Nom, EquipeNom FROM Club WHERE Id_Club REGEXP '[^0-9]' ORDER BY Nom")->fetchAll();
$reels  = $pdo->query("SELECT Id_Club, Nom, EquipeNom FROM Club WHERE Id_Club REGEXP '^[0-9]+$'")->fetchAll();

// Index des clubs réels par clé à 8 caractères dérivée de leur propre Nom
$indexReels = [];
foreach ($reels as $r) {
    $indexReels[cle8($r['Nom'])][] = $r;
}

echo count($alphas) . " club(s) avec Id_Club non numérique :\n\n";

foreach ($alphas as $a) {
    $cle = cle8($a['Nom']);
    $verifie = ($cle === $a['Id_Club']) ? 'OK' : "ATTENDU {$a['Id_Club']}";
    echo "{$a['Id_Club']} — {$a['Nom']} (clé recalculée : $cle [$verifie])\n";

    $candidats = $indexReels[$cle] ?? [];
    if ($candidats) {
        foreach ($candidats as $c) {
            echo "    -> correspondance exacte : {$c['Id_Club']} — {$c['Nom']}\n";
        }
    } else {
        echo "    -> aucune correspondance exacte (le nom du club réel diffère trop du nom d'équipe importé)\n";
    }
}
