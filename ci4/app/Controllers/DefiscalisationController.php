<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Défiscalisation JA (E039), rôle Defiscalisateur.
 *
 * La défiscalisation s'applique sur l'année civile en cours (1er janvier -
 * 31 décembre), pas sur la saison/phase NIJAC — pas de sélecteur de période.
 * Liste tous les JA actifs ayant coché "Défiscalisation" (ja.Actif=1 AND
 * ja.Defiscalisation=1), y compris ceux sans mission cette année (LEFT JOIN
 * depuis `ja`), avec le cumul péages/kilomètres de leurs nominations de
 * l'année (nomination → disponible → ja). Export CSV pour la génération des
 * reçus. Accessible rôle Defiscalisateur + Administrateur (filtre
 * "defiscauth"). Pas de Model : agrégations ponctuelles, raw PDO comme
 * ComptaController.
 */
class DefiscalisationController extends BaseController
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

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
            'annee'       => (int) date('Y'),
        ];

        return view('defiscalisation_index', $data);
    }

    /**
     * Requête commune à donnees()/exportCsv() : tous les JA actifs défiscalisés,
     * avec le cumul péages/km de leurs nominations tombant dans l'année civile
     * [debut, fin] — LEFT JOIN depuis `ja` (pas depuis `nomination`) pour que
     * les JA sans mission cette année apparaissent aussi, avec des totaux à 0.
     */
    private function requeteAgregee(\PDO $pdo, string $dateDebut, string $dateFin): array
    {
        $tauxKm = (float) getConfig('frais_kilometrique', '0.30');

        $stmt = $pdo->prepare('
            SELECT
                j.Id_JA, j.Nom, j.Prenom, j.Cp, j.Ville,
                COUNT(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Id_Nomination END) AS NbMissions,
                COALESCE(SUM(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Peage     END), 0) AS Peage,
                COALESCE(SUM(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Kilometre END), 0) AS Kilometre
            FROM ja j
            LEFT JOIN disponible d ON d.Id_JA = j.Id_JA
            LEFT JOIN nomination n ON n.Id_Disponible = d.Id_Disponible
                AND (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
            LEFT JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                AND r.Date BETWEEN :debut AND :fin
            WHERE j.Actif = 1 AND j.Defiscalisation = 1
            GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Cp, j.Ville
            ORDER BY j.Nom, j.Prenom
        ');
        $stmt->execute([':debut' => $dateDebut, ':fin' => $dateFin]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['FraisKmPeages'] = round((float) $r['Kilometre'] * $tauxKm + (float) $r['Peage'], 2);
        }
        unset($r);

        return $rows;
    }

    private function anneeCivile(): array
    {
        $annee = date('Y');

        return ["$annee-01-01", "$annee-12-31"];
    }

    public function donnees(): ResponseInterface
    {
        return $this->tryJson(function () {
            [$debut, $fin] = $this->anneeCivile();
            $rows = $this->requeteAgregee(getPDO(), $debut, $fin);

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function exportCsv(): ResponseInterface
    {
        return $this->tryJson(function () {
            [$debut, $fin] = $this->anneeCivile();
            $rows = $this->requeteAgregee(getPDO(), $debut, $fin);

            $lignes = [];
            foreach ($rows as $r) {
                $lignes[] = implode(';', [
                    $r['Nom'],
                    $r['Prenom'],
                    $r['Cp'] ?? '',
                    $r['Ville'] ?? '',
                    $r['NbMissions'],
                    number_format((float) $r['Peage'], 2, ',', ''),
                    number_format((float) $r['Kilometre'], 2, ',', ''),
                    number_format((float) $r['FraisKmPeages'], 2, ',', ''),
                ]);
            }

            return $this->response->setJSON(['ok' => true, 'csv' => implode("\n", $lignes)]);
        });
    }
}
