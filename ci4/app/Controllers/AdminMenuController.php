<?php

namespace App\Controllers;

/**
 * NIJAC – Menu paramètres administrateur (E002), portage CI4 de admin_menu.php.
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur'], déjà peuplée par
 * AuthController/index.php. Protégé par le filtre "adminauth" (routes).
 */
class AdminMenuController extends BaseController
{
    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
            'isChautard'  => ($u['login'] ?? '') === 'CHAUTARD',
        ];

        return view('admin_menu_index', $data);
    }
}
