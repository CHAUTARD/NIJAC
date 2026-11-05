<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des rôles (EA94), table `role` (Id_Role, Nom, Libelle, Priorite, RouteMenu).
 *
 * Écran admin (filtre adminauth). Le code PHP ne manipule que le NOM des rôles : le nom d'un rôle n'est donc
 * jamais modifiable après création, et les six rôles de référence (ROLES_REFERENCE) ne sont pas supprimables.
 * Un rôle porté par au moins un compte (utilisateur_role) ne peut pas être supprimé non plus.
 * Raw PDO, pas de Model (même choix que CompetitionRegionaleController).
 */
class RoleController extends BaseController
{
    private const SELECT = 'SELECT r.Id_Role, r.Nom, r.Libelle, r.Priorite, r.RouteMenu,
                (SELECT COUNT(*) FROM utilisateur_role ur WHERE ur.Id_Role = r.Id_Role) AS NbComptes
            FROM role r';

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        return view('role_index', [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'reference'   => array_keys(ROLES_REFERENCE),
        ]);
    }

    public function data(): ResponseInterface
    {
        try {
            $rows = getPDO()->query(self::SELECT . ' ORDER BY r.Priorite, r.Nom')->fetchAll(\PDO::FETCH_ASSOC);

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        } catch (\PDOException $e) {
            error_log('[NIJAC] EA94 data : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Table role indisponible : ouvrez EA98.']);
        }
    }

    public function show($id = null): ResponseInterface
    {
        $row = $this->trouver((int) $id);

        return $this->response->setJSON($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']);
    }

    public function store(): ResponseInterface
    {
        $in  = $this->request->getPost();
        $nom = trim((string) ($in['nom'] ?? ''));
        [$f, $err] = $this->valider($in);
        if ($nom === '' || mb_strlen($nom) > 30) {
            $err = 'Le nom est obligatoire (30 caractères maximum).';
        }
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        try {
            $pdo = getPDO();
            $pdo->prepare('INSERT INTO role (Nom, Libelle, Priorite, RouteMenu) VALUES (?, ?, ?, ?)')->execute([$nom, ...$f]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Rôle créé.', 'id' => (int) $pdo->lastInsertId()]);
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getCode() === '23000' ? 'Ce nom de rôle existe déjà.' : 'Erreur base de données.']);
        }
    }

    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        if (!$this->trouver($id)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Rôle introuvable.']);
        }
        [$f, $err] = $this->valider($this->request->getRawInput());
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        getPDO()->prepare('UPDATE role SET Libelle = ?, Priorite = ?, RouteMenu = ? WHERE Id_Role = ?')->execute([...$f, $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Rôle mis à jour.', 'id' => $id]);
    }

    public function delete($id = null): ResponseInterface
    {
        $row = $this->trouver((int) $id);
        if (!$row) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Rôle introuvable.']);
        }
        if (isset(ROLES_REFERENCE[$row['Nom']])) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Les rôles de référence ne peuvent pas être supprimés.']);
        }
        if ((int) $row['NbComptes'] > 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Ce rôle est porté par {$row['NbComptes']} compte(s) : retirez-le d'abord (EA86)."]);
        }

        getPDO()->prepare('DELETE FROM role WHERE Id_Role = ?')->execute([(int) $row['Id_Role']]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Rôle supprimé.']);
    }

    private function trouver(int $id): array|false
    {
        $stmt = getPDO()->prepare(self::SELECT . ' WHERE r.Id_Role = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array{0: array, 1: ?string} [[libellé, priorité, route], message d'erreur] */
    private function valider(array $in): array
    {
        $libelle  = trim((string) ($in['libelle'] ?? ''));
        $priorite = trim((string) ($in['priorite'] ?? ''));
        $route    = trim((string) ($in['route_menu'] ?? ''));

        if ($libelle === '' || mb_strlen($libelle) > 60) {
            return [[], 'Le libellé est obligatoire (60 caractères maximum).'];
        }
        if (!ctype_digit($priorite) || (int) $priorite < 1 || (int) $priorite > 99) {
            return [[], 'La priorité doit être un entier entre 1 et 99.'];
        }
        if (!preg_match('~^[a-z0-9][a-z0-9/-]{0,49}$~', $route)) {
            return [[], 'Route du menu invalide (minuscules, chiffres, « - » et « / », 50 caractères maximum).'];
        }

        return [[$libelle, (int) $priorite, $route], null];
    }
}
