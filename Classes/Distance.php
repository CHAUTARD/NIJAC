<?php
function distance($lat1, $lon1, $lat2, $lon2, $unit = "K") {
    // Rayon de la Terre en km
    $earthRadius = 6378.137;

    // Conversion en radians
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    // Calcul de la différence
    $theta = $lon1 - $lon2;
    $dist = sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($theta);

    // Vérification des limites numériques (asin/acos)
    if ($dist > 1.0) { $dist = 1.0; }
    if ($dist < -1.0) { $dist = -1.0; }

    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;

    // Conversion de l'unité
    $unit = strtoupper($unit);
    if ($unit == "K") {
        return ($miles * 1.609344); // Kilomètres
    } else if ($unit == "N") {
        return ($miles * 0.8684);   // Miles nautiques
    } else {
        return $miles;              // Miles terrestres
    }
}

// Exemple d'utilisation : Paris à Lyon
//echo distance(48.8566, 2.3522, 45.7640, 4.8357, "K") . " km";

function distanceHaversineKm($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // Rayon de la Terre directement en KM

    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo = deg2rad($lat2);
    $lonTo = deg2rad($lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c; // Retourne la distance en Kilomètres
}

// Exemple
$km = distanceHaversineKm(48.8566, 2.3522, 45.7640, 4.8357);
echo round($km, 2) . " km"; // Affiche : ~392.14 km

/*
Coefficients moyens recommandés
Type de trajet	Coefficient	Détour estimé

Autoroute / Longue distance (> 90 km)	        1,10 – 1,20	+10% à +20%
Route nationale / Moyenne distance (20–90 km)	1,20 – 1,30	+20% à +30%
Zone urbaine / Ville (< 10 km)	                1,40 – 1,60	+40% à +60%
Montagne / Relief accidenté	                    1,50 – 1,80	+50% à +80%
Moyenne générale	                           ~1,30	+30%
*/

/*
Concrètement :

Pour 1 km : Coeff ≈ 1,40
Pour 8 km : Coeff ≈ 1,30
Pour 20 km : Coeff ≈ 1,20
Pour > 90 km : Coeff ≈ 1,10
*/
function distanceRouteEstimee($lat1, $lon1, $lat2, $lon2) {
    // 1. Calcul de la distance à vol d'oiseau (Haversine) en km
    $earthRadius = 6371;
    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo = deg2rad($lat2);
    $lonTo = deg2rad($lon2);
    
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distanceOiseau = $earthRadius * $c;

    // 2. Application du coefficient dynamique (Formule ADEME)
    $coefficient = 1.1 + 0.3 * exp(-$distanceOiseau / 20);
    
    // Optionnel : Plafonner le coefficient à 1.6 pour les très courts trajets urbains
    if ($coefficient > 1.6) $coefficient = 1.6;

    return $distanceOiseau * $coefficient;
}

// Exemple : Paris -> Lyon (environ 392 km à vol d'oiseau)
$kmRoute = distanceRouteEstimee(48.8566, 2.3522, 45.7640, 4.8357);
echo "Distance estimée par route : " . round($kmRoute, 1) . " km";
// Résultat attendu : ~430-440 km (Réel ~460 km via A6/A7, l'estimation reste approximative)
?>     