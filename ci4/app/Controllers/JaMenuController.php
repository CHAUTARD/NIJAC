<?php

namespace App\Controllers;

/**
 * NIJAC – Menu JA (E011), rôle « JA » (compte JA pur dont la fiche ja est utilisateur.Id_Ja, ou
 * compte non-JA en mode JA sur sa fiche liée — bouton « Revenir à mon compte … »). Protégé par le filtre "jaauth" (rôle JA uniquement, l'Administrateur n'y a PAS
 * accès — voir JaAuth.php). Boutons : EJA2 Mes disponibilités, EJA3 Mes nominations, EJA4 Mes frais,
 * E006 Changer le mot de passe, Se déconnecter. Seul accès BDD : idJaCompteSession() (garde-fou fiche).
 */
class JaMenuController extends BaseController
{
    public function index()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        // Garde-fou propre au contrôleur, en plus de "jaauth" : rôle JA ET fiche du compte (Id_Ja).
        if (idJaCompteSession() <= 0) {
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        return view('ja_menu_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            // Mode JA d'un compte non-JA (fiche liée, POST changer-compte) : bouton de retour.
            'modeJa'      => estSessionModeJa($u),
            'libelleRetour' => self::libelleRetour($u),
        ]);
    }

    /**
     * Libellé du bouton de retour du mode JA : « Revenir à mon compte <rôle d'origine> » (libellé du rôle
     * principal d'origine en minuscules, repli « nominateur »). Affichage seulement : le retour relit les
     * rôles en base (ChangerCompteController).
     */
    public static function libelleRetour(array $u): string
    {
        $role = (string) ($u['origine_role'] ?? '');
        $lib  = 'nominateur';
        if ($role !== '') {
            try {
                $lib = mb_strtolower(libellesRoles(getPDO())[$role] ?? $role);
            } catch (\Throwable $e) {
                $lib = mb_strtolower($role);
            }
        }

        return 'Revenir à mon compte ' . $lib;
    }
}
