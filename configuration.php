<?php
/**
 * NIJAC – Configuration générale de l'application (E015)
 *
 * Écran en cours de développement. Permettra de modifier les paramètres
 * généraux de l'application (saison active, département, règles de nomination…).
 * En attendant, redirige vers la page encours.php (E013).
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
// Si encours.php manque, le site s'arrête net pour éviter des erreurs de sécurité
require('encours.php');
echo "Le fichier encours.php est requis pour le bon fonctionnement du site.";
?>