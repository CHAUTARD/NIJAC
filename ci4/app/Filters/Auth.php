<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Réplique includes/auth_required.php de l'app legacy NIJAC : accès réservé
 * aux sessions authentifiées (Administrateur OU Nominateur), contrairement à
 * AdminAuth.php qui exige en plus is_admin. Un rôle JA est redirigé vers son
 * unique écran autorisé (E030 — InfoRencontreController).
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class Auth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        demarrerSessionNijac();

        $utilisateur = $_SESSION['utilisateur'] ?? null;

        if (!$utilisateur) {
            return redirect()->to(site_url('login'));
        }

        if (($utilisateur['role'] ?? '') === 'JA') {
            return redirect()->to(site_url('info-rencontre'));
        }

        session_write_close();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
