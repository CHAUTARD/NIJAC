<?php

namespace App\Controllers;

/**
 * NIJAC – Page de connexion (E001), portage CI4 de index.php.
 *
 * Recherche Utilisateur par Login/Password (Administrateur, Nominateur, CSR).
 * Le rôle JA n'a plus de login : ses écrans (E029/E030/E031/E032) sont tous
 * publics, identifiés par un lien tokenisé (Obfuscator) envoyé par email —
 * voir InfoRencontreController::resolveContext() pour E030.
 * Session native (jamais le service Session de CI4) — voir AdminAuth.php
 * pour l'explication complète de l'incompatibilité des deux mécanismes.
 */
class AuthController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
    }

    public function index()
    {
        demarrerSessionNijac();

        // Déjà connecté : redirection selon le rôle (comme index.php legacy)
        if (isset($_SESSION['utilisateur'])) {
            $redirect = $this->redirectForRole($_SESSION['utilisateur']['role']);
            session_write_close();
            return redirect()->to($redirect);
        }

        $status      = 'Prêt.';
        $statutClass = 'text-secondary';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $login    = trim($this->request->getPost('login')    ?? '');
            $password = trim($this->request->getPost('password') ?? '');

            if ($login === '' || $password === '') {
                $status      = 'Veuillez remplir tous les champs.';
                $statutClass = 'text-warning';
            } else {
                try {
                    $pdo = getPDO();

                    $stmt = $pdo->prepare(
                        'SELECT Id_Utilisateur, Login, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin, Email
                         FROM Utilisateur
                         WHERE Login = :login
                         LIMIT 1'
                    );
                    $stmt->execute([':login' => $login]);
                    $row = $stmt->fetch();

                    if ($row && (bool) $row['Actif'] && \SecurePasswordHasher::verify($password, $row['Password'])) {
                        session_unset();
                        session_regenerate_id(true);

                        $_SESSION['utilisateur'] = [
                            'id'             => $row['Id_Utilisateur'],
                            // Casse canonique de la base (Login a une collation *_ci,
                            // insensible à la casse) — pas la saisie brute de l'utilisateur,
                            // dont la casse peut différer et casser les comparaisons
                            // strictes === 'CHAUTARD' (E018, E099, isChautard E002/E017).
                            'login'          => $row['Login'],
                            'nom'            => $row['Nom'],
                            'prenom'         => $row['Prenom'],
                            'role'           => $row['Role'],
                            'id_departement' => $row['Id_Departement'],
                            'change_login'   => (bool) $row['ChangeLogin'],
                            'is_admin'       => ($row['Role'] === 'Administrateur'),
                            'email'          => $row['Email'] ?? '',
                        ];

                        // Pas de cron sur ce projet (déploiement FTP) : le rappel d'expiration des
                        // identifiants API FFTT se déclenche à la connexion d'un administrateur
                        // plutôt qu'à chaque chargement du menu admin (voir verifierRappelExpirationFfttApi()).
                        if ($row['Role'] === 'Administrateur') {
                            verifierRappelExpirationFfttApi();
                        }

                        $redirect = $this->redirectForRole($row['Role']);
                        session_write_close();
                        return redirect()->to($redirect);
                    }

                    $status      = 'Échec : Identifiants invalides.';
                    $statutClass = 'text-danger';
                } catch (\PDOException $e) {
                    $status      = 'Erreur système : impossible de contacter la base de données.';
                    $statutClass = 'text-danger';
                    error_log('[NIJAC] PDOException login : ' . $e->getMessage());
                }
            }
        }

        $data = [
            'status'      => $status,
            'statutClass' => $statutClass,
            'loginValue'  => $this->request->getPost('login') ?? '',
        ];

        session_write_close();
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé pour toute
        // réponse HTML non-AJAX, donc à chaque F5) de démarrer le service Session
        // de CI4 — voir Auth.php (filtre) pour le détail du conflit avec la
        // session native.
        unset($_SESSION);

        return view('login_index', $data);
    }

    private function redirectForRole(string $role): string
    {
        return match ($role) {
            'Administrateur' => site_url('admin-menu'),
            'CSR'             => site_url('csr-menu'),
            default           => site_url('nominateur-menu'),
        };
    }
}
