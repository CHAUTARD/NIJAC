<?php

namespace App\Controllers;

use App\Models\RegionModel;
use CodeIgniter\HTTP\ResponseInterface;

class RegionController extends BaseController
{
    protected RegionModel $regionModel;

    public function __construct()
    {
        $this->regionModel = new RegionModel();
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
        ];

        return view('region_index', $data);
    }

    public function data(): ResponseInterface
    {
        $rows = $this->regionModel
            ->select('code, nom, Gentile, chef_lieu')
            ->orderBy('nom', 'ASC')
            ->findAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($code = null): ResponseInterface
    {
        $code = trim((string) $code);
        $row  = $this->regionModel
            ->select('code, nom, Gentile, chef_lieu')
            ->find($code);

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {

        $input    = $this->request->getPost();
        $code     = trim($input['code'] ?? '');
        [$nom, $gentile, $chefLieu] = $this->extractFields($input);

        if ($code === '' || $nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code et nom obligatoires.']);
        }

        $this->regionModel->insert([
            'code'      => $code,
            'nom'       => $nom,
            'Gentile'   => $gentile,
            'chef_lieu' => $chefLieu,
        ]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Région créée.', 'code' => $code]);
    }

    public function update($code = null): ResponseInterface
    {

        $code = trim((string) $code);
        [$nom, $gentile, $chefLieu] = $this->extractFields($this->request->getRawInput());

        if ($code === '' || $nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code et nom obligatoires.']);
        }

        $this->regionModel->update($code, [
            'nom'       => $nom,
            'Gentile'   => $gentile,
            'chef_lieu' => $chefLieu,
        ]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Région mise à jour.', 'code' => $code]);
    }

    public function delete($code = null): ResponseInterface
    {

        $code = trim((string) $code);
        if ($code === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Code manquant.']);
        }

        $this->regionModel->delete($code);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Région supprimée.']);
    }

    /**
     * Extrait nom/gentile/chef_lieu d'un tableau de données de formulaire.
     * gentile/chef_lieu vides deviennent null (comme region.php). Le code
     * n'est volontairement pas géré ici : en création il vient du corps
     * (validé par l'appelant), en modification il vient de l'URL.
     */
    private function extractFields(array $input): array
    {
        $nom      = trim($input['nom']       ?? '');
        $gentile  = trim($input['gentile']   ?? '') ?: null;
        $chefLieu = trim($input['chef_lieu'] ?? '') ?: null;

        return [$nom, $gentile, $chefLieu];
    }
}
