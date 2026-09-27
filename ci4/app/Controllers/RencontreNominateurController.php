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
    /** EN23 garde son tri historique (Date/Heure), contrairement au tri Phase/Journée/Poule d'EA95. */
    protected function ordreListe(): string
    {
        return 'r.Date, r.Heure';
    }

    /** EN23 ne modifie que date/heure : les catalogues équipes/salles (toutes les équipes de la base) ne lui servent à rien. */
    protected function avecCatalogues(): bool
    {
        return false;
    }

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

            // État actuel + éventuelle nomination : sert à la fois au « introuvable » et à l'avertissement.
            $stmt = $pdo->prepare('
                SELECT r.Date, r.Heure, n.Id_Nomination, n.EmailEnvoye,
                       CONCAT(ja.Prenom, " ", ja.Nom) AS NomJa
                FROM rencontre r
                LEFT JOIN nomination n ON n.Id_Rencontre  = r.Id_Rencontre
                LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                LEFT JOIN ja           ON ja.Id_JA        = d.Id_JA
                WHERE r.Id_Rencontre = ?
            ');
            $stmt->execute([$idRencontre]);
            $avant = $stmt->fetch();
            if (!$avant) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Rencontre $idRencontre introuvable."]);
            }

            $change = substr((string) $avant['Date'], 0, 10) !== $date || substr((string) $avant['Heure'], 0, 8) !== $heure;
            if ($change) {
                $pdo->prepare('UPDATE rencontre SET Date=?, Heure=? WHERE Id_Rencontre=?')->execute([$date, $heure, $idRencontre]);
            }

            // Un JA déjà nommé n'est pas prévenu automatiquement du changement : le nominateur doit le savoir.
            $avertissement = '';
            if ($change && $avant['Id_Nomination']) {
                $avertissement = 'Un JA est déjà nommé sur cette rencontre (' . htmlspecialchars($avant['NomJa'] ?? '') . ')'
                    . ($avant['EmailEnvoye'] ? ' et sa convocation a déjà été envoyée' : '')
                    . ' : vérifiez sa disponibilité à la nouvelle date/heure et prévenez-le (ou refaites la nomination dans EN14).';
            }

            return $this->response->setJSON([
                'ok'            => true,
                'msg'           => $change ? 'Rencontre mise à jour.' : 'Aucune modification.',
                'avertissement' => $avertissement,
            ]);
        });
    }
}
