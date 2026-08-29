<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Statistiques des Juges-Arbitres (EN17), portage CI4 de
 * Nominateur/stats_ja.php.
 *
 * Récapitulatif par JA (arbitrages, km, péages, indemnité, total frais) sur
 * une période choisie, avec export CSV. Accessible Administrateur +
 * Nominateur (filtre "auth"). Les deux actions sont en GET (comme le
 * legacy : $.getJSON / window.open) — le filtre CSRF global CI4 ne
 * s'applique qu'aux POST/PUT/PATCH/DELETE, donc jamais ici.
 */
class StatsJaController extends BaseController
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

    /**
     * Lit une date de phase (EA91, saisie/affichée au format MM/JJ) et la
     * normalise en MM-JJ pour l'arithmétique interne (comparaisons lexicales
     * et construction de dates ISO AAAA-MM-JJ, qui exigent le tiret).
     */
    private function phaseCfg(string $cle, string $defautSlash): string
    {
        return str_replace('/', '-', getConfig($cle, $defautSlash));
    }

    /** Vrai si aujourd'hui (MM-JJ) tombe dans [debut, fin] (MM-JJ), fin pouvant franchir le 31 décembre (ex. phase1 09-01→01-31). */
    private function phaseContientDate(string $mmddDebut, string $mmddFin, string $mmddToday): bool
    {
        if ($mmddDebut <= $mmddFin) {
            return $mmddToday >= $mmddDebut && $mmddToday <= $mmddFin;
        }

        return $mmddToday >= $mmddDebut || $mmddToday <= $mmddFin;
    }

    /**
     * Dates calendaires [début, fin] (AAAA-MM-JJ) de la phase $phase (1 ou 2) de
     * la saison démarrant l'année civile $annee, d'après les bornes MM/JJ
     * configurées en EA91. Règle d'année : un mois >= juillet appartient à la
     * 1re année civile de la saison, sinon à la suivante (ex. phase 1 09/01 →
     * $annee-09-01, 01/31 → ($annee+1)-01-31). La fin est bornée à aujourd'hui
     * pour une phase encore en cours.
     *
     * @return array{0: string, 1: string}
     */
    private function datesPhaseAnnee(int $phase, int $annee): array
    {
        $debutMmdd = $this->phaseCfg($phase === 2 ? 'phase2_debut' : 'phase1_debut', $phase === 2 ? '02/01' : '09/01');
        $finMmdd   = $this->phaseCfg($phase === 2 ? 'phase2_fin'   : 'phase1_fin',   $phase === 2 ? '06/30' : '01/31');

        $anneeDe = fn (string $mmdd): int => ((int) substr($mmdd, 0, 2) >= 7) ? $annee : $annee + 1;

        $debut = $anneeDe($debutMmdd) . '-' . $debutMmdd;
        $fin   = $anneeDe($finMmdd)   . '-' . $finMmdd;

        $today = date('Y-m-d');

        return [$debut, min($fin, $today)];
    }

    /**
     * Phase (1|2) et année de saison par défaut : la phase en cours si
     * aujourd'hui y tombe, sinon la phase 2 de la saison précédente (coupure
     * estivale). L'année de saison est l'année civile de son mois de septembre.
     *
     * @return array{0: int, 1: int}
     */
    private function phaseAnneeDefaut(): array
    {
        $p1d = $this->phaseCfg('phase1_debut', '09/01');
        $p1f = $this->phaseCfg('phase1_fin', '01/31');
        $p2d = $this->phaseCfg('phase2_debut', '02/01');
        $p2f = $this->phaseCfg('phase2_fin', '06/30');

        $today = new \DateTimeImmutable('today');
        $mmdd  = $today->format('m-d');
        $y     = (int) $today->format('Y');
        $m     = (int) $today->format('n');

        $anneeSaison = $m >= 7 ? $y : $y - 1;

        if ($this->phaseContientDate($p1d, $p1f, $mmdd)) {
            return [1, $anneeSaison];
        }
        if ($this->phaseContientDate($p2d, $p2f, $mmdd)) {
            return [2, $anneeSaison];
        }

        // Coupure estivale (juillet/août) : dernière phase terminée = phase 2 de la saison écoulée.
        return [2, $y - 1];
    }

    /** Année de saison courante (celle de son mois de septembre). */
    private function anneeSaisonCourante(): int
    {
        return (int) date('n') >= 7 ? (int) date('Y') : (int) date('Y') - 1;
    }

    /**
     * Résout (phase, année) depuis la requête GET, en repliant sur les valeurs
     * par défaut si absentes/invalides.
     *
     * @return array{0: int, 1: int}
     */
    private function phaseAnneeRequete(): array
    {
        [$defPhase, $defAnnee] = $this->phaseAnneeDefaut();
        $phase = (int) ($this->request->getGet('phase') ?: $defPhase);
        $annee = (int) ($this->request->getGet('annee') ?: $defAnnee);
        if (!in_array($phase, [1, 2], true)) {
            $phase = $defPhase;
        }
        if ($annee < 2000 || $annee > (int) date('Y') + 1) {
            $annee = $defAnnee;
        }

        return [$phase, $annee];
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        [$defaultPhase, $defaultAnnee] = $this->phaseAnneeDefaut();
        $anneeCourante = $this->anneeSaisonCourante();

        $data = [
            'nomComplet'    => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement'   => $u['id_departement'] ?? '',
            'changeLogin'   => !empty($u['change_login']),
            'isAdmin'       => !empty($u['is_admin']),
            'defaultPhase'  => $defaultPhase,
            'defaultAnnee'  => $defaultAnnee,
            'anneesDispo'   => range($anneeCourante, $anneeCourante - 6),
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
        } else {
            // Aucun département autorisé résolu : échouer fermé (aucune ligne)
            // plutôt que d'omettre le filtre et exposer tous les départements.
            $sql .= ' AND 1 = 0';
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

            [$phase, $annee] = $this->phaseAnneeRequete();
            [$dateDebut, $dateFin] = $this->datesPhaseAnnee($phase, $annee);

            if ($dateDebut > date('Y-m-d')) {
                return $this->response->setJSON(['ok' => false, 'msg' => "La phase $phase de la saison $annee-" . ($annee + 1) . " n'a pas encore commencé."]);
            }

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

        [$phase, $annee] = $this->phaseAnneeRequete();
        [$dateDebut, $dateFin] = $this->datesPhaseAnnee($phase, $annee);

        if ($dateDebut > date('Y-m-d')) {
            return $this->response->setJSON(['ok' => false, 'msg' => "La phase $phase de la saison $annee-" . ($annee + 1) . " n'a pas encore commencé."]);
        }

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
            ->setHeader('Content-Disposition', 'attachment; filename="stats_ja_saison' . $annee . '_phase' . $phase . '.csv"')
            ->setBody($csv);
    }
}
