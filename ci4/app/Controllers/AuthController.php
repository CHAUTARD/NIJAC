<?php

namespace App\Controllers;

/**
 * NIJAC – Page de connexion (E001), portage CI4 de index.php.
 *
 * Réplique exactement la logique legacy : recherche Utilisateur par Login,
 * fallback JA Nom+Licence en 2 étapes, règle métier des rencontres R3/R4.
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

                        if ($row['Role'] === 'JA') {
                            $stmtIdJa = $pdo->prepare(
                                'SELECT Id_JA FROM ja WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) LIMIT 1'
                            );
                            $stmtIdJa->execute([':nom' => $row['Nom']]);
                            $_SESSION['utilisateur']['id_ja'] = $stmtIdJa->fetchColumn() ?: null;
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

                    // ── Authentification JA : Nom + numéro de licence ────────────────
                    $stmtJaNom = $pdo->prepare(
                        'SELECT Id_JA, Nom, Prenom, Email, Grade, Id_Club, SUBSTRING(Id_Club, 3, 2) AS Departement
                         FROM ja
                         WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) AND Actif = 1
                         LIMIT 1'
                    );
                    $stmtJaNom->execute([':nom' => $login]);
                    $jaNom = $stmtJaNom->fetch();

                    $ja = null;

                    if (!$jaNom) {
                        $status      = 'Nom « ' . htmlspecialchars($login) . ' » introuvable dans la liste des JA actifs.';
                        $statutClass = 'text-danger';
                    } else {
                        $stmtJa = $pdo->prepare(
                            'SELECT Id_JA, Nom, Prenom, Email, Grade, Id_Club,
                                    SUBSTRING(Id_Club, 3, 2) AS Departement
                             FROM ja
                             WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) AND TRIM(Id_JA) = TRIM(:licence) AND Actif = 1
                             LIMIT 1'
                        );
                        $stmtJa->execute([':nom' => $login, ':licence' => $password]);
                        $ja = $stmtJa->fetch() ?: null;
                    }

                    if ($jaNom && !$ja) {
                        $status      = 'Numéro de licence incorrect pour le JA « ' . htmlspecialchars($jaNom['Nom']) . ' ».';
                        $statutClass = 'text-danger';
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

                        if ($nbRencontres === 0) {
                            $pdo->prepare('DELETE FROM Utilisateur WHERE Login = :login AND Role = \'JA\'')
                                ->execute([':login' => $ja['Nom']]);
                            $status      = 'Accès refusé : aucune rencontre R3/R4 pour votre club.';
                            $statutClass = 'text-danger';
                        } else {
                            $stmtU = $pdo->prepare(
                                'SELECT Id_Utilisateur FROM Utilisateur WHERE Login = :login LIMIT 1'
                            );
                            $stmtU->execute([':login' => $ja['Nom']]);
                            $utilisateur = $stmtU->fetch();

                            if (!$utilisateur) {
                                $hashedPwd = \SecurePasswordHasher::hash($password);
                                $pdo->prepare(
                                    'INSERT INTO Utilisateur (Login, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin)
                                     VALUES (:login, :pwd, :nom, :prenom, \'JA\', :dept, 1, 0)'
                                )->execute([
                                    ':login'  => $ja['Nom'],
                                    ':pwd'    => $hashedPwd,
                                    ':nom'    => $ja['Nom'],
                                    ':prenom' => $ja['Prenom'],
                                    ':dept'   => $idDept,
                                ]);
                                $idUtilisateur = (int) $pdo->lastInsertId();
                            } else {
                                $idUtilisateur = (int) $utilisateur['Id_Utilisateur'];
                            }

                            session_unset();
                            session_regenerate_id(true);

                            $_SESSION['utilisateur'] = [
                                'id'             => $idUtilisateur,
                                'login'          => $ja['Nom'],
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
