<?php

namespace App\Controllers;

/**
 * NIJAC – Déconnexion, portage CI4 de logout.php.
 *
 * Détruit la session en cours et redirige vers la page de connexion (E001).
 * Pas de filtre "auth" : appelable même si la session est déjà expirée, comme
 * le fait le fichier legacy (aucun garde, juste session_start + destroy).
 */
class LogoutController extends BaseController
{
    public function index()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_unset();
        session_destroy();

        return redirect()->to(site_url('login'));
    }
}
