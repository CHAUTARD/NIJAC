<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Disponibilités JA Championnat Régional (EN23), nouvel écran.
 *
 * Page PUBLIQUE (sans authentification), tokenisée par le paramètre `?ja=TOKEN`
 * (Obfuscator) — remplace le formulaire Excel "Disponibilités JA 2ème phase"
 * (Importation/) envoyé par email (message système n°8, voir
 * assurerTemplateDispoRegionale()) : le JA coche les dates où il est
 * disponible et le(s) département(s) choisis parmi la liste fermée
 * 14/27/50/61/76, pour les rencontres du championnat régional listées dans
 * `competition_regionale` (calendrier saisi une fois par un admin, voir
 * DisponibilitesController).
 *
 * Même schéma que AdresseJaController/DisponibiliteJaController : pas de
 * Model, session native uniquement pour permettre l'accès direct par un
 * Nominateur/Admin (pas de filtre "auth" sur la route publique).
 */
class DispoRegionaleJaController extends BaseController
{
    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);

        $pdo = getPDO();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS disponible_regionale (
                Id_DisponibleRegionale  INT AUTO_INCREMENT PRIMARY KEY,
                Id_JA                   INT NOT NULL,
                Id_CompetitionRegionale INT NOT NULL,
                Departement             SET('14','27','50','61','76') NULL,
                DateReponse             DATE NOT NULL,
                UNIQUE KEY uq_ja_competition (Id_JA, Id_CompetitionRegionale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function index()
    {
        demarrerSessionNijac();

        $idJa     = 0;
        $tokenGet = trim($this->request->getGet('ja') ?? '');
        if ($tokenGet !== '') {
            $decoded = $this->obf->deobfuscate($tokenGet);
            if ($decoded > 0) {
                $idJa = $decoded;
            }
        }

        $pdo    = getPDO();
        $ja     = null;
        $dates  = [];
        $erreur = '';

        if ($idJa > 0) {
            $stmt = $pdo->prepare('SELECT Id_JA, Nom, Prenom FROM ja WHERE Id_JA = ?');
            $stmt->execute([$idJa]);
            $ja = $stmt->fetch();
            if (!$ja) {
                $erreur = "Juge-Arbitre #$idJa introuvable.";
            } else {
                $stmt = $pdo->prepare('
                    SELECT cr.Id_CompetitionRegionale, cr.Date, cr.Heure,
                           dr.Id_DisponibleRegionale, dr.Departement
                    FROM competition_regionale cr
                    LEFT JOIN disponible_regionale dr ON dr.Id_CompetitionRegionale = cr.Id_CompetitionRegionale AND dr.Id_JA = ?
                    ORDER BY cr.Date, cr.Heure
                ');
                $stmt->execute([$idJa]);
                $dates = $stmt->fetchAll();

                $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                foreach ($dates as &$d) {
                    $d['DateLabel'] = $jours[(int) (new \DateTime($d['Date']))->format('w')] . ' ' . (new \DateTime($d['Date']))->format('d/m/Y');
                }
                unset($d);
            }
        } else {
            $erreur = 'Lien invalide ou paramètre manquant.';
        }

        session_write_close();
        unset($_SESSION);

        return view('dispo_regionale_ja_index', [
            'idJa'   => $idJa,
            'ja'     => $ja,
            'dates'  => $dates,
            'erreur' => $erreur,
        ]);
    }

    /**
     * Enregistre les choix du JA — action publique. $_POST['reponses'] : tableau
     * JSON [{id_competition, departements: ['14','27',...]}] pour les seules dates
     * où le JA est disponible (une date absente du tableau = non disponible, la
     * ligne existante est supprimée).
     */
    public function sauvegarder(): ResponseInterface
    {
        demarrerSessionNijac();
        session_write_close();
        unset($_SESSION);

        $pdo  = getPDO();
        $idJa = (int) ($this->request->getPost('id_ja') ?? 0);
        if (!$idJa) {
            return $this->response->setJSON(['ok' => false, 'err' => 'JA manquant.']);
        }

        $check = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
        $check->execute([$idJa]);
        if (!$check->fetch()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'JA introuvable.']);
        }

        $reponses = json_decode($this->request->getPost('reponses') ?? '[]', true);
        if (!is_array($reponses)) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Données invalides.']);
        }

        $deptsValides = ['14', '27', '50', '61', '76'];
        $idsRetenus   = [];

        $upsert = $pdo->prepare('
            INSERT INTO disponible_regionale (Id_JA, Id_CompetitionRegionale, Departement, DateReponse)
            VALUES (?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE Departement = VALUES(Departement), DateReponse = VALUES(DateReponse)
        ');

        foreach ($reponses as $r) {
            $idCompetition = (int) ($r['id_competition'] ?? 0);
            if (!$idCompetition) {
                continue;
            }
            $depts = array_values(array_intersect((array) ($r['departements'] ?? []), $deptsValides));
            $upsert->execute([$idJa, $idCompetition, $depts ? implode(',', $depts) : null]);
            $idsRetenus[] = $idCompetition;
        }

        if ($idsRetenus) {
            $placeholders = implode(',', array_fill(0, count($idsRetenus), '?'));
            $pdo->prepare("DELETE FROM disponible_regionale WHERE Id_JA = ? AND Id_CompetitionRegionale NOT IN ($placeholders)")
                ->execute([$idJa, ...$idsRetenus]);
        } else {
            $pdo->prepare('DELETE FROM disponible_regionale WHERE Id_JA = ?')->execute([$idJa]);
        }

        return $this->response->setJSON(['ok' => true]);
    }
}
