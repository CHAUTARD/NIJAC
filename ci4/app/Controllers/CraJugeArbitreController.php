<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Degrés Juge-Arbitre (EC72), rôle « CRA Convoc ».
 *
 * CRUD de la table de référence JugeArbitre (créée et seedée par
 * initTableConfiguration()). Accès depuis E009, filtre "craconvocauth"
 * (rôle CRA Convoc ou Administrateur) sur toutes les routes. Raw PDO, pas de Model.
 *
 * Le Code correspond à une colonne TINYINT de `ja` (JA1, JA2, JA3, JAN, JAI) :
 * il n'est saisissable qu'en création, et la suppression est refusée si au
 * moins un JA a cette colonne à 1.
 */
class CraJugeArbitreController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('cra_juge_arbitre_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
        ]);
    }

    public function data(): ResponseInterface
    {
        $rows = getPDO()->query('SELECT Id_JugeArbitre, Code, Libelle, Description FROM JugeArbitre ORDER BY Code')
            ->fetchAll(\PDO::FETCH_ASSOC);

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function store(): ResponseInterface
    {
        $in   = $this->request->getPost();
        $code = trim((string) ($in['code'] ?? ''));

        if ($code === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code, libellé et description sont obligatoires.']);
        }
        if (mb_strlen($code) !== 3) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le code doit faire exactement 3 caractères.']);
        }
        $doublon = getPDO()->prepare('SELECT 1 FROM JugeArbitre WHERE UPPER(Code) = UPPER(?)');
        $doublon->execute([$code]);
        if ($doublon->fetchColumn()) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Le code $code existe déjà."]);
        }

        [$f, $err] = $this->valider($in);
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        getPDO()->prepare('INSERT INTO JugeArbitre (Code, Libelle, Description) VALUES (?, ?, ?)')
            ->execute([$code, ...$f]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Degré créé.', 'id' => (int) getPDO()->lastInsertId()]);
    }

    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        $existe = getPDO()->prepare('SELECT 1 FROM JugeArbitre WHERE Id_JugeArbitre = ?');
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Degré introuvable.']);
        }

        // Code ignoré : non modifiable (colonne de `ja`).
        [$f, $err] = $this->valider($this->request->getRawInput());
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        getPDO()->prepare('UPDATE JugeArbitre SET Libelle = ?, Description = ? WHERE Id_JugeArbitre = ?')
            ->execute([...$f, $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Degré mis à jour.', 'id' => $id]);
    }

    public function delete($id = null): ResponseInterface
    {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT Code FROM JugeArbitre WHERE Id_JugeArbitre = ?');
        $stmt->execute([(int) $id]);
        $code = $stmt->fetchColumn();
        if ($code === false) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Degré introuvable.']);
        }

        // Colonne de `ja` portant le même nom que le Code : nom repris d'information_schema
        // (jamais de la saisie) et restreint à [A-Za-z0-9_] avant d'être injecté dans la requête.
        $col = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'ja\' AND COLUMN_NAME = ?');
        $col->execute([$code]);
        $colonne = $col->fetchColumn();
        if ($colonne !== false && preg_match('/^\w+$/', $colonne)) {
            $nb = (int) $pdo->query("SELECT COUNT(*) FROM ja WHERE `$colonne` = 1")->fetchColumn();
            if ($nb > 0) {
                return $this->response->setJSON(['ok' => false,
                    'msg' => "Suppression impossible : le degré $code est attribué à $nb JA (colonne ja.$colonne)."]);
            }
        }

        $pdo->prepare('DELETE FROM JugeArbitre WHERE Id_JugeArbitre = ?')->execute([(int) $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Degré supprimé.']);
    }

    /**
     * Valide Libellé et Description. Retourne [[libelle, description], message d'erreur ou null].
     */
    private function valider(array $in): array
    {
        $libelle     = trim((string) ($in['libelle'] ?? ''));
        $description = trim((string) ($in['description'] ?? ''));

        if ($libelle === '' || $description === '') {
            return [null, 'Code, libellé et description sont obligatoires.'];
        }
        if (mb_strlen($libelle) > 26) {
            return [null, 'Libellé trop long (26 car. max).'];
        }

        return [[$libelle, $description], null];
    }
}
