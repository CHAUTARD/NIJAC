<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Attestation sur l'honneur défiscalisation (ED53).
 *
 * Deux modes (patron EN20) :
 *  - **Public tokenisé** (`?ja=TOKEN`, Obfuscator) : lien envoyé au JA dans
 *    l'email de relance (marqueur {URL_ATTESTATION_JA}, message n°10). Champs
 *    pré-remplis depuis `ja` ; bouton « Valider » → écrit
 *    `ja.PuissanceFiscale` / `ja.VehiculeElectrique` et dépose le PDF signé
 *    dans `_Defiscalisation/{Id_JA}.pdf`.
 *  - **Session Défiscalisateur / Administrateur** (menu E005, bouton ED51) :
 *    formulaire vierge, impression seule, aucune écriture ni PDF serveur.
 *
 * Route publique sans filtre : l'accès est vérifié ici (token valide OU
 * session). Le PDF est produit côté navigateur (jsPDF) et posté en base64.
 */
class AttestationDefiscController extends BaseController
{
    private const CV_AUTORISES = [3, 4, 5, 6, 7];

    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';
        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);
    }

    /** Id_JA depuis ?ja=TOKEN (obfusqué) ; 0 si absent ou invalide. */
    private function idJaDepuisToken(): int
    {
        $t = trim($this->request->getGet('ja') ?? $this->request->getPost('ja') ?? '');
        if ($t === '') {
            return 0;
        }
        $d = $this->obf->deobfuscate($t);

        return $d > 0 ? $d : 0;
    }

    /** Lit la session native sans la conserver (voir DisponibiliteJaController). */
    private function sessionUtilisateur(): array
    {
        demarrerSessionNijac();
        $u = $_SESSION['utilisateur'] ?? [];
        session_write_close();
        unset($_SESSION);

        return is_array($u) ? $u : [];
    }

    public function index()
    {
        $u        = $this->sessionUtilisateur();
        $estStaff = in_array($u['role'] ?? '', ['Defiscalisateur', 'Administrateur'], true);
        $idJa     = $this->idJaDepuisToken();

        if ($idJa <= 0 && !$estStaff) {
            return redirect()->to(site_url('login'));
        }

        $fiche = null;
        if ($idJa > 0) {
            $st = getPDO()->prepare(
                'SELECT Nom, Prenom, Cp, Ville, PuissanceFiscale, VehiculeElectrique FROM ja WHERE Id_JA = ?'
            );
            $st->execute([$idJa]);
            $ja = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$ja) {
                return redirect()->to(site_url('login'));
            }
            $cv = $ja['PuissanceFiscale'];
            $fiche = [
                'nomPrenom'  => trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')),
                'adresse'    => trim(($ja['Cp'] ?? '') . ' ' . ($ja['Ville'] ?? '')),
                'ville'      => $ja['Ville'] ?? '',
                'cv'         => $cv !== null ? (int) $cv : null,
                'electrique' => (int) $ja['VehiculeElectrique'],
            ];
        }

        return view('attestation_defisc_index', [
            'modeJa'      => $idJa > 0,
            'tokenJa'     => $idJa > 0 ? trim($this->request->getGet('ja') ?? '') : '',
            'fiche'       => $fiche,
            'cvAutorises' => self::CV_AUTORISES,
            'retourUrl'   => $estStaff ? site_url('defiscalisation') : getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
            'nomComplet'  => isset($u['prenom'], $u['nom']) ? trim($u['prenom'] . ' ' . $u['nom']) : '',
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'association' => 'Ligue ' . getConfig('region', 'Normandie') . ' de Tennis de Table',
            'dateJour'    => date('d/m/Y'),
        ]);
    }

    /**
     * Validation par le JA (token obligatoire) : met à jour
     * `ja.PuissanceFiscale` / `ja.VehiculeElectrique` et enregistre le PDF signé
     * dans `_Defiscalisation/{Id_JA}.pdf`.
     */
    public function valider(): ResponseInterface
    {
        try {
            $idJa = $this->idJaDepuisToken();
            if ($idJa <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Lien invalide ou expiré.']);
            }

            $st = getPDO()->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
            $st->execute([$idJa]);
            if (!$st->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'JA introuvable.']);
            }

            $cvBrut = $this->request->getPost('puissance');
            $cv     = ($cvBrut === null || $cvBrut === '') ? null : (int) $cvBrut;
            if ($cv !== null && !in_array($cv, self::CV_AUTORISES, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Puissance fiscale invalide.']);
            }
            $elec = $this->request->getPost('electrique') ? 1 : 0;

            $pdfData = (string) $this->request->getPost('pdf');
            if (!str_starts_with($pdfData, 'data:application/pdf;base64,')) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'PDF manquant ou invalide.']);
            }
            $bin = base64_decode(substr($pdfData, strpos($pdfData, ',') + 1), true);
            if ($bin === false || strncmp($bin, '%PDF-', 5) !== 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Contenu PDF invalide.']);
            }
            if (strlen($bin) > 10 * 1024 * 1024) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'PDF trop volumineux.']);
            }

            $dir = __DIR__ . '/../../../_Defiscalisation';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Répertoire _Defiscalisation absent.']);
            }
            if (file_put_contents($dir . '/' . $idJa . '.pdf', $bin) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Écriture du PDF impossible."]);
            }

            getPDO()->prepare('UPDATE ja SET PuissanceFiscale = :cv, VehiculeElectrique = :e WHERE Id_JA = :id')
                ->execute([':cv' => $cv, ':e' => $elec, ':id' => $idJa]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Attestation enregistrée. Merci !']);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}
