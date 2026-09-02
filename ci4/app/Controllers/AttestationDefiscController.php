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

            $att = $this->decoderJustificatif((string) $this->request->getPost('pdf'));
            if (!$att['ok'] || $att['ext'] !== 'pdf') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'PDF de l\'attestation : ' . ($att['msg'] ?? 'invalide') . '.']);
            }

            // Carte grise facultative (PDF ou image) jointe par le JA.
            $cg = null;
            $cgData = trim((string) $this->request->getPost('carte_grise'));
            if ($cgData !== '') {
                $cgRes = $this->decoderJustificatif($cgData);
                if (!$cgRes['ok']) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise : ' . $cgRes['msg'] . '.']);
                }
                $cg = $cgRes;
            }

            $dir = __DIR__ . '/../../../_Defiscalisation';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Répertoire _Defiscalisation absent.']);
            }
            if (file_put_contents($dir . '/' . $idJa . '.pdf', $att['bin']) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Écriture du PDF impossible."]);
            }
            if ($cg !== null) {
                foreach (glob($dir . '/' . $idJa . '_cg.*') ?: [] as $vieux) {
                    @unlink($vieux);
                }
                file_put_contents($dir . '/' . $idJa . '_cg.' . $cg['ext'], $cg['bin']);
            }

            getPDO()->prepare('UPDATE ja SET PuissanceFiscale = :cv, VehiculeElectrique = :e WHERE Id_JA = :id')
                ->execute([':cv' => $cv, ':e' => $elec, ':id' => $idJa]);

            return $this->response->setJSON([
                'ok'  => true,
                'msg' => 'Attestation enregistrée' . ($cg !== null ? ' avec la carte grise' : '') . '. Merci !',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Décode un data-URI base64 (sortie jsPDF ou fichier téléversé) et valide le
     * type par la signature du contenu. Retourne
     * ['ok'=>bool, 'bin'=>?string, 'ext'=>?string ('pdf'|'jpg'|'png'), 'msg'=>string].
     */
    private function decoderJustificatif(string $dataUri): array
    {
        $virgule = strpos($dataUri, ',');
        if ($virgule === false || stripos(substr($dataUri, 0, $virgule), 'base64') === false) {
            return ['ok' => false, 'ext' => null, 'msg' => 'fichier manquant ou format non reconnu'];
        }
        $bin = base64_decode(substr($dataUri, $virgule + 1), true);
        if ($bin === false || $bin === '') {
            return ['ok' => false, 'ext' => null, 'msg' => 'contenu illisible'];
        }
        if (strlen($bin) > 10 * 1024 * 1024) {
            return ['ok' => false, 'ext' => null, 'msg' => 'fichier trop volumineux (max 10 Mo)'];
        }
        $ext = match (true) {
            strncmp($bin, '%PDF-', 5) === 0             => 'pdf',
            strncmp($bin, "\xFF\xD8\xFF", 3) === 0      => 'jpg',
            strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0 => 'png',
            default                                     => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'ext' => null, 'msg' => 'seuls les fichiers PDF, JPEG ou PNG sont acceptés'];
        }

        return ['ok' => true, 'bin' => $bin, 'ext' => $ext, 'msg' => ''];
    }
}
