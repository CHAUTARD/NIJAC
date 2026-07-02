<?php
/**
 * Guard : utilisateur connecté.
 * Usage : require __DIR__ . '/includes/auth_required.php';
 * Pour les pages Nominateur/ : définir $authRedirect = '../index.php' avant l'include.
 * Pour la page E030 (JA/info_rencontre.php) : définir $allowJa = true avant l'include.
 */
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ' . ($authRedirect ?? 'index.php'));
    exit;
}

// Un utilisateur au rôle JA n'a accès qu'à l'écran E030 (JA/info_rencontre.php).
if (($_SESSION['utilisateur']['role'] ?? '') === 'JA' && empty($allowJa)) {
    header('Location: ' . str_replace('index.php', 'JA/info_rencontre.php', $authRedirect ?? 'index.php'));
    exit;
}
