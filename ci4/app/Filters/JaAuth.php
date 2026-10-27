<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accès réservé aux sessions authentifiées avec le rôle « JA » : compte JA pur (convention Id_Utilisateur =
 * ja.Id_JA) ou compte non-JA en mode JA (fiche liée utilisateur.Id_Ja, POST changer-compte) —
 * fiche résolue par idJaCompteSession().
 * Utilisé par E011 (Menu JA) et ses écrans EJA2, EJA3, EJA4 (routes `ja-menu`, `ja/...`).
 *
 * Contrairement à "csrauth" / "defiscauth" / "craconvocauth", l'Administrateur n'a PAS accès :
 * ces écrans affichent et modifient les données d'UN JA précis (celui de la session, jamais un id
 * fourni par le client) — un administrateur n'a pas de fiche ja, il passe par EN11 / EN14 / EN28.
 *
 * Compte JA sans fiche ja de même Id (fiche supprimée) : session vidée (nouvel id) + retour à
 * E001 avec un message clair (MSG_COMPTE_JA_SANS_FICHE). Panne SQL pendant cette vérification : journalisée,
 * session conservée, réponse 503 + Retry-After (JSON {ok:false} en AJAX / POST). Session native — voir AdminAuth.php.
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

        // Fiche de la session : idJaCompteSession() (compte JA pur : Id_Utilisateur = ja.Id_JA ; mode JA :
        // 'id_ja' = fiche liée au compte, lien relu en base).
        // Panne SQL / base injoignable (exception relancée) ≠ fiche absente (0) : pas de déconnexion,
        // réponse 503 temporaire (Retry-After), la session reste intacte pour la requête suivante.
        try {
            $ficheOk = idJaCompteSession(getPDO(), true) > 0;
        } catch (\Throwable $e) {
            error_log('[NIJAC] jaauth : vérification de la fiche JA impossible (Id_Utilisateur ' . (int) ($u['id'] ?? 0)
                . ') : ' . $e->getMessage());
            session_write_close();
            $msg = 'Service momentanément indisponible : réessayez dans quelques instants.';
            $rep = service('response')->setStatusCode(503)->setHeader('Retry-After', '30')
                ->setHeader('Cache-Control', 'no-store');
            $ajax = method_exists($request, 'isAJAX') && $request->isAJAX();
            if ($ajax || strtoupper($request->getMethod()) !== 'GET') {
                return $rep->setJSON(['ok' => false, 'msg' => $msg]);
            }

            return $rep->setContentType('text/plain', 'UTF-8')->setBody($msg);
        }
        if (!$ficheOk) {
            error_log('[NIJAC][SEC] jaauth : session JA sans fiche valide (Id_Utilisateur ' . (int) ($u['id'] ?? 0)
                . (estSessionModeJa($u) ? ', mode JA' : '') . ')');
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
