<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Contrôle FFTT 131 (EN30).
 *
 * Confronte les lignes JA des fichiers FFTT 131 chargés dans EN28 (table de travail f131_travail, complétée
 * — jamais vidée — par SuiviNominationController::f131Apercu()) aux nominations en base, avec exactement
 * l'analyse de l'import (SuiviNominationController::analyser131() : mêmes règles, mêmes libellés).
 * Choix d'un département (groupes de getDepartementsAutorises() : 76 + 27…) puis d'une date du fichier ;
 * chaque date affiche le nombre de rencontres sans JA du département à cette date.
 * Validation LIGNE PAR LIGNE (valider()) : seule écriture de l'écran, uniquement si le verdict serveur
 * de la ligne est applicable (création / remplacement). Validation en lot (validerLot()) : toutes les créations R3M / R4M
 * de la journée affichée, recalculées par le serveur. Annulation de la dernière validation (annuler()) d'après
 * l'instantané de session $_SESSION['f131_annulation']. Statut par ligne f131_travail.Traiter (0 = à traiter, 1 = traité) :
 * case à cocher (traiter()), posé à 1 par une validation réussie, remis à 0 par l'annulation ; seules les lignes à traiter
 * sont affichées par défaut et analysées pour les validations. Aucun email.
 *
 * Accès Nominateur ou Administrateur (filtre "auth").
 */
class ControleF131Controller extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('controle_f131_index', [
            'nomComplet'   => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            'groupes'      => $this->groupesDepartements(),
            'divisionNoms' => getDivisionNoms(),
            'annulation'   => self::etatAnnulation(),
        ]);
    }

    /**
     * Départements proposés : un groupe par département actif = getDepartementsAutorises(code) ∩ départements actifs
     * (règles `regles_departements` : 76 inclut 27), un groupe inclus dans un autre est omis (27 seul disparaît).
     * Administrateur : tous les groupes ; sinon seuls ceux du périmètre de l'utilisateur.
     * Retourne [clé => ['libelle' => '76 + 27', 'depts' => ['76', '27'], 'defaut' => bool]].
     */
    private function groupesDepartements(): array
    {
        $u       = $_SESSION['utilisateur'] ?? [];
        $actifs  = getDeptActifs();
        $codes   = array_map('strval', array_column($actifs, 'CodeDept'));
        $noms    = array_column($actifs, 'nom', 'CodeDept');
        $perim   = getDepartementsAutorises($u['id_departement'] ?? null);
        $groupes = [];
        foreach ($codes as $c) {
            $groupes[$c] = array_values(array_intersect(getDepartementsAutorises($c), $codes)) ?: [$c];
        }
        $res = [];
        foreach ($groupes as $c => $depts) {
            foreach ($groupes as $c2 => $autres) {
                if ($c2 !== $c && !array_diff($depts, $autres) && (count($autres) > count($depts) || strcmp((string) $c2, (string) $c) < 0)) {
                    continue 2;   // inclus dans un autre groupe (ou doublon exact : on garde le premier code)
                }
            }
            if (empty($u['is_admin']) && !array_intersect($depts, $perim)) {
                continue;
            }
            $res[$c] = [
                'libelle' => count($depts) > 1 ? implode(' + ', $depts) : $c . ' — ' . ($noms[$c] ?? ''),
                'depts'   => $depts,
                'defaut'  => in_array((string) ($u['id_departement'] ?? ''), $depts, true),
            ];
        }

        return $res;
    }

    /** Groupe demandé (?dept=clé, ou dept POST pour valider-lot), validé contre les groupes autorisés ; null si invalide. */
    private function groupeDemande(?string $cle = null): ?array
    {
        $cle ??= (string) $this->request->getGet('dept');
        $g   = $this->groupesDepartements();

        return $g[$cle]['depts'] ?? null;
    }

    /**
     * Périmètre d'analyse et d'écriture : tous les départements actifs pour l'administrateur (il voit tous les groupes),
     * sinon getDepartementsAutorises() de l'utilisateur (même périmètre que l'import EN28). Le verdict d'une ligne
     * affichée ne dépend que de l'appartenance de son département à ce périmètre : affichage et validation concordent.
     */
    private function perimetre(): array
    {
        $u = $_SESSION['utilisateur'] ?? [];
        if (!empty($u['is_admin'])) {
            return array_map('strval', array_column(getDeptActifs(), 'CodeDept'));
        }

        return array_map('strval', getDepartementsAutorises($u['id_departement'] ?? null));
    }

    /** Date AAAA-MM-JJ valide. */
    private static function dateValide(string $date): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Message si la table de travail est absente ou vide, sinon null. */
    private function travailIndisponible(\PDO $pdo): ?string
    {
        if (!SuiviNominationController::f131TravailExiste($pdo)) {
            return 'Table f131_travail absente : chargez l\'écran EA98 (Administration BDD), puis chargez d\'abord le fichier 131 (EN28 « Mise à jour FFTT 131 »).';
        }
        if (!$pdo->query('SELECT 1 FROM f131_travail LIMIT 1')->fetchColumn()) {
            return 'Aucune ligne FFTT 131 : chargez d\'abord le fichier 131 (EN28 « Suivi des nominations » → « Mise à jour FFTT 131 »).';
        }

        return null;
    }

    /**
     * Analyse d'un ensemble de lignes de f131_travail avec analyser131() (règles de lire131()['lignes']). La clé d'analyse
     * ('id' d'analyser131() : parLigne, ops, deptRecevant, jaInconnu) est Id_F131 ; le n° de ligne Excel (colonne Ligne,
     * informative) n'intervient nulle part.
     */
    private function analyser(\PDO $pdo, array $rows): array
    {
        $lignes = [];
        $dates  = [];
        foreach ($rows as $r) {
            $lic = preg_replace('/\s+/', '', (string) $r['LicenceBrute']);
            $lignes[] = [
                'id'       => (int) $r['Id_F131'],
                'licence'  => ctype_digit($lic) && (int) $lic > 0 ? (int) $lic : null,
                'brut'     => (string) $r['LicenceBrute'],
                'nom'      => trim($r['Nom'] . ' ' . $r['Prenom']),
                'date'     => $r['Date'],
                'dateTxt'  => (string) $r['DateBrute'],
                'dom'      => (string) $r['Equipe1'],
                'ext'      => (string) $r['Equipe2'],
                'division' => (string) $r['Division'],
            ];
            if ($r['Date'] !== null) {
                $dates[] = $r['Date'];
            }
        }

        return SuiviNominationController::analyser131($pdo, $this->perimetre(), $lignes, $dates ? min($dates) : null, $dates ? max($dates) : null);
    }

    /** L'utilisateur peut-il changer le statut Traiter d'une ligne de ce département ? Administrateur : toujours ; sinon département dans perimetre(). */
    private function traiterModifiable(string $dept): bool
    {
        static $perim = null;
        if (!empty($_SESSION['utilisateur']['is_admin'])) {
            return true;
        }
        $perim ??= $this->perimetre();

        return $dept !== '' && in_array($dept, $perim, true);
    }

    /**
     * Lignes de f131_travail analysées comme à l'import, chacune avec son département : club recevant de la rencontre
     * rapprochée, sinon (rencontre non rapprochée) club du JA (colonne D, NumClub) — repère deptJa — et son statut
     * Traiter (0 = à traiter, 1 = traité). Seules les lignes à traiter (Traiter = 0) sont analysées ENSEMBLE et portent
     * les ops (valider / valider-lot) : $an reçoit cette analyse. $avecTraites : ajoute les lignes Traiter = 1, analysées
     * À PART (verdict affiché seulement, jamais d'op, jamais mêlées aux lignes à traiter : une ancienne ligne traitée ne
     * crée pas de « Plusieurs JA pour une rencontre »). Analyse faite avec perimetre().
     */
    private function lignesAnalysees(\PDO $pdo, ?array &$an = null, bool $avecTraites = false): array
    {
        $rows = $pdo->query('
            SELECT Id_F131, Licence, LicenceBrute, Nom, Prenom, NumClub, Club, Date, DateBrute, Journee, Poule,
                   Equipe1, Equipe2, Division, Epreuve, Traiter
            FROM f131_travail ORDER BY Date, Id_F131
        ')->fetchAll(\PDO::FETCH_ASSOC);

        $aTraiter = array_filter($rows, fn ($r) => (int) $r['Traiter'] === 0);
        $an       = $this->analyser($pdo, $aTraiter);
        $traites  = $avecTraites ? array_filter($rows, fn ($r) => (int) $r['Traiter'] !== 0) : [];
        $anT      = $traites ? $this->analyser($pdo, $traites) : null;

        $res = [];
        foreach ($rows as $r) {
            $r['Traiter'] = (int) $r['Traiter'] !== 0 ? 1 : 0;
            if ($r['Traiter'] && !$avecTraites) {
                continue;
            }
            $a = $r['Traiter'] ? $anT : $an;
            $k = (int) $r['Id_F131'];
            $r['deptJa']    = !isset($a['deptRecevant'][$k]);
            $r['dept']      = $a['deptRecevant'][$k] ?? substr((string) $r['NumClub'], 2, 2);
            $r['rapport']   = $a['parLigne'][$k] ?? null;
            $r['jaInconnu'] = $a['jaInconnu'][$k] ?? null;
            // Code division (equipe.Division) de l'équipe recevante de la rencontre rapprochée, lignes à traiter applicables seulement.
            $r['divisionRencontre'] = $r['Traiter'] ? null : ($an['ops'][$k][1]['DivisionDom'] ?? null);
            $r['traiterModifiable'] = $this->traiterModifiable((string) $r['dept']);
            $res[] = $r;
        }

        return $res;
    }

    /**
     * TOUTES les erreurs du fichier (liste GLOBALE, indépendante de la sélection, lecture seule : aucune n'a d'op) :
     * - type 'nonTraitable' : lignes de f131_travail qu'aucune sélection département / journée ne peut afficher, soit date
     *   illisible ou absente (aucune journée), soit département (club recevant, ou club du JA si rencontre non rapprochée)
     *   hors de tous les groupes proposés à l'utilisateur. Motif lisible = causes cumulées : date, rencontre non rapprochée
     *   (motif d'analyser131()), département, JA (analyser131()['jaInconnu']) ;
     * - type 'verdict' : autres lignes au verdict analyser131() « Incohérence » ou « Hors périmètre » (rapport inchangé).
     * Chaque élément porte Id_F131, Traiter, traiterModifiable, Licence, LicenceBrute, Nom, Prenom, Club, Date,
     * DateBrute, Journee, Equipe1, Equipe2, Division, type, motif (nonTraitable) ou rapport (verdict).
     * $rows = lignesAnalysees() : les lignes traitées n'y figurent que si « Afficher les lignes traitées » est coché.
     */
    private function lignesErreurs(array $rows): array
    {
        $visibles = array_merge([], ...array_column($this->groupesDepartements(), 'depts'));
        $horsLib  = empty($_SESSION['utilisateur']['is_admin']) ? 'hors de vos départements' : 'département non actif';
        $res      = [];
        foreach ($rows as $r) {
            $hors = !in_array($r['dept'], $visibles, true);
            $base = [
                'Id_F131' => (int) $r['Id_F131'], 'Traiter' => $r['Traiter'], 'traiterModifiable' => $r['traiterModifiable'],
                'Licence' => $r['Licence'], 'LicenceBrute' => $r['LicenceBrute'],
                'Nom'     => $r['Nom'], 'Prenom' => $r['Prenom'], 'Club' => $r['Club'],
                'Date'    => $r['Date'], 'DateBrute' => $r['DateBrute'], 'Journee' => $r['Journee'],
                'Equipe1' => $r['Equipe1'], 'Equipe2' => $r['Equipe2'], 'Division' => $r['Division'],
            ];
            if ($r['Date'] !== null && !$hors) {   // affichable dans une journée d'un groupe proposé
                if (in_array($r['rapport']['action'] ?? '', ['Incohérence', 'Hors périmètre'], true)) {
                    $res[] = $base + ['type' => 'verdict', 'rapport' => $r['rapport']];
                }
                continue;
            }
            $motifs = [];
            if ($r['Date'] === null) {
                $motifs[] = $r['DateBrute'] === null ? 'date absente' : 'date illisible « ' . $r['DateBrute'] . ' »';
            } elseif ($r['deptJa'] && ($r['rapport']['action'] ?? '') === 'Incohérence') {
                $motifs[] = $r['rapport']['motif'];   // Rencontre introuvable / ambiguë, Équipes absentes
            }
            if ($hors) {
                $motifs[] = !$r['deptJa'] ? 'club recevant (département ' . $r['dept'] . ') ' . $horsLib
                    : ($r['dept'] === '' ? 'rencontre non rapprochée et n° de club du JA absent'
                    : 'rencontre non rapprochée, club du JA (département ' . $r['dept'] . ') ' . $horsLib);
            }
            if ($r['jaInconnu'] !== null) {
                $motifs[] = $r['jaInconnu'];
            }
            $motif = implode(' ; ', $motifs);
            $res[] = $base + ['type' => 'nonTraitable', 'motif' => mb_strtoupper(mb_substr($motif, 0, 1)) . mb_substr($motif, 1)];
        }

        return $res;
    }

    /** ?traites=1 : « Afficher les lignes traitées » coché (lignes Traiter = 1 incluses), sinon lignes à traiter seulement. */
    private function avecTraites(): bool
    {
        return (string) $this->request->getGet('traites') === '1';
    }

    /** GET controle-f131/journees?dept=CLE[&traites=1] : dates des lignes (à traiter, + traitées si traites=1) de ce département + nombre de rencontres sans JA. */
    public function journees(): ResponseInterface
    {
        try {
            $depts = $this->groupeDemande();
            if (!$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Département non autorisé.']);
            }
            $pdo = getPDO();
            if ($msg = $this->travailIndisponible($pdo)) {
                return $this->response->setJSON(['ok' => false, 'vide' => true, 'msg' => $msg]);
            }

            $parDate  = [];
            $sansDate = 0;
            foreach ($this->lignesAnalysees($pdo, $an, $this->avecTraites()) as $r) {
                if (!in_array($r['dept'], $depts, true)) {
                    continue;
                }
                if ($r['Date'] === null) {
                    $sansDate++;
                    continue;
                }
                $parDate[$r['Date']] = ($parDate[$r['Date']] ?? 0) + 1;
            }
            ksort($parDate);

            // Rencontres sans JA (même critère que la ligne « Aucun JA » d'EN28) du département, aux dates du fichier.
            $sansJa = [];
            if ($parDate) {
                $stmt = $pdo->prepare('
                    SELECT r.Date, COUNT(*) AS Nb
                    FROM rencontre r
                    JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                    LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre AND (n.Valide = 1 OR r.ArbitrageCRA = 0)
                    WHERE n.Id_Nomination IS NULL
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                      AND r.Date IN (' . implode(',', array_fill(0, count($parDate), '?')) . ')
                    GROUP BY r.Date
                ');
                $stmt->execute(array_merge($depts, array_keys($parDate)));
                $sansJa = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            }

            $journees = [];
            foreach ($parDate as $d => $n) {
                $journees[] = ['date' => $d, 'lignes' => $n, 'sansJa' => (int) ($sansJa[$d] ?? 0)];
            }

            return $this->response->setJSON(['ok' => true, 'journees' => $journees, 'sansDate' => $sansDate]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 journees : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Liste des journées indisponible.')]);
        }
    }

    /**
     * GET controle-f131/data?dept=CLE&date=AAAA-MM-JJ[&traites=1] : lignes de f131_travail de ce département à cette date
     * + rapport, et erreurs = toutes les erreurs de la table (lignesErreurs() : non traitables + Incohérence / Hors
     * périmètre, indépendantes de la sélection). Sans traites=1, lignes à traiter (Traiter = 0) seulement, partout.
     */
    public function data(): ResponseInterface
    {
        try {
            $depts = $this->groupeDemande();
            if (!$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Département non autorisé.']);
            }
            $date = (string) $this->request->getGet('date');
            if (!self::dateValide($date)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Date invalide.']);
            }
            $pdo = getPDO();
            if ($msg = $this->travailIndisponible($pdo)) {
                return $this->response->setJSON(['ok' => false, 'vide' => true, 'msg' => $msg]);
            }

            $toutes = $this->lignesAnalysees($pdo, $an, $this->avecTraites());
            $lignes = array_values(array_filter(
                $toutes,
                fn ($r) => $r['Date'] === $date && in_array($r['dept'], $depts, true)
            ));

            return $this->response->setJSON(['ok' => true, 'lignes' => $lignes, 'erreurs' => $this->lignesErreurs($toutes)]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 data : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Contrôle indisponible.')]);
        }
    }

    /**
     * POST controle-f131/valider (id_f131) : applique UNE ligne du fichier 131. Le serveur ré-analyse toute la table
     * (analyser131() : plusieurs JA par rencontre, ±7 jours, périmètre, JA inconnu…) dans une transaction et n'écrit que
     * si la ligne est à traiter (Traiter = 0) et que son verdict est applicable (ops[Id_F131] : création ou remplacement),
     * avec l'écriture de l'import (SuiviNominationController::ecrireOperation131()), et passe la ligne à Traiter = 1 dans
     * la même transaction. Rollback sur nomination changée / créée entre-temps.
     * Réponse {ok, msg, rapport, traiter} : rapport = verdict de la ligne recalculé (« Conforme » après succès). Aucun email.
     */
    public function valider(): ResponseInterface
    {
        $id = (int) $this->request->getPost('id_f131');
        if ($id <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne invalide.']);
        }
        try {
            $pdo = getPDO();
            if (!nominationAF131($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Colonne nomination.F131 absente : chargez l\'écran EA98 (Administration BDD).']);
            }
            if ($msg = $this->travailIndisponible($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
            }
            if (!$this->perimetre()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun département autorisé.']);
            }

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT Traiter FROM f131_travail WHERE Id_F131 = ? FOR UPDATE');
                $st->execute([$id]);
                $traiter = $st->fetchColumn();
                if ($traiter === false) {
                    $pdo->rollBack();

                    return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne introuvable : actualisez la page.']);
                }
                if ((int) $traiter !== 0) {
                    $pdo->rollBack();

                    return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne déjà traitée : décochez « Traité » pour la remettre à traiter avant de la valider. Aucune modification enregistrée.']);
                }
                $this->lignesAnalysees($pdo, $an);
                $op = $an['ops'][$id] ?? null;   // présent seulement si verdict Création / Remplacement dans le périmètre
                if ($op === null) {
                    $pdo->rollBack();

                    return $this->response->setJSON([
                        'ok' => false, 'msg' => 'Cette ligne n\'est pas applicable : aucune modification enregistrée.',
                        'rapport' => $an['parLigne'][$id] ?? null,
                    ]);
                }
                $annul = [SuiviNominationController::ecrireOperation131($pdo, ...$op) + ['idF131' => [$id]]];
                $pdo->prepare('UPDATE f131_travail SET Traiter = 1 WHERE Id_F131 = ?')->execute([$id]);   // validée = traitée
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof \PDOException && $e->getCode() === '23000') {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Une nomination a été créée entre-temps pour cette rencontre : aucune modification enregistrée, relancez l\'analyse.']);
                }
                throw $e;
            }
            self::memoriserAnnulation($annul);

            // Verdict recalculé après écriture (attendu : « Conforme ») ; la ligne est désormais traitée (Traiter = 1).
            $rapport = null;
            foreach ($this->lignesAnalysees($pdo, $an, true) as $r) {
                if ((int) $r['Id_F131'] === $id) {
                    $rapport = $r['rapport'];
                    break;
                }
            }

            return $this->response->setJSON([
                'ok'      => true,
                'msg'     => $op[0] === 'creer' ? 'Nomination créée (FFTT 131).' : 'JA remplacé (FFTT 131).',
                'rapport' => $rapport,
                'traiter' => 1,
            ] + self::etatAnnulation());
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 valider : ' . get_class($e) . ' ' . $e->getCode());   // pas de message : aucune donnée de la ligne

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Validation impossible : aucune modification enregistrée.')]);
        }
    }

    /** Divisions (equipe.Division de l'équipe recevante) concernées par la validation en lot. */
    public const DIVISIONS_LOT = ['R3M', 'R4M'];

    /**
     * POST controle-f131/valider-lot (dept, date : sélection courante, mêmes contrôles que data()) : crée en une fois
     * les nominations de toutes les lignes de ce département / cette journée dont le verdict serveur est « Création »
     * (jamais Remplacement) et dont la rencontre rapprochée est en DIVISIONS_LOT (code division de l'équipe recevante).
     * Le serveur ré-analyse toute la table (analyser131(), périmètre perimetre()) dans UNE transaction et écrit chaque
     * ops[Id_F131] avec ecrireOperation131() ; un conflit (création concurrente, nomination changée) annule tout le lot.
     * Seules les lignes à traiter (Traiter = 0) sont candidates ; les lignes écrites passent à Traiter = 1 dans la même transaction.
     * Réponse {ok, msg, nb} ; en échec d'écriture, ids = [Id_F131 en cours d'écriture] (détail affiché par EN30). Aucun email.
     */
    public function validerLot(): ResponseInterface
    {
        $depts = $this->groupeDemande((string) $this->request->getPost('dept'));
        if (!$depts) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Département non autorisé.']);
        }
        $date = (string) $this->request->getPost('date');
        if (!self::dateValide($date)) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Date invalide.']);
        }
        try {
            $pdo = getPDO();
            if (!nominationAF131($pdo)) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Colonne nomination.F131 absente : chargez l\'écran EA98 (Administration BDD).']);
            }
            if ($msg = $this->travailIndisponible($pdo)) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => $msg]);
            }
            if (!$this->perimetre()) {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Aucun département autorisé.']);
            }

            $nb      = 0;
            $annul   = [];
            $enCours = null;
            $pdo->beginTransaction();
            try {
                $ids = [];
                foreach ($this->lignesAnalysees($pdo, $an) as $r) {   // lignes à traiter (Traiter = 0) seulement
                    $id = (int) $r['Id_F131'];
                    $op = $an['ops'][$id] ?? null;
                    if ($r['Date'] !== $date || !in_array($r['dept'], $depts, true) || $op === null || $op[0] !== 'creer'
                        || !in_array((string) ($op[1]['DivisionDom'] ?? ''), self::DIVISIONS_LOT, true)) {
                        continue;
                    }
                    $enCours = $id;   // ligne citée si l'écriture échoue (message d'erreur EN30)
                    $annul[] = SuiviNominationController::ecrireOperation131($pdo, ...$op) + ['idF131' => [$id]];
                    $ids[]   = $id;
                    $nb++;
                }
                if ($nb === 0) {
                    $pdo->rollBack();

                    return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Aucune ligne R3M / R4M « Aucune nomination » à valider : aucune modification enregistrée.']);
                }
                self::marquerTraiter($pdo, $ids, 1);   // lignes écrites = traitées
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof \PDOException && $e->getCode() === '23000') {
                    return $this->response->setJSON(['ok' => false, 'nb' => 0, 'ids' => $enCours ? [$enCours] : [], 'msg' => 'Une nomination a été créée entre-temps pour une de ces rencontres : aucune modification enregistrée, relancez l\'analyse.']);
                }
                throw $e;
            }
            self::memoriserAnnulation($annul);

            return $this->response->setJSON(['ok' => true, 'nb' => $nb, 'msg' => $nb . ' nomination' . ($nb > 1 ? 's créées' : ' créée') . ' (FFTT 131, R3M / R4M).'] + self::etatAnnulation());
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'ids' => isset($enCours) ? [$enCours] : [], 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 valider-lot : ' . get_class($e) . ' ' . $e->getCode());

            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => messageErreur($e, 'Validation impossible : aucune modification enregistrée.')]);
        }
    }

    /** Pose Traiter = $val (0 = à traiter, 1 = traité) sur ces lignes de f131_travail (dans la transaction de l'appelant le cas échéant). */
    private static function marquerTraiter(\PDO $pdo, array $ids, int $val): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 500) as $lot) {
            $pdo->prepare('UPDATE f131_travail SET Traiter = ? WHERE Id_F131 IN (' . implode(',', array_fill(0, count($lot), '?')) . ')')
                ->execute(array_merge([$val], $lot));
        }
    }

    /**
     * POST controle-f131/traiter (id_f131, traiter 0/1) : case « Traité » d'EN30. Change SEULEMENT f131_travail.Traiter
     * de cette ligne (0 = à traiter, 1 = traité), aucune nomination. Mêmes contrôles que valider() (table, périmètre),
     * et la ligne doit appartenir au périmètre de l'utilisateur : département (club recevant, sinon club du JA) dans
     * perimetre() — administrateur : toute ligne. Réponse {ok, msg, traiter}.
     */
    public function traiter(): ResponseInterface
    {
        $id  = (int) $this->request->getPost('id_f131');
        $val = (string) $this->request->getPost('traiter');
        if ($id <= 0 || !in_array($val, ['0', '1'], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Requête invalide.']);
        }
        try {
            $pdo = getPDO();
            if ($msg = $this->travailIndisponible($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
            }
            if (!$this->perimetre()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun département autorisé.']);
            }
            $ligne = null;
            foreach ($this->lignesAnalysees($pdo, $an, true) as $r) {
                if ((int) $r['Id_F131'] === $id) {
                    $ligne = $r;
                    break;
                }
            }
            if ($ligne === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne introuvable : actualisez la page.']);
            }
            if (!$ligne['traiterModifiable']) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ligne hors de vos départements : statut non modifiable.', 'traiter' => $ligne['Traiter']]);
            }
            self::marquerTraiter($pdo, [$id], (int) $val);

            return $this->response->setJSON([
                'ok' => true, 'traiter' => (int) $val,
                'msg' => $val === '1' ? 'Ligne marquée traitée.' : 'Ligne remise à traiter.',
            ]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 traiter : ' . get_class($e) . ' ' . $e->getCode());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Statut non enregistré.')]);
        }
    }

    // ── Annulation de la dernière validation (instantané en session PHP, aucune table) ──

    /** Clé de session de l'instantané : descripteurs d'ecrireOperation131() de la DERNIÈRE validation réussie (valider / valider-lot). */
    private const SESSION_ANNULATION = 'f131_annulation';

    /**
     * Remplace (ou efface si $ops vide) l'instantané d'annulation dans la session native : le filtre « auth » l'a
     * refermée, on la rouvre avec un session_start() BRUT (voir ChangerMotDePasseController), puis on la referme.
     * Appelée juste après le commit : un échec ici est journalisé sans masquer l'écriture réussie.
     */
    private static function memoriserAnnulation(array $ops): void
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if ($ops) {
                $_SESSION[self::SESSION_ANNULATION] = ['date' => date('Y-m-d H:i:s'), 'ops' => array_values($ops)];
            } else {
                unset($_SESSION[self::SESSION_ANNULATION]);
            }
            session_write_close();
        } catch (\Throwable $e) {
            error_log('[NIJAC] EN30 instantané d\'annulation : ' . get_class($e));
        }
    }

    /** État du bouton « Annuler la dernière validation » : {annulable, libelleAnnulation} (ex. « 12 créations », « 1 remplacement »). */
    private static function etatAnnulation(): array
    {
        $ops = $_SESSION[self::SESSION_ANNULATION]['ops'] ?? [];
        $nb  = ['creer' => 0, 'remplacer' => 0];
        foreach ($ops as $d) {
            $nb[$d['op']] = ($nb[$d['op']] ?? 0) + 1;
        }
        $lib = [];
        if ($nb['creer']) {
            $lib[] = $nb['creer'] . ' création' . ($nb['creer'] > 1 ? 's' : '');
        }
        if ($nb['remplacer']) {
            $lib[] = $nb['remplacer'] . ' remplacement' . ($nb['remplacer'] > 1 ? 's' : '');
        }

        return ['annulable' => (bool) $ops, 'libelleAnnulation' => implode(', ', $lib)];
    }

    /**
     * POST controle-f131/annuler : défait la dernière validation (instantané de session) dans UNE transaction, en ordre
     * inverse : création → suppression de la nomination (et de la disponibilité si elle avait été créée, sinon remise de
     * ses anciennes valeurs) ; remplacement → remise de Id_Disponible / Valide / F131 / AccuseReception d'avant, puis même
     * traitement de la disponibilité du JA remplaçant. Refus (rollback, instantané conservé) si une nomination concernée
     * a changé depuis (supprimée, Id_Disponible ≠ celui posé, Valide ≠ 1), a un accusé de réception, ou une convocation
     * envoyée depuis (EmailEnvoye passé à 1). Les lignes f131_travail validées (descripteurs idF131) repassent à Traiter = 0
     * dans la même transaction. Succès : instantané vidé. Réponse {ok, msg, nb, annulable, libelleAnnulation}. Aucun email.
     */
    public function annuler(): ResponseInterface
    {
        $ops = $_SESSION[self::SESSION_ANNULATION]['ops'] ?? [];
        if (!$ops) {
            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Aucune validation à annuler.'] + self::etatAnnulation());
        }
        try {
            $pdo     = getPDO();
            $aAccuse = nominationAAccuseReception($pdo);
            $nb      = 0;
            $pdo->beginTransaction();
            try {
                $lire = $pdo->prepare('SELECT Id_Disponible, Valide, EmailEnvoye' . ($aAccuse ? ', AccuseReception' : '') . ' FROM nomination WHERE Id_Nomination = ? FOR UPDATE');
                foreach (array_reverse($ops) as $d) {
                    $idNom = (int) $d['idNomination'];
                    $lire->execute([$idNom]);
                    $n = $lire->fetch(\PDO::FETCH_ASSOC);
                    $lire->closeCursor();
                    if (!$n || (int) $n['Id_Disponible'] !== (int) $d['idDispo'] || (int) $n['Valide'] !== 1) {
                        throw new \RuntimeException("La nomination n° $idNom a été modifiée depuis la validation : annulation impossible, aucune modification enregistrée.");
                    }
                    if ($aAccuse && $n['AccuseReception'] !== null) {
                        throw new \RuntimeException("La nomination n° $idNom a un accusé de réception : annulation impossible, aucune modification enregistrée.");
                    }
                    $emailAvant = $d['op'] === 'remplacer' ? (int) ($d['avant']['EmailEnvoye'] ?? 0) : 0;
                    if ((int) $n['EmailEnvoye'] === 1 && $emailAvant === 0) {
                        throw new \RuntimeException("La convocation de la nomination n° $idNom a été envoyée : annulation impossible, aucune modification enregistrée.");
                    }

                    if ($d['op'] === 'creer') {
                        $pdo->prepare('DELETE FROM nomination WHERE Id_Nomination = ?')->execute([$idNom]);
                    } else {
                        $av = $d['avant'] ?? null;
                        $ex = $pdo->prepare('SELECT 1 FROM disponible WHERE Id_Disponible = ? FOR UPDATE');
                        $ex->execute([(int) ($av['Id_Disponible'] ?? 0)]);
                        if (!$av || !$ex->fetchColumn()) {
                            throw new \RuntimeException("La disponibilité du JA d'origine de la nomination n° $idNom n'existe plus : annulation impossible, aucune modification enregistrée.");
                        }
                        $accuse = $aAccuse && array_key_exists('AccuseReception', $av) ? ', AccuseReception = ?' : '';
                        $params = [(int) $av['Id_Disponible'], (int) $av['Valide'], $av['F131']];
                        if ($accuse) {
                            $params[] = $av['AccuseReception'];
                        }
                        $pdo->prepare("UPDATE nomination SET Id_Disponible = ?, Valide = ?, F131 = ?$accuse WHERE Id_Nomination = ? AND Id_Disponible = ?")
                            ->execute(array_merge($params, [$idNom, (int) $d['idDispo']]));
                    }

                    // Disponibilité posée par la validation : supprimée si créée, anciennes valeurs remises si modifiée.
                    if (!empty($d['dispo']['cree'])) {
                        $pdo->prepare('DELETE FROM disponible WHERE Id_Disponible = ?')->execute([(int) $d['idDispo']]);
                    } elseif (!empty($d['dispo']['avant'])) {
                        $a = $d['dispo']['avant'];
                        $pdo->prepare('UPDATE disponible SET Reponse = ?, DateReponse = ?, DateCompetition = ?, Note = ? WHERE Id_Disponible = ?')
                            ->execute([$a['Reponse'], $a['DateReponse'], $a['DateCompetition'], $a['Note'], (int) $d['idDispo']]);
                    }
                    $nb++;
                }
                // Lignes 131 de la validation annulée : remises à traiter (instantanés antérieurs sans idF131 : rien à faire).
                $ids = array_merge([], ...array_map(fn ($d) => (array) ($d['idF131'] ?? []), $ops));
                if ($ids && SuiviNominationController::f131TravailExiste($pdo)) {
                    self::marquerTraiter($pdo, $ids, 0);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            self::memoriserAnnulation([]);

            return $this->response->setJSON(['ok' => true, 'nb' => $nb, 'msg' => 'Dernière validation annulée (' . $nb . ' nomination' . ($nb > 1 ? 's' : '') . ' remise' . ($nb > 1 ? 's' : '') . ' en l\'état).'] + self::etatAnnulation());
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && !$e instanceof \PDOException) {   // garde-fous ci-dessus (PDOException hérite de RuntimeException)
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => $e->getMessage()] + self::etatAnnulation());
            }
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => 'Une nomination ou disponibilité concernée a été réutilisée depuis la validation : annulation impossible, aucune modification enregistrée.'] + self::etatAnnulation());
            }
            error_log('[NIJAC] EN30 annuler : ' . get_class($e) . ' ' . $e->getCode());

            return $this->response->setJSON(['ok' => false, 'nb' => 0, 'msg' => messageErreur($e, 'Annulation impossible : aucune modification enregistrée.')] + self::etatAnnulation());
        }
    }
}
