<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Statistiques des nominations (EN26).
 *
 * Écran de récapitulatif ouvert dans une nouvelle fenêtre depuis EN14 : toutes
 * les journées / rencontres du périmètre du nominateur avec leur JA nominé
 * affiché en texte seul, plus un tableau des JA nominés et de leur nombre de
 * nominations.
 *
 * Écran entièrement en lecture seule : aucune route d'écriture, aucune logique
 * métier propre. La nomination / le retrait d'un JA se font dans EN14.
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
                return $this->response->setJSON(['ok' => true, 'rencontres' => [], 'compteurs' => [], 'clubs' => []]);
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

            // Cartouche « Clubs » : pour chaque club du périmètre ayant au moins une équipe
            // régionale, nombre de nominations faites par ses JA (ja.Id_Club) rapporté au
            // quota = nb équipes nationales × nombre_arbitrage_national
            //        + nb équipes régionales × nombre_arbitrage_regional.
            // Régionales = table `equipe` (Division NOT LIKE 'N%'), club porteur principal
            // (Id_Club) ; nationales = table `equipe_nationale`. Les nominations comptées ne
            // sont pas restreintes au périmètre : c'est un indicateur de complétude du club.
            $coefReg = (int) getConfig('nombre_arbitrage_regional', '5');
            $coefNat = (int) getConfig('nombre_arbitrage_national', '7');

            $stmt = $pdo->prepare("
                SELECT c.Id_Club, c.Nom,
                       er.nb              AS NbReg,
                       COALESCE(en.nb, 0) AS NbNat,
                       COALESCE(nm.nb, 0) AS NbNom
                FROM Club c
                JOIN (
                    SELECT Id_Club, COUNT(*) nb FROM equipe
                    WHERE Division NOT LIKE 'N%' GROUP BY Id_Club
                ) er ON er.Id_Club = c.Id_Club
                LEFT JOIN (
                    SELECT Id_Club, COUNT(*) nb FROM equipe_nationale GROUP BY Id_Club
                ) en ON en.Id_Club = c.Id_Club
                LEFT JOIN (
                    SELECT ja.Id_Club, COUNT(*) nb
                    FROM nomination n
                    JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                    JOIN ja           ON ja.Id_JA        = d.Id_JA
                    GROUP BY ja.Id_Club
                ) nm ON nm.Id_Club = c.Id_Club
                WHERE SUBSTRING(c.Id_Club, 3, 2) IN ($ph)
                ORDER BY c.Nom
            ");
            $stmt->execute($depts);
            $clubs = array_map(static function (array $r) use ($coefReg, $coefNat): array {
                $r['NbReg']  = (int) $r['NbReg'];
                $r['NbNat']  = (int) $r['NbNat'];
                $r['NbNom']  = (int) $r['NbNom'];
                $r['Quota']  = $r['NbNat'] * $coefNat + $r['NbReg'] * $coefReg;
                return $r;
            }, $stmt->fetchAll());

            return $this->response->setJSON([
                'ok'         => true,
                'rencontres' => $rencontres,
                'compteurs'  => $compteurs,
                'clubs'      => $clubs,
                'coefReg'    => $coefReg,
                'coefNat'    => $coefNat,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }
}
