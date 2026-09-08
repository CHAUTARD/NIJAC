<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Date des rencontres (EN23) : version nominateur d'EA95. Même liste
 * (query héritée de RencontreAdminController::data), mais seules la date et
 * l'heure d'une rencontre sont modifiables — ni poule, ni journée, ni
 * suppression, ni recherche de doublons.
 *
 * Accès Nominateur ou Administrateur (filtre "auth").
 */
class RencontreNominateurController extends RencontreAdminController
{
    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        return view('rencontre_nominateur_index', [
            'nomComplet'   => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'  => $moi['id_departement'] ?? '',
            'changeLogin'  => !empty($moi['change_login']),
            'deptActifs'   => getDeptActifs(),
            'divisionNoms' => getDivisionNoms(),
        ]);
    }

    public function update(int $idRencontre): ResponseInterface
    {
        return $this->tryJson(function () use ($idRencontre) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $date  = trim($input['date'] ?? '');
            $heure = trim($input['heure'] ?? '');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Date invalide.']);
            }
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $heure)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Heure invalide.']);
            }
            if (strlen($heure) === 5) {
                $heure .= ':00';
            }

            $stmt = $pdo->prepare('UPDATE rencontre SET Date=?, Heure=? WHERE Id_Rencontre=?');
            $stmt->execute([$date, $heure, $idRencontre]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM rencontre WHERE Id_Rencontre = ?');
                $chk->execute([$idRencontre]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Rencontre $idRencontre introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Rencontre mise à jour.']);
        });
    }
}
