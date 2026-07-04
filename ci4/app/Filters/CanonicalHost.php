<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Force l'accès à l'app CI4 via le seul host configuré dans Config\App::$baseURL
 * (host canonique dérivé dynamiquement de ce baseURL, donc "nijac" en local
 * WAMP, "www.ligue-normandie-tt.fr" en production — jamais codé en dur ici,
 * pour que ce filtre reste valable sur tous les environnements sans
 * modification). D'autres vhosts (ex. "localhost", dont le docroot est le
 * dossier www/ parent, donc NIJAC en sous-dossier) servent accidentellement
 * le même code, mais tous les chemins racine-relatifs de l'appli (/asset/…,
 * /img/…) et le cookie de session native supposent le host canonique — d'où
 * un décalage de cookie qui casse la vérification CSRF (formulaire posté
 * vers le host canonique sans le cookie de session émis sous l'autre host).
 *
 * Plutôt que de réécrire tous ces chemins pour être multi-hôtes, on redirige
 * une fois vers l'URL équivalente sur le host canonique dès qu'un autre host
 * est détecté, avant tout traitement de route.
 *
 * `current_url(true)` reflète déjà le host canonique (voir Config\App::$allowedHostnames,
 * volontairement laissé à défaut) : SiteURIFactory ne valide que les hôtes
 * listés là, donc un host non reconnu comme "localhost" est ignoré et l'URI
 * générée retombe sur l'hôte configuré dans Config\App::$baseURL.
 */
class CanonicalHost implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $host = $request->getServer('HTTP_HOST') ?? '';
        [$host] = explode(':', $host, 2);

        $canonicalHost = parse_url(config('App')->baseURL, PHP_URL_HOST) ?? '';

        if ($host === '' || $canonicalHost === '' || strcasecmp($host, $canonicalHost) === 0) {
            return null;
        }

        return redirect()->to(current_url(true));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
