<?php

namespace App\Controllers;

/**
 * NIJAC – Menu paramètres administrateur (E002), portage CI4 de admin_menu.php.
 *
 * Protégé par le filtre "adminauth" (routes).
 */
class AdminMenuController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        // Badge d'expiration des identifiants API FFTT ci-dessous (le rappel par email,
        // lui, est déclenché une seule fois par connexion admin — voir AuthController::index()).
        $data = [
            'nomComplet'           => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'          => $u['id_departement'] ?? '',
            'changeLogin'          => !empty($u['change_login']),
            'isAdmin'              => !empty($u['is_admin']),
            'isChautard'           => ($u['login'] ?? '') === 'CHAUTARD',
            'ffttJoursExpiration'  => getFfttApiJoursAvantExpiration(),
        ];

        return view('admin_menu_index', $data);
    }
}
