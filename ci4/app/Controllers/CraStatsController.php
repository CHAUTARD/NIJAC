<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Statistiques CRA (EC75), rôle « CRA Convoc ». Lecture seule.
 *
 * JA « Disponibles » (au moins une ligne CRA_Dispo.Disponible = 'Disponible')
 * avec leurs nombres de désignations CRA_Designation comme JA principal (Role 'JA')
 * et comme adjoint (Role 'Adjoint'), toutes compétitions confondues.
 * Périmètre = celui d'EC73 (CraDesignationController::data()) : au moins JA2,
 * départements actifs s'il y en a. Accès depuis E009, filtre "craconvocauth".
 */
class CraStatsController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('cra_stats_index', [
            'nomComplet'   => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            // Graphe : JA prévus (NbrJA + NbrAdjoint) et JA convoqués (désignations avec DateConvocation) par compétition.
            'competitions' => getPDO()->query('SELECT c.Numero, c.NbrJA + c.NbrAdjoint AS Prevus, COUNT(d.DateConvocation) AS Convoques
                FROM CRA_Competition c LEFT JOIN CRA_Designation d ON d.Id_CRA_Competition = c.Id_CRA_Competition
                GROUP BY c.Id_CRA_Competition, c.Numero, c.NbrJA, c.NbrAdjoint, c.DateDebut ORDER BY c.DateDebut, c.Numero')->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }

    /** GET cra-stats/data : une ligne par JA disponible (compteurs globaux, toutes compétitions). */
    public function data(): ResponseInterface
    {
        $params = [];

        $where = '(j.JA2 = 1 OR j.JA3 = 1 OR j.JAN = 1 OR j.JAI = 1)';
        $depts = array_column(getDeptActifs(), 'CodeDept');
        if ($depts) {
            $where .= ' AND j.CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')';
            $params = [...$params, ...$depts];
        }

        $stmt = getPDO()->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.JA1, j.JA2, j.JA3, j.JAN, j.JAI, cl.Nom AS NomClub,
                COALESCE(NULLIF(j.CodeDept, ''), SUBSTRING(NULLIF(j.Id_Club, ''), 3, 2)) AS CodeDept,
                dp.NbDispo, dp.NbAConfirmer, dp.NbSousCondition,
                COALESCE(ds.NbJA, 0) AS NbJA, COALESCE(ds.NbAdj, 0) AS NbAdj
            FROM ja j
            JOIN (SELECT Id_JA,
                         SUM(Disponible = 'Disponible') AS NbDispo,
                         SUM(Disponible = 'À confirmer') AS NbAConfirmer,
                         SUM(Disponible = 'Disponible sous condition') AS NbSousCondition
                  FROM CRA_Dispo GROUP BY Id_JA HAVING NbDispo > 0) dp ON dp.Id_JA = j.Id_JA
            LEFT JOIN (SELECT Id_JA, SUM(Role = 'JA') AS NbJA, SUM(Role = 'Adjoint') AS NbAdj
                       FROM CRA_Designation GROUP BY Id_JA) ds ON ds.Id_JA = j.Id_JA
            LEFT JOIN Club cl ON cl.Id_Club = j.Id_Club
            WHERE $where
            ORDER BY j.Nom, j.Prenom");
        $stmt->execute($params);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }
}
