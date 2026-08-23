<?php

namespace App\Controllers;

/**
 * NIJAC – Menu Défiscalisateur (E038), rôle Defiscalisateur.
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur']. Protégé par le filtre "defiscauth"
 * (rôle Defiscalisateur ou Administrateur — voir DefiscalisateurAuth.php). Même structure que
 * CsrMenuController (E034).
 */
class DefiscalisateurMenuController extends BaseController
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

        return view('defiscalisateur_menu_index', $data);
    }
}
