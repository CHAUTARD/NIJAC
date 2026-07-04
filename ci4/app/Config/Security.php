<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Security extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * CSRF Protection Method
     * --------------------------------------------------------------------------
     *
     * Protection Method for Cross Site Request Forgery protection.
     *
     * @var string 'cookie' or 'session'
     *
     * Volontairement 'cookie' (double-submit, sans dépendance à la session) :
     * l'app utilise la session PHP native (jamais le service Session de CI4)
     * pour rester compatible avec les fichiers de session de l'app legacy —
     * voir App\Filters\AdminAuth. Le mode 'session' de la protection CSRF
     * CI4 passe par le service Session de CI4, ce qui réintroduirait ce
     * conflit ; le mode 'cookie' l'évite entièrement.
     */
    public string $csrfProtection = 'cookie';

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Randomization
     * --------------------------------------------------------------------------
     *
     * Randomize the CSRF Token for added security.
     */
    public bool $tokenRandomize = false;

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Name
     * --------------------------------------------------------------------------
     *
     * Token name for Cross Site Request Forgery protection.
     */
    public string $tokenName = '_csrf';

    /**
     * --------------------------------------------------------------------------
     * CSRF Header Name
     * --------------------------------------------------------------------------
     *
     * Header name for Cross Site Request Forgery protection.
     */
    public string $headerName = 'X-CSRF-Token';

    /**
     * --------------------------------------------------------------------------
     * CSRF Cookie Name
     * --------------------------------------------------------------------------
     *
     * Cookie name for Cross Site Request Forgery protection.
     */
    public string $cookieName = 'csrf_cookie_name';

    /**
     * --------------------------------------------------------------------------
     * CSRF Expires
     * --------------------------------------------------------------------------
     *
     * Expiration time for Cross Site Request Forgery protection cookie.
     *
     * Defaults to two hours (in seconds).
     */
    public int $expires = 7200;

    /**
     * --------------------------------------------------------------------------
     * CSRF Regenerate
     * --------------------------------------------------------------------------
     *
     * Regenerate CSRF Token on every submission.
     *
     * Désactivé volontairement (false) : de nombreux écrans NIJAC enchaînent
     * plusieurs appels AJAX POST depuis un seul chargement de page (ex. E015
     * Configuration : ~15 boutons Enregistrer indépendants). Avec regenerate=true,
     * le 2ᵉ appel échouerait avec le token périmé sans rafraîchissement JS
     * après chaque réponse. Reproduit le comportement de l'ancien système
     * (config/csrf.php) : un token stable pour toute la session.
     */
    public bool $regenerate = false;

    /**
     * --------------------------------------------------------------------------
     * CSRF Redirect
     * --------------------------------------------------------------------------
     *
     * Redirect to previous page with error on failure.
     *
     * @see https://codeigniter4.github.io/userguide/libraries/security.html#redirection-on-failure
     */
    public bool $redirect = (ENVIRONMENT === 'production');
}
