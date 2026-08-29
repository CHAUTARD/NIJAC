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

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
        $this->utilisateurModel = new UtilisateurModel();

        // Auto-migration : rôle CSR (Commission Sportive Régionale), voir config/app_config.php.
        assurerRoleCsr(getPDO());
        // Auto-migration : rôle Defiscalisateur, voir config/app_config.php.
        assurerRoleDefiscalisateur(getPDO());
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
        $fields = $this->extractFields($this->request->getPost(), true, $mdpGenere);

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
        $mdpGenere = null;
        $fields = $this->extractFields($this->request->getRawInput(), false, $mdpGenere);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $this->utilisateurModel->update($id, $fields);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur mis à jour.', 'id' => $id, 'mdp' => $mdpGenere]);
    }

    public function delete($id = null): ResponseInterface
    {

        $id  = (int) $id;
        $moi = $_SESSION['utilisateur'] ?? [];

        if ($id === (int) ($moi['id'] ?? 0)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        $db = \Config\Database::connect();
        $db->table('Messagerie')->where('Id_Utilisateur', $id)->delete();

        $this->utilisateurModel->delete($id);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur supprimé.']);
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
    private function extractFields(array $input, bool $isNew, ?string &$mdpGenere = null)
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
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse email invalide.';
        }

        $fields = [
            'Login'          => $login,
            'Nom'            => $nom,
            'Prenom'         => $prenom,
            'Role'           => $role,
            'Id_Departement' => $dept,
            'Actif'          => $actif,
            'Email'          => $email !== '' ? $email : null,
        ];

        if ($isNew || $ecraser) {
            $mdpGenere              = genererMotDePasseAleatoire();
            $fields['Password']     = \SecurePasswordHasher::hash($mdpGenere);
            $fields['ChangeLogin']  = 1;
        }

        return $fields;
    }
}
