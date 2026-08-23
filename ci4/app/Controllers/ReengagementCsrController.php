<?php

namespace App\Controllers;

/**
 * NIJAC – Message Réengagement CSR (E040), remplace le lien vers E026 (Gestion des
 * messages) dans le menu CSR (E034).
 *
 * Coquille légère : affiche uniquement la partie droite (formulaire) d'E026,
 * réduite au seul message Réengagement (Id_Messagerie = 6) et à un bouton
 * Enregistrer. Aucune logique CRUD ici — les appels AJAX de la vue tapent
 * directement les routes existantes `messagerie/data/6` (GET) et `messagerie/6`
 * (PUT), déjà restreintes au message n°6 pour le rôle CSR côté
 * MessagerieController (voir isCsr()/ID_MESSAGE_CSR). Accès rôle CSR +
 * Administrateur (filtre "csrauth"), comme E034/E035.
 */
class ReengagementCsrController extends BaseController
{
    private const ID_MESSAGE_REENGAGEMENT = 6;

    public function __construct()
    {
        // Requis par la vue pour les valeurs d'exemple du cartouche de marqueurs
        // (getAnneePhase(), getConfig()) — même besoin que MessagerieController.
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
            'idMessage'   => self::ID_MESSAGE_REENGAGEMENT,
        ];

        return view('reengagement_csr_index', $data);
    }
}
