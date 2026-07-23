<?php

namespace App\Controllers;

/**
 * NIJAC – Menu CSR (E034), rôle Commission Sportive Régionale.
 *
 * Pas d'accès BDD : lit uniquement $_SESSION['utilisateur']. Protégé par le filtre "csrauth"
 * (rôle CSR ou Administrateur — voir CsrAuth.php).
 */
class CsrMenuController extends BaseController
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

        return view('csr_menu_index', $data);
    }
}
