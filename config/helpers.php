<?php
/**
 * Fonctions utilitaires partagées — inclure après db.php.
 */

/**
 * Normalise un nom de ville pour la recherche dans la table laposte.
 * La table laposte stocke les noms en majuscules sans accents.
 * Règles : accents supprimés, majuscules, tirets/apostrophes → espace,
 *          caractères non-alphanum supprimés, SAINT → ST.
 */
function normaliserVille(string $ville): string
{
    $v = trim($ville);
    $v = str_replace(
        ['à','â','ä','ç','è','é','ê','ë','î','ï','ô','ö','ù','û','ü','ÿ','æ','œ',
         'À','Â','Ä','Ç','È','É','Ê','Ë','Î','Ï','Ô','Ö','Ù','Û','Ü','Ÿ','Æ','Œ'],
        ['a','a','a','c','e','e','e','e','i','i','o','o','u','u','u','y','ae','oe',
         'A','A','A','C','E','E','E','E','I','I','O','O','U','U','U','Y','AE','OE'],
        $v
    );
    $v = mb_strtoupper($v, 'UTF-8');
    $v = str_replace(['-', "'", "\u{2019}"], ' ', $v);
    $v = preg_replace('/[^A-Z0-9 ]/', '', $v);
    $v = preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/\bSAINT\b/', 'ST', $v);
    return trim($v);
}

/**
 * Cherche Id_LaPoste pour un couple CP / Ville.
 * Retourne l'Id_LaPoste unique trouvé, null si ambigu ou introuvable.
 * Stratégie : CP + début de nom → CP seul (si une seule commune) → null.
 */
function trouverIdLaPoste(PDO $pdo, string $cp, string $ville): ?int
{
    $cp        = trim($cp);
    $villeNorm = normaliserVille($ville);

    if ($cp !== '' && $villeNorm !== '') {
        $stmt = $pdo->prepare(
            "SELECT Id_LaPoste FROM laposte
             WHERE CodePostal = ?
               AND REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
             LIMIT 1"
        );
        $stmt->execute([$cp, $villeNorm . '%']);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    }

    if ($cp !== '') {
        $stmt = $pdo->prepare(
            'SELECT Id_LaPoste FROM laposte WHERE CodePostal = ? ORDER BY Nom LIMIT 2'
        );
        $stmt->execute([$cp]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) === 1) return (int)$rows[0];
    }

    return null;
}
