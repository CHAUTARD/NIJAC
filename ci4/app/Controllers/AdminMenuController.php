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
        ];

        return view('admin_menu_index', $data);
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
