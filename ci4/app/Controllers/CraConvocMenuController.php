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
    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
        ];

        return view('cra_convoc_menu_index', $data);
    }
}
