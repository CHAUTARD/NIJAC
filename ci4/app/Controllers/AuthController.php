<?php

namespace App\Controllers;

/**
 * NIJAC – Page de connexion (E001), portage CI4 de index.php, et saisie du code de sécurité (E010).
 *
 * Recherche Utilisateur par Login/Password (Administrateur, Nominateur, CSR).
 * Double authentification : mot de passe valide -> état $_SESSION['mfa_attente'] (id, horodatages,
 * hash du code, essais, envois ; jamais le code en clair) + code de 6 chiffres envoyé par email ->
 * E010 (login/code) -> $_SESSION['utilisateur'] créé seulement après validation du code. Coupe-circuit :
 * configuration.double_authentification = '0' (mot de passe seul). Règles : fonctions pures
 * *CodeSecurite* de config/app_config.php.
 * Le rôle JA n'a plus de login : ses écrans (EN19/EN21/EN22) sont tous
 * publics, identifiés par un lien tokenisé (Obfuscator) envoyé par email.
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

        // Vide au premier affichage : le bandeau n'apparaît que pour un avertissement ou une erreur
        // Message transmis par E010 (essais épuisés, attente expirée) ; une connexion
        // inachevée est abandonnée dès qu'on revient sur E001.
        $status      = $_SESSION['login_message'] ?? '';
        $statutClass = $status !== '' ? 'text-danger' : '';
        unset($_SESSION['login_message'], $_SESSION['mfa_attente']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $login    = trim($this->request->getPost('login')    ?? '');
            $password = trim($this->request->getPost('password') ?? '');

            if ($login === '' || $password === '') {
                $status      = 'Veuillez remplir tous les champs.';
                $statutClass = 'text-warning';
            } elseif ($limite = checkLoginRateLimit()) {
                $status      = $limite;
                $statutClass = 'text-danger';
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

                        // Coupe-circuit (configuration.double_authentification = '0') : mot de passe seul.
                        if (!doubleAuthentificationActive()) {
                            error_log('[NIJAC][SEC] double authentification désactivée par configuration');
                            return $this->ouvrirSession($row);
                        }

                        // 2e étape (E010) : état d'attente seulement — aucune donnée
                        // $_SESSION['utilisateur'] (les filtres voient une session NON authentifiée).
                        $email = trim((string) ($row['Email'] ?? ''));
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            error_log('[NIJAC][SEC] connexion refusée : aucune adresse email valide (Id_Utilisateur ' . (int) $row['Id_Utilisateur'] . ')');
                            $status      = "Aucune adresse email valide n'est enregistrée sur votre compte, contactez l'administrateur.";
                            $statutClass = 'text-danger';
                        } else {
                            $envoi = $this->envoyerCode(['id' => (int) $row['Id_Utilisateur'], 'debut' => time(), 'envois' => []], $email);
                            if ($envoi['ok']) {
                                $_SESSION['mfa_attente'] = $envoi['etat'];
                                session_write_close();
                                return redirect()->to(site_url('login/code'));
                            }
                            $status      = $envoi['erreur'];
                            $statutClass = 'text-danger';
                        }
                    } else {
                        enregistrerEchecLogin();
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

    /**
     * E010 : saisie du code de sécurité (GET) et validation (POST). Accessible seulement avec un
     * état $_SESSION['mfa_attente'] posé par index() ; session déjà authentifiée -> son menu.
     */
    public function code()
    {
        demarrerSessionNijac();

        if (isset($_SESSION['utilisateur'])) {
            $redirect = $this->redirectForRole($_SESSION['utilisateur']['role']);
            session_write_close();
            return redirect()->to($redirect);
        }
        $etat = $this->etatAttente();
        if ($etat === null) {
            return $this->retourLogin();
        }

        $flash       = $_SESSION['mfa_flash'] ?? ['', ''];
        unset($_SESSION['mfa_flash']);
        [$status, $statutClass] = $flash;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($limite = checkLoginRateLimit()) {
                $status      = $limite;
                $statutClass = 'text-danger';
            } else {
                $saisie = $this->request->getPost('code');
                $res    = verifierCodeSecurite($etat, is_string($saisie) ? $saisie : '', time());
                if ($res['ok']) {
                    unset($_SESSION['mfa_attente']);
                    $row = $this->chargerUtilisateur((int) $etat['id']);
                    if ($row) {
                        session_regenerate_id(true);
                        return $this->ouvrirSession($row);
                    }
                    return $this->retourLogin('Échec : Identifiants invalides.');
                }

                enregistrerEchecLogin(); // même verrou par IP que les échecs de mot de passe (E001)
                error_log('[NIJAC][SEC] code de sécurité incorrect ou expiré (Id_Utilisateur ' . (int) $etat['id'] . ')');
                if ($res['erreur'] === 'epuise') {
                    return $this->retourLogin("Nombre maximal d'essais atteint : veuillez vous reconnecter.");
                }
                $_SESSION['mfa_attente'] = $res['etat'];
                $status      = 'Code incorrect ou expiré.';
                $statutClass = 'text-danger';
            }
        }

        $renvoi = peutRenvoyerCode($_SESSION['mfa_attente'], time());
        session_write_close();
        unset($_SESSION); // voir index() : pas de démarrage du service Session de CI4

        return view('login_code_index', [
            'status'      => $status,
            'statutClass' => $statutClass,
            'attente'     => $renvoi['attente'],
        ]);
    }

    /** E010 : renvoi d'un nouveau code (remplace l'ancien, essais remis à 0) — délai et plafond dans peutRenvoyerCode(). */
    public function renvoyerCode()
    {
        demarrerSessionNijac();

        $etat = $this->etatAttente();
        if ($etat === null || isset($_SESSION['utilisateur'])) {
            return $this->retourLogin();
        }

        $renvoi = peutRenvoyerCode($etat, time());
        $row    = $renvoi['ok'] ? $this->chargerUtilisateur((int) $etat['id']) : null;
        if (!$renvoi['ok']) {
            $_SESSION['mfa_flash'] = ['Nouvel envoi possible dans ' . $renvoi['attente'] . ' seconde(s).', 'text-warning'];
        } elseif (!$row || !filter_var(trim((string) $row['Email']), FILTER_VALIDATE_EMAIL)) {
            return $this->retourLogin("Aucune adresse email valide n'est enregistrée sur votre compte, contactez l'administrateur.");
        } else {
            $envoi = $this->envoyerCode($renvoi['etat'], trim((string) $row['Email']));
            $_SESSION['mfa_attente'] = $envoi['etat'];
            $_SESSION['mfa_flash']   = $envoi['ok']
                ? ['Un nouveau code vient de vous être envoyé.', 'text-success']
                : [$envoi['erreur'], 'text-danger'];
        }

        session_write_close();
        return redirect()->to(site_url('login/code'));
    }

    /**
     * État d'attente courant, ou null (absent, ou connexion commencée il y a plus de 3 durées de code :
     * il faut alors ressaisir le mot de passe).
     */
    private function etatAttente(): ?array
    {
        $etat = $_SESSION['mfa_attente'] ?? null;
        if (!is_array($etat) || empty($etat['id'])) {
            return null;
        }
        if (time() - (int) ($etat['debut'] ?? 0) > 3 * CODE_SECURITE_DUREE) {
            $_SESSION['login_message'] = 'Délai de connexion dépassé : veuillez vous reconnecter.';
            return null;
        }
        return $etat;
    }

    /** Abandonne la connexion en cours et revient à E001 (message éventuel affiché par index()). */
    private function retourLogin(string $message = '')
    {
        unset($_SESSION['mfa_attente'], $_SESSION['mfa_flash']);
        if ($message !== '') {
            $_SESSION['login_message'] = $message;
        }
        session_write_close();
        return redirect()->to(site_url('login'));
    }

    /**
     * Génère un code, l'envoie au titulaire du compte (NijacMailer : redirigé vers email_developpement
     * en mode Développement) et retourne ['ok', 'etat', 'erreur']. Le code n'est conservé que haché ;
     * l'envoi est compté même en cas d'échec (limite les relances SMTP). Plafond par compte (fichier,
     * indépendant de la session) contre le mail-bombing : 6 envois / 15 min.
     */
    private function envoyerCode(array $etat, string $email): array
    {
        $cle = 'mfa_envoi:' . (int) $etat['id'];
        if ($limite = checkTentativesRateLimit($cle, 6, 15)) {
            return ['ok' => false, 'etat' => $etat, 'erreur' => $limite];
        }
        enregistrerTentative($cle, 15);
        $etat['envois'][] = time();

        $code = genererCodeSecurite();
        try {
            // Message système uniquement (Id 0 : aucune copie personnelle ne peut correspondre).
            $rendu = rendreMessageCodeSecurite(resoudreModeleMessagerieParType(getPDO(), TYPE_MESSAGE_CODE_SECURITE, 0), $code);
            $mail  = getNijacMailer();
            $mail->isHTML($rendu['html']);
            $mail->addAddress(getEmailDestinataire($email));
            $mail->Subject = $rendu['sujet'];
            $mail->Body    = $rendu['corps'];
            $mail->send();
        } catch (\Throwable $e) {
            error_log('[NIJAC][SEC] envoi du code de sécurité impossible (Id_Utilisateur ' . (int) $etat['id'] . ') : ' . $e->getMessage());
            return ['ok' => false, 'etat' => $etat, 'erreur' => "Impossible d'envoyer le code, réessayez ou contactez l'administrateur."];
        }

        return ['ok' => true, 'etat' => emettreCodeSecurite($etat, hacherCodeSecurite($code), time()), 'erreur' => null];
    }

    /** Relit un compte actif (les données de session viennent de la base au moment de la validation du code). */
    private function chargerUtilisateur(int $id): ?array
    {
        $stmt = getPDO()->prepare(
            'SELECT Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin, Email
             FROM Utilisateur WHERE Id_Utilisateur = ? AND Actif = 1 LIMIT 1'
        );
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Crée $_SESSION['utilisateur'] (format inchangé) et redirige selon le rôle. */
    private function ouvrirSession(array $row)
    {
        $_SESSION['utilisateur'] = [
            'id'             => $row['Id_Utilisateur'],
            // Casse canonique de la base (Login a une collation *_ci,
            // insensible à la casse) — pas la saisie brute de l'utilisateur,
            // dont la casse peut différer et casser les comparaisons
            // strictes === 'CHAUTARD' (EA96, EA98, isChautard E002/EA83).
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

    private function redirectForRole(string $role): string
    {
        return match ($role) {
            'Administrateur'  => site_url('admin-menu'),
            'CSR'             => site_url('csr-menu'),
            'Defiscalisateur' => site_url('defiscalisateur-menu'),
            'CRA Convoc'      => site_url('cra-convoc-menu'),
            default          => site_url('nominateur-menu'),
        };
    }
}
