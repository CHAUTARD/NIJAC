<?php

namespace App\Controllers;

/**
 * NIJAC – Mes disponibilités (EJA2), menu JA (E011), rôle « JA » (filtre "jaauth").
 *
 * Réutilise TOUTE la logique d'EN22 (DisponibiliteJaController : calendrier des journées du
 * championnat régional `competition_regionale`, réponses `disponible`, note, défiscalisation,
 * arbitrage dans les départements voisins) et sa vue `disponibilite_ja_index` ; seule la résolution
 * du JA change : la fiche est celle du compte connecté (convention Id_Utilisateur = ja.Id_JA,
 * idJaCompteSession() : rôle JA + fiche existante, ou fiche liée en mode JA, revérifié ici en plus du filtre), jamais un paramètre
 * client (`ja` / `id_ja` ignorés). 0 = refus (parent : JA introuvable).
 * Routes `ja/disponibilites/...` (mêmes noms d'actions qu'EN22, voir Routes.php).
 */
class JaDisponibiliteController extends DisponibiliteJaController
{
    private int $idJaSession;

    public function __construct()
    {
        parent::__construct();
        // Garde-fou propre au contrôleur, en plus de "jaauth" : rôle JA ET fiche de même Id ($_SESSION lisible).
        $this->idJaSession = idJaCompteSession();
    }

    /** Id_JA du compte connecté, jamais celui du client. */
    protected function resolveIdJaAutorise(?bool $sessionOk = null): int
    {
        return $this->idJaSession;
    }

    public function index()
    {
        if ($this->idJaSession <= 0) {
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        return view('disponibilite_ja_index', [
            'idJa'        => $this->idJaSession,
            'tokenJa'     => '',
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'tbSwitchTo'  => null,
            'baseUrl'     => site_url('ja/disponibilites'),
            'codeEcran'   => 'EJA2',
            'titreEcran'  => 'Mes disponibilités',
            'retourUrl'   => site_url('ja-menu'),
        ]);
    }

    /** Fabrication de jeton EN22 : sans objet pour un compte JA. */
    public function token(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setJSON(['ok' => false, 'err' => 'Action non disponible.']);
    }
}
