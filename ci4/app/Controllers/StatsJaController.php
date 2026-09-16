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
     * $annee-09-01, 01/31 → ($annee+1)-01-31). Par défaut la fin est bornée à
     * aujourd'hui (phase encore en cours) — utilisé pour les stats d'arbitrages
     * déjà réalisés. Passer $bornerAujourdhui=false pour la période complète de
     * la phase (rencontres *prévues*, y compris futures — ex. graphe EN17).
     *
     * @return array{0: string, 1: string}
     */
    private function datesPhaseAnnee(int $phase, int $annee, bool $bornerAujourdhui = true): array
    {
        $debutMmdd = $this->phaseCfg($phase === 2 ? 'phase2_debut' : 'phase1_debut', $phase === 2 ? '02/01' : '09/01');
        $finMmdd   = $this->phaseCfg($phase === 2 ? 'phase2_fin'   : 'phase1_fin',   $phase === 2 ? '06/30' : '01/31');

        $anneeDe = fn (string $mmdd): int => ((int) substr($mmdd, 0, 2) >= 7) ? $annee : $annee + 1;

        $debut = $anneeDe($debutMmdd) . '-' . $debutMmdd;
        $fin   = $anneeDe($finMmdd)   . '-' . $finMmdd;

        if (!$bornerAujourdhui) {
            return [$debut, $fin];
        }

        return [$debut, min($fin, date('Y-m-d'))];
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

    /**
     * Agrégats par département pour le graphe EN17 : nombre de JA actifs
     * (département du JA, `ja.Id_LaPoste`) et, pour chaque département, le
     * nombre de rencontres par journée (`rencontre.Journee`) sur la période
     * choisie — département du club recevant (salle principale de
     * `rencontre.Id_EquipeDom → equipe.Id_Club`), mêmes règles de repli
     * CodePostal/Cp que le reste de l'appli. `journees` liste les numéros de
     * journée trouvés (triés), une ligne (courbe) par journée côté graphe.
     * `nb_sans_arbitre` distingue le vrai manque (`ArbitrageObligatoire=1` sans
     * nomination Valide) de `nb_arbitre_club` (`ArbitrageObligatoire=0`, R3M/R4M —
     * la CRA ne fournit pas de JA sur ces rencontres ; le club recevant n'en a
     * lui-même que s'il en a fait la demande via EN25, ce qui crée alors une
     * nomination Valide, comptée dans `nb_avec_arbitre`). Sans demande du club,
     * ces rencontres n'ont donc réellement aucun JA, mais ce n'est pas un manque
     * imputable à la CRA : elles restent dans `nb_arbitre_club`, pas dans
     * `nb_sans_arbitre`.
     * Porte sur tous les départements actifs de la ligue (getDeptActifs),
     * pas seulement ceux autorisés à l'utilisateur : simple comptage, pas de
     * donnée nominative.
     */
    public function parDepartement(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo        = getPDO();
            $deptActifs = getDeptActifs();

            [$phase, $annee] = $this->phaseAnneeRequete();
            // Période complète de la phase (non bornée à aujourd'hui) : on compte les
            // rencontres prévues, y compris celles pas encore jouées.
            [$dateDebut, $dateFin] = $this->datesPhaseAnnee($phase, $annee, false);

            if (!$deptActifs) {
                return $this->response->setJSON(['ok' => true, 'rows' => [], 'journees' => [], 'journees_dates' => []]);
            }

            $params = [];
            $deptNamed = [];
            foreach ($deptActifs as $i => $d) {
                $key          = ':d' . $i;
                $deptNamed[]  = $key;
                $params[$key] = str_pad((string) $d['CodeDept'], 2, '0', STR_PAD_LEFT);
            }
            $inClause = implode(',', $deptNamed);

            $stmtJa = $pdo->prepare("
                SELECT LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) AS Dept, COUNT(*) AS nb
                FROM ja
                LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
                WHERE ja.Actif = 1 AND LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) IN ($inClause)
                GROUP BY Dept
            ");
            $stmtJa->execute($params);
            $jaParDept = array_column($stmtJa->fetchAll(), 'nb', 'Dept');

            $paramsR           = $params;
            $paramsR[':debut'] = $dateDebut;
            $paramsR[':fin']   = $dateFin;
            // Sans nomination "Valide" ne veut pas toujours dire sans arbitre : sur les divisions où
            // la CRA ne fournit pas l'arbitrage (R3M/R4M, ArbitrageObligatoire=0 — voir EA82), c'est
            // au club recevant de désigner l'un de ses JA, sans passer par une nomination NIJAC.
            $stmtR = $pdo->prepare("
                SELECT LEFT(COALESCE(lp.CodePostal, s.Cp), 2) AS Dept,
                       r.Journee AS Journee,
                       COUNT(*) AS nb,
                       SUM(CASE WHEN EXISTS (
                           SELECT 1 FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
                       ) THEN 1 ELSE 0 END) AS nb_avec_arbitre,
                       SUM(CASE WHEN r.ArbitrageObligatoire = 0 AND NOT EXISTS (
                           SELECT 1 FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
                       ) THEN 1 ELSE 0 END) AS nb_arbitre_club,
                       SUM(CASE WHEN r.ArbitrageObligatoire = 1 THEN 1 ELSE 0 END) AS nb_besoin_ja,
                       SUM(CASE WHEN r.ArbitrageObligatoire = 0 THEN 1 ELSE 0 END) AS nb_sans_besoin_ja
                FROM rencontre r
                JOIN equipe        ed ON ed.Id_Equipe   = r.Id_EquipeDom
                LEFT JOIN Club     cl ON cl.Id_Club      = ed.Id_Club
                LEFT JOIN salle    s  ON s.Id_Club       = cl.Id_Club AND s.EstPrincipale = 1
                LEFT JOIN laposte  lp ON lp.Id_LaPoste   = s.Id_Laposte
                WHERE r.Date BETWEEN :debut AND :fin AND r.Journee IS NOT NULL
                      AND LEFT(COALESCE(lp.CodePostal, s.Cp), 2) IN ($inClause)
                GROUP BY Dept, Journee
            ");
            $stmtR->execute($paramsR);

            // Par (département, journée) : nb rencontres, nb avec arbitre nommé, nb arbitré par un JA du club.
            $rencParDeptJournee = [];
            $journees           = [];
            foreach ($stmtR->fetchAll() as $r) {
                $j = (int) $r['Journee'];
                $journees[$j] = true;
                $rencParDeptJournee[$r['Dept']][$j] = [
                    'nb'          => (int) $r['nb'],
                    'avec'        => (int) $r['nb_avec_arbitre'],
                    'club'        => (int) $r['nb_arbitre_club'],
                    'besoinJa'    => (int) $r['nb_besoin_ja'],
                    'sansBesoinJa' => (int) $r['nb_sans_besoin_ja'],
                ];
            }
            ksort($journees);
            $journees = array_keys($journees);

            // Plage de dates de chaque journée (une journée couvre généralement un week-end
            // samedi+dimanche, cf. rencontre.Journee) : le seul numéro ne parle pas à
            // l'utilisateur, et n'afficher que la 1re date masquerait les matchs du dimanche.
            $journeesDates = [];
            if ($journees) {
                $stmtDates = $pdo->prepare('
                    SELECT Journee, MIN(Date) AS dmin, MAX(Date) AS dmax FROM rencontre
                    WHERE Date BETWEEN :debut AND :fin AND Journee IS NOT NULL
                    GROUP BY Journee
                ');
                $stmtDates->execute([':debut' => $dateDebut, ':fin' => $dateFin]);
                $plages = [];
                foreach ($stmtDates->fetchAll() as $r) {
                    $plages[$r['Journee']] = [$r['dmin'], $r['dmax']];
                }
                foreach ($journees as $j) {
                    if (!isset($plages[$j])) {
                        $journeesDates[$j] = (string) $j;
                        continue;
                    }
                    [$dmin, $dmax] = $plages[$j];
                    if ($dmin === $dmax) {
                        $journeesDates[$j] = date('d/m/Y', strtotime($dmin));
                    } elseif (date('m/Y', strtotime($dmin)) === date('m/Y', strtotime($dmax))) {
                        $journeesDates[$j] = date('d', strtotime($dmin)) . '-' . date('d/m/Y', strtotime($dmax));
                    } else {
                        $journeesDates[$j] = date('d/m', strtotime($dmin)) . '-' . date('d/m/Y', strtotime($dmax));
                    }
                }
            }

            $rows = [];
            foreach ($deptActifs as $d) {
                $code       = str_pad((string) $d['CodeDept'], 2, '0', STR_PAD_LEFT);
                $parJournee = [];
                foreach ($journees as $j) {
                    $cell = $rencParDeptJournee[$code][$j] ?? ['nb' => 0, 'avec' => 0, 'club' => 0, 'besoinJa' => 0, 'sansBesoinJa' => 0];
                    $parJournee[$j] = [
                        'nb_rencontres'      => $cell['nb'],
                        'nb_avec_arbitre'    => $cell['avec'],
                        'nb_arbitre_club'    => $cell['club'],
                        'nb_sans_arbitre'    => $cell['nb'] - $cell['avec'] - $cell['club'],
                        'nb_besoin_ja'       => $cell['besoinJa'],
                        'nb_sans_besoin_ja'  => $cell['sansBesoinJa'],
                    ];
                }
                $rows[] = [
                    'dept'        => $d['CodeDept'],
                    'nom'         => $d['nom'],
                    'nb_ja'       => (int) ($jaParDept[$code] ?? 0),
                    'par_journee' => $parJournee,
                ];
            }

            return $this->response->setJSON(['ok' => true, 'rows' => $rows, 'journees' => $journees, 'journees_dates' => $journeesDates]);
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
                csvSafe($r['Nom']), csvSafe($r['Prenom']), csvSafe($r['Grade']), csvSafe($r['Club']),
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
