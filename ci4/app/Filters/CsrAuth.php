<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle CSR (Commission Sportive Régionale),
 * ou Administrateur (pour permettre à un admin de prévisualiser/tester les écrans CSR — même
 * logique que "auth" qui laisse passer Administrateur en plus de Nominateur). Utilisé par
 * E034 (Menu CSR) et E035 (Club CSR).
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class CsrAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        demarrerSessionNijac();

        $utilisateur = $_SESSION['utilisateur'] ?? null;
        $role        = $utilisateur['role'] ?? '';

        if (!$utilisateur || !in_array($role, ['CSR', 'Administrateur'], true)) {
            return redirect()->to(site_url('login'));
        }

        session_write_close();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
