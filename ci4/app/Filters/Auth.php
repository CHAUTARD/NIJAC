<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Réplique includes/auth_required.php de l'app legacy NIJAC : écrans du menu
 * nominateur (E003) et routes partagées. Sans argument, seuls les rôles
 * Administrateur et Nominateur (ou is_admin) passent ; les rôles CSR,
 * Defiscalisateur et CRA Convoc n'ont accès qu'aux écrans de leur menu (filtres
 * "csrauth", "defiscauth", "craconvocauth").
 *
 * Arguments de filtre CI4 = rôles SUPPLÉMENTAIRES autorisés sur une route
 * partagée dont dépend un autre menu, ex. ['filter' => 'auth:CSR,Defiscalisateur,CRA Convoc']
 * (E006 changement de mot de passe) ou ['filter' => 'auth:CRA Convoc'] (EA93 depuis E009).
 * Le contrôleur reste responsable du filtrage fin par rôle (ex. MessagerieController).
 *
 * Le rôle JA (compte juge-arbitre, menu E011) n'est jamais dans ROLES_DEFAUT : il est refusé sur
 * toute route "auth" sans argument et n'a accès qu'à ses écrans ("jaauth", voir JaAuth.php) et aux
 * routes dont l'argument le nomme (seulement changer-mot-de-passe). Les écrans publics tokenisés
 * EN19/EN21/EN22 restent accessibles aux JA sans compte, sans filtre.
 *
 * Session native — voir AdminAuth.php pour le détail de cette contrainte.
 */
class Auth implements FilterInterface
{
    /** Rôles toujours autorisés (en plus de is_admin). */
    private const ROLES_DEFAUT = ['Administrateur', 'Nominateur'];

    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Session + revalidation en base (1 fois / minute) + changement de mot de passe forcé.
        if ($cible = controlerSessionFiltre(method_exists($request, 'getPath') ? $request->getPath() : '')) {
            return redirect()->to(site_url($cible));
        }
        // Multi-rôles : droits = UNION des rôles de la session (aRole() / estAdmin(), app_config.php).
        $autorises = array_merge(self::ROLES_DEFAUT, array_map('trim', (array) ($arguments ?? [])));
        if (!estAdmin() && !aRole($autorises)) {
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
