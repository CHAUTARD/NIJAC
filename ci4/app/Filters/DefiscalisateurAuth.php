<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle Defiscalisateur, ou
 * Administrateur (pour permettre à un admin de prévisualiser/tester les écrans
 * Défiscalisateur — même logique que "csrauth" pour le rôle CSR). Utilisé par
 * E005 (Menu Défiscalisateur) et ED51 (Défiscalisation JA).
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class DefiscalisateurAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Session + revalidation en base + changement de mot de passe forcé (voir Auth.php).
        if ($cible = controlerSessionFiltre(method_exists($request, 'getPath') ? $request->getPath() : '')) {
            return redirect()->to(site_url($cible));
        }
        $role = $_SESSION['utilisateur']['role'] ?? '';

        if (!in_array($role, ['Defiscalisateur', 'Administrateur'], true)) {
            return redirect()->to(site_url('login'));
        }

        session_write_close();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Voir Auth.php pour le détail : empêche CodeIgniter\CodeIgniter::storePreviousURL()
        // de démarrer le service Session de CI4 (session.use_strict_mode=1 forcé),
        // qui régénère sinon le cookie PHPSESSID et casse la requête AJAX suivante.
        unset($_SESSION);
    }
}
