<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des rencontres (EA95) : édition directe de la table
 * `rencontre` (Date, Heure, Poule, Journee), avec filtres Équipe domicile,
 * Équipe extérieure, Poule, Journée, Date. Distinct des écrans d'import
 * (EA82/EA83), qui créent les rencontres — celui-ci corrige un enregistrement
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

    protected function tryJson(\Closure $fn): ResponseInterface
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
            'nomComplet'   => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'  => $moi['id_departement'] ?? '',
            'changeLogin'  => !empty($moi['change_login']),
            'deptActifs'   => getDeptActifs(),
            'divisionNoms' => getDivisionNoms(),
        ];

        return view('rencontre_admin_index', $data);
    }

    public function data(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Poule, r.Journee, r.Phase,
                        r.Id_EquipeDom, r.Id_EquipeExt, r.id_Salle, r.ArbitrageObligatoire, r.Commentaire,
                        ed.Division, dv.Color AS DivisionColor, ed.Nom AS NomDom, ed.Id_Club AS IdClubDom,
                        ev.Nom AS NomExt, ev.Id_Club AS IdClubExt,
                        ce.Nom AS NomClubExt, ce.CorNom AS CorrNomExt, ce.CorEmail AS CorrEmailExt, ce.CorTelephone AS CorrTelExt,
                        s.Nom AS NomSalle, s.Adresse AS AdresseSalle, s.Cp AS CpSalle, s.Ville AS VilleSalle
                 FROM rencontre r
                 JOIN equipe   ed ON ed.Id_Equipe = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 LEFT JOIN Club ce ON ce.Id_Club = ev.Id_Club
                 LEFT JOIN division dv ON dv.Division = ed.Division
                 LEFT JOIN salle s ON s.Id_Salle = r.id_Salle
                 ORDER BY r.Date, r.Heure'
            )->fetchAll();

            // Catalogues pour le formulaire d'édition : équipe domicile/extérieure
            // (recherche par nom) et salles du club domicile sélectionné.
            $equipes = getPDO()->query('SELECT Id_Equipe, Nom, Division, Id_Club FROM equipe ORDER BY Nom')->fetchAll();
            $salles  = getPDO()->query('SELECT Id_Salle, Nom, Id_Club, EstPrincipale FROM salle ORDER BY Nom')->fetchAll();

            return $this->response->setJSON(['ok' => true, 'rencontres' => $rows, 'equipes' => $equipes, 'salles' => $salles]);
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

            $date        = trim($input['date'] ?? '');
            $heure       = trim($input['heure'] ?? '');
            $poule       = (int) ($input['poule'] ?? 0);
            $journee     = (int) ($input['journee'] ?? 0);
            $phase       = (int) ($input['phase'] ?? 0);
            $idEquipeDom = (int) ($input['id_equipe_dom'] ?? 0);
            $idEquipeExtRaw = trim((string) ($input['id_equipe_ext'] ?? ''));
            $idEquipeExt = $idEquipeExtRaw === '' ? null : (int) $idEquipeExtRaw;
            $idSalleRaw  = trim((string) ($input['id_salle'] ?? ''));
            $idSalle     = $idSalleRaw === '' ? null : (int) $idSalleRaw;
            $arbitrageObligatoire = !empty($input['arbitrage_obligatoire']) ? 1 : 0;
            $commentaire = trim($input['commentaire'] ?? '') ?: null;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Date invalide.']);
            }
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $heure)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Heure invalide.']);
            }
            if (strlen($heure) === 5) {
                $heure .= ':00';
            }
            if (!in_array($phase, [1, 2], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Phase invalide (1 ou 2).']);
            }

            $chkEqDom = $pdo->prepare('SELECT 1 FROM equipe WHERE Id_Equipe = ?');
            $chkEqDom->execute([$idEquipeDom]);
            if (!$chkEqDom->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe domicile « $idEquipeDom » inconnue."]);
            }
            if ($idEquipeExt !== null) {
                $chkEqExt = $pdo->prepare('SELECT 1 FROM equipe WHERE Id_Equipe = ?');
                $chkEqExt->execute([$idEquipeExt]);
                if (!$chkEqExt->fetchColumn()) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Équipe extérieure « $idEquipeExt » inconnue."]);
                }
            }
            if ($idSalle !== null) {
                $chkSalle = $pdo->prepare('SELECT 1 FROM salle WHERE Id_Salle = ?');
                $chkSalle->execute([$idSalle]);
                if (!$chkSalle->fetchColumn()) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Salle « $idSalle » inconnue."]);
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE rencontre SET Date=?, Heure=?, Poule=?, Journee=?, Phase=?, Id_EquipeDom=?, Id_EquipeExt=?, id_Salle=?, ArbitrageObligatoire=?, Commentaire=?
                 WHERE Id_Rencontre=?'
            );
            $stmt->execute([$date, $heure, $poule, $journee, $phase, $idEquipeDom, $idEquipeExt, $idSalle, $arbitrageObligatoire, $commentaire, $idRencontre]);

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
