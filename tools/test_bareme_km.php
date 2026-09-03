<?php
/**
 * Vérif rapide du barème kilométrique — sans BDD.
 * Usage : php tools/test_bareme_km.php
 */

// Stub minimal : getConfig() n'est pas appelée ici (on fournit le barème à la main).
require_once __DIR__ . '/../config/helpers.php';

$bareme = [
    'majoration' => 1.20, // +20 % électrique
    'lignes' => [
        ['Cv_Min' => 3, 'Cv_Max' => 3, 'Coef_T1' => 0.529, 'Coef_T2' => 0.316, 'Fixe_T2' => 1065, 'Coef_T3' => 0.370],
        ['Cv_Min' => 7, 'Cv_Max' => 99, 'Coef_T1' => 0.697, 'Coef_T2' => 0.394, 'Fixe_T2' => 1515, 'Coef_T3' => 0.470],
    ],
];

// Tranche 1 : d <= 5000
assert(montantBaremeKilometrique($bareme, 3, 4000, false) === round(4000 * 0.529, 2));
// Tranche 2 : 5000 < d <= 20000
assert(montantBaremeKilometrique($bareme, 3, 10000, false) === round(10000 * 0.316 + 1065, 2));
// Tranche 3 : d > 20000
assert(montantBaremeKilometrique($bareme, 3, 25000, false) === round(25000 * 0.370, 2));
// Majoration électrique
assert(montantBaremeKilometrique($bareme, 3, 4000, true) === round(4000 * 0.529 * 1.20, 2));
// Sélection de la bonne tranche de puissance (7 CV et +)
assert(montantBaremeKilometrique($bareme, 8, 4000, false) === round(4000 * 0.697, 2));
// CV absent -> null
assert(montantBaremeKilometrique($bareme, null, 4000, false) === null);
// CV hors barème (aucune ligne 4-6) -> null
assert(montantBaremeKilometrique($bareme, 5, 4000, false) === null);

echo "OK\n";
