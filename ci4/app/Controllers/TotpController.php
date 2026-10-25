<?php

namespace App\Controllers;

use App\Libraries\TotpChautard;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Sécurité TOTP (EA99) : activation et saisie du code de la double authentification
 * TOTP (RFC 6238, Google Authenticator) exigée par les écrans réservés au login CHAUTARD
 * (EA96 / EA97 / EA98, voir App\Libraries\TotpChautard).
 *
 * Filtre "adminauth" + login === 'CHAUTARD' vérifié ici (même règle que EA96/97/98). CSRF global.
 *  - GET  securite-totp?retour=…   page : activation (QR + secret + mot de passe + 1er code) si la 2FA
 *                                   n'est pas activée, sinon saisie du code (ou d'un code de secours) ;
 *  - POST securite-totp/activer     mot de passe + premier code valide → secret chiffré enregistré,
 *                                   8 codes de secours renvoyés UNE seule fois ;
 *  - POST securite-totp/verifier    code TOTP (anti-rejeu) → session déverrouillée 30 min glissantes ;
 *  - POST securite-totp/secours     code de secours (usage unique) → idem.
 * Limitation `totp:<id>` (login_rate_limit_*). Procédure d'urgence : config/app_config.php (bloc TOTP).
 */
class TotpController extends BaseController
{
    /** Durée de validité (s) du secret proposé à l'activation, gardé en session (chiffré) le temps de scanner. */
    private const ACTIVATION_DUREE = 900;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function estChautard(): bool
    {
        return ($_SESSION['utilisateur']['login'] ?? '') === 'CHAUTARD';
    }

    private function idUtilisateur(): int
    {
        return (int) ($_SESSION['utilisateur']['id'] ?? 0);
    }

    /** Écran de retour (liste blanche TotpChautard::RETOURS, défaut EA98). */
    private function retour(?string $valeur): string
    {
        return isset(TotpChautard::RETOURS[$valeur ?? '']) ? (string) $valeur : 'db-admin';
    }

    private function refus(): ResponseInterface
    {
        return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
    }

    /** Secret en attente d'activation (base32), régénéré s'il est absent, expiré ou d'un autre compte. */
    private function secretActivation(bool $creer): ?string
    {
        $a      = $_SESSION['totp_activation'] ?? null;
        $pepper = getObfuscatorPepper();
        if (is_array($a) && (int) ($a['id'] ?? 0) === $this->idUtilisateur()
            && time() - (int) ($a['ts'] ?? 0) <= self::ACTIVATION_DUREE) {
            $secret = dechiffrerSecretTotp((string) ($a['secret'] ?? ''), $pepper);
            if ($secret !== null) {
                return $secret;
            }
        }
        if (!$creer) {
            return null;
        }
        // Persisté immédiatement (session refermée par le filtre) : sinon le QR code change à chaque requête.
        $secret     = genererSecretTotp();
        $activation = ['id' => $this->idUtilisateur(), 'ts' => time(), 'secret' => chiffrerSecretTotp($secret, $pepper)];
        TotpChautard::ecrireSession(static function () use ($activation): void {
            $_SESSION['totp_activation'] = $activation;
        });

        return $secret;
    }

    public function index()
    {
        if (!$this->estChautard()) {
            return redirect()->to(site_url('admin-menu'));
        }

        $u      = $_SESSION['utilisateur'] ?? [];
        $id     = $this->idUtilisateur();
        $retour = $this->retour($this->request->getGet('retour'));
        $data   = [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'retour'      => $retour,
            'retourCode'  => TotpChautard::RETOURS[$retour],
            'retourUrl'   => site_url($retour),
            'mode'        => 'saisie',
            'secret'      => '',
            'uri'         => '',
            'nbSecours'   => 0,
            'erreur'      => '',
        ];

        try {
            $etat = TotpChautard::etat($id);
            if (!$etat['colonnes']) {
                $data['mode'] = 'absent';
            } elseif (!$etat['active']) {
                $data['mode']   = 'activation';
                $data['secret'] = $this->secretActivation(true);
                $data['uri']    = uriOtpauth($data['secret'], (string) ($u['login'] ?? 'CHAUTARD'));
            } else {
                $data['mode']      = TotpChautard::sessionDeverrouillee($id) ? 'deverrouille' : 'saisie';
                $data['nbSecours'] = count($etat['secours']);
                if ($etat['secret'] === null) {
                    $data['erreur'] = 'Le secret TOTP enregistré est illisible (clé de chiffrement modifiée ?) : utilisez un code de secours, puis la procédure de réinitialisation.';
                }
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] EA99 : ' . $e->getMessage());
            $data['mode']   = 'erreur';
            $data['erreur'] = 'Lecture de l\'état TOTP impossible.';
        }

        return view('totp_index', $data);
    }

    /** POST securite-totp/activer : mot de passe + premier code valide du secret proposé. */
    public function activer(): ResponseInterface
    {
        if (!$this->estChautard()) {
            return $this->refus();
        }

        $id      = $this->idUtilisateur();
        $cle     = TotpChautard::cleTentatives($id);
        $fenetre = (int) getConfig('login_rate_limit_fenetre', '15');
        if ($limite = checkTentativesRateLimit($cle, (int) getConfig('login_rate_limit_max', '5'), $fenetre)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $limite]);
        }

        try {
            $pdo = getPDO();
            if (!utilisateurATotp($pdo)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Colonnes TOTP absentes : chargez d\'abord EA98.']);
            }
            if (TotpChautard::etat($id)['active']) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'La double authentification TOTP est déjà activée.']);
            }

            $secret = $this->secretActivation(false);
            if ($secret === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Secret expiré : rechargez la page et scannez le nouveau QR code.']);
            }

            // Mot de passe redemandé : une session volée ne doit pas pouvoir enrôler son propre téléphone.
            if (!verifierMotDePasseUtilisateur($id, (string) ($this->request->getPost('password') ?? ''))) {
                enregistrerTentative($cle, $fenetre);
                TotpChautard::journaliser('activation refusée (mot de passe incorrect)');

                return $this->response->setJSON(['ok' => false, 'msg' => 'Mot de passe incorrect.']);
            }

            $pas = null;
            if (!verifierTotp(base32Decoder($secret) ?: '', (string) ($this->request->getPost('code') ?? ''), $pas)) {
                enregistrerTentative($cle, $fenetre);
                TotpChautard::journaliser('activation refusée (code incorrect)');

                return $this->response->setJSON(['ok' => false, 'msg' => 'Code incorrect : vérifiez l\'heure du téléphone et saisissez le code affiché.']);
            }

            $secours = genererCodesSecours();
            $up      = $pdo->prepare(
                'UPDATE utilisateur SET TotpSecret = ?, TotpDernierPas = ?, TotpSecours = ?
                 WHERE Id_Utilisateur = ? AND TotpSecret IS NULL'
            );
            $up->execute([chiffrerSecretTotp($secret, getObfuscatorPepper()), $pas, json_encode($secours['haches']), $id]);
            if ($up->rowCount() !== 1) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Activation impossible (déjà activée entre-temps ?).']);
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] EA99 activation : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur lors de l\'activation.']);
        }

        // Une seule écriture persistée : nouvel identifiant + secret d'activation oublié + déverrouillage.
        TotpChautard::ecrireSession(static function () use ($id): void {
            unset($_SESSION['totp_activation']);
            TotpChautard::marquerDeverrouillee($id);
        }, true);
        TotpChautard::journaliser('activée');

        return $this->response->setJSON([
            'ok'     => true,
            'msg'    => 'Double authentification TOTP activée.',
            'codes'  => $secours['codes'],
            'url'    => site_url($this->retour($this->request->getPost('retour'))),
        ]);
    }

    /** POST securite-totp/verifier : code TOTP à 6 chiffres. */
    public function verifier(): ResponseInterface
    {
        return $this->deverrouiller(false);
    }

    /** POST securite-totp/secours : code de secours à usage unique. */
    public function secours(): ResponseInterface
    {
        return $this->deverrouiller(true);
    }

    private function deverrouiller(bool $secours): ResponseInterface
    {
        if (!$this->estChautard()) {
            return $this->refus();
        }

        $id     = $this->idUtilisateur();
        $saisie = (string) ($this->request->getPost('code') ?? '');
        if ($secours && !estFormatCodeSecours($saisie)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Format attendu : XXXXX-XXXXX (10 caractères).']);
        }

        try {
            $r = TotpChautard::verifierSaisie($id, $secours ? $saisie : preg_replace('/\s+/', '', $saisie), $secours);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EA99 vérification : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur lors de la vérification.']);
        }
        if (!$r['ok']) {
            return $this->response->setJSON(['ok' => false, 'msg' => $r['msg']]);
        }

        TotpChautard::deverrouiller($id, true); // nouvel identifiant de session + déverrouillage, persistés

        return $this->response->setJSON([
            'ok'  => true,
            'msg' => $r['msg'] !== '' ? $r['msg'] : 'Code accepté.',
            'url' => site_url($this->retour($this->request->getPost('retour'))),
        ]);
    }
}
