<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle « JA » (juge-arbitre disposant d'un compte),
 * dont l'identifiant est celui d'une fiche ja (convention Id_Utilisateur = ja.Id_JA, sans colonne de lien).
 * Utilisé par E011 (Menu JA) et ses écrans EJA2, EJA3, EJA4 (routes `ja-menu`, `ja/...`).
 *
 * Contrairement à "csrauth" / "defiscauth" / "craconvocauth", l'Administrateur n'a PAS accès :
 * ces écrans affichent et modifient les données d'UN JA précis (celui de la session, jamais un id
 * fourni par le client) — un administrateur n'a pas de fiche ja, il passe par EN11 / EN14 / EN28.
 *
 * Compte JA sans fiche ja de même Id (fiche supprimée) : session vidée (nouvel id) + retour à
 * E001 avec un message clair (MSG_COMPTE_JA_SANS_FICHE). Session native — voir AdminAuth.php.
 */
class JaAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Session + revalidation en base (dont la fiche ja) + changement de mot de passe forcé (voir Auth.php).
        if ($cible = controlerSessionFiltre(method_exists($request, 'getPath') ? $request->getPath() : '')) {
            return redirect()->to(site_url($cible));
        }
        $u = $_SESSION['utilisateur'];

        // Multi-rôles : il faut le rôle JA parmi les rôles de la session ; Administrateur seul refusé.
        if (!aRole('JA')) {
            session_write_close();
            return redirect()->to(site_url('login'));
        }

        $idJa = (int) ($u['id'] ?? 0); // convention Id_Utilisateur = ja.Id_JA
        try {
            $ficheOk = ficheJaExiste(getPDO(), $idJa);
        } catch (\Throwable $e) {
            error_log('[NIJAC] jaauth : vérification de la fiche JA impossible : ' . $e->getMessage());
            $ficheOk = $idJa > 0; // base injoignable : l'écran échouera proprement de lui-même
        }
        if (!$ficheOk) {
            error_log('[NIJAC][SEC] jaauth : compte JA sans fiche valide (Id_Utilisateur ' . (int) ($u['id'] ?? 0) . ')');
            // Déconnexion : plus aucune donnée d'authentification, nouvel id ; seul le message
            // destiné à E001 (AuthController::index(), clé login_message) est conservé.
            $_SESSION = ['login_message' => MSG_COMPTE_JA_SANS_FICHE];
            session_regenerate_id(true);
            session_write_close();
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
