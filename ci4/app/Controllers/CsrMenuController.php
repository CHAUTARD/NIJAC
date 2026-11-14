<?php

namespace App\Controllers;

/**
 * NIJAC – Menu CSR (E004), rôle Commission Sportive Régionale.
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur']. Protégé par le filtre "csrauth"
 * (rôle CSR ou Administrateur — voir CsrAuth.php).
 */
class CsrMenuController extends BaseController
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
            'menuDefiscVisible' => estAdmin($u) || aRole(['Defiscalisateur'], $u),
            'menuCraVisible'    => estAdmin($u) || aRole(['CRA Convoc'], $u),
            'menuSwitchTo'      => estAdmin($u) ? 'admin' : (aRole(['Nominateur'], $u) ? 'nominateur' : null),
        ];

        return view('csr_menu_index', $data);
    }
}
