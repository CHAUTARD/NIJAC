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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $utilisateur = $_SESSION['utilisateur'] ?? null;

        if (!$utilisateur || empty($utilisateur['is_admin'])) {
            return redirect()->to('http://nijac/ci4/public/login');
        }

        // Garantit qu'un token CSRF existe et est persisté AVANT de fermer la
        // session. Sans ça, un contrôleur qui appelle csrfToken()/csrfField()
        // après ce filtre (donc après session_write_close()) le générerait en
        // mémoire seulement, jamais écrit sur disque — chaque requête suivante
        // en recréerait un différent et toute vérification CSRF échouerait en
        // boucle. Cas réel : une session fraîchement créée par AuthController
        // (qui fait session_unset() à la connexion, effaçant l'ancien token)
        // n'a plus aucun csrf_token persisté avant son premier passage ici.
        require_once __DIR__ . '/../../../config/csrf.php';
        csrfToken();

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
        // no-op
    }
}
