<?php

namespace App\Controllers;

/**
 * NIJAC – Bascule entre un compte non-JA et son compte JA lié (POST changer-compte, filtre
 * "auth:CSR,Defiscalisateur,CRA Convoc,JA" = tout rôle connecté, CSRF global).
 *
 * Une personne à la fois nominateur (ou administrateur…) et JA a deux comptes (rôle JA exclusif,
 * Id_Utilisateur = Id_JA). Un administrateur règle dans EA86 utilisateur.Id_Utilisateur_Lie = compte
 * JA lié ; depuis E002 / E003, le bouton « Accéder à mon espace JA » ouvre alors E011 SANS nouvelle
 * saisie de mot de passe ni code (l'utilisateur est déjà authentifié sur le compte d'origine), et
 * E011 propose « Revenir à mon compte nominateur ».
 *
 *   action=ja     : cible = idCompteJaLie() du compte de session, relu en base (lien, cible active,
 *                   rôle JA seul, fiche ja) — jamais un id venant du client. Refusée depuis une session
 *                   déjà basculée ou pendant un changement de mot de passe forcé.
 *   action=retour : cible = 'origine_id' mémorisé en session, revalidé (motifSessionOrigineInvalide() :
 *                   compte existant, actif, même mot de passe, lien pointant toujours vers le compte
 *                   courant) ; invalide → session détruite, retour à E001.
 *
 * Bascule : session_regenerate_id(true), $_SESSION['utilisateur'] reconstruit par
 * construireSessionUtilisateur() (+ 'origine_id' / 'origine_pwd_version' pour 'ja'), déverrouillage
 * TOTP (EA99) oublié, journal [NIJAC][SEC] (ids seulement), redirection vers menuDuRole() du compte
 * cible. La déconnexion (POST logout) détruit le tout.
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
        $u      = $_SESSION['utilisateur'] ?? [];
        $idMoi  = (int) ($u['id'] ?? 0);
        $action = (string) ($this->request->getPost('action') ?? '');
        $menu   = site_url(menuDuRole((string) ($u['role'] ?? '')));

        if ($idMoi <= 0) {
            return redirect()->to(site_url('login'));
        }

        try {
            $pdo = getPDO();

            if ($action === 'ja') {
                if (!empty($u['origine_id']) || !empty($u['change_login'])) {
                    error_log('[NIJAC][SEC] changer-compte : bascule vers le compte JA refusée (session basculée ou changement de mot de passe en attente, Id_Utilisateur ' . $idMoi . ')');
                    return redirect()->to($menu);
                }
                $idCible = idCompteJaLie($pdo, $idMoi);
                if ($idCible <= 0) {
                    error_log('[NIJAC][SEC] changer-compte : aucun compte JA lié utilisable (Id_Utilisateur ' . $idMoi . ')');
                    return redirect()->to($menu);
                }
                $row = $this->lireCompte($pdo, $idCible);
                if (!$row) {
                    return redirect()->to($menu);
                }
                $nouvelle = construireSessionUtilisateur($row, $pdo);
                $nouvelle['origine_id']          = $idMoi;
                $nouvelle['origine_pwd_version'] = $u['pwd_version'] ?? null;
            } elseif ($action === 'retour') {
                $idOrigine = (int) ($u['origine_id'] ?? 0);
                if ($idOrigine <= 0) {
                    return redirect()->to($menu);
                }
                $motif = motifSessionOrigineInvalide($pdo, $u);
                $row   = $motif === null ? $this->lireCompte($pdo, $idOrigine) : null;
                if ($row) {
                    $nouvelle = construireSessionUtilisateur($row, $pdo);
                }
                if (!$row || rolesDe($nouvelle) === []) {
                    error_log('[NIJAC][SEC] changer-compte : retour impossible (' . ($motif ?? 'compte d\'origine sans rôle')
                        . ', Id_Utilisateur ' . $idMoi . ' → ' . $idOrigine . ') : session détruite');
                    $this->reprendreSession();
                    detruireSessionNijac();
                    return redirect()->to(site_url('login'));
                }
            } else {
                return redirect()->to($menu);
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] changer-compte : ' . $e->getMessage());
            return redirect()->to($menu);
        }

        // Nouvel identifiant de session : l'ancien ne donne accès à aucun des deux comptes.
        $this->reprendreSession();
        session_regenerate_id(true);
        unset($_SESSION['totp_ok_ts'], $_SESSION['totp_ok_id']);
        $_SESSION['utilisateur'] = $nouvelle;
        session_write_close();

        error_log('[NIJAC][SEC] changer-compte (' . $action . ') : Id_Utilisateur ' . $idMoi . ' → ' . (int) $nouvelle['id']);

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
