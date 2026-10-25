<?php

namespace App\Controllers;

/**
 * NIJAC – Déconnexion, portage CI4 de logout.php.
 *
 * POST logout (filtre csrf global) : détruit la session en cours — données, fichier et cookie
 * (detruireSessionNijac()) — puis redirige vers la page de connexion (E001). Les liens « Se
 * déconnecter » des menus et le bouton « Annuler » d'E010 soumettent un petit formulaire POST.
 * Pas de filtre "auth" : appelable même si la session est déjà expirée.
 *
 * GET logout (anciens favoris) : ne détruit RIEN (une déconnexion par simple GET serait
 * déclenchable par un lien ou une image tiers) et redirige vers E001, qui renvoie une session
 * encore ouverte vers son menu.
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
        detruireSessionNijac();

        return redirect()->to(site_url('login'));
    }

    /** GET logout : redirection simple vers E001, la session n'est pas touchée. */
    public function rediriger()
    {
        return redirect()->to(site_url('login'));
    }
}
