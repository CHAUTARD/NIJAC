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

        $retour = !empty($moi['is_admin']) ? site_url('admin-menu') : site_url('nominateur-menu');
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
            } else {
                try {
                    $pdo  = getPDO();
                    $stmt = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
                    $stmt->execute([$moi['id']]);
                    $row = $stmt->fetch();

                    if (!$row || !\SecurePasswordHasher::verify($actuel, $row['Password'])) {
                        $status      = 'Mot de passe actuel incorrect.';
                        $statutClass = 'text-danger';
                    } else {
                        $hash = \SecurePasswordHasher::hash($nouveau);
                        $pdo->prepare('UPDATE Utilisateur SET Password = ?, ChangeLogin = 0 WHERE Id_Utilisateur = ?')
                            ->execute([$hash, $moi['id']]);

                        $moi['change_login'] = false;
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start(); // reprise brute : PAS demarrerSessionNijac() (voir docblock)
                        }
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
