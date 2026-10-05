<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle « CRA Convoc », ou
 * Administrateur (prévisualisation — même logique que "csrauth"/"defiscauth").
 * Utilisé par E009 (Menu CRA Convoc).
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class CraConvocAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        demarrerSessionNijac();

        $utilisateur = $_SESSION['utilisateur'] ?? null;
        $role        = $utilisateur['role'] ?? '';

        if (!$utilisateur || !in_array($role, ['CRA Convoc', 'Administrateur'], true)) {
            return redirect()->to(site_url('login'));
        }

        session_write_close();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Voir Auth.php : empêche storePreviousURL() de démarrer le service Session de CI4.
        unset($_SESSION);
    }
}
