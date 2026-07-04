<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Résolution code postal / commune (table laposte), portage CI4 de
 * ajax/laposte.php.
 *
 * Utilitaire AJAX partagé (pas un écran à part entière, pas de code EXXX) :
 * réutilisé par E005 (Salle) et E007 (Juge-Arbitre) pour résoudre un
 * code postal / nom de commune en Id_LaPoste. Accessible à tout utilisateur
 * authentifié (Administrateur ou Nominateur) — filtre "auth".
 */
class LaposteController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/helpers.php';
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] ajax/laposte.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD.']);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] ajax/laposte.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur serveur.']);
        }
    }

    public function rechercheLaposte(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo   = getPDO();
            $cp    = trim($this->request->getPost('cp') ?? '');
            $ville = normaliserVille($this->request->getPost('ville') ?? '');

            if ($cp === '' && $ville === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CP et ville vides.']);
            }

            // 1) Correspondance CP + début de nom
            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare(
                    "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                     WHERE CodePostal = ?
                       AND REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                     LIMIT 1"
                );
                $stmt->execute([$cp, $ville . '%']);
                $row = $stmt->fetch();
                if ($row) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                }
            }

            // 2) CP seul
            if ($cp !== '') {
                $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
                $stmt->execute([$cp]);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    $sugg = array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);

                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                }
            }

            // 3) Ville seule (correspondance partielle)
            if ($ville !== '') {
                $stmt = $pdo->prepare(
                    "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                     WHERE REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                     ORDER BY CodePostal, Nom LIMIT 20"
                );
                $stmt->execute([$ville . '%']);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    $sugg = array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);

                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                }
            }

            return $this->response->setJSON(['ok' => false, 'msg' => 'Commune non trouvée.']);
        });
    }

    public function lookupLaposte(): ResponseInterface
    {
        return $this->tryJson(function () {
            $cp = trim($this->request->getPost('cp') ?? '');
            if ($cp === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CP vide.']);
            }
            $stmt = getPDO()->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
            $stmt->execute([$cp]);
            $rows = $stmt->fetchAll();

            return $this->response->setJSON(['ok' => true, 'communes' => array_map(fn ($r) => [
                'id'  => $r['Id_LaPoste'],
                'cp'  => $r['CodePostal'],
                'nom' => $r['Nom'],
            ], $rows)]);
        });
    }
}
