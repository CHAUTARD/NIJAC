<?php

namespace App\Controllers;

/**
 * NIJAC – Page de connexion (E001), portage CI4 de index.php.
 *
 * Recherche Utilisateur par Login ; pour un JA, Login = numéro de licence et
 * Password = Nom (inversé pour désambiguïser les homonymes), avec repli sur
 * la table `ja` (licence + nom) et règle métier des rencontres R3/R4.
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

                    // Pour un compte JA, Login est le numéro de licence (Id_JA de `ja`,
                    // clé primaire donc naturellement unique) et Password est le hash du
                    // Nom — inversé par rapport à Admin/Nominateur/CSR (Login = identifiant
                    // choisi, Password = secret) pour éliminer toute ambiguïté entre deux JA
                    // homonymes sans avoir besoin d'une colonne de désambiguïsation séparée.
                    // Le Nom est donc comparé indépendamment de la casse, comme partout
                    // ailleurs dans l'appli (UPPER/TRIM), d'où la normalisation ici plutôt
                    // qu'un verify() direct sur la saisie brute (qui, lui, reste tel quel
                    // pour les autres rôles).
                    $passwordAVerifier = ($row && $row['Role'] === 'JA')
                        ? mb_strtoupper(trim($password))
                        : $password;

                    if ($row && (bool) $row['Actif'] && \SecurePasswordHasher::verify($passwordAVerifier, $row['Password'])) {
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

                        if ($row['Role'] === 'JA') {
                            // Login EST le numéro de licence pour un compte JA (voir plus haut).
                            $_SESSION['utilisateur']['id_ja'] = (int) $row['Login'];
                        }

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

                    // ── Authentification JA : numéro de licence (login) + Nom (mot de passe) ──
                    $stmtJa = $pdo->prepare(
                        'SELECT Id_JA, Nom, Prenom, Email, Grade, Id_Club, SUBSTRING(Id_Club, 3, 2) AS Departement
                         FROM ja
                         WHERE TRIM(Id_JA) = TRIM(:licence) AND Actif = 1
                         LIMIT 1'
                    );
                    $stmtJa->execute([':licence' => $login]);
                    $jaLicence = $stmtJa->fetch() ?: null;

                    $ja = null;

                    if (!$jaLicence) {
                        $status      = 'Numéro de licence « ' . htmlspecialchars($login) . ' » introuvable dans la liste des JA actifs.';
                        $statutClass = 'text-danger';
                    } elseif (mb_strtoupper(trim($jaLicence['Nom'])) !== mb_strtoupper(trim($password))) {
                        $status      = 'Nom incorrect pour ce numéro de licence.';
                        $statutClass = 'text-danger';
                    } else {
                        $ja = $jaLicence;
                    }

                    if ($ja) {
                        $idDept = $ja['Departement'] ?? '';

                        $stmtCheck = $pdo->prepare(
                            'SELECT COUNT(*) FROM rencontre r
                             JOIN division dv  ON dv.Id_Division = r.Id_Division
                             JOIN equipe   ed  ON ed.Id_Equipe   = r.Id_EquipeDom
                             WHERE (dv.ArbitrageCRA = 1 OR ed.JAdemande = 1 OR dv.Division IN (\'R3M\', \'R4M\'))
                               AND ed.Id_Club = :id_club
                               AND r.Date BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY) AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)'
                        );
                        $stmtCheck->execute([':id_club' => $ja['Id_Club']]);
                        $nbRencontres = (int) $stmtCheck->fetchColumn();

                        $loginJa = (string) $ja['Id_JA'];

                        if ($nbRencontres === 0) {
                            $pdo->prepare('DELETE FROM Utilisateur WHERE Login = :login AND Role = \'JA\'')
                                ->execute([':login' => $loginJa]);
                            $status      = 'Accès refusé : aucune rencontre R3/R4 pour votre club.';
                            $statutClass = 'text-danger';
                        } else {
                            $stmtU = $pdo->prepare(
                                'SELECT Id_Utilisateur FROM Utilisateur WHERE Login = :login LIMIT 1'
                            );
                            $stmtU->execute([':login' => $loginJa]);
                            $utilisateur = $stmtU->fetch();
                            $hashedPwd   = \SecurePasswordHasher::hash(mb_strtoupper(trim($ja['Nom'])));

                            if (!$utilisateur) {
                                // Login = numéro de licence : unique par construction (clé
                                // primaire de `ja`), aucun risque de collision entre deux JA
                                // homonymes, contrairement à l'ancien schéma basé sur le Nom.
                                $pdo->prepare(
                                    'INSERT INTO Utilisateur (Login, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin)
                                     VALUES (:login, :pwd, :nom, :prenom, \'JA\', :dept, 1, 0)'
                                )->execute([
                                    ':login'  => $loginJa,
                                    ':pwd'    => $hashedPwd,
                                    ':nom'    => $ja['Nom'],
                                    ':prenom' => $ja['Prenom'],
                                    ':dept'   => $idDept,
                                ]);
                                $idUtilisateur = (int) $pdo->lastInsertId();
                            } else {
                                // Rafraîchit le hash (nom revérifié avec succès ci-dessus).
                                $pdo->prepare('UPDATE Utilisateur SET Password = :pwd WHERE Id_Utilisateur = :id')
                                    ->execute([':pwd' => $hashedPwd, ':id' => $utilisateur['Id_Utilisateur']]);
                                $idUtilisateur = (int) $utilisateur['Id_Utilisateur'];
                            }

                            session_unset();
                            session_regenerate_id(true);

                            $_SESSION['utilisateur'] = [
                                'id'             => $idUtilisateur,
                                'login'          => $loginJa,
                                'nom'            => $ja['Nom'],
                                'prenom'         => $ja['Prenom'],
                                'role'           => 'JA',
                                'id_departement' => $idDept,
                                'change_login'   => false,
                                'is_admin'       => false,
                                'id_ja'          => $ja['Id_JA'],
                            ];

                            session_write_close();
                            return redirect()->to(site_url('info-rencontre'));
                        }
                    }

                    if ($status === 'Prêt.') {
                        $status      = 'Échec : Identifiants invalides.';
                        $statutClass = 'text-danger';
                    }
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
            'JA'              => site_url('info-rencontre'),
            'CSR'             => site_url('csr-menu'),
            default           => site_url('nominateur-menu'),
        };
    }
}
