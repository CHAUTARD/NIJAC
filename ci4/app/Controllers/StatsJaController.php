<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Statistiques des Juges-Arbitres (E028), portage CI4 de
 * Nominateur/stats_ja.php.
 *
 * Récapitulatif par JA (arbitrages, km, péages, indemnité, total frais) sur
 * une période choisie, avec export CSV. Accessible Administrateur +
 * Nominateur (filtre "auth"). Les deux actions sont en GET (comme le
 * legacy : $.getJSON / window.open) — aucune vérification CSRF, cohérent
 * avec `if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);` côté
 * legacy qui ne s'applique jamais ici.
 */
class StatsJaController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/csrf.php';
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

        // Période par défaut : 1er septembre de l'année en cours → aujourd'hui
        $data = [
            'nomComplet'   => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            'changeLogin'  => !empty($u['change_login']),
            'isAdmin'      => !empty($u['is_admin']),
            'csrfToken'    => csrfToken(),
            'defaultDebut' => date('Y') . '-09-01',
            'defaultFin'   => date('Y-m-d'),
        ];

        return view('stats_ja_index', $data);
    }

    /**
     * Construit la requête d'agrégation par JA (partagée entre `donnees` et
     * `exportCsv`), en appliquant le filtre de départements autorisés sur la
     * salle principale du club du JA.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildQuery(array $select, string $groupHaving, string $dateDebut, string $dateFin, array $depts): array
    {
        $indemniteForfait = (float) getConfig('indemnite_forfaitaire', '25.00');
        $tauxKm           = (float) getConfig('frais_kilometrique', '0.30');

        $selectSql = implode(",\n                    ", $select);

        $sql = "
            SELECT
                    $selectSql
            FROM ja
            JOIN nomination n    ON n.Valide = 1
            JOIN disponible dn   ON dn.Id_Disponible = n.Id_Disponible AND dn.Id_JA = ja.Id_JA
            JOIN rencontre  r    ON r.Id_Rencontre  = n.Id_Rencontre
            LEFT JOIN Club  cl   ON cl.Id_Club      = ja.Id_Club
            LEFT JOIN salle s    ON s.Id_Club       = cl.Id_Club AND s.EstPrincipale = 1
            LEFT JOIN laposte lp ON lp.Id_LaPoste   = s.Id_Laposte
            WHERE r.Date BETWEEN :debut AND :fin
        ";
        $params = [
            ':indem'  => $indemniteForfait,
            ':taux'   => $tauxKm,
            ':indem2' => $indemniteForfait,
            ':debut'  => $dateDebut,
            ':fin'    => $dateFin,
        ];

        if ($depts) {
            $deptNamed = [];
            foreach ($depts as $i => $d) {
                $key = ':d' . $i;
                $deptNamed[]  = $key;
                $params[$key] = $d;
            }
            $sql .= ' AND LEFT(lp.CodePostal, 2) IN (' . implode(',', $deptNamed) . ')';
        }

        $sql .= $groupHaving;

        return [$sql, $params];
    }

    public function donnees(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo   = getPDO();
            $u     = $_SESSION['utilisateur'] ?? [];
            $depts = getDepartementsAutorises($u['id_departement'] ?? null);

            $dateDebut = $this->request->getGet('debut') ?? date('Y-09-01');
            $dateFin   = $this->request->getGet('fin') ?? date('Y-m-d');

            [$sql, $params] = $this->buildQuery(
                [
                    'ja.Id_JA',
                    'ja.Nom',
                    'ja.Prenom',
                    'ja.Grade',
                    'cl.Nom                             AS Club',
                    'COUNT(n.Id_Nomination)             AS nb_arbitrages',
                    'COALESCE(SUM(n.Kilometre), 0)      AS total_km',
                    'COALESCE(SUM(n.Peage), 0)          AS total_peages',
                    'COUNT(n.Id_Nomination) * :indem    AS total_indemnite',
                    'COALESCE(SUM(n.Kilometre), 0) * :taux
                        + COALESCE(SUM(n.Peage), 0)
                        + COUNT(n.Id_Nomination) * :indem2 AS total_frais',
                ],
                '
                GROUP BY ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade, cl.Nom
                HAVING nb_arbitrages > 0
                ORDER BY nb_arbitrages DESC, ja.Nom, ja.Prenom
                ',
                $dateDebut,
                $dateFin,
                $depts
            );

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $totaux = [
                'nb_arbitrages'   => array_sum(array_column($rows, 'nb_arbitrages')),
                'total_km'        => array_sum(array_column($rows, 'total_km')),
                'total_peages'    => array_sum(array_column($rows, 'total_peages')),
                'total_indemnite' => array_sum(array_column($rows, 'total_indemnite')),
                'total_frais'     => array_sum(array_column($rows, 'total_frais')),
            ];

            return $this->response->setJSON([
                'ok'     => true,
                'rows'   => $rows,
                'totaux' => $totaux,
                'cfg'    => ['indem' => (float) getConfig('indemnite_forfaitaire', '25.00'), 'taux_km' => (float) getConfig('frais_kilometrique', '0.30')],
            ]);
        });
    }

    public function exportCsv(): ResponseInterface
    {
        $pdo   = getPDO();
        $u     = $_SESSION['utilisateur'] ?? [];
        $depts = getDepartementsAutorises($u['id_departement'] ?? null);

        $dateDebut = $this->request->getGet('debut') ?? date('Y-09-01');
        $dateFin   = $this->request->getGet('fin') ?? date('Y-m-d');

        [$sql, $params] = $this->buildQuery(
            [
                'ja.Nom',
                'ja.Prenom',
                'ja.Grade',
                'cl.Nom AS Club',
                'COUNT(n.Id_Nomination)             AS Arbitrages',
                'COALESCE(SUM(n.Kilometre), 0)      AS Km',
                'COALESCE(SUM(n.Peage), 0)          AS Peages',
                'COUNT(n.Id_Nomination) * :indem    AS Indemnite',
                'COALESCE(SUM(n.Kilometre), 0) * :taux
                    + COALESCE(SUM(n.Peage), 0)
                    + COUNT(n.Id_Nomination) * :indem2 AS Total',
            ],
            ' GROUP BY ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade, cl.Nom HAVING Arbitrages > 0 ORDER BY Arbitrages DESC, ja.Nom',
            $dateDebut,
            $dateFin,
            $depts
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Nom', 'Prénom', 'Grade', 'Club', 'Arbitrages', 'Km', 'Péages (€)', 'Indemnité (€)', 'Total (€)'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['Nom'], $r['Prenom'], $r['Grade'], $r['Club'],
                $r['Arbitrages'],
                $r['Km'],
                number_format((float) $r['Peages'], 2, ',', ''),
                number_format((float) $r['Indemnite'], 2, ',', ''),
                number_format((float) $r['Total'], 2, ',', ''),
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="stats_ja_' . $dateDebut . '_' . $dateFin . '.csv"')
            ->setBody($csv);
    }
}
