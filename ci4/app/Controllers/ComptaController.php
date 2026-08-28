<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Comptabilité frais JA (EN16), portage CI4 de Nominateur/compta.php.
 *
 * Récapitulatif des frais de déplacement des JA sur une période, et export
 * CSV au format EBP (journal AC). Accessible Administrateur + Nominateur
 * (filtre "auth"). Pas de Model : agrégations ponctuelles, reste en raw PDO
 * comme le reste de cette famille d'écrans.
 */
class ComptaController extends BaseController
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
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $pdo = getPDO();

        $saison      = getConfig('saison', '');
        $phase1Debut = getConfig('phase1_debut', '09-01');
        $phase1Fin   = getConfig('phase1_fin', '01-31');
        $phase2Debut = getConfig('phase2_debut', '02-01');
        $phase2Fin   = getConfig('phase2_fin', '06-30');

        // Calcule les dates absolues des phases à partir de la saison "2024-2025"
        if (preg_match('/^(\d{4})-(\d{4})$/', $saison, $m)) {
            $an1 = $m[1];
            $an2 = $m[2];
            // Phase 1 : an1-MM-JJ → an2-MM-JJ (si mois fin < mois début, fin est an2)
            $p1DebMois = (int) explode('-', $phase1Debut)[0];
            $p1FinMois = (int) explode('-', $phase1Fin)[0];
            $p1FinAn   = $p1FinMois < $p1DebMois ? $an2 : $an1;
            // Phase 2 : toujours dans an2
            $p2DebAn = $an2;
            $p2FinAn = $an2;

            $dateP1Debut = "$an1-$phase1Debut";
            $dateP1Fin   = "$p1FinAn-$phase1Fin";
            $dateP2Debut = "$p2DebAn-$phase2Debut";
            $dateP2Fin   = "$p2FinAn-$phase2Fin";
        } else {
            // Septembre ou plus → saison qui commence cette année
            // Janvier-Août → saison qui a commencé l'année précédente
            $an1 = (int) date('n') >= 9 ? (int) date('Y') : (int) date('Y') - 1;
            $an2 = $an1 + 1;
            $dateP1Debut = "$an1-$phase1Debut";
            $dateP1Fin   = "$an2-$phase1Fin";
            $dateP2Debut = "$an2-$phase2Debut";
            $dateP2Fin   = "$an2-$phase2Fin";
        }
        $defaultDebut = $dateP1Debut;
        $defaultFin   = $dateP1Fin;

        $data = [
            'nomComplet'   => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            'changeLogin'  => !empty($u['change_login']),
            'isAdmin'      => !empty($u['is_admin']),
            'defaultDebut' => $defaultDebut,
            'defaultFin'   => $defaultFin,
            'dateP1Debut'  => $dateP1Debut,
            'dateP1Fin'    => $dateP1Fin,
            'dateP2Debut'  => $dateP2Debut,
            'dateP2Fin'    => $dateP2Fin,
        ];

        return view('compta_index', $data);
    }

    public function donnees(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo = getPDO();

            $dateDebut = trim($this->request->getPost('date_debut') ?? '');
            $dateFin   = trim($this->request->getPost('date_fin') ?? '');
            if (!$dateDebut || !$dateFin) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Dates obligatoires.']);
            }

            $u     = $_SESSION['utilisateur'] ?? [];
            $depts = getDepartementsAutorises($u['id_departement'] ?? null);
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'data' => [], 'taux_km' => 0, 'indem' => 0]);
            }
            // PDO n'autorise pas de mélanger placeholders nommés et positionnels
            // dans une même requête : les départements utilisent donc aussi des
            // paramètres nommés (:d0, :d1, ...).
            $deptParams = [];
            $deptNamed  = [];
            foreach (array_values($depts) as $i => $d) {
                $key              = ':d' . $i;
                $deptNamed[]      = $key;
                $deptParams[$key] = $d;
            }
            $deptPh = implode(',', $deptNamed);

            $tauxKm    = (float) getConfig('frais_kilometrique', '0.30');
            $indemForf = (float) getConfig('indemnite_forfaitaire', '25.00');

            $stmt = $pdo->prepare("
                SELECT
                    j.Id_JA,
                    j.Nom,
                    j.Prenom,
                    j.Defiscalisation,
                    r.Date                                                        AS DateRencontre,
                    COALESCE(n.Peage, 0)                                          AS Peage,
                    COALESCE(n.Kilometre, 0)                                      AS Kilometre,
                    ROUND((COALESCE(n.Kilometre, 0)) * :taux
                          + COALESCE(n.Peage, 0), 2)                             AS FraisKmPeages,
                    :indem                                                        AS Prestations
                FROM nomination n
                JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN ja j        ON j.Id_JA        = d.Id_JA
                LEFT JOIN Club    cl ON cl.Id_Club    = j.Id_Club
                LEFT JOIN salle   s  ON s.Id_Club     = cl.Id_Club AND s.EstPrincipale = 1
                LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                WHERE (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
                  AND r.Date BETWEEN :debut AND :fin
                  AND LEFT(lp.CodePostal, 2) IN ($deptPh)
                ORDER BY j.Nom, j.Prenom, r.Date
            ");
            $stmt->execute(array_merge([
                ':taux'  => $tauxKm,
                ':indem' => $indemForf,
                ':debut' => $dateDebut,
                ':fin'   => $dateFin,
            ], $deptParams));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['Total'] = round((float) $r['FraisKmPeages'] + (float) $r['Prestations'], 2);
            }
            unset($r);

            return $this->response->setJSON(['ok' => true, 'data' => $rows, 'taux_km' => $tauxKm, 'indem' => $indemForf]);
        });
    }

    public function exportCsv(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo = getPDO();

            $dateDebut = trim($this->request->getPost('date_debut') ?? '');
            $dateFin   = trim($this->request->getPost('date_fin') ?? '');
            if (!$dateDebut || !$dateFin) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Dates obligatoires.']);
            }

            $u     = $_SESSION['utilisateur'] ?? [];
            $depts = getDepartementsAutorises($u['id_departement'] ?? null);
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'csv' => '']);
            }
            $deptParams = [];
            $deptNamed  = [];
            foreach (array_values($depts) as $i => $d) {
                $key              = ':d' . $i;
                $deptNamed[]      = $key;
                $deptParams[$key] = $d;
            }
            $deptPh = implode(',', $deptNamed);

            $tauxKm    = (float) getConfig('frais_kilometrique', '0.30');
            $indemForf = (float) getConfig('indemnite_forfaitaire', '25.00');

            $codeAnalytique = getConfig('code_analytique_compta', '04EPR232');
            $cpte62511      = getConfig('compte_frais_km', '62511');
            $cpte62261      = getConfig('compte_prestations', '62261');

            $stmt = $pdo->prepare("
                SELECT
                    j.Id_JA,
                    j.Nom,
                    j.Prenom,
                    j.NumCompteEBP,
                    ROUND(SUM(COALESCE(n.Kilometre, 0)) * :taux
                          + SUM(COALESCE(n.Peage, 0)), 2)  AS FraisKmPeages,
                    ROUND(COUNT(n.Id_Nomination) * :indem, 2) AS Prestations
                FROM nomination n
                JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN ja j        ON j.Id_JA        = d.Id_JA
                LEFT JOIN Club    cl ON cl.Id_Club    = j.Id_Club
                LEFT JOIN salle   s  ON s.Id_Club     = cl.Id_Club AND s.EstPrincipale = 1
                LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                WHERE (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
                  AND r.Date BETWEEN :debut AND :fin
                  AND LEFT(lp.CodePostal, 2) IN ($deptPh)
                GROUP BY j.Id_JA, j.Nom, j.Prenom, j.NumCompteEBP
                HAVING (FraisKmPeages > 0 OR Prestations > 0)
                ORDER BY j.Nom, j.Prenom
            ");
            $stmt->execute(array_merge([
                ':taux'  => $tauxKm,
                ':indem' => $indemForf,
                ':debut' => $dateDebut,
                ':fin'   => $dateFin,
            ], $deptParams));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $lignes = [];
            foreach ($rows as $r) {
                $libelle = strtoupper($r['Nom']) . ' ' . $r['Prenom'];
                $frais   = (float) $r['FraisKmPeages'];
                $prest   = (float) $r['Prestations'];
                $total   = round($frais + $prest, 2);
                $cpteJA  = $r['NumCompteEBP'] ?? '';

                if ($frais > 0) {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpte62511, 'D',
                        number_format($frais, 2, '.', ''),
                        'virement', $libelle, $codeAnalytique,
                    ]);
                }
                if ($prest > 0) {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpte62261, 'D',
                        number_format($prest, 2, '.', ''),
                        'virement', $libelle, $codeAnalytique,
                    ]);
                }
                if ($total > 0) {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpteJA ?: '?????', 'C',
                        number_format($total, 2, '.', ''),
                        'virement', $libelle,
                    ]);
                    $lignes[] = ''; // ligne vide entre chaque JA
                }
            }

            return $this->response->setJSON(['ok' => true, 'csv' => implode("\n", $lignes)]);
        });
    }
}
