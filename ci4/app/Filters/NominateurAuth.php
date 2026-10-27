<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle Nominateur ou
 * Administrateur (rôle Administrateur ou is_admin) — même logique que "csrauth"
 * / "defiscauth". Contrairement à "auth", les rôles CSR (limité au menu E004),
 * Defiscalisateur et CRA Convoc sont refusés. Utilisé par EN14 (Nomination des
 * Juges-Arbitres, routes nomination*), EN26 (Statistiques des nominations,
 * routes stats-nomination*) et EN28 (Suivi des nominations, routes
 * suivi-nomination*).
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class NominateurAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Session + revalidation en base + changement de mot de passe forcé (voir Auth.php).
        if ($cible = controlerSessionFiltre(method_exists($request, 'getPath') ? $request->getPath() : '')) {
            return redirect()->to(site_url($cible));
        }
        if (!estAdmin() && !aRole('Nominateur')) {
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
