<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Force l'accès à l'app CI4 via le seul vhost "nijac" (docroot pointant
 * directement sur le dossier NIJAC). D'autres vhosts WAMP (ex. "localhost",
 * dont le docroot est le dossier www/ parent, donc NIJAC en sous-dossier)
 * servent accidentellement le même code, mais tous les chemins racine-
 * relatifs de l'appli (/asset/…, /img/…, /Nominateur/menu.php…) et le cookie
 * de session native supposent le docroot de "nijac" — d'où un décalage de
 * cookie qui casse la vérification CSRF (formulaire posté vers "nijac" sans
 * le cookie de session émis sous "localhost").
 *
 * Plutôt que de réécrire tous ces chemins pour être multi-hôtes, on redirige
 * une fois vers l'URL équivalente sur "nijac" dès qu'un autre host est
 * détecté, avant tout traitement de route.
 *
 * `current_url(true)` reflète déjà "nijac" (voir Config\App::$allowedHostnames,
 * volontairement laissé à défaut) : SiteURIFactory ne valide que les hôtes
 * listés là, donc un host non reconnu comme "localhost" est ignoré et l'URI
 * générée retombe sur l'hôte configuré dans Config\App::$baseURL.
 */
class CanonicalHost implements FilterInterface
{
    private const CANONICAL_HOST = 'nijac';

    public function before(RequestInterface $request, $arguments = null)
    {
        $host = $request->getServer('HTTP_HOST') ?? '';
        [$host] = explode(':', $host, 2);

        if ($host === '' || strcasecmp($host, self::CANONICAL_HOST) === 0) {
            return null;
        }

        return redirect()->to(current_url(true));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
