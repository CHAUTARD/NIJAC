<?php

namespace App\Controllers;

/**
 * NIJAC – Mode JA d'un compte non-JA lié à sa fiche juge-arbitre (POST changer-compte, filtre
 * "auth:CSR,Defiscalisateur,CRA Convoc,JA" = tout rôle connecté, CSRF global).
 *
 * Une personne à la fois nominateur (ou administrateur…, rôle de Priorite < 6) et JA n'a PAS de compte
 * JA : un administrateur renseigne dans EA86 utilisateur.Id_Ja = ja.Id_JA de sa fiche
 * (n° de licence). Depuis E002 / E003, « Accéder à mon espace JA » ouvre alors E011 SANS nouvelle
 * saisie de mot de passe ni code et SANS changer de compte ; E011 propose « Revenir à mon compte … ».
 *
 *   action=ja     : fiche = idFicheJaLiee() du compte de session, relue en base (compte actif sans rôle JA,
 *                   lien renseigné, fiche existante) — jamais un id venant du client. Refusée en mode JA
 *                   ou pendant un changement de mot de passe forcé. $_SESSION['utilisateur'] garde 'id'
 *                   (Id du compte : aucune collision avec la convention Id_Utilisateur = Id_JA), le login,
 *                   le nom, le mot de passe ('pwd_version') ; reçoit 'mode_ja' = true, 'id_ja', 'role' = 'JA',
 *                   'roles' = ['JA'], 'is_admin' = false, 'origine_role' / 'origine_roles' (rôles du compte
 *                   à la bascule : affichage et revalidation, motifSessionModeJaInvalide()).
 *   action=retour : session en mode JA uniquement ; rôles d'origine RELUS en base (compte actif, même mot de
 *                   passe), jamais repris de la session ni du client : construireSessionUtilisateur()
 *                   reconstruit la session normale. Compte invalide → session détruite, retour à E001.
 *
 * Avant toute action : revalidation forcée ('verifie_le' = 0 puis revaliderSessionUtilisateur()) — compte
 * invalide → session détruite + E001 ; base injoignable → aucune bascule, retour au menu.
 * Dans les deux cas : session_regenerate_id(true), déverrouillage TOTP (EA99) oublié, journal [NIJAC][SEC]
 * (ids seulement), redirection vers menuDuRole() du nouveau rôle principal. POST logout détruit le tout.
 *
 * Session : le filtre a ouvert puis refermé la session native ; reprise par un session_start()
 * BRUT, pas demarrerSessionNijac() (voir ChangerMotDePasseController).
 */
class ChangerCompteController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $action = (string) ($this->request->getPost('action') ?? '');

        // Revalidation FORCÉE avant toute bascule (le filtre a pu la sauter, délai de 60 s) : Actif, rôles,
        // login, pwd_version, lien / fiche relus en base. Compte invalide → session détruite, E001.
        $this->reprendreSession();
        if (!empty($_SESSION['utilisateur']) && is_array($_SESSION['utilisateur'])) {
            $_SESSION['utilisateur']['verifie_le'] = 0;
        }
        $cibleRevalid = empty($_SESSION['utilisateur']) ? 'login' : revaliderSessionUtilisateur('changer-compte');
        $u            = $_SESSION['utilisateur'] ?? [];
        $revalidee    = (int) ($u['verifie_le'] ?? 0) > 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $idMoi = (int) ($u['id'] ?? 0);
        $menu  = site_url(menuDuRole((string) ($u['role'] ?? '')));

        if ($cibleRevalid === 'login' || $idMoi <= 0) {
            return redirect()->to(site_url('login'));
        }
        if (!$revalidee) {
            // Base injoignable : revalidation impossible (journalisée) → aucune bascule, session conservée.
            error_log('[NIJAC] changer-compte : revalidation impossible, bascule annulée (Id_Utilisateur ' . $idMoi . ')');
            return redirect()->to($menu);
        }

        try {
            $pdo = getPDO();

            if ($action === 'ja') {
                if (estSessionModeJa($u) || !empty($u['change_login'])) {
                    error_log('[NIJAC][SEC] changer-compte : passage en mode JA refusé (déjà en mode JA ou changement de mot de passe en attente, Id_Utilisateur ' . $idMoi . ')');
                    return redirect()->to($menu);
                }
                $idJa = idFicheJaLiee($pdo, $idMoi);
                if ($idJa <= 0) {
                    error_log('[NIJAC][SEC] changer-compte : aucune fiche JA liée utilisable (Id_Utilisateur ' . $idMoi . ')');
                    return redirect()->to($menu);
                }
                // Rôles d'origine relus en base (pas ceux de la session) : la revalidation les compare.
                $rolesOrigine = rolesUtilisateur($pdo, $idMoi);
                $nouvelle = $u;
                unset($nouvelle['origine_id'], $nouvelle['origine_pwd_version']);
                $nouvelle['mode_ja']       = true;
                $nouvelle['id_ja']         = $idJa;
                $nouvelle['origine_role']  = $rolesOrigine[0] ?? (string) ($u['role'] ?? '');
                $nouvelle['origine_roles'] = $rolesOrigine;
                $nouvelle['role']          = 'JA';
                $nouvelle['roles']         = ['JA'];
                $nouvelle['is_admin']      = false;
                $nouvelle['verifie_le']    = time();
                $cible = 'fiche JA ' . $idJa;
            } elseif ($action === 'retour') {
                if (!estSessionModeJa($u)) {
                    return redirect()->to($menu);
                }
                $row      = $this->lireCompte($pdo, $idMoi);
                $nouvelle = $row ? construireSessionUtilisateur($row, $pdo) : null;
                $motif    = !$row ? 'compte supprimé ou désactivé'
                    : ((isset($u['pwd_version'], $nouvelle['pwd_version']) && (int) $u['pwd_version'] !== (int) $nouvelle['pwd_version'])
                        ? 'mot de passe modifié'
                        : (rolesDe($nouvelle) === [] || aRole('JA', $nouvelle) ? 'rôles du compte invalides' : null));
                if ($motif !== null) {
                    error_log('[NIJAC][SEC] changer-compte : retour impossible (' . $motif . ', Id_Utilisateur ' . $idMoi . ') : session détruite');
                    $this->reprendreSession();
                    detruireSessionNijac();
                    return redirect()->to(site_url('login'));
                }
                $cible = 'compte';
            } else {
                return redirect()->to($menu);
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] changer-compte : ' . $e->getMessage());
            return redirect()->to($menu);
        }

        // Nouvel identifiant de session : l'ancien ne donne plus accès ni à l'un ni à l'autre mode.
        $this->reprendreSession();
        session_regenerate_id(true);
        unset($_SESSION['totp_ok_ts'], $_SESSION['totp_ok_id']);
        $_SESSION['utilisateur'] = $nouvelle;
        session_write_close();

        error_log('[NIJAC][SEC] changer-compte (' . $action . ') : Id_Utilisateur ' . $idMoi . ' → ' . $cible);

        return redirect()->to(site_url(menuDuRole((string) ($nouvelle['role'] ?? ''))));
    }

    /** Ligne de session (colonnesSessionUtilisateur()) d'un compte actif, null sinon. */
    private function lireCompte(\PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT ' . colonnesSessionUtilisateur($pdo) . ' FROM utilisateur WHERE Id_Utilisateur = ? AND Actif = 1 LIMIT 1'
        );
        $st->execute([$id]);

        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Rouvre la session native refermée par le filtre (session_start() brut, voir docblock). */
    private function reprendreSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
