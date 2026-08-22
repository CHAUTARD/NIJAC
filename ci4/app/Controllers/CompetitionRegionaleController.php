<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Calendrier du championnat régional (E014), nouvel écran.
 *
 * CRUD admin du calendrier (date/heure) des rencontres régionales, table
 * `competition_regionale` — voir DisponibilitesController pour la création
 * de la table et le seed initial (fichier "Disponibilités JA 2ème phase",
 * Importation/), et DispoRegionaleJaController (E036) qui l'exploite côté JA.
 *
 * Pas de Model : table à 2 colonnes, reste au raw PDO comme le contrôleur qui
 * l'a introduite (DisponibilitesController) plutôt que de mélanger les deux
 * approches pour une seule table.
 */
class CompetitionRegionaleController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
        ];

        return view('competition_regionale_index', $data);
    }

    public function data(): ResponseInterface
    {
        $rows = getPDO()->query('SELECT Id_CompetitionRegionale, Date, Heure, Commentaire FROM competition_regionale ORDER BY Date, Heure')->fetchAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($id = null): ResponseInterface
    {
        $stmt = getPDO()->prepare('SELECT Id_CompetitionRegionale, Date, Heure, Commentaire FROM competition_regionale WHERE Id_CompetitionRegionale = ?');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {
        [$date, $heure, $commentaire, $err] = $this->extractFields($this->request->getPost());
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        try {
            $pdo = getPDO();
            $pdo->prepare('INSERT INTO competition_regionale (Date, Heure, Commentaire) VALUES (?, ?, ?)')->execute([$date, $heure, $commentaire]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Date créée.', 'id' => (int) $pdo->lastInsertId()]);
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $this->messageErreur($e)]);
        }
    }

    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        [$date, $heure, $commentaire, $err] = $this->extractFields($this->request->getRawInput());
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        try {
            getPDO()->prepare('UPDATE competition_regionale SET Date = ?, Heure = ?, Commentaire = ? WHERE Id_CompetitionRegionale = ?')
                ->execute([$date, $heure, $commentaire, $id]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Date mise à jour.', 'id' => $id]);
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $this->messageErreur($e)]);
        }
    }

    public function delete($id = null): ResponseInterface
    {
        $id = (int) $id;
        if (!$id) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'ID manquant.']);
        }

        getPDO()->prepare('DELETE FROM competition_regionale WHERE Id_CompetitionRegionale = ?')->execute([$id]);
        getPDO()->prepare('DELETE FROM disponible_regionale WHERE Id_CompetitionRegionale = ?')->execute([$id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Date supprimée.']);
    }

    private function messageErreur(\PDOException $e): string
    {
        return $e->getCode() === '23000' ? 'Cette date/heure existe déjà.' : ('Erreur BDD : ' . $e->getMessage());
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} [date, heure, commentaire, message d'erreur] */
    private function extractFields(array $input): array
    {
        $date        = trim($input['date']  ?? '');
        $heure       = trim($input['heure'] ?? '');
        $commentaire = trim($input['commentaire'] ?? '') ?: null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [null, null, null, 'Date invalide.'];
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $heure)) {
            return [null, null, null, 'Horaire invalide.'];
        }

        return [$date, $heure, $commentaire, null];
    }
}
