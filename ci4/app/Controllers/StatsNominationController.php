<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Statistiques des nominations (EN26).
 *
 * Écran de récapitulatif ouvert dans une nouvelle fenêtre depuis EN14 : toutes
 * les journées / rencontres du périmètre du nominateur avec leur JA nominé
 * (modifiable), plus un tableau des JA nominés et de leur nombre de nominations.
 *
 * Lecture seule côté serveur : la modification du JA réutilise telles quelles
 * les routes d'écriture d'EN14 (nomination/affecter-ja, nomination/retirer-ja),
 * qui portent déjà le contrôle de périmètre et la règle « 2 nominations max par
 * journée ». Aucune logique métier propre ici.
 */
class StatsNominationController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function deptsAutorises(): array
    {
        return getDepartementsAutorises($_SESSION['utilisateur']['id_departement'] ?? null);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('stats_nomination_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
        ]);
    }

    public function data(): ResponseInterface
    {
        try {
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'rencontres' => [], 'jas' => [], 'compteurs' => [], 'disposParDate' => []]);
            }

            $pdo = getPDO();
            $ph  = implode(',', array_fill(0, count($depts), '?'));

            // Rencontres du périmètre + JA nominé (le regroupement par journée est fait côté client)
            $stmt = $pdo->prepare("
                SELECT
                    r.Id_Rencontre, r.Journee, r.Date, r.Heure, r.Poule,
                    dv.Division AS DivisionCode, dv.Color AS DivisionColor,
                    ed.Nom AS NomDom, ee.Nom AS NomExt,
                    d_n.Id_JA AS IdJaAffecte,
                    CONCAT(ja_n.Prenom, ' ', ja_n.Nom) AS NomJaAffecte,
                    n.Valide, n.EmailEnvoye
                FROM rencontre r
                JOIN  equipe   ed  ON ed.Id_Equipe = r.Id_EquipeDom
                JOIN  division dv  ON dv.Division  = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe = r.Id_EquipeExt
                LEFT JOIN nomination n   ON n.Id_Rencontre   = r.Id_Rencontre
                LEFT JOIN disponible d_n ON d_n.Id_Disponible = n.Id_Disponible
                LEFT JOIN ja ja_n        ON ja_n.Id_JA        = d_n.Id_JA
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
                ORDER BY r.Journee, r.Date, dv.Ord, r.Poule, r.Id_Rencontre
            ");
            $stmt->execute($depts);
            $rencontres = $stmt->fetchAll();

            // JA actifs du périmètre (domicile ou CodeDept) — liste des <select>
            $stmt = $pdo->prepare("
                SELECT ja.Id_JA, ja.Nom, ja.Prenom
                FROM ja
                LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
                WHERE ja.Actif = 1
                  AND (LEFT(lp.CodePostal, 2) IN ($ph) OR ja.CodeDept IN ($ph))
                ORDER BY ja.Nom, ja.Prenom
            ");
            $stmt->execute(array_merge($depts, $depts));
            $jas = $stmt->fetchAll();

            // Nombre de nominations par JA (rencontres du périmètre)
            $stmt = $pdo->prepare("
                SELECT ja.Id_JA, ja.Nom, ja.Prenom, COUNT(*) AS Nb
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN ja           ON ja.Id_JA        = d.Id_JA
                JOIN rencontre r  ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed    ON ed.Id_Equipe    = r.Id_EquipeDom
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
                GROUP BY ja.Id_JA
                ORDER BY Nb DESC, ja.Nom, ja.Prenom
            ");
            $stmt->execute($depts);
            $compteurs = $stmt->fetchAll();

            // JA disponibles (Reponse='O', niveau journée ou rencontre précise) pour chaque
            // date de rencontre du périmètre — alimente le filtrage de la combo côté client.
            $stmt = $pdo->prepare("
                SELECT DISTINCT r.Date AS d, dsp.Id_JA
                FROM rencontre r
                JOIN equipe ed     ON ed.Id_Equipe = r.Id_EquipeDom
                JOIN disponible dsp ON dsp.DateCompetition = r.Date AND dsp.Reponse = 'O'
                JOIN ja             ON ja.Id_JA = dsp.Id_JA AND ja.Actif = 1
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
            ");
            $stmt->execute($depts);
            $disposParDate = [];
            foreach ($stmt->fetchAll() as $row) {
                $disposParDate[$row['d']][] = (int) $row['Id_JA'];
            }

            return $this->response->setJSON([
                'ok'            => true,
                'rencontres'    => $rencontres,
                'jas'           => $jas,
                'compteurs'     => $compteurs,
                'disposParDate' => $disposParDate,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }
}
