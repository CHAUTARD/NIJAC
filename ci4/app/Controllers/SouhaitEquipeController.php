<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * NIJAC – Souhaits des équipes (E044) : saisie par la CSR (Commission Sportive
 * Régionale) du jour souhaité et du souhait d'arbitrage (`equipe.JourSouhaite`,
 * `equipe.SouhaitJA`) des équipes régionales.
 *
 * Ces deux champs ne se saisissent que pour les divisions R3M et R4M — la règle
 * est appliquée aussi côté serveur dans modifier(). Les autres colonnes
 * affichées (Id_Club, nom du club, nom d'équipe, division) sont en lecture
 * seule : ce n'est pas un écran de gestion d'équipes (voir E019/E041).
 *
 * Accès rôle CSR ou Administrateur (filtre "csrauth"). Pas de Model : jointure
 * Club pour l'affichage, réutilise getPDO() directement comme le reste de cette
 * famille d'écrans.
 */
class SouhaitEquipeController extends BaseController
{
    /** Divisions pour lesquelles le jour et l'arbitrage sont saisissables. */
    private const DIVISIONS_SAISIE = ['R3M', 'R4M'];

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../vendor/autoload.php'; // PhpSpreadsheet (import xlsx)
    }

    /** File des rapports d'exécution CSV -> equipe, consultables via rapports(). */
    private function assurerTableRapport(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS souhait_equipe_rapport (
                Id_Rapport INT AUTO_INCREMENT PRIMARY KEY,
                DateExecution DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                Operateur VARCHAR(100) NULL,
                NomFichier VARCHAR(255) NULL,
                NbMaj INT NOT NULL DEFAULT 0,
                NbProblemes INT NOT NULL DEFAULT 0,
                Problemes MEDIUMTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT=\'Rapports d\'\'exécution CSV -> equipe (E044)\''
        );
    }

    /**
     * Libellé de division du fichier CRA -> code de la table `equipe`.
     * « PRENAT M » -> PNM, « PRENAT D » -> PNF, « R1M » -> R1M, « R1D » -> R1F.
     * Retourne null hors périmètre régional (N1M/N2M/N3M ou libellé inconnu).
     */
    private function normaliserDivision(string $brut): ?string
    {
        $b = strtoupper(preg_replace('/\s+/', ' ', trim($brut)));
        if (str_starts_with($b, 'PRENAT')) {
            return 'PN' . (str_ends_with($b, 'D') ? 'F' : 'M');
        }
        if (preg_match('/^R([1-4]) ?([MDF])$/', $b, $m)) {
            return 'R' . $m[1] . ($m[2] === 'D' ? 'F' : $m[2]);
        }

        return null;
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] souhait_equipe PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] souhait_equipe : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        return view('souhait_equipe_index', [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
        ]);
    }

    public function liste(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT e.Id_Equipe, e.Nom, e.Division, e.Id_Club, c.Nom AS NomClub,
                        e.JourSouhaite, e.SouhaitJA
                 FROM equipe e
                 JOIN Club c ON c.Id_Club = e.Id_Club
                 ORDER BY e.Division, e.Nom'
            )->fetchAll();

            return $this->response->setJSON([
                'ok'                => true,
                'data'              => $rows,
                'divisionsSaisie'   => self::DIVISIONS_SAISIE,
            ]);
        });
    }

    public function modifier(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $jourSouhaite = trim($input['jour_souhaite'] ?? '') ?: null;
            $souhaitJa    = trim($input['souhait_ja'] ?? '') ?: null;

            if ($jourSouhaite !== null && !in_array($jourSouhaite, ['Samedi', 'Dimanche'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Jour souhaité invalide.']);
            }
            if ($souhaitJa !== null && !in_array($souhaitJa, ['CRA', 'Club'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Souhait d\'arbitrage invalide.']);
            }

            $stmt = $pdo->prepare('SELECT Division FROM equipe WHERE Id_Equipe = ?');
            $stmt->execute([$idEquipe]);
            $division = $stmt->fetchColumn();
            if ($division === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
            }
            if (!in_array($division, self::DIVISIONS_SAISIE, true)) {
                return $this->response->setJSON([
                    'ok'  => false,
                    'msg' => 'Le jour et l\'arbitrage ne se saisissent que pour les divisions '
                             . implode(' et ', self::DIVISIONS_SAISIE) . '.',
                ]);
            }

            $pdo->prepare('UPDATE equipe SET JourSouhaite = ?, SouhaitJA = ? WHERE Id_Equipe = ?')
                ->execute([$jourSouhaite, $souhaitJa, $idEquipe]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Souhaits enregistrés.']);
        });
    }

    /**
     * « Importer engagements » : reçoit le classeur Excel « Fichiers CRA
     * engagements juges-arbitrages » et renvoie un CSV prêt pour majCsv().
     *
     * Le CSV liste **toutes les équipes régionales de NIJAC** (table `equipe`,
     * hors nationales N1/N2/N3, une ligne par équipe), et non seulement celles
     * présentes dans le classeur : le fichier CRA sert à renseigner le jour souhaité
     * (colonne E, « Dimanche » sinon vide = Samedi) et, pour R3M/R4M seulement,
     * l'arbitrage (colonne G, « … du club » -> Club sinon CRA), en rapprochant
     * sur N° club + division + n° d'équipe final. Les lignes du classeur sans
     * équipe NIJAC correspondante sont signalées à part.
     * Feuille « SECTEUR … », en-tête ligne 3, données à partir de la ligne 4.
     */
    public function xlsxCsv(): ResponseInterface
    {
        return $this->tryJson(function () {
            if (empty($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
            }
            if (strtolower(pathinfo($_FILES['xlsx']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
            }

            $classeur = IOFactory::load($_FILES['xlsx']['tmp_name']);
            $feuille  = null;
            foreach ($classeur->getSheetNames() as $nom) {
                if (stripos($nom, 'secteur') === 0) {
                    $feuille = $classeur->getSheetByName($nom);
                    break;
                }
            }
            $feuille ??= $classeur->getActiveSheet();
            $maxRow = $feuille->getHighestRow();

            // 1. Index du classeur CRA : "IDCLUB8|DIVISION|N°ÉQUIPE" => jour / arbitrage.
            $cra = [];
            for ($r = 4; $r <= $maxRow; $r++) {
                $divCode = $this->normaliserDivision((string) $feuille->getCell('B' . $r)->getValue());
                if ($divCode === null) {
                    continue;
                }
                $id8    = str_pad(preg_replace('/\D/', '', (string) $feuille->getCell('C' . $r)->getValue()), 8, '0', STR_PAD_LEFT);
                $equipe = trim((string) $feuille->getCell('D' . $r)->getValue());
                if (!preg_match('/^\d{8}$/', $id8) || !preg_match('/(\d+)\s*$/', $equipe, $m)) {
                    continue;
                }
                $estR3R4 = in_array($divCode, self::DIVISIONS_SAISIE, true);
                $cra["$id8|$divCode|" . (int) $m[1]] = [
                    'jour'   => stripos((string) $feuille->getCell('E' . $r)->getValue(), 'dimanche') !== false ? 'Dimanche' : '',
                    'arb'    => $estR3R4 ? (stripos((string) $feuille->getCell('G' . $r)->getValue(), 'club') !== false ? 'Club' : 'CRA') : '',
                    'nomCra' => preg_replace('/\s+/', ' ', $equipe),
                ];
            }

            // 2. Roster NIJAC : les équipes régionales (tout sauf les nationales
            // N1/N2/N3 qui n'ont pas de jour/arbitrage à saisir ici).
            $equipes = getPDO()->query(
                "SELECT Id_Club, Nom, Division FROM equipe WHERE Division NOT LIKE 'N%' ORDER BY Division, Nom"
            )->fetchAll();

            $lignes = [];
            $utilises = [];
            foreach ($equipes as $e) {
                if (!preg_match('/(\d+)\s*$/', $e['Nom'], $m)) {
                    continue;
                }
                $cle = $e['Id_Club'] . '|' . $e['Division'] . '|' . (int) $m[1];
                $hit = $cra[$cle] ?? null;
                if ($hit !== null) {
                    $utilises[$cle] = true;
                }

                $lignes[] = [
                    $e['Id_Club'],
                    str_replace(['"', ';', "\r", "\n"], ['', ',', ' ', ' '], $e['Nom']),
                    $e['Division'],
                    $hit['jour'] ?? '',
                    $hit['arb'] ?? '',
                ];
            }

            if (!$lignes) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune équipe régionale (PN à R4) dans NIJAC.']);
            }

            // Lignes du classeur qui n'ont pas trouvé d'équipe NIJAC.
            $ignorees = [];
            foreach ($cra as $cle => $v) {
                if (!isset($utilises[$cle])) {
                    [$id8, $div] = explode('|', $cle);
                    $ignorees[] = "{$v['nomCra']} (N° club $id8, $div)";
                }
            }

            $csv = "\xEF\xBB\xBFN° Club;Équipe;Division;Jour souhaité;Arbitrage\r\n";
            foreach ($lignes as $l) {
                $csv .= implode(';', $l) . "\r\n";
            }

            // Copie horodatée dans Importation/Souhait_R3M-R4M/ (historique).
            $nom     = 'souhaits_R3M_R4M_' . date('YmdHi') . '.csv';
            $dossier = __DIR__ . '/../../../Importation/Souhait_R3M-R4M';
            $chemin  = null;
            if (is_dir($dossier) || @mkdir($dossier, 0755, true)) {
                if (file_put_contents($dossier . '/' . $nom, $csv) !== false) {
                    $chemin = 'Importation/Souhait_R3M-R4M/' . $nom;
                }
            }

            $nbDim = count(array_filter($lignes, static fn ($l) => $l[3] === 'Dimanche'));

            return $this->response->setJSON([
                'ok'       => true,
                'csv'      => $csv,
                'nom'      => $nom,
                'nb'       => count($lignes),
                'nb_dim'   => $nbDim,
                'ignorees' => $ignorees,
                'chemin'   => $chemin,
            ]);
        });
    }

    /**
     * « Exécuter le CSV » : lit un CSV et met à jour equipe.JourSouhaite pour
     * toute division, equipe.SouhaitJA uniquement pour R3M/R4M. Rapprochement
     * sur Id_Club + n° d'équipe final (+ Division quand la colonne est présente,
     * les noms d'équipe FFTT et ceux du fichier CRA diffèrent).
     *
     * Colonnes repérées par leur en-tête (Jour / Arbitrage / Division), pas par
     * position : le fichier accepté peut être celui de xlsxCsv() (5 colonnes,
     * Division = code equipe) ou un CSV manuel « N° Club ; Équipe ; Jour
     * souhaité » (3 colonnes, rapprochement limité à R3M/R4M faute de Division).
     */
    public function majCsv(): ResponseInterface
    {
        return $this->tryJson(function () {
            $file = $this->request->getFile('csv');
            if (!$file || !$file->isValid()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier CSV manquant ou invalide.']);
            }

            $lignes = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if ($lignes === []) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CSV vide.']);
            }
            $lignes[0] = ltrim($lignes[0], "\xEF\xBB\xBF");

            // Repère les colonnes Jour/Arbitrage depuis l'en-tête ; repli
            // positionnel si l'en-tête n'est pas reconnu.
            $sep    = str_contains($lignes[0], ';') ? ';' : ',';
            $entete = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($lignes[0], $sep));
            $colJour = $colArb = $colDiv = null;
            foreach ($entete as $i => $h) {
                if ($colJour === null && str_contains($h, 'jour'))      $colJour = $i;
                if ($colArb === null && str_contains($h, 'arbitrage'))  $colArb  = $i;
                if ($colDiv === null && str_contains($h, 'division'))    $colDiv  = $i;
            }
            $colJour ??= (count($entete) <= 3 ? 2 : 3);

            $pdo = getPDO();
            // Sans colonne Division dans le CSV : on reste sur R3M/R4M (ancien
            // format 3 colonnes). Avec Division : on cible cette division précise.
            $selAvecDiv = $pdo->prepare('SELECT Id_Equipe, Nom, Division FROM equipe WHERE Id_Club = ? AND Division = ?');
            $selR3R4    = $pdo->prepare("SELECT Id_Equipe, Nom, Division FROM equipe WHERE Id_Club = ? AND Division IN ('R3M','R4M')");
            $selClub    = $pdo->prepare('SELECT Nom, Division FROM equipe WHERE Id_Club = ? ORDER BY Division, Nom');
            $majJour    = $pdo->prepare('UPDATE equipe SET JourSouhaite = ? WHERE Id_Equipe = ?');
            $majJourArb = $pdo->prepare('UPDATE equipe SET JourSouhaite = ?, SouhaitJA = ? WHERE Id_Equipe = ?');

            $nbMaj = 0;
            $problemes = [];
            foreach ($lignes as $l) {
                $c = str_getcsv($l, $sep);
                $num    = preg_replace('/\D/', '', trim($c[0] ?? ''));
                $equipe = trim($c[1] ?? '');
                if (!preg_match('/^\d{8}$/', $num) || $equipe === '') {
                    continue; // en-tête ou ligne incomplète
                }
                $jour      = stripos($c[$colJour] ?? '', 'dimanche') !== false ? 'Dimanche' : 'Samedi';
                $arbCel    = $colArb !== null ? trim($c[$colArb] ?? '') : '';
                $divCsv    = $colDiv !== null ? trim($c[$colDiv] ?? '') : '';

                // Préfixe commun à tous les messages du rapport pour cette ligne.
                $ref  = "Ligne CSV « $equipe » (N° club $num" . ($divCsv !== '' ? ", division $divCsv" : '') . ')';
                $rien = $jour === 'Dimanche' ? " — jour « Dimanche » NON enregistré." : ' — non enregistrée.';

                if (!preg_match('/(\d+)\s*$/', $equipe, $m)) {
                    $problemes[] = "$ref : le nom d'équipe ne se termine pas par un numéro, impossible de le rapprocher d'une équipe NIJAC$rien";
                    continue;
                }
                $noEquipe = (int) $m[1];

                if ($divCsv !== '') {
                    $selAvecDiv->execute([$num, $divCsv]);
                    $rows = $selAvecDiv->fetchAll();
                } else {
                    $selR3R4->execute([$num]);
                    $rows = $selR3R4->fetchAll();
                }
                $candidats = [];
                foreach ($rows as $e) {
                    if (preg_match('/(\d+)\s*$/', $e['Nom'], $mm) && (int) $mm[1] === $noEquipe) {
                        $candidats[] = $e;
                    }
                }

                if (count($candidats) > 1) {
                    $noms = implode(', ', array_column($candidats, 'Nom'));
                    $problemes[] = "$ref : rapprochement ambigu, plusieurs équipes NIJAC portent le numéro $noEquipe ($noms). Précisez la division dans le CSV$rien";
                    continue;
                }

                if (count($candidats) === 0) {
                    $selClub->execute([$num]);
                    $enBase = $selClub->fetchAll();
                    if (!$enBase) {
                        $detail = "le N° de club $num n'existe pas dans NIJAC (aucune équipe). Vérifiez ce numéro dans le fichier CRA (colonne C).";
                    } elseif ($divCsv !== '' && !in_array($divCsv, array_column($enBase, 'Division'), true)) {
                        $divs = implode(', ', array_values(array_unique(array_column($enBase, 'Division'))));
                        $detail = "ce club n'a aucune équipe en division $divCsv dans NIJAC (divisions présentes : $divs). Vérifiez la division dans le fichier CRA (colonne B).";
                    } else {
                        $pertinent = array_filter($enBase, static fn ($e) => $divCsv === '' || $e['Division'] === $divCsv);
                        $noms = implode(', ', array_map(static fn ($e) => $e['Nom'] . " ({$e['Division']})", $pertinent));
                        $detail = "aucune équipe NIJAC de ce club ne porte le numéro $noEquipe. Équipes connues : $noms. Vérifiez le n° d'équipe dans le fichier CRA (colonne D).";
                    }
                    $problemes[] = "$ref : $detail$rien";
                    continue;
                }

                $e = $candidats[0];
                // JourSouhaite pour toutes les divisions ; SouhaitJA seulement R3M/R4M.
                if (in_array($e['Division'], self::DIVISIONS_SAISIE, true) && in_array($arbCel, ['CRA', 'Club'], true)) {
                    $majJourArb->execute([$jour, $arbCel, $e['Id_Equipe']]);
                } else {
                    $majJour->execute([$jour, $e['Id_Equipe']]);
                }
                $nbMaj++;
            }

            // Sauvegarde du rapport pour consultation ultérieure (voir rapports()).
            $this->assurerTableRapport($pdo);
            $moi = $_SESSION['utilisateur'] ?? [];
            $ins = $pdo->prepare(
                'INSERT INTO souhait_equipe_rapport (Operateur, NomFichier, NbMaj, NbProblemes, Problemes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([
                trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: ($moi['login'] ?? null),
                $file->getClientName(),
                $nbMaj,
                count($problemes),
                $problemes ? implode("\n", $problemes) : null,
            ]);

            // On ne conserve que les 2 rapports les plus récents.
            $garde = $pdo->query('SELECT Id_Rapport FROM souhait_equipe_rapport ORDER BY Id_Rapport DESC LIMIT 2')
                ->fetchAll(\PDO::FETCH_COLUMN);
            if (count($garde) === 2) {
                $pdo->prepare('DELETE FROM souhait_equipe_rapport WHERE Id_Rapport < ?')->execute([min($garde)]);
            }

            return $this->response->setJSON([
                'ok'          => true,
                'msg'         => "$nbMaj équipe(s) mise(s) à jour, " . count($problemes) . ' non rapprochée(s).',
                'problemes'   => $problemes,
                'id_rapport'  => (int) $pdo->lastInsertId(),
            ]);
        });
    }

    /** Liste des rapports d'exécution CSV (seuls les 2 derniers sont conservés). */
    public function rapports(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            $this->assurerTableRapport($pdo);

            $rows = $pdo->query(
                'SELECT Id_Rapport, DateExecution, Operateur, NomFichier, NbMaj, NbProblemes, Problemes
                 FROM souhait_equipe_rapport ORDER BY Id_Rapport DESC LIMIT 2'
            )->fetchAll();

            foreach ($rows as &$r) {
                $r['Problemes'] = ($r['Problemes'] ?? '') !== '' ? explode("\n", $r['Problemes']) : [];
            }

            return $this->response->setJSON(['ok' => true, 'rapports' => $rows]);
        });
    }
}
