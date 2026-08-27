<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des rencontres (E042) : édition directe de la table
 * `rencontre` (Date, Heure, Poule, Journee), avec filtres Équipe domicile,
 * Équipe extérieure, Poule, Journée, Date. Distinct des écrans d'import
 * (E011/E017), qui créent les rencontres — celui-ci corrige un enregistrement
 * déjà en base sans repasser par un import.
 *
 * Admin uniquement (filtre "adminauth"). Pas de Model : jointures equipe/
 * division pour l'affichage, réutilise getPDO() directement comme le reste de
 * cette famille d'écrans (EquipeAdminController, EquipeRegionaleController...).
 */
class RencontreAdminController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] rencontre_admin PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] rencontre_admin : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'deptActifs'  => getDeptActifs(),
        ];

        return view('rencontre_admin_index', $data);
    }

    public function data(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Poule, r.Journee, r.Phase,
                        ed.Division, dv.Color AS DivisionColor, ed.Nom AS NomDom, ed.Id_Club AS IdClubDom, ev.Nom AS NomExt
                 FROM rencontre r
                 JOIN equipe   ed ON ed.Id_Equipe = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 LEFT JOIN division dv ON dv.Division = ed.Division
                 ORDER BY r.Date, r.Heure'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'rencontres' => $rows]);
        });
    }

    /**
     * Recherche les rencontres en doublon : plusieurs rencontres pour la même
     * affiche dans la même phase — même équipe domicile, même équipe extérieure,
     * même Phase — quelles que soient la date, l'heure, la journée, la poule ou
     * la salle (une affiche ne se joue qu'une fois par phase). Le GROUP BY de
     * MySQL considère les NULL comme égaux (équipe extérieure « exempt »).
     * Renvoie la liste à plat des Id_Rencontre concernés.
     */
    public function doublons(): ResponseInterface
    {
        return $this->tryJson(function () {
            $groupes = getPDO()->query(
                'SELECT GROUP_CONCAT(Id_Rencontre ORDER BY Id_Rencontre) AS ids, COUNT(*) AS n
                 FROM rencontre
                 GROUP BY Id_EquipeDom, Id_EquipeExt, Phase
                 HAVING n > 1'
            )->fetchAll();

            $ids = [];
            foreach ($groupes as $g) {
                foreach (explode(',', $g['ids']) as $id) {
                    $ids[] = (int) $id;
                }
            }

            return $this->response->setJSON(['ok' => true, 'ids' => $ids, 'groupes' => count($groupes)]);
        });
    }

    public function update(int $idRencontre): ResponseInterface
    {
        return $this->tryJson(function () use ($idRencontre) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $date   = trim($input['date'] ?? '');
            $heure  = trim($input['heure'] ?? '');
            $poule  = (int) ($input['poule'] ?? 0);
            $journee = (int) ($input['journee'] ?? 0);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Date invalide.']);
            }
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $heure)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Heure invalide.']);
            }
            if (strlen($heure) === 5) {
                $heure .= ':00';
            }

            $stmt = $pdo->prepare('UPDATE rencontre SET Date=?, Heure=?, Poule=?, Journee=? WHERE Id_Rencontre=?');
            $stmt->execute([$date, $heure, $poule, $journee, $idRencontre]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM rencontre WHERE Id_Rencontre = ?');
                $chk->execute([$idRencontre]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Rencontre $idRencontre introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Rencontre mise à jour.']);
        });
    }

    public function delete(int $idRencontre): ResponseInterface
    {
        return $this->tryJson(function () use ($idRencontre) {
            $stmt = getPDO()->prepare('DELETE FROM rencontre WHERE Id_Rencontre=?');
            $stmt->execute([$idRencontre]);

            if ($stmt->rowCount() === 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Rencontre $idRencontre introuvable."]);
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Rencontre supprimée.']);
        });
    }
}
