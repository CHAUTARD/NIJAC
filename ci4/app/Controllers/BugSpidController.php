<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – BugSpid (E043) : file de corrections « club dupliqué avec un
 * Id_Club fantôme » (code alphabétique généré par l'import quand le nom du
 * club de la rencontre ne matchait aucun Club.EquipeNom existant — voir
 * ImportRencontresController::synchroniserClubFftt() et l'outil de
 * rapprochement tools/rapprocher_clubs_alpha.php).
 *
 * Chaque ligne décrit une fusion à faire (ancien Id_Club → nouveau) ; la
 * requête n'est jamais stockée telle quelle, elle est régénérée à
 * l'exécution à partir des champs de la ligne — trois étapes systématiques :
 *   1. UPDATE equipe SET Id_Club = nouveau WHERE Id_Club = ancien
 *   2. DELETE FROM Club WHERE Id_Club = ancien
 *   3. UPDATE Club SET EquipeNom = ... WHERE Id_Club = nouveau (si renseigné)
 *
 * Même restriction que E099 (outil de correction de données) : filtre
 * "adminauth" + login === 'CHAUTARD' vérifié manuellement, même règle que
 * E018/E099.
 */
class BugSpidController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function guardChautard(): ?ResponseInterface
    {
        if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
            return redirect()->to(site_url('admin-menu'));
        }

        return null;
    }

    private function assurerTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS BugSpid (
                Id_BugSpid INT AUTO_INCREMENT PRIMARY KEY,
                Description VARCHAR(255) NOT NULL,
                AncienIdClub VARCHAR(20) NOT NULL,
                NouveauIdClub VARCHAR(20) NOT NULL,
                EquipeNom VARCHAR(100) NULL,
                Statut ENUM(\'A traiter\',\'Traite\') NOT NULL DEFAULT \'A traiter\',
                DateAjout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                DateExecution DATETIME NULL,
                Resultat TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT=\'File de corrections Id_Club dupliqué (alpha -> code FFTT réel), exécutable en lot depuis E043\''
        );
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] bug_spid PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] bug_spid : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $this->assurerTable(getPDO());

        $u = $_SESSION['utilisateur'] ?? [];

        return view('bug_spid_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'changeLogin' => !empty($u['change_login']),
        ]);
    }

    public function data(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo = getPDO();
            $this->assurerTable($pdo);

            $rows = $pdo->query('SELECT * FROM BugSpid ORDER BY Id_BugSpid DESC')->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function store(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo   = getPDO();
            $this->assurerTable($pdo);
            $input = $this->request->getPost();

            [$description, $ancien, $nouveau, $equipeNom, $err] = $this->lireEtValider($input);
            if ($err) {
                return $this->response->setJSON(['ok' => false, 'msg' => $err]);
            }

            $pdo->prepare(
                'INSERT INTO BugSpid (Description, AncienIdClub, NouveauIdClub, EquipeNom) VALUES (?, ?, ?, ?)'
            )->execute([$description, $ancien, $nouveau, $equipeNom]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne ajoutée.', 'id' => (int) $pdo->lastInsertId()]);
        });
    }

    public function update(int $id): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($id) {
            $pdo   = getPDO();
            $this->assurerTable($pdo);
            $input = $this->request->getRawInput();

            [$description, $ancien, $nouveau, $equipeNom, $err] = $this->lireEtValider($input);
            if ($err) {
                return $this->response->setJSON(['ok' => false, 'msg' => $err]);
            }

            $stmt = $pdo->prepare(
                'UPDATE BugSpid SET Description=?, AncienIdClub=?, NouveauIdClub=?, EquipeNom=? WHERE Id_BugSpid=?'
            );
            $stmt->execute([$description, $ancien, $nouveau, $equipeNom, $id]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM BugSpid WHERE Id_BugSpid = ?');
                $chk->execute([$id]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Ligne $id introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne mise à jour.']);
        });
    }

    public function delete(int $id): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($id) {
            getPDO()->prepare('DELETE FROM BugSpid WHERE Id_BugSpid = ?')->execute([$id]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne supprimée.']);
        });
    }

    /**
     * Exécute la fusion (UPDATE equipe / DELETE Club / UPDATE Club EquipeNom)
     * pour chaque ligne cochée. Chaque ligne est traitée dans sa propre
     * transaction : une erreur sur l'une n'empêche pas le traitement des
     * suivantes, contrairement au requêteur libre de E099.
     */
    public function executer(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', json_decode($this->request->getPost('ids') ?? '[]', true) ?: [])
            )));
            if (!$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune ligne sélectionnée.']);
            }

            $pdo    = getPDO();
            $this->assurerTable($pdo);
            $ph     = implode(',', array_fill(0, count($ids), '?'));
            $lignes = $pdo->prepare("SELECT * FROM BugSpid WHERE Id_BugSpid IN ($ph)");
            $lignes->execute($ids);

            $resultats = [];
            foreach ($lignes->fetchAll() as $ligne) {
                $resultats[] = $this->executerLigne($pdo, $ligne);
            }

            return $this->response->setJSON(['ok' => true, 'resultats' => $resultats]);
        });
    }

    private function executerLigne(\PDO $pdo, array $ligne): array
    {
        $id      = (int) $ligne['Id_BugSpid'];
        $ancien  = $ligne['AncienIdClub'];
        $nouveau = $ligne['NouveauIdClub'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE equipe SET Id_Club = ? WHERE Id_Club = ?');
            $stmt->execute([$nouveau, $ancien]);
            $nbEquipes = $stmt->rowCount();

            $pdo->prepare('DELETE FROM Club WHERE Id_Club = ?')->execute([$ancien]);

            if (!empty($ligne['EquipeNom'])) {
                $pdo->prepare('UPDATE Club SET EquipeNom = ? WHERE Id_Club = ?')->execute([$ligne['EquipeNom'], $nouveau]);
            }

            $pdo->commit();

            $resultat = "$nbEquipes équipe(s) repointée(s) de $ancien vers $nouveau.";
            $pdo->prepare('UPDATE BugSpid SET Statut=\'Traite\', DateExecution=NOW(), Resultat=? WHERE Id_BugSpid=?')
                ->execute([$resultat, $id]);

            return ['id' => $id, 'ok' => true, 'msg' => $resultat];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'Erreur : ' . $e->getMessage();
            $pdo->prepare('UPDATE BugSpid SET Resultat=? WHERE Id_BugSpid=?')->execute([$msg, $id]);

            return ['id' => $id, 'ok' => false, 'msg' => $msg];
        }
    }

    /** @return array{0:string,1:string,2:string,3:?string,4:?string} */
    private function lireEtValider(array $input): array
    {
        $description = trim($input['description'] ?? '');
        $ancien      = trim($input['ancien_id_club'] ?? '');
        $nouveau     = trim($input['nouveau_id_club'] ?? '');
        $equipeNom   = trim($input['equipe_nom'] ?? '') ?: null;

        if ($description === '' || $ancien === '' || $nouveau === '') {
            return [$description, $ancien, $nouveau, $equipeNom, 'Description, ancien et nouveau Id_Club sont obligatoires.'];
        }

        return [$description, $ancien, $nouveau, $equipeNom, null];
    }
}
