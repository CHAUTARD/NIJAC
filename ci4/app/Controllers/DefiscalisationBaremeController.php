<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Barème kilométrique (ED52), rôle Defiscalisateur.
 *
 * Édite les 5 tranches de puissance de la table ComptaDefiscalisation (barème
 * fiscal voiture, valeurs de l'année en cours — voir le COMMENT de la table)
 * ainsi que le taux de majoration pour véhicule électrique (clé de config
 * comptadefisc_majoration_electrique). Structure figée : 5 lignes, pas
 * d'ajout / suppression — seuls les coefficients T1/T2/T3 et la part fixe T2
 * sont modifiables. Accessible rôle Defiscalisateur + Administrateur (filtre
 * "defiscauth"), appelé depuis ED51. Raw PDO, pas de Model.
 */
class DefiscalisationBaremeController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'lignes'      => getPDO()->query(
                'SELECT Id_ComptaDefiscalisation, Cv_Min, Cv_Max, Libelle, Coef_T1, Coef_T2, Fixe_T2, Coef_T3
                 FROM ComptaDefiscalisation ORDER BY Cv_Min'
            )->fetchAll(\PDO::FETCH_ASSOC),
            'majoration'  => (float) getConfig('comptadefisc_majoration_electrique', '20'),
        ];

        return view('defiscalisation_bareme_index', $data);
    }

    /**
     * Enregistre les 5 lignes (POST 'lignes' : tableau de
     * {id, coef_t1, coef_t2, fixe_t2, coef_t3}) et le taux de majoration
     * électrique (POST 'majoration', en %). Tout ou rien (transaction).
     */
    public function enregistrer(): ResponseInterface
    {
        $pdo = null;
        try {
            $lignes = $this->request->getPost('lignes');
            if (!is_array($lignes) || $lignes === []) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune donnée reçue.']);
            }

            $nombre = static function ($v): ?float {
                $v = str_replace(',', '.', (string) $v);
                return (is_numeric($v) && (float) $v >= 0) ? (float) $v : null;
            };

            $pdo = getPDO();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE ComptaDefiscalisation
                    SET Coef_T1 = :t1, Coef_T2 = :t2, Fixe_T2 = :fx, Coef_T3 = :t3
                  WHERE Id_ComptaDefiscalisation = :id'
            );

            foreach ($lignes as $l) {
                $id = (int) ($l['id'] ?? 0);
                $t1 = $nombre($l['coef_t1'] ?? null);
                $t2 = $nombre($l['coef_t2'] ?? null);
                $fx = $nombre($l['fixe_t2'] ?? null);
                $t3 = $nombre($l['coef_t3'] ?? null);
                if ($id <= 0 || $t1 === null || $t2 === null || $fx === null || $t3 === null) {
                    $pdo->rollBack();
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Valeur invalide : nombre positif attendu sur chaque coefficient.']);
                }
                $stmt->execute([
                    ':t1' => round($t1, 3), ':t2' => round($t2, 3),
                    ':fx' => round($fx, 2), ':t3' => round($t3, 3), ':id' => $id,
                ]);
            }

            $maj = $nombre($this->request->getPost('majoration'));
            if ($maj === null) {
                $pdo->rollBack();
                return $this->response->setJSON(['ok' => false, 'msg' => 'Majoration électrique invalide.']);
            }
            $pdo->prepare(
                "INSERT INTO configuration (cle, valeur) VALUES ('comptadefisc_majoration_electrique', ?)
                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)"
            )->execute([number_format(round($maj, 2), 2, '.', '')]);

            $pdo->commit();

            return $this->response->setJSON(['ok' => true, 'msg' => 'Barème enregistré.']);
        } catch (\Throwable $e) {
            if ($pdo instanceof \PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}
