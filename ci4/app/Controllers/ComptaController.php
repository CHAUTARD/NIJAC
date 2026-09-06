<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Comptes EBP des JA (EN16).
 *
 * Renseignement du champ ja.NumCompteEBP : import d'un fichier CSV « balance
 * EBP » (rapprochement par nom), liste des JA sans compte, saisie manuelle, et
 * export CSV « n° EBP ; NOM Prénom ». Accessible Administrateur + Nominateur
 * (filtre "auth"). Pas de Model : requêtes ponctuelles en raw PDO comme le
 * reste de cette famille d'écrans.
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

    /**
     * Départements autorisés de l'utilisateur + placeholders nommés pour un
     * "CodeDept IN (...)". Retourne [codes[], ":d0,:d1,...", [':d0'=>..., ...]].
     */
    private function deptScope(): array
    {
        $u     = $_SESSION['utilisateur'] ?? [];
        $depts = getDepartementsAutorises($u['id_departement'] ?? null);
        $named = [];
        $params = [];
        foreach (array_values($depts) as $i => $d) {
            $named[]           = ':d' . $i;
            $params[':d' . $i] = $d;
        }
        return [$depts, implode(',', $named), $params];
    }

    /** Normalise un « NOM Prénom » pour comparaison : sans accents, majuscules,
     *  tout séparateur → espace simple. */
    private function normNom(string $s): string
    {
        $s = str_replace(
            ['à','â','ä','ç','è','é','ê','ë','î','ï','ô','ö','ù','û','ü','ÿ','æ','œ',
             'À','Â','Ä','Ç','È','É','Ê','Ë','Î','Ï','Ô','Ö','Ù','Û','Ü','Ÿ','Æ','Œ'],
            ['A','A','A','C','E','E','E','E','I','I','O','O','U','U','U','Y','AE','OE',
             'A','A','A','C','E','E','E','E','I','I','O','O','U','U','U','Y','AE','OE'],
            $s
        );
        $s = mb_strtoupper($s, 'UTF-8');
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * EN16 – Liste des JA du périmètre sans NumCompteEBP (ou tous si ?tous=1).
     * Pour les JA défiscalisés : total des kilomètres arbitrés (toutes
     * nominations en base), puissance fiscale et énergie du véhicule.
     */
    public function jaSansCompte(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            [$depts, $ph, $params] = $this->deptScope();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            $where = "j.CodeDept IN ($ph)";
            if ($this->request->getGet('tous') !== '1') {
                $where .= " AND (j.NumCompteEBP IS NULL OR j.NumCompteEBP = '')";
            }

            $stmt = $pdo->prepare("
                SELECT
                    j.Id_JA, j.Nom, j.Prenom, j.CodeDept, j.Actif,
                    COALESCE(j.NumCompteEBP, '') AS NumCompteEBP,
                    j.Defiscalisation, j.PuissanceFiscale, j.VehiculeElectrique,
                    (SELECT COALESCE(SUM(n.Kilometre), 0)
                       FROM nomination n
                       JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                      WHERE d.Id_JA = j.Id_JA) AS KmTotal
                FROM ja j
                WHERE $where
                ORDER BY j.Nom, j.Prenom
            ");
            $stmt->execute($params);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        });
    }

    /**
     * EN16 – Mise à jour manuelle du NumCompteEBP d'un JA du périmètre.
     */
    public function majCompte(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            [$depts, $ph, $params] = $this->deptScope();
            if (!$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun département autorisé.']);
            }

            $idJa      = (int) $this->request->getPost('id_ja');
            $numCompte = trim($this->request->getPost('num_compte') ?? '');
            if ($idJa <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'JA invalide.']);
            }
            if (mb_strlen($numCompte) > 20) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de compte trop long (20 caractères max).']);
            }

            $stmt = $pdo->prepare("UPDATE ja SET NumCompteEBP = :n WHERE Id_JA = :id AND CodeDept IN ($ph)");
            $stmt->execute(array_merge([':n' => ($numCompte === '' ? null : $numCompte), ':id' => $idJa], $params));

            return $this->response->setJSON([
                'ok'  => true,
                'msg' => $stmt->rowCount() ? 'Compte mis à jour.' : 'Aucune modification.',
            ]);
        });
    }

    /**
     * EN16 – Import d'un fichier CSV « balance EBP » : rapproche chaque ligne
     * (nom + prénom / n° de compte, ordre des 2 colonnes indifférent) avec un JA
     * du périmètre et renseigne NumCompteEBP. Renvoie le détail ligne à ligne.
     */
    public function importEbp(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            [$depts, $ph, $params] = $this->deptScope();
            if (!$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun département autorisé.']);
            }

            if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier CSV manquant ou illisible.']);
            }
            if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'csv') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le fichier doit avoir l\'extension .csv.']);
            }

            $fh = fopen($_FILES['fichier']['tmp_name'], 'r');
            if ($fh === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
            }

            $firstLine = (string) fgets($fh);
            rewind($fh);
            $sep = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

            // Index des JA du périmètre : clé normalisée « NOM PRENOM » (et l'inverse) → Id_JA.
            $stmt = $pdo->prepare("SELECT Id_JA, Nom, Prenom, COALESCE(NumCompteEBP, '') AS NumCompteEBP FROM ja WHERE CodeDept IN ($ph)");
            $stmt->execute($params);
            $index   = [];
            $courant = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                $courant[$j['Id_JA']] = $j['NumCompteEBP'];
                foreach ([$j['Nom'] . ' ' . $j['Prenom'], $j['Prenom'] . ' ' . $j['Nom']] as $variante) {
                    $index[$this->normNom($variante)][$j['Id_JA']] = true;
                }
            }

            $upd       = $pdo->prepare('UPDATE ja SET NumCompteEBP = ? WHERE Id_JA = ?');
            $resultats = [];
            $nbMaj     = 0;

            while (($row = fgetcsv($fh, 0, $sep)) !== false) {
                if (count($row) < 2) {
                    continue;
                }
                $a = trim((string) $row[0]);
                $b = trim((string) $row[1]);

                // Repère la colonne « compte » (que des chiffres) et la colonne « nom ».
                $aNum = ctype_digit(str_replace(' ', '', $a));
                $bNum = ctype_digit(str_replace(' ', '', $b));
                if ($aNum === $bNum) {
                    continue; // 0 ou 2 colonnes numériques → en-tête, sous-totaux, ligne vide…
                }
                [$compte, $nom] = $aNum ? [str_replace(' ', '', $a), $b] : [str_replace(' ', '', $b), $a];
                if ($nom === '' || $compte === '' || strlen($compte) > 20) {
                    continue;
                }

                $ids = array_keys($index[$this->normNom($nom)] ?? []);

                if (count($ids) === 0) {
                    $statut = 'introuvable';
                    $detail = 'Aucun JA du périmètre ne porte ce nom';
                } elseif (count($ids) > 1) {
                    $statut = 'ambigu';
                    $detail = count($ids) . ' JA portent ce nom — non modifié';
                } elseif ($courant[$ids[0]] === $compte) {
                    $statut = 'inchange';
                    $detail = 'Déjà renseigné';
                } else {
                    $upd->execute([$compte, $ids[0]]);
                    $courant[$ids[0]] = $compte;
                    $statut = 'maj';
                    $detail = 'Compte renseigné';
                    $nbMaj++;
                }

                $resultats[] = ['nom' => $nom, 'compte' => $compte, 'statut' => $statut, 'detail' => $detail];
            }
            fclose($fh);

            return $this->response->setJSON([
                'ok'        => true,
                'nb_maj'    => $nbMaj,
                'nb_lignes' => count($resultats),
                'resultats' => $resultats,
            ]);
        });
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('compta_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
        ]);
    }

    /**
     * EN16 – Export CSV « compte;nom » des JA du périmètre ayant un
     * NumCompteEBP renseigné (NOM en majuscules + Prénom). Réimportable tel
     * quel par importEbp().
     */
    public function exportCsv(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            [$depts, $ph, $params] = $this->deptScope();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'csv' => '']);
            }

            $stmt = $pdo->prepare("
                SELECT NumCompteEBP, Nom, Prenom
                FROM ja
                WHERE CodeDept IN ($ph)
                  AND NumCompteEBP IS NOT NULL AND NumCompteEBP <> ''
                ORDER BY Nom, Prenom
            ");
            $stmt->execute($params);

            $lignes = ['compte;nom'];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $lignes[] = $r['NumCompteEBP'] . ';' . trim(mb_strtoupper($r['Nom'] ?? '', 'UTF-8') . ' ' . ($r['Prenom'] ?? ''));
            }

            return $this->response->setJSON(['ok' => true, 'csv' => implode("\n", $lignes)]);
        });
    }
}
