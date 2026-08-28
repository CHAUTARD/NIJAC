<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Réplique includes/auth_required.php de l'app legacy NIJAC : accès réservé
 * aux sessions authentifiées (Administrateur, Nominateur ou CSR), contrairement
 * à AdminAuth.php qui exige en plus is_admin. Le rôle JA n'a plus de session
 * (voir AuthController) : son unique écran (EN20) est public, tokenisé.
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

        session_write_close();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé juste après
        // les filtres "after" pour toute réponse HTML non-AJAX, donc à chaque F5)
        // de démarrer le service Session de CI4 : celui-ci force
        // session.use_strict_mode=1, et comme son FileHandler ne reconnaît pas le
        // fichier de la session native (format "sess_<id>" vs "PHPSESSID<id>"),
        // PHP régénère un nouvel ID de session et réémet le cookie PHPSESSID — la
        // requête AJAX suivante (ex: chargerListe()) part alors avec un ID sans
        // $_SESSION['utilisateur'], et le filtre la redirige vers /login (mal
        // interprété côté JS comme une "erreur réseau"). storePreviousURL() ne
        // touche à rien si $_SESSION n'est plus défini.
        unset($_SESSION);
    }
}
