<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Réplique includes/admin_required.php de l'app legacy NIJAC : accès réservé
 * aux sessions authentifiées avec $_SESSION['utilisateur']['is_admin'] vrai.
 *
 * IMPORTANT : démarre la session PHP nativement (session_start()) plutôt que
 * via le service Session de CI4. Le FileHandler de CI4 nomme ses fichiers de
 * session "PHPSESSID<id>" (sans préfixe "sess_"), un format propre à CI4 et
 * incompatible avec celui de PHP natif ("sess_<id>") qu'utilise l'app legacy
 * — même dossier de sauvegarde, mais fichiers différents, donc aucun partage
 * réel malgré une config Session.php alignée. session_start() natif lit/écrit
 * exactement les mêmes fichiers que l'app legacy.
 */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        require_once __DIR__ . '/../../../config/db.php';
        demarrerSessionNijac();

        $utilisateur = $_SESSION['utilisateur'] ?? null;

        if (!$utilisateur || empty($utilisateur['is_admin'])) {
            return redirect()->to(site_url('login'));
        }

        // Referme proprement l'écriture de la session native maintenant qu'on a
        // lu ce qu'il fallait. $_SESSION reste lisible en tant que tableau PHP
        // normal pour le contrôleur, mais session_status() redevient NONE.
        // Sans ça, CI4 déclenche automatiquement son propre service Session en
        // fin de requête pour les réponses HTML (storePreviousURL(), pour la
        // fonctionnalité "revenir à l'URL précédente"), et son FileHandler
        // plante en tentant des ini_set() sur une session déjà active.
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
