<?php

namespace App\Controllers;

use App\Models\UtilisateurModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des utilisateurs (EA86), portage CI4 de utilisateur.php.
 */
class UtilisateurController extends BaseController
{
    protected UtilisateurModel $utilisateurModel;

    /**
     * Login du compte réservé (accès EA96 / EA97 / EA98, contrôle `login === 'CHAUTARD'`).
     * Ce compte ne peut être modifié ni supprimé que par lui-même, son login ne peut pas
     * changer et ce login ne peut être attribué à aucun autre compte (création ou renommage).
     */
    private const LOGIN_RESERVE = 'CHAUTARD';

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
        $this->utilisateurModel = new UtilisateurModel();

        // Auto-migration : rôle CSR (Commission Sportive Régionale), voir config/app_config.php.
        assurerRoleCsr(getPDO());
        // Auto-migration : rôle Defiscalisateur, voir config/app_config.php.
        assurerRoleDefiscalisateur(getPDO());
        // Auto-migration : rôle CRA Convoc, voir config/app_config.php.
        assurerRoleCraConvoc(getPDO());
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'moiId'       => (int) ($moi['id'] ?? 0),
            'deptActifs'  => getDeptActifs(),
            'roles'       => $this->rolesValides(),
        ];

        return view('utilisateur_index', $data);
    }

    /**
     * Lit les valeurs possibles de l'ENUM utilisateur.Role directement en base, pour que la
     * combobox Rôle (EA86) et la validation de extractFields() restent synchronisées avec la
     * colonne sans modification de code si un rôle est ajouté (voir ajouterValeurEnum() /
     * assurerRoleCsr() dans config/app_config.php) — même pattern que MessagerieController::typesValides().
     */
    private function rolesValides(): array
    {
        $col   = getPDO()->query("SHOW COLUMNS FROM utilisateur WHERE Field = 'Role'")->fetch();
        $roles = [];
        if ($col && preg_match("/^enum\((.+)\)$/i", $col['Type'], $m)) {
            foreach (str_getcsv($m[1], ',', "'") as $v) {
                $roles[] = trim($v);
            }
            sort($roles, SORT_STRING | SORT_FLAG_CASE);
        }

        return $roles;
    }

    public function data(): ResponseInterface
    {
        $rows = $this->utilisateurModel
            ->select('Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin, Email')
            ->orderBy('Nom', 'ASC')
            ->orderBy('Prenom', 'ASC')
            ->findAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($id = null): ResponseInterface
    {
        $id  = (int) $id;
        $row = $this->utilisateurModel
            ->select('Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin, Email')
            ->find($id);

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {

        $mdpGenere = null;
        $fields = $this->extractFields($this->request->getPost(), true, $mdpGenere, 0);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $this->utilisateurModel->insert($fields);
        $id = (int) $this->utilisateurModel->getInsertID();

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur créé.', 'id' => $id, 'mdp' => $mdpGenere]);
    }

    public function update($id = null): ResponseInterface
    {

        $id     = (int) $id;

        // Compte réservé : seul son titulaire peut le modifier (Login lu en base, jamais du client).
        $refus = $this->refusCompteReserve($id);
        if ($refus !== null) {
            return $refus;
        }

        $mdpGenere = null;
        $fields = $this->extractFields($this->request->getRawInput(), false, $mdpGenere, $id);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $existant = $this->utilisateurModel->select('Login, Role, Actif')->find($id);
        if (!$existant) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Utilisateur introuvable.']);
        }

        // Le login du compte réservé ne change jamais (sinon l'accès EA96/EA97/EA98 serait perdu
        // et le login réservé redeviendrait attribuable à un autre compte).
        if ($this->estLoginReserve((string) $existant['Login'])
            && (string) $fields['Login'] !== (string) $existant['Login']) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le login de ce compte ne peut pas être modifié.']);
        }

        // Garde-fou anti-verrouillage : on ne se désactive pas soi-même et on ne quitte pas son propre rôle
        // (le dernier administrateur perdrait tout accès aux écrans EA).
        if ($id === (int) ($_SESSION['utilisateur']['id'] ?? 0)
            && ((int) $fields['Actif'] !== 1 || $fields['Role'] !== $existant['Role'])) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez ni désactiver votre propre compte, ni modifier votre propre rôle.']);
        }

        $this->utilisateurModel->update($id, $fields);

        // « Écraser » : nouveau mot de passe → PwdVersion + 1 (si présente, EA98), les sessions
        // ouvertes sur ce compte sont détruites à leur prochaine revalidation (1 fois / minute).
        // Désactivation, suppression, changement de rôle : rien à faire ici, la revalidation
        // (revaliderSessionUtilisateur()) relit Actif / Role.
        if (isset($fields['Password'])) {
            $pdo = getPDO();
            if (utilisateurAPwdVersion($pdo)) {
                $pdo->prepare('UPDATE utilisateur SET PwdVersion = PwdVersion + 1 WHERE Id_Utilisateur = ?')->execute([$id]);
            }
        }

        // Modification de son propre compte : le profil de session (département surtout) est
        // relu en base, sans reconnexion. Jamais touché pour un autre utilisateur. Rôle et
        // statut Actif ne peuvent pas changer ici (garde-fou ci-dessus) : pas d'état incohérent.
        if ($id === (int) ($_SESSION['utilisateur']['id'] ?? 0)) {
            $this->rafraichirSessionPropre($id);
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur mis à jour.', 'id' => $id, 'mdp' => $mdpGenere]);
    }

    /**
     * Réécrit $_SESSION['utilisateur'] depuis la base (même format qu'à la connexion, voir
     * construireSessionUtilisateur()). Le filtre adminauth a déjà refermé la session : reprise
     * par un session_start() BRUT, pas demarrerSessionNijac() (voir ChangerMotDePasseController).
     * Aucun cache de périmètre en session ou fichier : getDepartementsAutorises() relit la
     * configuration à chaque appel, le nouveau département est donc vu dès la requête suivante.
     */
    private function rafraichirSessionPropre(int $id): void
    {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare(
                'SELECT ' . colonnesSessionUtilisateur($pdo) . '
                 FROM utilisateur WHERE Id_Utilisateur = ? AND Actif = 1 LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return; // compte introuvable/inactif : on laisse la session telle quelle
            }

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['utilisateur'] = construireSessionUtilisateur($row);
            session_write_close();
        } catch (\Throwable $e) {
            error_log('[NIJAC] EA86 : rafraîchissement de la session impossible : ' . $e->getMessage());
        }
    }

    public function delete($id = null): ResponseInterface
    {

        $id  = (int) $id;
        $moi = $_SESSION['utilisateur'] ?? [];

        if ($id === (int) ($moi['id'] ?? 0)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        // Compte réservé : jamais supprimé par un autre compte (et pas par lui-même, garde ci-dessus).
        $refus = $this->refusCompteReserve($id);
        if ($refus !== null) {
            return $refus;
        }

        $db = \Config\Database::connect();
        $db->table('Messagerie')->where('Id_Utilisateur', $id)->delete();

        $this->utilisateurModel->delete($id);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur supprimé.']);
    }

    /** Le login donné est-il le login réservé ? (trim, insensible à la casse) */
    private function estLoginReserve(string $login): bool
    {
        return strcasecmp(trim($login), self::LOGIN_RESERVE) === 0;
    }

    /**
     * Refus si le compte $id porte en base le login réservé et que l'utilisateur connecté n'est
     * pas ce compte (Id de session). Login relu en base, jamais pris du client. null = autorisé.
     */
    private function refusCompteReserve(int $id): ?ResponseInterface
    {
        $row = $this->utilisateurModel->select('Login')->find($id);
        if ($row && $this->estLoginReserve((string) $row['Login'])
            && $id !== (int) ($_SESSION['utilisateur']['id'] ?? 0)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ce compte est réservé.']);
        }

        return null;
    }

    /**
     * Extrait et valide les champs du formulaire. Retourne un message d'erreur
     * (string) en cas de validation échouée, sinon le tableau de champs prêt
     * pour insert()/update().
     *
     * Mot de passe : plus de saisie manuelle. À la création, ou si la case
     * « Écraser » est cochée, un mot de passe aléatoire est généré, le compte
     * passe en ChangeLogin = 1, et le mot de passe en clair est renvoyé via
     * $mdpGenere (pour affichage à l'admin). Sinon aucune clé Password/ChangeLogin
     * n'est écrite (valeurs BDD inchangées).
     */
    private function extractFields(array $input, bool $isNew, ?string &$mdpGenere = null, int $idCourant = 0)
    {
        $mdpGenere = null;

        $login   = trim($input['login'] ?? '');
        $nom     = trim($input['nom'] ?? '');
        $prenom  = trim($input['prenom'] ?? '');
        $role    = trim($input['role'] ?? '');
        $dept    = (int) ($input['dept'] ?? 0);
        $email   = trim($input['email'] ?? '');
        $actif   = ($input['actif'] ?? '0') === '1' ? 1 : 0;
        $ecraser = ($input['ecraser'] ?? '0') === '1';

        if ($login === '') {
            return 'Le login ne peut pas être vide.';
        }
        // Login réservé : jamais attribué à un autre compte (création ou renommage). Le compte
        // qui le porte déjà en base le conserve (update() interdit d'en changer).
        if ($this->estLoginReserve($login)) {
            $actuel = $idCourant > 0 ? $this->utilisateurModel->select('Login')->find($idCourant) : null;
            if (!$actuel || !$this->estLoginReserve((string) $actuel['Login'])) {
                return 'Ce login est réservé.';
            }
        }
        $doublon = getPDO()->prepare('SELECT 1 FROM utilisateur WHERE Login = ? AND Id_Utilisateur <> ? LIMIT 1');
        $doublon->execute([$login, $idCourant]);
        if ($doublon->fetchColumn()) {
            return 'Ce login est déjà utilisé par un autre compte.';
        }
        if ($nom === '') {
            return 'Le nom ne peut pas être vide.';
        }
        if ($prenom === '') {
            return 'Le prénom ne peut pas être vide.';
        }
        if (!in_array($role, $this->rolesValides(), true)) {
            return 'Rôle invalide.';
        }
        if ($dept <= 0) {
            return 'Le département est obligatoire.';
        }
        // Obligatoire : le code de sécurité de connexion (E010) part à cette adresse.
        // Pas de contrôle d'unicité : des comptes partagent déjà une adresse en base.
        if ($email === '') {
            return "L'adresse email est obligatoire (envoi du code de sécurité de connexion).";
        }
        if (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse email invalide.';
        }

        $fields = [
            'Login'          => $login,
            'Nom'            => $nom,
            'Prenom'         => $prenom,
            'Role'           => $role,
            'Id_Departement' => $dept,
            'Actif'          => $actif,
            'Email'          => $email,
        ];

        if ($isNew || $ecraser) {
            $mdpGenere              = genererMotDePasseAleatoire();
            $fields['Password']     = \SecurePasswordHasher::hash($mdpGenere);
            $fields['ChangeLogin']  = 1;
        }

        return $fields;
    }
}
