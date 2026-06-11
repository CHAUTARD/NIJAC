<?php
/**
 * NIJAC – Déconnexion (E099)
 *
 * Détruit la session en cours et redirige vers la page de connexion (E001).
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
session_unset();
session_destroy();
header('Location: index.php');
exit;
