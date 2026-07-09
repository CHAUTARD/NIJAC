<?php

namespace App\Controllers;

/**
 * NIJAC – Déconnexion, portage CI4 de logout.php.
 *
 * Détruit la session en cours et redirige vers la page de connexion (E001).
 * Pas de filtre "auth" : appelable même si la session est déjà expirée, comme
 * le fait le fichier legacy (aucun garde, juste démarrage + destroy).
 */
class LogoutController extends BaseController
{
    public function index()
    {
        require_once __DIR__ . '/../../../config/db.php';

        // demarrerSessionNijac() (pas session_start() brut) : doit ouvrir la
        // session dans le même sous-dossier dédié (nijac_sessions) que le
        // reste de l'app (filtres Auth/AdminAuth, AuthController). Sinon
        // session_destroy() détruit un fichier vide dans le mauvais dossier,
        // la vraie session reste intacte, et /login (qui rouvre la session
        // correctement) voit l'utilisateur toujours connecté et le renvoie
        // vers son menu au lieu du formulaire de connexion.
        demarrerSessionNijac();

        session_unset();
        session_destroy();

        return redirect()->to(site_url('login'));
    }
}
