<?php

namespace App\Controllers;

/**
 * NIJAC – Menu paramètres administrateur (E002), portage CI4 de admin_menu.php.
 *
 * Protégé par le filtre "adminauth" (routes).
 */
class AdminMenuController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        // Badge d'expiration des identifiants API FFTT ci-dessous (le rappel par email,
        // lui, est déclenché une seule fois par connexion admin — voir AuthController::index()).
        $data = [
            'nomComplet'           => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'          => $u['id_departement'] ?? '',
            'changeLogin'          => !empty($u['change_login']),
            'isAdmin'              => !empty($u['is_admin']),
            'isChautard'           => ($u['login'] ?? '') === 'CHAUTARD',
            'ffttJoursExpiration'  => getFfttApiJoursAvantExpiration(),
            'infosPhp'             => $this->infosPhp(),
            // Bouton de barre « Menu JA » (E011, partials/toolbar) : affiché hors mode JA ; actif (POST changer-compte action=ja)
            // si fiche JA liée utilisable (idFicheJaLiee()), sinon grisé « Aucune fiche JA liée : voir EA86 ».
            'menuJaVisible'        => !estSessionModeJa($u),
            'compteJaLie'          => !estSessionModeJa($u) && $this->compteJaLieUtilisable((int) ($u['id'] ?? 0)),
        ];

        return view('admin_menu_index', $data);
    }

    /** Le compte a-t-il une fiche JA liée utilisable (idFicheJaLiee()) ? Base injoignable : non. */
    private function compteJaLieUtilisable(int $id): bool
    {
        try {
            return idFicheJaLiee(getPDO(), $id) > 0;
        } catch (\Throwable $e) {
            error_log('[NIJAC] E002 fiche JA liée : ' . $e->getMessage());

            return false;
        }
    }

    /**
     * GET admin-menu/python : bouton « Version Python » d'E002. Commandes fixes (aucune saisie client),
     * lancées à la demande seulement ; exec() désactivé ou Python absent (cas courant en hébergement
     * FTP) → {ok:false} sans erreur technique.
     */
    public function python()
    {
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $off, true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'exec() est désactivé sur ce serveur.']);
        }
        foreach (['python3', 'python', 'py'] as $cmd) {
            $sortie = [];
            $code   = 1;
            @exec($cmd . ' --version 2>&1', $sortie, $code);
            if ($code === 0 && preg_match('/^Python\s+(\S+)/i', trim((string) ($sortie[0] ?? '')), $m)) {
                return $this->response->setJSON(['ok' => true, 'version' => $m[1], 'commande' => $cmd]);
            }
        }

        return $this->response->setJSON(['ok' => false, 'msg' => 'Python n\'est pas installé (ou pas dans le PATH) sur ce serveur.']);
    }

    /**
     * Bouton « Version PHP » d'E002 : version, SAPI, quelques limites ini et présence
     * des extensions utilisées par l'appli. Volontairement restreint (pas de phpinfo(),
     * aucun chemin serveur ni réglage sensible).
     */
    private function infosPhp(): array
    {
        $extensions = [];
        foreach (['bcmath', 'mbstring', 'zip', 'openssl', 'curl', 'pdo_mysql', 'gd', 'intl', 'fileinfo'] as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }

        return [
            'parametres' => [
                'Version PHP'         => PHP_VERSION,
                'SAPI'                => PHP_SAPI,
                'memory_limit'        => (string) ini_get('memory_limit'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'       => (string) ini_get('post_max_size'),
                'max_execution_time'  => (string) ini_get('max_execution_time'),
            ],
            'extensions' => $extensions,
        ];
    }
}
