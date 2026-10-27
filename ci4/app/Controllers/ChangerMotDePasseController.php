<?php

namespace App\Controllers;

/**
 * NIJAC – Changement du mot de passe (E006), portage CI4 de
 * changer_mot_de_passe.php.
 *
 * Ouverte depuis la modale "Mot de passe à modifier" (toolbar, _modal_mdp.php)
 * en AJAX (GET puis POST, en-tête X-Requested-With), ou directement en page
 * complète si JS désactivé. Filtre "auth" (voir Auth.php) : redirige déjà le
 * rôle JA ailleurs, exactement comme includes/auth_required.php sans
 * $allowJa — ce dernier n'était jamais activé par le fichier legacy.
 *
 * Le filtre "auth" a déjà ouvert puis refermé la session ; $_SESSION['utilisateur']
 * reste en mémoire (le superglobal survit à session_write_close()), on le lit donc
 * directement comme les autres écrans "auth". NE PAS rappeler demarrerSessionNijac()
 * ici : son 2e appel (2e session_save_path()/session_set_cookie_params() avant
 * session_start()) empêche PHP de recharger $_SESSION depuis le fichier → tableau
 * vide et "Undefined array key utilisateur". Pour persister ChangeLogin=0 dans la
 * session vivante, on rouvre avec un session_start() BRUT (sans re-réglage), qui
 * lui recharge bien le fichier, puis on referme.
 *
 * Après un changement réussi : PwdVersion + 1 (si présente, EA98 — les autres sessions du compte
 * sont détruites à leur prochaine revalidation), session_regenerate_id(true), et la session
 * courante reçoit la nouvelle version + change_login = false (elle reste valide). Essais du
 * mot de passe actuel limités par compte (clé mdp_actuel:<id>). Changement forcé (change_login) :
 * les filtres n'autorisent que cet écran et logout (voir revaliderSessionUtilisateur()).
 */
class ChangerMotDePasseController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? null;
        if (!$moi) {
            return redirect()->to(site_url('login'));
        }

        // Menu de retour = menu du rôle principal de la session (menuDuRole(), app_config.php).
        $retour = site_url(menuDuRole($moi['role'] ?? ''));
        $isAjax = strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';

        $status      = !empty($moi['change_login'])
            ? 'Pour des raisons de sécurité, vous devez changer votre mot de passe.'
            : 'Modifiez votre mot de passe ci-dessous.';
        $statutClass = !empty($moi['change_login']) ? 'text-warning' : 'text-secondary';
        $succes      = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $actuel   = trim($this->request->getPost('mdp_actuel')   ?? '');
            $nouveau  = trim($this->request->getPost('mdp_nouveau')  ?? '');
            $confirme = trim($this->request->getPost('mdp_confirme') ?? '');

            if ($actuel === '' || $nouveau === '' || $confirme === '') {
                $status      = 'Veuillez remplir tous les champs.';
                $statutClass = 'text-warning';
            } elseif (($erreurRobustesse = validerRobustesseMotDePasse($nouveau)) !== null) {
                $status      = $erreurRobustesse;
                $statutClass = 'text-warning';
            } elseif ($nouveau !== $confirme) {
                $status      = 'Les deux saisies du nouveau mot de passe ne correspondent pas.';
                $statutClass = 'text-warning';
            } elseif ($limite = checkTentativesRateLimit(
                'mdp_actuel:' . (int) $moi['id'],
                (int) getConfig('login_rate_limit_max', '5'),
                (int) getConfig('login_rate_limit_fenetre', '15')
            )) {
                // Anti brute-force du mot de passe actuel (session volée) : verrou par compte,
                // même motif que CleanController::verifierMdpRequete().
                $status      = $limite;
                $statutClass = 'text-danger';
            } else {
                try {
                    $pdo  = getPDO();
                    $stmt = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
                    $stmt->execute([$moi['id']]);
                    $row = $stmt->fetch();

                    if (!$row || !\SecurePasswordHasher::verify($actuel, $row['Password'])) {
                        enregistrerTentative('mdp_actuel:' . (int) $moi['id'], (int) getConfig('login_rate_limit_fenetre', '15'));
                        $status      = 'Mot de passe actuel incorrect.';
                        $statutClass = 'text-danger';
                    } else {
                        // PwdVersion + 1 (si la colonne existe, EA98) : invalide les AUTRES sessions
                        // ouvertes sur ce compte à leur prochaine revalidation (revaliderSessionUtilisateur()).
                        $hash = \SecurePasswordHasher::hash($nouveau);
                        $pdo->prepare('UPDATE Utilisateur SET Password = ?, ChangeLogin = 0' . sqlIncrementPwdVersion($pdo)
                                . ' WHERE Id_Utilisateur = ?')
                            ->execute([$hash, $moi['id']]);

                        $moi['change_login'] = false;
                        $moi['verifie_le']   = time();
                        if (utilisateurAPwdVersion($pdo)) {
                            $v = $pdo->prepare('SELECT PwdVersion FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
                            $v->execute([$moi['id']]);
                            $moi['pwd_version'] = (int) $v->fetchColumn(); // la session courante reste valide
                        }
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start(); // reprise brute : PAS demarrerSessionNijac() (voir docblock)
                        }
                        session_regenerate_id(true); // nouvel identifiant de session après changement de mot de passe
                        $_SESSION['utilisateur'] = $moi; // réécrit le tableau complet
                        session_write_close();

                        $status       = 'Mot de passe modifié avec succès.';
                        $statutClass  = 'text-success';
                        $succes       = true;
                    }
                } catch (\PDOException $e) {
                    $status      = 'Erreur système : impossible de contacter la base de données.';
                    $statutClass = 'text-danger';
                    error_log('[NIJAC] PDOException changer_mot_de_passe : ' . $e->getMessage());
                }
            }

            if ($isAjax) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                return $this->response->setJSON(['ok' => $succes, 'msg' => $status, 'retour' => $retour]);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return view('changer_mot_de_passe_index', [
            'isAjax'      => $isAjax,
            'moi'         => $moi,
            'retour'      => $retour,
            'status'      => $status,
            'statutClass' => $statutClass,
            'succes'      => $succes,
        ]);
    }
}
