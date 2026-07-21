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
     * Lit une date de phase (E015, saisie/affichée au format MM/JJ) et la
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

    /** Date calendaire (AAAA-MM-JJ) du début de la phase [debut, fin] (MM-JJ) en cours à la date $mmddToday/$anneeToday. */
    private function dateDebutPhase(string $mmddDebut, string $mmddFin, string $mmddToday, int $anneeToday): string
    {
        // Franchissement du nouvel an : si on est dans la partie janvier de la
        // phase (ex. phase1 09-01→01-31, aujourd'hui = 01-15), la phase a
        // commencé l'année civile précédente.
        $anneeDebut = ($mmddDebut > $mmddFin && $mmddToday < $mmddDebut) ? $anneeToday - 1 : $anneeToday;

        return $anneeDebut . '-' . $mmddDebut;
    }

    /**
     * Parmi les phases données (paires [mmddDebut, mmddFin]), les dates
     * calendaires (AAAA-MM-JJ) de l'occurrence la plus récemment terminée
     * avant aujourd'hui (ex. pendant la coupure estivale, entre la fin de
     * phase2 et le début de phase1).
     *
     * @param array<int, array{0: string, 1: string}> $phases
     * @return array{0: string, 1: string} [dateDebut, dateFin]
     */
    private function phasePrecedente(array $phases, string $mmddToday, int $anneeToday): array
    {
        $candidats = [];
        foreach ($phases as [$mmddDebut, $mmddFin]) {
            $anneeFin       = ($mmddFin <= $mmddToday) ? $anneeToday : $anneeToday - 1;
            $wraparound     = $mmddDebut > $mmddFin;
            $anneeDebut     = $wraparound ? $anneeFin - 1 : $anneeFin;
            $candidats[]    = [$anneeDebut . '-' . $mmddDebut, $anneeFin . '-' . $mmddFin];
        }

        usort($candidats, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return $candidats[0];
    }

    /**
     * Période par défaut du filtre, calculée à partir des dates de phase
     * configurées (E015, MM/JJ, normalisées via phaseCfg()) : dates de la
     * phase en cours si aujourd'hui y tombe (début de phase → aujourd'hui,
     * la phase n'étant pas encore terminée) ; sinon dates complètes de la
     * dernière phase terminée (ex. pendant la coupure estivale) — jamais une
     * date de fin future, cf. periodeValide().
     *
     * @return array{0: string, 1: string} [dateDebut, dateFin]
     */
    private function periodeDefautParPhase(): array
    {
        $phase1Debut = $this->phaseCfg('phase1_debut', '09/01');
        $phase1Fin   = $this->phaseCfg('phase1_fin', '01/31');
        $phase2Debut = $this->phaseCfg('phase2_debut', '02/01');
        $phase2Fin   = $this->phaseCfg('phase2_fin', '06/30');

        $today      = new \DateTimeImmutable('today');
        $mmddToday  = $today->format('m-d');
        $anneeToday = (int) $today->format('Y');
        $aujourdhui = $today->format('Y-m-d');

        if ($this->phaseContientDate($phase1Debut, $phase1Fin, $mmddToday)) {
            return [$this->dateDebutPhase($phase1Debut, $phase1Fin, $mmddToday, $anneeToday), $aujourdhui];
        }
        if ($this->phaseContientDate($phase2Debut, $phase2Fin, $mmddToday)) {
            return [$this->dateDebutPhase($phase2Debut, $phase2Fin, $mmddToday, $anneeToday), $aujourdhui];
        }

        return $this->phasePrecedente(
            [[$phase1Debut, $phase1Fin], [$phase2Debut, $phase2Fin]],
            $mmddToday,
            $anneeToday
        );
    }

    /**
     * Valide le couple de dates du filtre : début non postérieur à la fin
     * (un jour unique est une période valide — cf. le jour même d'un début
     * de phase, où periodeDefautParPhase() renvoie début = fin = aujourd'hui),
     * et fin non postérieure à aujourd'hui. Les dates ne sont plus
     * contraintes à un début de phase (E015) — celui-ci ne sert plus qu'à
     * calculer la période par défaut proposée au chargement.
     */
    private function periodeValide(string $dateDebut, string $dateFin): bool
    {
        if ($dateDebut > $dateFin) {
            return false;
        }

        return $dateFin <= date('Y-m-d');
    }

    /**
     * Message d'erreur adapté à la cause réelle de l'échec de periodeValide().
     */
    private function messagePeriodeInvalide(string $dateDebut, string $dateFin): string
    {
        if ($dateDebut > $dateFin) {
            return 'La date de début doit être antérieure à la date de fin.';
        }

        return 'La date de fin ne peut pas être postérieure à la date du jour.';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        // Période par défaut (E015) : phase en cours, ou dernière phase terminée
        [$defaultDebut, $defaultFin] = $this->periodeDefautParPhase();
        $data = [
            'nomComplet'   => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            'changeLogin'  => !empty($u['change_login']),
            'isAdmin'      => !empty($u['is_admin']),
            'defaultDebut' => $defaultDebut,
            'defaultFin'   => $defaultFin,
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

            [$defautDebut, $defautFin] = $this->periodeDefautParPhase();
            $dateDebut = $this->request->getGet('debut') ?? $defautDebut;
            $dateFin   = $this->request->getGet('fin') ?? $defautFin;

            if (!$this->periodeValide($dateDebut, $dateFin)) {
                return $this->response->setJSON(['ok' => false, 'msg' => $this->messagePeriodeInvalide($dateDebut, $dateFin)]);
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

        [$defautDebut, $defautFin] = $this->periodeDefautParPhase();
        $dateDebut = $this->request->getGet('debut') ?? $defautDebut;
        $dateFin   = $this->request->getGet('fin') ?? $defautFin;

        if (!$this->periodeValide($dateDebut, $dateFin)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $this->messagePeriodeInvalide($dateDebut, $dateFin)]);
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
            ->setHeader('Content-Disposition', 'attachment; filename="stats_ja_' . $dateDebut . '_' . $dateFin . '.csv"')
            ->setBody($csv);
    }
}
