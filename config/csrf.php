<?php
/**
 * Protection CSRF (Cross-Site Request Forgery).
 *
 * Nécessite qu'une session soit déjà démarrée avant l'inclusion.
 *
 * Utilisation :
 *   - Dans chaque formulaire HTML   : <?= csrfField() ?>
 *   - Dans chaque endpoint POST     : csrfVerify();
 *   - Dans les requêtes AJAX (JS)   : ajouter le header X-CSRF-Token: csrfToken()
 *     ou passer le champ _csrf dans le corps de la requête.
 */

/**
 * Génère (ou retourne) le token CSRF de la session courante.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Retourne un champ <input> caché prêt à coller dans un <form>.
 */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Vérifie le token CSRF de la requête courante.
 * Cherche dans $_POST['_csrf'] puis dans l'en-tête HTTP X-CSRF-Token.
 * Termine avec une réponse 403 si le token est absent ou invalide.
 *
 * @param bool $json  true = répondre en JSON (endpoints AJAX), false = page HTML
 */
function csrfVerify(bool $json = false): void
{
    $token    = csrfToken();
    $received = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!hash_equals($token, $received)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Token CSRF invalide. Rechargez la page et réessayez.']);
        } else {
            echo '<!DOCTYPE html><html lang="fr"><body>'
               . '<h2>403 – Requête refusée</h2>'
               . '<p>Token CSRF invalide. <a href="javascript:history.back()">Retour</a></p>'
               . '</body></html>';
        }
        exit;
    }
}
