<?php
/**
 * NIJAC – Résolution code postal / commune (table laposte)
 *
 * Actions :
 *   recherche_laposte  POST  cp, ville  → commune unique ou liste de suggestions
 *   lookup_laposte     POST  cp         → toutes les communes du code postal
 *
 * Accessible à tout utilisateur authentifié (Administrateur ou Nominateur).
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

$authRedirect = '../index.php';
require __DIR__ . '/../includes/auth_required.php';

ob_start();
header('Content-Type: application/json; charset=utf-8');
csrfVerify(true);

function normaliserVille(string $ville): string
{
    $v = mb_strtoupper(trim($ville), 'UTF-8');
    $v = str_replace(['-', "'", "\u{2019}"], ' ', $v);
    $v = preg_replace('/\s+/', ' ', $v);
    return trim($v);
}

try {
    $pdo    = getPDO();
    $action = $_POST['action'] ?? '';

    // ── recherche_laposte ─────────────────────────────────────────────────────
    if ($action === 'recherche_laposte') {
        $cp    = trim($_POST['cp']    ?? '');
        $ville = normaliserVille($_POST['ville'] ?? '');

        if ($cp === '' && $ville === '') {
            ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'CP et ville vides.']); exit;
        }

        // 1) Correspondance CP + début de nom
        if ($cp !== '' && $ville !== '') {
            $stmt = $pdo->prepare(
                "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                 WHERE CodePostal = ?
                   AND UPPER(REPLACE(REPLACE(REPLACE(Nom,'-',' '),''',' '),'\u{2019}',' ')) LIKE ?
                 LIMIT 1"
            );
            $stmt->execute([$cp, $ville . '%']);
            $row = $stmt->fetch();
            if ($row) {
                ob_end_clean();
                echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                exit;
            }
        }

        // 2) CP seul
        if ($cp !== '') {
            $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
            $stmt->execute([$cp]);
            $rows = $stmt->fetchAll();
            if (count($rows) === 1) {
                ob_end_clean();
                echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                exit;
            }
            if (count($rows) > 1) {
                $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                ob_end_clean(); echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
            }
        }

        // 3) Ville seule (correspondance partielle)
        if ($ville !== '') {
            $stmt = $pdo->prepare(
                "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                 WHERE UPPER(REPLACE(REPLACE(REPLACE(Nom,'-',' '),''',' '),'\u{2019}',' ')) LIKE ?
                 ORDER BY CodePostal, Nom LIMIT 20"
            );
            $stmt->execute([$ville . '%']);
            $rows = $stmt->fetchAll();
            if (count($rows) === 1) {
                ob_end_clean();
                echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                exit;
            }
            if (count($rows) > 1) {
                $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                ob_end_clean(); echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
            }
        }

        ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Commune non trouvée.']); exit;
    }

    // ── lookup_laposte ────────────────────────────────────────────────────────
    if ($action === 'lookup_laposte') {
        $cp = trim($_POST['cp'] ?? '');
        if ($cp === '') {
            ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'CP vide.']); exit;
        }
        $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
        $stmt->execute([$cp]);
        $rows = $stmt->fetchAll();
        ob_end_clean();
        echo json_encode(['ok' => true, 'communes' => array_map(fn($r) => [
            'id'  => $r['Id_LaPoste'],
            'cp'  => $r['CodePostal'],
            'nom' => $r['Nom'],
        ], $rows)]);
        exit;
    }

    ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']); exit;

} catch (PDOException $e) {
    error_log('[NIJAC] ajax/laposte.php PDO : ' . $e->getMessage());
    ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Erreur BDD.']); exit;
} catch (\Throwable $e) {
    error_log('[NIJAC] ajax/laposte.php : ' . $e->getMessage());
    ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Erreur serveur.']); exit;
}
