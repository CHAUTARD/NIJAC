<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Remplacement d'équipe (EN24) : une équipe forfait/désistée sur le
 * reste de la saison est remplacée par une autre équipe sur toutes ses
 * rencontres restantes (domicile ou extérieur). Les nominations déjà faites
 * sur ces rencontres sont supprimées (elles ne concernent plus la nouvelle
 * équipe) — le nominateur doit les refaire.
 *
 * Accès Nominateur ou Administrateur (filtre "auth"), comme EN23. Pas de
 * Model : jointures directes comme le reste de cette famille d'écrans
 * (RencontreAdminController, EquipeAdminController...).
 */
class RemplacementEquipeController extends BaseController
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
            log_message('error', '[NIJAC] remplacement_equipe PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, "Erreur technique : le remplacement n'a pas été effectué.")]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] remplacement_equipe : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        return view('remplacement_equipe_index', [
            'nomComplet'   => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'  => $moi['id_departement'] ?? '',
            'changeLogin'  => !empty($moi['change_login']),
            'deptActifs'   => getDeptActifs(),
            'divisionNoms' => getDivisionNoms(),
        ]);
    }

    /** Toutes les équipes (recherche de l'équipe de remplacement, à droite). */
    public function equipes(): ResponseInterface
    {
        return $this->tryJson(function () {
            $equipes = getPDO()->query(
                'SELECT e.Id_Equipe, e.Nom, e.Division, e.Id_Club, c.Nom AS NomClub
                 FROM equipe e
                 JOIN club c ON c.Id_Club = e.Id_Club
                 ORDER BY e.Nom'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'equipes' => $equipes]);
        });
    }

    /**
     * Toutes les rencontres, mêmes informations et filtres que EN23
     * (RencontreAdminController::data(), dont EN23 hérite) — Id_EquipeDom/
     * Id_EquipeExt et le statut de nomination en plus, nécessaires pour
     * cliquer une équipe dans le tableau et savoir si elle a déjà un JA.
     */
    public function data(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Poule, r.Journee, r.Phase,
                        r.Id_EquipeDom, r.Id_EquipeExt,
                        ed.Division, dv.Color AS DivisionColor, ed.Nom AS NomDom, ed.Id_Club AS IdClubDom, ev.Nom AS NomExt,
                        CASE WHEN n.Id_Nomination IS NOT NULL THEN 1 ELSE 0 END AS JaNomme
                 FROM rencontre r
                 JOIN equipe   ed ON ed.Id_Equipe = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 LEFT JOIN division dv ON dv.Division = ed.Division
                 LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre
                 ORDER BY r.Date, r.Heure'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'rencontres' => $rows]);
        });
    }

    /**
     * Remplace $idEquipe par $idRemplacement sur les rencontres listées dans
     * $ids (celles affichées à l'écran au moment de la confirmation, filtres
     * compris — pas forcément toute la saison de l'équipe) : supprime
     * d'abord les nominations existantes sur ces rencontres, puis bascule
     * Id_EquipeDom/Id_EquipeExt vers la nouvelle équipe.
     */
    public function remplacer(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();

            $idEquipe       = (int) ($this->request->getPost('id_equipe') ?? 0);
            $idRemplacement = (int) ($this->request->getPost('id_equipe_remplacement') ?? 0);
            $ids            = array_values(array_unique(array_filter(
                array_map('intval', json_decode($this->request->getPost('ids') ?? '[]', true) ?: [])
            )));

            if (!$idEquipe || !$idRemplacement) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Équipes manquantes.']);
            }
            if ($idEquipe === $idRemplacement) {
                return $this->response->setJSON(['ok' => false, 'msg' => "L'équipe de remplacement doit être différente de l'équipe remplacée."]);
            }
            if (!$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune rencontre sélectionnée.']);
            }

            $chk = $pdo->prepare('SELECT COUNT(*) FROM equipe WHERE Id_Equipe = ?');
            $chk->execute([$idRemplacement]);
            if (!$chk->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Équipe de remplacement introuvable.']);
            }

            // Ne retient, parmi les rencontres demandées, que celles qui impliquent
            // réellement l'équipe à remplacer (Domicile ou Extérieur) — jamais au-delà,
            // même si la liste envoyée par le client était erronée.
            $ph       = implode(',', array_fill(0, count($ids), '?'));
            $stmtRenc = $pdo->prepare("SELECT Id_Rencontre, Id_EquipeDom, Id_EquipeExt FROM rencontre WHERE Id_Rencontre IN ($ph) AND (Id_EquipeDom = ? OR Id_EquipeExt = ?)");
            $stmtRenc->execute([...$ids, $idEquipe, $idEquipe]);
            $concernees = $stmtRenc->fetchAll();

            // Si la remplaçante joue déjà contre l'équipe remplacée sur une rencontre, la remplacer
            // donnerait « B contre B » : ces rencontres sont écartées et signalées.
            $idsRencontre = [];
            foreach ($concernees as $r) {
                $adverse = (int) $r['Id_EquipeDom'] === $idEquipe ? (int) $r['Id_EquipeExt'] : (int) $r['Id_EquipeDom'];
                if ($adverse !== $idRemplacement) {
                    $idsRencontre[] = (int) $r['Id_Rencontre'];
                }
            }
            $nbIgnorees = count($concernees) - count($idsRencontre);

            if (!$idsRencontre) {
                return $this->response->setJSON(['ok' => false, 'msg' => $nbIgnorees
                    ? "Aucune rencontre remplaçable : l'équipe de remplacement joue déjà contre l'équipe remplacée sur les $nbIgnorees rencontre(s) sélectionnée(s)."
                    : 'Aucune rencontre à remplacer parmi la sélection.']);
            }

            $pdo->beginTransaction();
            try {
                $ph2 = implode(',', array_fill(0, count($idsRencontre), '?'));

                // 1. Nominations déjà faites sur ces rencontres (JA nommé, convocation envoyée ou non) :
                //    les JA dont la convocation est partie doivent être prévenus par le nominateur.
                $stmtNoms = $pdo->prepare("
                    SELECT n.EmailEnvoye, CONCAT(ja.Prenom, ' ', ja.Nom) AS NomJa
                    FROM nomination n
                    LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                    LEFT JOIN ja           ON ja.Id_JA        = d.Id_JA
                    WHERE n.Id_Rencontre IN ($ph2)
                ");
                $stmtNoms->execute($idsRencontre);
                $noms          = $stmtNoms->fetchAll();
                $nbNominations = count($noms);
                $aPrevenir     = array_values(array_unique(array_map(
                    static fn ($n) => htmlspecialchars((string) $n['NomJa']),   // affiché dans un toast HTML
                    array_filter($noms, static fn ($n) => (int) $n['EmailEnvoye'] === 1 && $n['NomJa'] !== null)
                )));

                // 2. Supprime ces nominations (la confirmation utilisateur a déjà eu lieu).
                $pdo->prepare("DELETE FROM nomination WHERE Id_Rencontre IN ($ph2)")->execute($idsRencontre);

                // 3. Bascule l'équipe (Domicile ou Extérieur) sur ces rencontres uniquement.
                $pdo->prepare("UPDATE rencontre SET Id_EquipeDom = ? WHERE Id_EquipeDom = ? AND Id_Rencontre IN ($ph2)")
                    ->execute(array_merge([$idRemplacement, $idEquipe], $idsRencontre));
                $pdo->prepare("UPDATE rencontre SET Id_EquipeExt = ? WHERE Id_EquipeExt = ? AND Id_Rencontre IN ($ph2)")
                    ->execute(array_merge([$idRemplacement, $idEquipe], $idsRencontre));

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $msg = count($idsRencontre) . ' rencontre(s) mise(s) à jour.';
            if ($nbIgnorees > 0) {
                $msg .= " $nbIgnorees rencontre(s) ignorée(s) : l'équipe de remplacement y joue déjà contre l'équipe remplacée.";
            }
            if ($nbNominations > 0) {
                $msg .= ' ' . $nbNominations . ' nomination(s) supprimée(s) : à refaire (EN14).';
            }
            if ($aPrevenir) {
                $msg .= ' JA déjà convoqué(s) à prévenir : ' . implode(', ', $aPrevenir) . '.';
            }

            return $this->response->setJSON(['ok' => true, 'msg' => $msg]);
        });
    }
}
