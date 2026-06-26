<?php
/**
 * Guard : administrateur connecté.
 * Usage : require __DIR__ . '/includes/admin_required.php';
 */
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
