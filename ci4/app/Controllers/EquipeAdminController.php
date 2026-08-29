<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des équipes (EA94) : édition directe de la table `equipe`
 * (Nom, Division, Club) avec filtres Club/Division/Nom, sans passer par les
 * écrans d'import. Distinct d'EA92 (Équipes régionales), qui édite les champs
 * de désidératas (ReEngagement, JourSouhaite, SouhaitJA...) d'équipes déjà
 * importées mais laisse Nom/Division/Club en lecture seule.
 *
 * Admin uniquement (filtre "adminauth"). Pas de Model : jointure club pour
 * l'affichage, réutilise getPDO() directement comme le reste de cette famille
 * d'écrans (EquipeRegionaleController, ClubController...).
 */
class EquipeAdminController extends BaseController
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
            log_message('error', '[NIJAC] equipe_admin PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] equipe_admin : ' . $e->getMessage());

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
            'divisionNoms' => getDivisionNoms(),
        ];

        return view('equipe_admin_index', $data);
    }

    /** Équipes + liste des clubs/divisions pour peupler filtres et formulaire d'édition. */
    public function data(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();

            // Département dérivé du numéro de club FFTT (positions 3-4, ex. "09140156" → "14"),
            // validé contre la table `departement` — même convention que
            // ImportRencontresNatController::filtrerCodeDept() (un club "entente" fictif, sans
            // vrai numéro FFTT, produit un code garbage à ignorer plutôt qu'un faux département).
            $equipes = $pdo->query(
                "SELECT e.Id_Equipe, e.Nom, e.Division, e.Id_Club, c.Nom AS NomClub,
                        d.CodeDept AS Departement,
                        e.ReEngagement, e.JourSouhaite, e.SouhaitJA, e.DesiderataSaison
                 FROM equipe e
                 JOIN club c ON c.Id_Club = e.Id_Club
                 LEFT JOIN departement d ON d.CodeDept = SUBSTRING(e.Id_Club, 3, 2)
                 ORDER BY e.Nom"
            )->fetchAll();

            $clubs = $pdo->query('SELECT Id_Club, Nom FROM club ORDER BY Nom')->fetchAll();

            $divisions = $pdo->query('SELECT Division FROM division ORDER BY Division')->fetchAll(\PDO::FETCH_COLUMN);

            // Départements de Normandie (clé config "departements_actifs"), pas la liste dérivée
            // des équipes affichées — un club "adversaire" national (N1-N3) est hors Normandie.
            $departements = getDeptActifs();

            return $this->response->setJSON(['ok' => true, 'equipes' => $equipes, 'clubs' => $clubs, 'divisions' => $divisions, 'departements' => $departements]);
        });
    }

    public function update(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $nom       = trim($input['nom'] ?? '');
            $division  = trim($input['division'] ?? '');
            $idClub    = trim($input['id_club'] ?? '');
            $reeng     = trim($input['re_engagement'] ?? '') ?: null;
            $jourSouh  = trim($input['jour_souhaite'] ?? '') ?: null;
            $souhaitJa = trim($input['souhait_ja'] ?? '') ?: null;
            $desider   = trim($input['desiderata_saison'] ?? '') ?: null;

            if ($nom === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom ne peut pas être vide.']);
            }

            $chkDiv = $pdo->prepare('SELECT 1 FROM division WHERE Division = ?');
            $chkDiv->execute([$division]);
            if (!$chkDiv->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Division « $division » inconnue."]);
            }

            $chkClub = $pdo->prepare('SELECT 1 FROM club WHERE Id_Club = ?');
            $chkClub->execute([$idClub]);
            if (!$chkClub->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Club « $idClub » inconnu."]);
            }

            if ($reeng !== null && !in_array($reeng, ['O', 'N'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Réengagement invalide.']);
            }
            if ($jourSouh !== null && !in_array($jourSouh, ['Samedi', 'Dimanche'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Jour souhaité invalide.']);
            }
            if ($souhaitJa !== null && !in_array($souhaitJa, ['CRA', 'Club'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Souhait JA invalide.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE equipe SET Nom=?, Division=?, Id_Club=?, ReEngagement=?, JourSouhaite=?, SouhaitJA=?, DesiderataSaison=?
                 WHERE Id_Equipe=?'
            );
            $stmt->execute([$nom, $division, $idClub, $reeng, $jourSouh, $souhaitJa, $desider, $idEquipe]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM equipe WHERE Id_Equipe = ?');
                $chk->execute([$idEquipe]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Équipe mise à jour.']);
        });
    }
}
