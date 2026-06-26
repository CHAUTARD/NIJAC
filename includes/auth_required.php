<?php
/**
 * Guard : utilisateur connecté.
 * Usage : require __DIR__ . '/includes/auth_required.php';
 * Pour les pages Nominateur/ : définir $authRedirect = '../index.php' avant l'include.
 */
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ' . ($authRedirect ?? 'index.php'));
    exit;
}
