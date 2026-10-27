<?php

namespace App\Controllers;

/**
 * NIJAC – Menu JA (E011), rôle « JA » (juge-arbitre disposant d'un compte dont l'Id_Utilisateur est
 * l'Id_JA de sa fiche ja). Protégé par le filtre "jaauth" (rôle JA uniquement, l'Administrateur n'y a PAS
 * accès — voir JaAuth.php). Boutons : EJA2 Mes disponibilités, EJA3 Mes nominations, EJA4 Mes frais,
 * E006 Changer le mot de passe, Se déconnecter. Seul accès BDD : idJaCompteSession() (garde-fou fiche).
 */
class JaMenuController extends BaseController
{
    public function index()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        // Garde-fou propre au contrôleur, en plus de "jaauth" : rôle JA ET fiche de même Id.
        if (idJaCompteSession() <= 0) {
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        return view('ja_menu_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            // Session basculée depuis un compte non-JA (POST changer-compte) : bouton de retour.
            'origineId'   => (int) ($u['origine_id'] ?? 0),
        ]);
    }
}
