<?php

namespace App\Controllers;

use App\Models\DivisionModel;
use CodeIgniter\HTTP\ResponseInterface;

class DivisionController extends BaseController
{
    protected DivisionModel $divisionModel;

    public function __construct()
    {
        $this->divisionModel = new DivisionModel();
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
        ];

        return view('division_index', $data);
    }

    public function data(): ResponseInterface
    {
        $rows = $this->divisionModel
            ->select('Division, Ord, Nom, Color, ArbitrageCRA')
            ->orderBy('Ord', 'ASC')
            ->findAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($id = null): ResponseInterface
    {
        $row = $this->divisionModel
            ->select('Division, Ord, Nom, Color, ArbitrageCRA')
            ->find(trim((string) $id));

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {

        $fields = $this->extractFields($this->request->getPost());

        if ($fields === null) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom de la division ne peut pas être vide.']);
        }

        $this->divisionModel->insert($fields);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Division créée.', 'id' => $fields['Division']]);
    }

    public function update($id = null): ResponseInterface
    {

        $id     = trim((string) $id);
        $fields = $this->extractFields($this->request->getRawInput());

        if ($fields === null) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom de la division ne peut pas être vide.']);
        }

        $this->divisionModel->update($id, $fields);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Division mise à jour.', 'id' => $fields['Division']]);
    }

    public function delete($id = null): ResponseInterface
    {

        $id = trim((string) $id);
        $this->divisionModel->delete($id);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Division supprimée.']);
    }

    /**
     * Extrait et valide les champs du formulaire. Retourne null si le nom
     * (colonne "Division", le code court) est vide — même règle que
     * division.php legacy.
     */
    private function extractFields(array $input): ?array
    {
        $nom       = trim($input['nom'] ?? '');
        $ord       = max(1, (int) ($input['ord'] ?? 1));
        $nomLong   = trim($input['nom_long'] ?? '');
        $color     = trim($input['color'] ?? '') ?: '#1565c0';
        $arbitrage = (int) ($input['arbitrage_obligatoire'] ?? 1) === 0 ? 0 : 1;

        if ($nom === '') {
            return null;
        }

        return [
            'Division'     => $nom,
            'Ord'          => $ord,
            'Nom'          => $nomLong,
            'Color'        => $color,
            'ArbitrageCRA' => $arbitrage,
        ];
    }
}
