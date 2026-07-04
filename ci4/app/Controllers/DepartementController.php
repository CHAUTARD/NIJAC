<?php

namespace App\Controllers;

use App\Models\DepartementModel;
use App\Models\RegionModel;
use CodeIgniter\HTTP\ResponseInterface;

class DepartementController extends BaseController
{
    protected DepartementModel $departementModel;
    protected RegionModel $regionModel;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/csrf.php';
        $this->departementModel = new DepartementModel();
        $this->regionModel      = new RegionModel();
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'csrfToken'   => csrfToken(),
        ];

        return view('departement_index', $data);
    }

    public function data(): ResponseInterface
    {
        $rows = $this->departementModel
            ->select('departement.code, departement.nom, departement.code_region, region.nom AS nom_region')
            ->join('region', 'region.code = departement.code_region', 'left')
            ->orderBy('CAST(departement.code AS UNSIGNED)', '', false)
            ->findAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($code = null): ResponseInterface
    {
        $code = trim((string) $code);
        $row  = $this->departementModel
            ->select('departement.code, departement.nom, departement.code_region, region.nom AS nom_region')
            ->join('region', 'region.code = departement.code_region', 'left')
            ->find($code);

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function regions(): ResponseInterface
    {
        $rows = $this->regionModel
            ->select('code, nom')
            ->orderBy('nom', 'ASC')
            ->findAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function store(): ResponseInterface
    {
        csrfVerify(true);

        $input      = $this->request->getPost();
        $code       = trim($input['code'] ?? '');
        [$nom, $codeRegion] = $this->extractFields($input);

        if ($code === '' || $nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code et nom obligatoires.']);
        }

        $this->departementModel->insert([
            'code'        => $code,
            'nom'         => $nom,
            'code_region' => $codeRegion,
        ]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Département créé.', 'code' => $code]);
    }

    public function update($code = null): ResponseInterface
    {
        csrfVerify(true);

        $code = trim((string) $code);
        [$nom, $codeRegion] = $this->extractFields($this->request->getRawInput());

        if ($code === '' || $nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code et nom obligatoires.']);
        }

        $this->departementModel->update($code, [
            'nom'         => $nom,
            'code_region' => $codeRegion,
        ]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Département mis à jour.', 'code' => $code]);
    }

    public function delete($code = null): ResponseInterface
    {
        csrfVerify(true);

        $code = trim((string) $code);
        if ($code === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code manquant.']);
        }

        $this->departementModel->delete($code);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Département supprimé.']);
    }

    /**
     * Extrait nom/code_region d'un tableau de données de formulaire.
     * code_region vide devient null (comme departement.php). Le code n'est
     * volontairement pas géré ici : en création il vient du corps (validé
     * par l'appelant), en modification il vient de l'URL.
     */
    private function extractFields(array $input): array
    {
        $nom        = trim($input['nom'] ?? '');
        $codeRegion = trim($input['code_region'] ?? '') ?: null;

        return [$nom, $codeRegion];
    }
}
