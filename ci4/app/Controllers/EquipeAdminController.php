<?php
// --------------------------------------------------------------------
// NIJAC – Gestion des équipes (EA94) : édition directe de la table `equipe` (Nom, Division, Club) avec filtres Club/Division/Nom, sans
// passer par les écrans d'import. Distinct d'EA92 (Équipes régionales), qui édite les champs de désidératas (ReEngagement, JourSouhaite, ArbitrageCRA...) 
// d'équipes déjà importées mais laisse Nom/Division/Club en lecture seule.
// -------------------------------------------------------------------- 

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des équipes (EA94) : édition directe de la table `equipe`
 * (Nom, Division, Club) avec filtres Club/Division/Nom, sans passer par les
 * écrans d'import. Distinct d'EA92 (Équipes régionales), qui édite les champs
 * de désidératas (ReEngagement, JourSouhaite, ArbitrageCRA...) d'équipes déjà
 * importées mais laisse Nom/Division/Club en lecture seule.
 *
 * Admin uniquement (filtre "adminauth"). Pas de Model : jointure club pour
 * l'affichage, réutilise getPDO() directement comme le reste de cette famille
 * d'écrans (EquipeRegionaleController, ClubController...).
 */
class EquipeAdminController extends BaseController
{
    /** Divisions pour lesquelles le souhait d'arbitrage « Club » est possible (voir ES33). */
    private const DIVISIONS_ARBITRAGE_CLUB = ['R3M', 'R4M'];

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
            'nomComplet'    => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'   => $moi['id_departement'] ?? '',
            'changeLogin'   => !empty($moi['change_login']),
            'divisionNoms'  => getDivisionNoms(),
            'saisonCourante' => getConfig('saison', date('Y') . '-' . (date('Y') + 1)),
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
                "SELECT e.Id_Equipe, e.Nom, e.Division, dv.Color AS DivisionColor, e.Id_Club, c.Nom AS NomClub,
                        e.Id_Club2, c2.Nom AS NomClub2, e.Id_Club3, c3.Nom AS NomClub3,
                        d.CodeDept AS Departement,
                        e.ReEngagement, e.JourSouhaite,
                        CASE WHEN e.ArbitrageCRA = 1 THEN 'CRA' ELSE 'Club' END AS SouhaitJA, e.DesiderataSaison
                 FROM equipe e
                 JOIN club c ON c.Id_Club = e.Id_Club
                 LEFT JOIN club c2 ON c2.Id_Club = e.Id_Club2
                 LEFT JOIN club c3 ON c3.Id_Club = e.Id_Club3
                 LEFT JOIN departement d ON d.CodeDept = SUBSTRING(e.Id_Club, 3, 2)
                 LEFT JOIN division dv ON dv.Division = e.Division
                 ORDER BY e.Nom"
            )->fetchAll();

            $clubs = $pdo->query('SELECT Id_Club, Nom FROM club ORDER BY Nom')->fetchAll();

            $divisions = $pdo->query('SELECT Division, Color FROM division ORDER BY Division')->fetchAll();

            // Départements de Normandie (clé config "departements_actifs"), pas la liste dérivée
            // des équipes affichées — un club "adversaire" national (N1-N3) est hors Normandie.
            $departements = getDeptActifs();

            return $this->response->setJSON(['ok' => true, 'equipes' => $equipes, 'clubs' => $clubs, 'divisions' => $divisions, 'departements' => $departements]);
        });
    }

    public function store(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo   = getPDO();
            $input = $this->request->getPost();

            $nom       = trim($input['nom'] ?? '');
            $division  = trim($input['division'] ?? '');
            $idClub    = trim($input['id_club'] ?? '');
            $idClub2   = trim($input['id_club2'] ?? '') ?: null;
            $idClub3   = trim($input['id_club3'] ?? '') ?: null;
            $reeng     = trim($input['re_engagement'] ?? '') ?: null;
            $jourSouh  = trim($input['jour_souhaite'] ?? '') ?: null;
            $souhaitJa = trim($input['souhait_ja'] ?? '') ?: 'CRA';
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
            foreach (['Club 2' => $idClub2, 'Club 3' => $idClub3] as $libelle => $idClubN) {
                if ($idClubN === null) {
                    continue;
                }
                $chkClub->execute([$idClubN]);
                if (!$chkClub->fetchColumn()) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "$libelle « $idClubN » inconnu."]);
                }
            }

            if ($reeng !== null && !in_array($reeng, ['O', 'N'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Réengagement invalide.']);
            }
            if ($jourSouh !== null && !in_array($jourSouh, ['Samedi', 'Dimanche'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Jour souhaité invalide.']);
            }
            if (!in_array($souhaitJa, ['CRA', 'Club'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Souhait JA invalide.']);
            }
            if ($souhaitJa === 'Club' && !in_array($division, self::DIVISIONS_ARBITRAGE_CLUB, true)) {
                return $this->response->setJSON([
                    'ok'  => false,
                    'msg' => 'Le souhait JA « Club » n\'est possible que pour les divisions '
                             . implode(' et ', self::DIVISIONS_ARBITRAGE_CLUB) . '.',
                ]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO equipe (Nom, Division, Id_Club, Id_Club2, Id_Club3, ReEngagement, JourSouhaite, ArbitrageCRA, DesiderataSaison)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$nom, $division, $idClub, $idClub2, $idClub3, $reeng, $jourSouh, $souhaitJa === 'CRA' ? 1 : 0, $desider]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Équipe créée.', 'id' => (int) $pdo->lastInsertId()]);
        });
    }

    public function update(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $ancien = $pdo->prepare('SELECT ArbitrageCRA FROM equipe WHERE Id_Equipe = ?');
            $ancien->execute([$idEquipe]);
            $ancienArbitrage = $ancien->fetchColumn();
            $ancienArbitrage = $ancienArbitrage === false ? null : (int) $ancienArbitrage;

            $nom       = trim($input['nom'] ?? '');
            $division  = trim($input['division'] ?? '');
            $idClub    = trim($input['id_club'] ?? '');
            $idClub2   = trim($input['id_club2'] ?? '') ?: null;
            $idClub3   = trim($input['id_club3'] ?? '') ?: null;
            $reeng     = trim($input['re_engagement'] ?? '') ?: null;
            $jourSouh  = trim($input['jour_souhaite'] ?? '') ?: null;
            $souhaitJa = trim($input['souhait_ja'] ?? '') ?: 'CRA';
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
            foreach (['Club 2' => $idClub2, 'Club 3' => $idClub3] as $libelle => $idClubN) {
                if ($idClubN === null) {
                    continue;
                }
                $chkClub->execute([$idClubN]);
                if (!$chkClub->fetchColumn()) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "$libelle « $idClubN » inconnu."]);
                }
            }

            if ($reeng !== null && !in_array($reeng, ['O', 'N'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Réengagement invalide.']);
            }
            if ($jourSouh !== null && !in_array($jourSouh, ['Samedi', 'Dimanche'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Jour souhaité invalide.']);
            }
            if (!in_array($souhaitJa, ['CRA', 'Club'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Souhait JA invalide.']);
            }
            if ($souhaitJa === 'Club' && !in_array($division, self::DIVISIONS_ARBITRAGE_CLUB, true)) {
                return $this->response->setJSON([
                    'ok'  => false,
                    'msg' => 'Le souhait JA « Club » n\'est possible que pour les divisions '
                             . implode(' et ', self::DIVISIONS_ARBITRAGE_CLUB) . '.',
                ]);
            }

            $stmt = $pdo->prepare(
                'UPDATE equipe SET Nom=?, Division=?, Id_Club=?, Id_Club2=?, Id_Club3=?, ReEngagement=?, JourSouhaite=?, ArbitrageCRA=?, DesiderataSaison=?
                 WHERE Id_Equipe=?'
            );
            $souhaitJaInt = $souhaitJa === 'CRA' ? 1 : 0;
            $stmt->execute([$nom, $division, $idClub, $idClub2, $idClub3, $reeng, $jourSouh, $souhaitJaInt, $desider, $idEquipe]);

            if ($stmt->rowCount() === 0 && $ancienArbitrage === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
            }

            // Souhait JA (CRA/Club) modifié : resynchronise automatiquement TOUTES les
            // rencontres de l'équipe, y compris celles déjà jouées — sur demande explicite,
            // contrairement à la règle "jamais l'historique" suivie par EN18/ES33/EA92.
            $reponse = ['ok' => true, 'msg' => 'Équipe mise à jour.'];
            if ($ancienArbitrage !== null && $ancienArbitrage !== $souhaitJaInt) {
                $maj = $pdo->prepare('UPDATE rencontre SET ArbitrageCRA=? WHERE Id_EquipeDom=?');
                $maj->execute([$souhaitJaInt, $idEquipe]);
                if ($maj->rowCount() > 0) {
                    $reponse['msg'] .= ' ' . $maj->rowCount() . ' rencontre(s) resynchronisée(s).';
                }
            }

            return $this->response->setJSON($reponse);
        });
    }

    /**
     * Applique le souhait JA (CRA/Club) courant de l'équipe à TOUTES ses rencontres
     * (y compris déjà jouées) dont elle reçoit — bouton ↻ manuel du panneau d'édition.
     * Relit ArbitrageCRA en base plutôt que de faire confiance à une valeur transmise
     * par le client.
     */
    public function appliquerArbitrageRencontres(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo = getPDO();

            $eq = $pdo->prepare('SELECT ArbitrageCRA FROM equipe WHERE Id_Equipe = ?');
            $eq->execute([$idEquipe]);
            $arbitrage = $eq->fetchColumn();
            if ($arbitrage === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
            }

            $stmt = $pdo->prepare('UPDATE rencontre SET ArbitrageCRA=? WHERE Id_EquipeDom=?');
            $stmt->execute([(int) $arbitrage, $idEquipe]);

            return $this->response->setJSON(['ok' => true, 'msg' => $stmt->rowCount() . ' rencontre(s) mise(s) à jour.']);
        });
    }

    public function delete(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo = getPDO();

            // FK rencontre.Id_EquipeDom / Id_EquipeExt en ON DELETE RESTRICT :
            // message explicite plutôt qu'une PDOException brute.
            $chk = $pdo->prepare('SELECT COUNT(*) FROM rencontre WHERE Id_EquipeDom = ? OR Id_EquipeExt = ?');
            $chk->execute([$idEquipe, $idEquipe]);
            $nbRenc = (int) $chk->fetchColumn();
            if ($nbRenc > 0) {
                return $this->response->setJSON([
                    'ok'  => false,
                    'msg' => "Suppression impossible : $nbRenc rencontre(s) référencent cette équipe. Supprimez-les d'abord (EA95).",
                ]);
            }

            $stmt = $pdo->prepare('DELETE FROM equipe WHERE Id_Equipe = ?');
            $stmt->execute([$idEquipe]);

            if ($stmt->rowCount() === 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Équipe supprimée.']);
        });
    }
}
