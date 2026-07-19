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

/**
 * Normalise un champ de réponse FFTT : un champ vide/absent revient comme un
 * tableau vide plutôt qu'une chaîne (particularité du parsing XML→JSON de
 * SimpleXML sur un élément sans contenu) — un (string) direct dessus lève
 * "Array to string conversion".
 */
function ffttStr($valeur): string
{
    return trim(is_array($valeur) ? '' : (string) $valeur);
}

/**
 * Assure la présence du club $numClub dans la table Club locale à partir
 * d'une réponse déjà récupérée de retrieveClubDetails() / xml_club_detail
 * (insert si absent, update du nom si déjà présent).
 * Retourne ['nom' => string, 'cree' => bool], ou null si le champ "nom" de
 * la réponse FFTT est vide/absent (club introuvable côté FFTT).
 */
function synchroniserClubFftt(PDO $pdo, string $numClub, array $detail): ?array
{
    $nomClub = ffttStr($detail['nom'] ?? '');
    if ($nomClub === '') {
        return null;
    }

    $chk = $pdo->prepare('SELECT COUNT(*) FROM Club WHERE Id_Club = ?');
    $chk->execute([$numClub]);
    $existeDeja = (int) $chk->fetchColumn() > 0;

    if ($existeDeja) {
        $pdo->prepare('UPDATE Club SET Nom = ? WHERE Id_Club = ?')->execute([$nomClub, $numClub]);
    } else {
        $pdo->prepare('INSERT INTO Club (Id_Club, Nom) VALUES (?, ?)')->execute([$numClub, $nomClub]);
    }

    return ['nom' => $nomClub, 'cree' => !$existeDeja];
}
