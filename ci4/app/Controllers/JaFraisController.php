<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Mes nominations et frais / péages / défiscalisation (EJA4, ex-EJA3 + EJA4 fusionnés), menu JA (E011), rôle « JA » (filtre "jaauth").
 *
 * Réutilise EN21 (ConvocationJaController) sans dupliquer ses règles : accuser() (J <= D,
 * accuserReceptionConvocation()), sauvegarderFrais() (J >= D, accusé requis le jour même,
 * fraisSaisissables() / etatSaisieFrais(), plafonds frais_max_*, arbitrage club = 0), joindreJustificatif()
 * et voirJustificatif() (App\Libraries\JustificatifPeage). Seule l'autorisation change
 * (nominationAutorisee()) : au lieu du jeton cnv du lien, la nomination doit appartenir au JA de la
 * session (idJaCompteSession() : rôle JA + fiche du compte (utilisateur.Id_Ja), ou fiche liée en mode JA, jamais un id client) — le jeton éventuellement envoyé est
 * ignoré. Liste : JaNominationsController::nominationsDuJa() + état accusé / saisie par nomination.
 */
class JaFraisController extends ConvocationJaController
{
    protected string $ecran = 'EJA4';

    private int $idJaSession;
    private string $loginSession;
    private bool $modeJa;

    public function __construct()
    {
        parent::__construct();
        // Garde-fou propre au contrôleur, en plus de "jaauth" : rôle JA ET fiche de la session (compte JA pur :
        // son Id_Ja, idJaDuCompteJa() ; mode JA : fiche liée relue en base) — 0 = tout refusé.
        $this->idJaSession  = idJaCompteSession();
        $this->loginSession = (string) ($_SESSION['utilisateur']['login'] ?? '');
        $this->modeJa       = estSessionModeJa();
    }

    /** La nomination appartient-elle au JA connecté ? (jeton ignoré) */
    protected function nominationAutorisee(int $idNomination, string $tokenCnv): bool
    {
        if ($idNomination <= 0 || $this->idJaSession <= 0) {
            return false;
        }
        $st = getPDO()->prepare('
            SELECT 1 FROM nomination n
            JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
            WHERE n.Id_Nomination = ? AND d.Id_JA = ?
            LIMIT 1
        ');
        $st->execute([$idNomination, $this->idJaSession]);

        return (bool) $st->fetchColumn();
    }

    /** Message neutre : n'indique pas si la nomination existe pour un autre JA. */
    protected function messageLienInvalide(): string
    {
        return 'Nomination introuvable.';
    }

    /** Session déjà lue par le constructeur (pas de réouverture). */
    protected function loginSession(): ?string
    {
        return $this->loginSession;
    }

    protected function tracerActionConnecte(?string $login, string $action, int $idNomination): void
    {
        error_log("[NIJAC] EJA4 : $action par le compte " . ($this->modeJa ? '' : 'JA ')
            . preg_replace('/[^\w.@-]/u', '_', (string) $login)
            . ($this->modeJa ? " en mode JA (fiche {$this->idJaSession})" : '') . " (nomination $idNomination)");
    }

    public function index($nomSeg = null, $cnvSeg = null)
    {
        if ($this->idJaSession <= 0) { // garde-fou : rôle JA + fiche de la session (Id_Ja du compte JA, ou fiche liée en mode JA)
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        return view('ja_frais_index', [
            'nomComplet'       => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement'      => $u['id_departement'] ?? '',
            'changeLogin'      => !empty($u['change_login']),
            'indemniteForfait' => (float) getConfig('indemnite_forfaitaire', '25.00'),
            'tauxKm'           => (float) getConfig('frais_kilometrique', '0.30'),
            'maxPeage'         => (float) getConfig('frais_max_peages', '80'),
            'maxKm'            => (int) getConfig('frais_max_km', '200'),
        ]);
    }

    /**
     * GET ja/frais/data : nominations du JA connecté + pour chacune l'état de l'accusé (accusable,
     * convocationAccusable()) et de la saisie des frais (etatSaisieFrais() : ok / err / attendAccuse),
     * calculés par les MÊMES fonctions qu'EN21. `justificatifDispo` : colonne FichierPeage présente.
     */
    public function data(): ResponseInterface
    {
        try {
            $pdo   = getPDO();
            $rows  = JaNominationsController::nominationsDuJa($pdo, $this->idJaSession);
            $colAr = nominationAAccuseReception($pdo);
            foreach ($rows as &$row) {
                $id = (int) $row['Id_Nomination'];
                $row['Accusable'] = $colAr && convocationAccusable($pdo, $id);
                $row['EtatFrais'] = self::etatSaisieFrais($pdo, $id);
                // Lien EN21 (même jeton que les convocations envoyées) : convocation complète / impression.
                $row['UrlConvocation'] = site_url('convocation-ja/' . $id . '/' . $this->obf->obfuscate($id));
            }
            unset($row);

            return $this->response->setJSON([
                'ok'                => true,
                'data'              => $rows,
                'accuseDispo'       => $colAr,
                'justificatifDispo' => nominationAFichierPeage($pdo),
            ]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EJA4 data : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur technique : impossible de charger vos frais.')]);
        }
    }
}
