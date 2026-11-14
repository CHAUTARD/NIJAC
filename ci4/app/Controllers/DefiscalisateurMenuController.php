<?php

namespace App\Controllers;

/**
 * NIJAC – Menu Défiscalisateur (E005), rôle Defiscalisateur.
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur']. Protégé par le filtre "defiscauth"
 * (rôle Defiscalisateur ou Administrateur — voir DefiscalisateurAuth.php). Même structure que
 * CsrMenuController (E004).
 */
class DefiscalisateurMenuController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
            // Boutons de barre vers les autres menus accessibles (rôle correspondant ou administrateur).
            'menuCsrVisible' => estAdmin($u) || aRole(['CSR'], $u),
            'menuCraVisible' => estAdmin($u) || aRole(['CRA Convoc'], $u),
            'menuSwitchTo'   => estAdmin($u) ? 'admin' : (aRole(['Nominateur'], $u) ? 'nominateur' : null),
        ];

        return view('defiscalisateur_menu_index', $data);
    }
}
