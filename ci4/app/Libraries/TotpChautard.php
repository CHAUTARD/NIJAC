<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – 2FA TOTP des écrans réservés au login CHAUTARD (EA96 FfttTest, EA97 BugSpid, EA98 DbAdmin),
 * activée et saisie sur EA99 (TotpController, route `securite-totp`).
 *
 * Contrôle commun appelé par les trois guardChautard() APRÈS la vérification du login :
 *  - colonnes utilisateur.Totp* absentes (avant EA98) : accès SANS TOTP, journalisé [NIJAC][SEC]
 *    une fois par session (sinon impossible d'ouvrir EA98 pour créer les colonnes) ;
 *  - 2FA non activée (TotpSecret NULL) ou session non déverrouillée : redirection vers EA99
 *    (page GET) ou réponse JSON {ok:false, totp:true, url, msg} (AJAX / POST) ;
 *  - session déverrouillée : $_SESSION['totp_ok_ts'] (+ totp_ok_id) récent, validité glissante
 *    TOTP_SESSION_DUREE (30 min) — effacé avec la session (logout / detruireSessionNijac()).
 *
 * Fonctions pures (base32, totpCode, verifierTotp, chiffrement, codes de secours) et procédure
 * d'urgence SQL : config/app_config.php (bloc « 2FA TOTP »). Journal [NIJAC][SEC] : login et
 * événement uniquement, jamais un code ni le secret.
 */
class TotpChautard
{
    /** Écrans vers lesquels EA99 peut renvoyer après validation (liste blanche, jamais une URL client). */
    public const RETOURS = [
        'fftt-test' => 'EA96',
        'bug-spid'  => 'EA97',
        'db-admin'  => 'EA98',
    ];

    /** Clé anti brute-force (fichier logs/login_attempts.json, réglages login_rate_limit_*). */
    public static function cleTentatives(int $idUtilisateur): string
    {
        return 'totp:' . $idUtilisateur;
    }

    public static function journaliser(string $evenement): void
    {
        error_log('[NIJAC][SEC] TOTP ' . $evenement . ' (login ' . ($_SESSION['utilisateur']['login'] ?? '?') . ')');
    }

    /**
     * État TOTP du compte.
     *
     * @return array{colonnes: bool, active: bool, secret: ?string, dernierPas: ?int, secours: string[]}
     *               secret = base32 déchiffré (null si non activée OU indéchiffrable : pepper changé)
     */
    public static function etat(int $idUtilisateur): array
    {
        $pdo  = getPDO();
        $etat = ['colonnes' => utilisateurATotp($pdo), 'active' => false, 'secret' => null, 'dernierPas' => null, 'secours' => []];
        if (!$etat['colonnes'] || $idUtilisateur <= 0) {
            return $etat;
        }

        $st = $pdo->prepare('SELECT TotpSecret, TotpDernierPas, TotpSecours FROM utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
        $st->execute([$idUtilisateur]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['TotpSecret'] === null || $row['TotpSecret'] === '') {
            return $etat;
        }

        $etat['active']     = true;
        $etat['secret']     = dechiffrerSecretTotp((string) $row['TotpSecret'], getObfuscatorPepper());
        $etat['dernierPas'] = $row['TotpDernierPas'] !== null ? (int) $row['TotpDernierPas'] : null;
        $secours            = json_decode((string) ($row['TotpSecours'] ?? ''), true);
        $etat['secours']    = is_array($secours) ? array_values(array_filter($secours, 'is_string')) : [];

        return $etat;
    }

    /** La session courante est-elle déverrouillée (code TOTP validé il y a moins de 30 min, glissant) ? */
    public static function sessionDeverrouillee(int $idUtilisateur): bool
    {
        $ts = (int) ($_SESSION['totp_ok_ts'] ?? 0);

        return $ts > 0
            && (int) ($_SESSION['totp_ok_id'] ?? 0) === $idUtilisateur
            && time() - $ts <= TOTP_SESSION_DUREE;
    }

    /**
     * Écriture persistée dans la session native. Les filtres (controlerSessionFiltre(), AdminAuth)
     * ont déjà appelé session_write_close() : $_SESSION reste lisible en mémoire, mais toute écriture
     * ou session_regenerate_id() faite ensuite serait perdue. Même motif que ChangerMotDePasseController :
     * reprise par un session_start() BRUT (jamais demarrerSessionNijac(), dont le 2e appel empêche de
     * recharger le fichier), régénération de l'identifiant si demandée, $maj() modifie $_SESSION,
     * puis session_write_close() — la valeur est donc persistée immédiatement.
     *
     * Attention : session_start() recharge $_SESSION depuis le fichier ; toute modification faite en
     * mémoire hors de ce helper est écrasée. Chaque écriture de session TOTP passe donc par ici.
     */
    public static function ecrireSession(callable $maj, bool $regenerer = false): void
    {
        try {
            if (session_status() === PHP_SESSION_NONE && !session_start()) { // reprise brute (voir docblock)
                error_log('[NIJAC] TOTP : reprise de session impossible, écriture non persistée');

                return;
            }
            if ($regenerer) {
                session_regenerate_id(true);
            }
            $maj();
        } catch (\Throwable $e) {
            error_log('[NIJAC] TOTP écriture de session : ' . $e->getMessage());
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    /**
     * Modifie $_SESSION pour marquer la session déverrouillée — à n'appeler QUE dans le callable
     * de ecrireSession() (sinon non persisté).
     */
    public static function marquerDeverrouillee(int $idUtilisateur): void
    {
        $_SESSION['totp_ok_ts'] = time();
        $_SESSION['totp_ok_id'] = $idUtilisateur;
    }

    /** Marque la session déverrouillée (persisté) ; $regenerer : nouvel identifiant de session en même temps. */
    public static function deverrouiller(int $idUtilisateur, bool $regenerer = false): void
    {
        self::ecrireSession(static function () use ($idUtilisateur): void {
            self::marquerDeverrouillee($idUtilisateur);
        }, $regenerer);
    }

    /**
     * Contrôle commun des guardChautard() d'EA96/97/98 (login CHAUTARD déjà vérifié).
     *
     * @param string $retour clé de self::RETOURS (écran appelant)
     * @return ResponseInterface|null null si l'accès est autorisé
     */
    public static function controlerAcces(string $retour): ?ResponseInterface
    {
        $id = (int) ($_SESSION['utilisateur']['id'] ?? 0);

        try {
            $pdo = getPDO();
            if (!utilisateurATotp($pdo)) {
                // Avant EA98 : pas de colonne, donc pas de 2FA possible — accès laissé ouvert
                // (EA98 doit pouvoir créer les colonnes), journalisé une fois par session.
                if (empty($_SESSION['totp_absent_journalise'])) {
                    self::ecrireSession(static function (): void {
                        $_SESSION['totp_absent_journalise'] = true;
                    });
                    self::journaliser('colonnes absentes (chargez EA98) : accès ' . (self::RETOURS[$retour] ?? $retour) . ' sans 2FA');
                }

                return null;
            }

            if (self::sessionDeverrouillee($id) && self::etat($id)['active']) {
                self::ecrireSession(static function (): void {
                    $_SESSION['totp_ok_ts'] = time(); // validité glissante
                });

                return null;
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] TOTP contrôle d\'accès : ' . $e->getMessage());
        }

        $url     = site_url('securite-totp') . '?retour=' . rawurlencode(isset(self::RETOURS[$retour]) ? $retour : 'db-admin');
        $request = service('request');
        if ($request->isAJAX() || strtoupper($request->getMethod()) !== 'GET') {
            return service('response')->setJSON([
                'ok'    => false,
                'totp'  => true,
                'url'   => $url,
                'msg'   => 'Double authentification TOTP requise ou expirée : revalidez votre code sur EA99 (rechargez la page).',
            ]);
        }

        return redirect()->to($url);
    }

    /**
     * Vérifie une saisie (code TOTP à 6 chiffres, ou code de secours si $accepterSecours) pour le compte,
     * avec limitation `totp:<id>` et anti-rejeu persistant (UPDATE conditionnel sur TotpDernierPas :
     * deux requêtes concurrentes avec le même code ne passent pas toutes les deux).
     *
     * @return array{ok: bool, msg: string, secours: bool}
     */
    public static function verifierSaisie(int $idUtilisateur, string $saisie, bool $accepterSecours): array
    {
        $fenetre = (int) getConfig('login_rate_limit_fenetre', '15');
        $cle     = self::cleTentatives($idUtilisateur);
        if ($limite = checkTentativesRateLimit($cle, (int) getConfig('login_rate_limit_max', '5'), $fenetre)) {
            return ['ok' => false, 'msg' => $limite, 'secours' => false];
        }

        $saisie = trim($saisie);
        if ($saisie === '') {
            return ['ok' => false, 'msg' => 'Code TOTP requis.', 'secours' => false];
        }

        $pdo  = getPDO();
        $etat = self::etat($idUtilisateur);
        if (!$etat['active']) {
            return ['ok' => false, 'msg' => 'Double authentification TOTP non activée (EA99).', 'secours' => false];
        }

        // ── Code de secours (usage unique) ──
        if ($accepterSecours && estFormatCodeSecours($saisie)) {
            $restants = consommerCodeSecours($etat['secours'], $saisie);
            if ($restants !== null) {
                $avant = json_encode($etat['secours']);
                $up    = $pdo->prepare('UPDATE utilisateur SET TotpSecours = ? WHERE Id_Utilisateur = ? AND TotpSecours = ?');
                $up->execute([json_encode($restants), $idUtilisateur, $avant]);
                if ($up->rowCount() === 1) {
                    self::journaliser('code de secours utilisé (' . count($restants) . ' restant(s))');

                    return ['ok' => true, 'msg' => 'Code de secours accepté (' . count($restants) . ' restant(s)).', 'secours' => true];
                }
            }
            enregistrerTentative($cle, $fenetre);
            self::journaliser('échec (code de secours invalide)');

            return ['ok' => false, 'msg' => 'Code de secours invalide ou déjà utilisé.', 'secours' => false];
        }

        if ($etat['secret'] === null) {
            self::journaliser('secret indéchiffrable (pepper modifié ?)');

            return ['ok' => false, 'msg' => 'Secret TOTP illisible (clé modifiée) : utilisez un code de secours ou la procédure de réinitialisation.', 'secours' => false];
        }

        $secret = base32Decoder($etat['secret']);
        $pas    = $secret === false ? null : totpPasCorrespondant($secret, $saisie);

        $dernierPas = $etat['dernierPas'];
        if ($secret !== false && verifierTotp($secret, $saisie, $dernierPas)) {
            $up = $pdo->prepare('UPDATE utilisateur SET TotpDernierPas = ? WHERE Id_Utilisateur = ? AND (TotpDernierPas IS NULL OR TotpDernierPas < ?)');
            $up->execute([$dernierPas, $idUtilisateur, $dernierPas]);
            if ($up->rowCount() === 1) {
                return ['ok' => true, 'msg' => '', 'secours' => false];
            }
        }

        enregistrerTentative($cle, $fenetre);
        self::journaliser($pas !== null ? 'échec (code déjà utilisé)' : 'échec (code incorrect)');

        return [
            'ok'      => false,
            'msg'     => $pas !== null ? 'Code TOTP déjà utilisé : attendez le code suivant.' : 'Code TOTP incorrect.',
            'secours' => false,
        ];
    }
}
