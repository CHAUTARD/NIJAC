<?php

namespace App\Controllers;

/**
 * NIJAC – Menu CRA Convoc (E009), rôle « CRA Convoc ».
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur']. Protégé par le filtre "craconvocauth"
 * (rôle CRA Convoc ou Administrateur — voir CraConvocAuth.php). Même structure que
 * DefiscalisateurMenuController (E005).
 */
class CraConvocMenuController extends BaseController
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
            'menuCsrVisible'    => estAdmin($u) || aRole(['CSR'], $u),
            'menuDefiscVisible' => estAdmin($u) || aRole(['Defiscalisateur'], $u),
            'menuSwitchTo'      => estAdmin($u) ? 'admin' : (aRole(['Nominateur'], $u) ? 'nominateur' : null),
        ];

        return view('cra_convoc_menu_index', $data);
    }
}
