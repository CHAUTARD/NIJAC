<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Équipes régionales (EA92) : consultation/édition des équipes chargées
 * via l'import FFTT (EA82, table `equipe`). Pas de création ici — les équipes
 * sont alimentées par l'import ; seuls les champs liés aux désidératas
 * (réengagement, jour souhaité, souhait JA, saison) sont modifiables, ainsi
 * que la suppression d'une équipe.
 *
 * Admin uniquement (filtre "adminauth"). Pas de Model : jointure Club pour
 * l'affichage, réutilise getPDO() directement comme le reste de cette
 * famille d'écrans (Club, Région, Division...).
 */
class EquipeRegionaleController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    /**
     * Résout le code division (table `division`) à partir du libellé d'en-tête
     * de section du fichier texte (ex. "PRENATIONALE", "REGIONALE 1_SECTEUR_27_76",
     * "R1 FEMININE") — "PRENATIONALE" → PN, "REGIONALE n" / "Rn" → Rn, suffixe M/F
     * selon la présence de "FEMININE".
     */
    private function resoudreDivision(string $entete): ?string
    {
        $entete = strtoupper($entete);
        if (str_contains($entete, 'PRENATIONALE')) {
            $base = 'PN';
        } elseif (preg_match('/R\D*(\d)/', $entete, $m)) {
            $base = 'R' . $m[1];
        } else {
            return null;
        }

        return $base . (str_contains($entete, 'FEMININE') ? 'F' : 'M');
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] equipe_regionale PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] equipe_regionale : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'   => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'  => $moi['id_departement'] ?? '',
            'changeLogin'  => !empty($moi['change_login']),
            'divisionNoms' => getDivisionNoms(),
        ];

        return view('equipe_regionale_index', $data);
    }

    public function liste(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();

            $rows = $pdo->query(
                'SELECT e.Id_Equipe, e.Nom, e.Division, e.Id_Club, c.Nom AS NomClub,
                        e.Id_Club2, c2.Nom AS NomClub2,
                        e.Id_Club3, c3.Nom AS NomClub3,
                        e.JAdemande, e.ReEngagement, e.JourSouhaite, e.SouhaitJA, e.DesiderataSaison
                 FROM equipe e
                 JOIN Club c ON c.Id_Club = e.Id_Club
                 LEFT JOIN Club c2 ON c2.Id_Club = e.Id_Club2
                 LEFT JOIN Club c3 ON c3.Id_Club = e.Id_Club3
                 ORDER BY e.Nom, e.Division'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    /**
     * Import du fichier texte club_Reg_R4.PN.txt (généré depuis le classeur FFTT
     * Excel des poules régionales) : sections par en-tête (ex. "PRENATIONALE",
     * "REGIONALE 1_SECTEUR_27_76") suivies de lignes "N° club(s)\tNom équipe",
     * un club unique ou plusieurs séparés par virgule (équipe "entente").
     * Upsert dans `equipe` sur la clé (Nom, Division) : Id_Club/Id_Club2/Id_Club3
     * (3 premiers clubs — un 4e club éventuel est ignoré et signalé), Division
     * résolue depuis l'en-tête de section, ReEngagement forcé à 'O'.
     */
    public function importerTxt(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();

            $file = $this->request->getFile('fichier');
            if ($file === null || !$file->isValid()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu (post_max_size = ' . ini_get('post_max_size') . ').']);
            }
            if (!in_array(strtolower($file->getClientExtension()), ['txt', 'csv'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format .txt ou .csv est accepté.']);
            }

            $lignes = file($file->getTempName(), FILE_IGNORE_NEW_LINES);
            if ($lignes === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier illisible ou corrompu.']);
            }

            $clubsValides = array_flip(array_column($pdo->query('SELECT Id_Club FROM Club')->fetchAll(), 'Id_Club'));
            $divsValides  = array_flip(array_column($pdo->query('SELECT Division FROM division')->fetchAll(), 'Division'));

            $stmtUpsert = $pdo->prepare(
                "INSERT INTO equipe (Nom, Division, Id_Club, Id_Club2, Id_Club3, ReEngagement, JourSouhaite, SouhaitJA)
                 VALUES (?, ?, ?, ?, ?, 'O', 'Samedi', 'CRA')
                 ON DUPLICATE KEY UPDATE Id_Club = VALUES(Id_Club), Id_Club2 = VALUES(Id_Club2), Id_Club3 = VALUES(Id_Club3),
                                          ReEngagement = 'O', JourSouhaite = 'Samedi', SouhaitJA = 'CRA'"
            );

            $divisionCourante = null;
            $importees        = 0;
            $erreurs          = [];
            $avertissements   = [];

            foreach ($lignes as $n => $ligne) {
                $ligneNo = $n + 1;
                if (!str_contains($ligne, "\t")) {
                    $entete = trim($ligne);
                    if ($entete === '') {
                        continue;
                    }
                    $divisionCourante = $this->resoudreDivision($entete);
                    if ($divisionCourante === null || !isset($divsValides[$divisionCourante])) {
                        $erreurs[] = "Ligne $ligneNo : en-tête « $entete » — division « " . ($divisionCourante ?? '?') . " » non reconnue, section ignorée.";
                        $divisionCourante = false; // ignore les lignes de cette section jusqu'au prochain en-tête
                    }
                    continue;
                }

                if ($divisionCourante === null || $divisionCourante === false) {
                    continue;
                }

                [$clubsChamp, $nom] = array_pad(explode("\t", $ligne, 2), 2, '');
                $nom   = trim($nom);
                $clubs = array_filter(array_map('trim', explode(',', $clubsChamp)));
                $clubs = array_values($clubs);

                if ($nom === '' || !$clubs) {
                    continue;
                }

                $idClub  = $clubs[0];
                $idClub2 = $clubs[1] ?? null;
                $idClub3 = $clubs[2] ?? null;
                if (count($clubs) > 3) {
                    $avertissements[] = "$nom : " . (count($clubs) - 3) . " club(s) au-delà des 3 premiers ignoré(s).";
                }

                if (!isset($clubsValides[$idClub])) {
                    $erreurs[] = "$nom : club « $idClub » inconnu (pas encore synchronisé) — ligne ignorée.";
                    continue;
                }
                if ($idClub2 !== null && !isset($clubsValides[$idClub2])) {
                    $avertissements[] = "$nom : 2e club « $idClub2 » inconnu — ignoré.";
                    $idClub2 = null;
                }
                if ($idClub3 !== null && !isset($clubsValides[$idClub3])) {
                    $avertissements[] = "$nom : 3e club « $idClub3 » inconnu — ignoré.";
                    $idClub3 = null;
                }

                $stmtUpsert->execute([$nom, $divisionCourante, $idClub, $idClub2, $idClub3]);
                $importees++;
            }

            return $this->response->setJSON([
                'ok'             => true,
                'msg'            => "$importees équipe(s) importée(s)/mise(s) à jour.",
                'importees'      => $importees,
                'erreurs'        => $erreurs,
                'avertissements' => $avertissements,
            ]);
        });
    }

    public function modifier(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $reEngagement = trim($input['re_engagement'] ?? '') ?: null;
            $jourSouhaite = trim($input['jour_souhaite'] ?? '') ?: null;
            $souhaitJa    = trim($input['souhait_ja'] ?? '') ?: null;
            $desiderata   = trim($input['desiderata_saison'] ?? '') ?: null;

            if ($reEngagement !== null && !in_array($reEngagement, ['O', 'N'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Réengagement invalide.']);
            }
            if ($jourSouhaite !== null && !in_array($jourSouhaite, ['Samedi', 'Dimanche'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Jour souhaité invalide.']);
            }
            if ($souhaitJa !== null && !in_array($souhaitJa, ['CRA', 'Club'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Souhait JA invalide.']);
            }

            $stmt = $pdo->prepare(
                'UPDATE equipe SET ReEngagement=?, JourSouhaite=?, SouhaitJA=?, DesiderataSaison=? WHERE Id_Equipe=?'
            );
            $stmt->execute([$reEngagement, $jourSouhaite, $souhaitJa, $desiderata, $idEquipe]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM equipe WHERE Id_Equipe = ?');
                $chk->execute([$idEquipe]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Équipe mise à jour.']);
        });
    }

    public function supprimer(int $idEquipe): ResponseInterface
    {
        return $this->tryJson(function () use ($idEquipe) {
            $stmt = getPDO()->prepare('DELETE FROM equipe WHERE Id_Equipe = ?');
            $stmt->execute([$idEquipe]);

            if ($stmt->rowCount() === 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Équipe $idEquipe introuvable."]);
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Équipe supprimée.']);
        });
    }
}
