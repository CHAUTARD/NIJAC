<?php

namespace App\Libraries;

/**
 * 2FA TOTP (RFC 6238 — SHA-1, 6 chiffres, pas de 30 s, compatible Google Authenticator) des écrans
 * réservés au login CHAUTARD (EA96 / EA97 / EA98), activée / saisie sur EA99 (TotpController,
 * TotpChautard). PHP natif, aucune dépendance, aucun accès base ni session : logique pure.
 *
 * Les fonctions globales de config/app_config.php (verifierTotp(), chiffrerSecretTotp()…) délèguent ici.
 * Stockage (colonnes créées par initTableConfiguration(), EA98) : utilisateur.TotpSecret (secret
 * base32 chiffré AES-256-GCM), utilisateur.TotpDernierPas (anti-rejeu), utilisateur.TotpSecours
 * (JSON des codes de secours hachés).
 *
 * PROCÉDURE D'URGENCE (téléphone perdu, pepper changé, codes de secours épuisés) — via la console
 * SQL de l'hébergeur (phpMyAdmin), ou EA98 après déverrouillage sur EA99 par un code de secours
 * (puis mot de passe dans EA98) :
 *   UPDATE utilisateur SET TotpSecret=NULL, TotpDernierPas=NULL, TotpSecours=NULL WHERE Login='CHAUTARD';
 * (réinitialise la 2FA : la prochaine ouverture d'EA96/97/98 renvoie vers l'activation EA99).
 */
class Totp
{
    public const PAS_SECONDES  = 30;
    public const CHIFFRES      = 6;
    public const TOLERANCE_PAS = 1;     // ±1 pas (décalage d'horloge du téléphone)
    public const SESSION_DUREE = 1800;  // validité glissante (s) du déverrouillage EA96/97/98 en session
    public const NB_SECOURS    = 8;     // codes de secours à usage unique générés à l'activation
    public const EMETTEUR      = 'NIJAC';
    // Sel constant de la clé de chiffrement du secret TOTP : clé = SHA-256(sel + pepper
    // getObfuscatorPepper(), .env non versionné). Pepper vide : clé dérivée du SEUL sel constant,
    // présent dans le dépôt — le chiffrement ne protège alors plus le secret d'un lecteur de la base
    // ayant aussi le code source. Changer le pepper rend le secret indéchiffrable (procédure
    // d'urgence ci-dessus, ou déverrouillage par code de secours).
    public const SEL_CLE       = 'NIJAC|totp-secret|v1|';

    /** Encodage base32 RFC 4648 (A-Z2-7), sans padding « = » (format attendu par Google Authenticator). */
    public static function base32Encoder(string $binaire): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits     = '';
        foreach (str_split($binaire) as $c) {
            if ($c !== '') {
                $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
            }
        }
        if ($bits === '') {
            return '';
        }
        $sortie = '';
        foreach (str_split($bits, 5) as $bloc) {
            $sortie .= $alphabet[bindec(str_pad($bloc, 5, '0', STR_PAD_RIGHT))];
        }

        return $sortie;
    }

    /** Décodage base32 (casse, espaces, tirets et padding ignorés) ; false si caractère invalide. */
    public static function base32Decoder(string $texte): string|false
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $texte    = strtoupper(str_replace([' ', '-', '='], '', $texte));
        $bits     = '';
        foreach (str_split($texte) as $c) {
            if ($c === '') {
                continue;
            }
            $v = strpos($alphabet, $c);
            if ($v === false) {
                return false;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $sortie = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) {
                $sortie .= chr(bindec($octet));
            }
        }

        return $sortie;
    }

    /** Nouveau secret TOTP : 20 octets aléatoires (160 bits, taille recommandée pour HMAC-SHA1), en base32. */
    public static function genererSecret(): string
    {
        return self::base32Encoder(random_bytes(20));
    }

    /** Pas TOTP courant (ou de l'instant $t). */
    public static function pasCourant(?int $t = null): int
    {
        return intdiv($t ?? time(), self::PAS_SECONDES);
    }

    /**
     * Code TOTP/HOTP (RFC 4226 §5.3 + RFC 6238) pour le secret BINAIRE $secret (base32 décodé) et le
     * pas $pas, sur $chiffres chiffres (zéros de tête conservés).
     */
    public static function code(string $secret, int $pas, int $chiffres = self::CHIFFRES): string
    {
        $compteur = pack('N2', ($pas >> 32) & 0xFFFFFFFF, $pas & 0xFFFFFFFF);
        $hmac     = hash_hmac('sha1', $compteur, $secret, true);
        $offset   = ord($hmac[19]) & 0x0F;
        $valeur   = ((ord($hmac[$offset]) & 0x7F) << 24)
                  | (ord($hmac[$offset + 1]) << 16)
                  | (ord($hmac[$offset + 2]) << 8)
                  | ord($hmac[$offset + 3]);

        return str_pad((string) ($valeur % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
    }

    /**
     * Pas (dans la tolérance ±TOLERANCE_PAS autour de $t) pour lequel $code est valide, ou null.
     * Comparaison hash_equals sur chaque pas (pas de sortie anticipée) ; le pas le plus récent gagne.
     */
    public static function pasCorrespondant(string $secret, string $code, ?int $t = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::CHIFFRES . '}$/', $code)) {
            return null;
        }
        $courant = self::pasCourant($t);
        $trouve  = null;
        for ($d = -self::TOLERANCE_PAS; $d <= self::TOLERANCE_PAS; $d++) {
            if (hash_equals(self::code($secret, $courant + $d), $code)) {
                $trouve = $courant + $d;
            }
        }

        return $trouve;
    }

    /**
     * Vérifie un code TOTP avec anti-rejeu : le pas accepté doit être STRICTEMENT supérieur à
     * $dernierPas (dernier pas déjà utilisé, null = aucun). En cas de succès $dernierPas reçoit le
     * pas accepté (à enregistrer en base par l'appelant) ; inchangé sinon.
     */
    public static function verifier(string $secret, string $code, ?int &$dernierPas, ?int $t = null): bool
    {
        $pas = self::pasCorrespondant($secret, $code, $t);
        if ($pas === null || ($dernierPas !== null && $pas <= $dernierPas)) {
            return false;
        }
        $dernierPas = $pas;

        return true;
    }

    /** URI otpauth:// à encoder dans le QR code (libellé NIJAC:<login>, émetteur NIJAC). */
    public static function uriOtpauth(string $secretBase32, string $login): string
    {
        return 'otpauth://totp/' . rawurlencode(self::EMETTEUR) . ':' . rawurlencode($login)
            . '?secret=' . $secretBase32 . '&issuer=' . rawurlencode(self::EMETTEUR);
    }

    /** Clé AES-256 du secret TOTP : SHA-256(sel constant + pepper) — voir SEL_CLE. */
    public static function cleChiffrement(string $pepper): string
    {
        return hash('sha256', self::SEL_CLE . $pepper, true);
    }

    /** Chiffre le secret (base32) en AES-256-GCM : « v1: » + base64(iv 12 o + tag 16 o + chiffré). */
    public static function chiffrerSecret(string $secretBase32, string $pepper): string
    {
        $iv      = random_bytes(12);
        $tag     = '';
        $chiffre = openssl_encrypt($secretBase32, 'aes-256-gcm', self::cleChiffrement($pepper), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($chiffre === false) {
            throw new \RuntimeException('Chiffrement du secret TOTP impossible.');
        }

        return 'v1:' . base64_encode($iv . $tag . $chiffre);
    }

    /** Déchiffre le secret (base32) ; null si format inconnu, données altérées ou mauvaise clé (pepper changé). */
    public static function dechiffrerSecret(string $stocke, string $pepper): ?string
    {
        if (!str_starts_with($stocke, 'v1:')) {
            return null;
        }
        $brut = base64_decode(substr($stocke, 3), true);
        if ($brut === false || strlen($brut) <= 28) {
            return null;
        }
        $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', self::cleChiffrement($pepper), OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));

        return $clair === false ? null : $clair;
    }

    /** Normalise un code de secours saisi (majuscules, sans espaces ni tirets). */
    public static function normaliserCodeSecours(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    /** La saisie a-t-elle la forme d'un code de secours (10 caractères base32) ? */
    public static function estFormatCodeSecours(string $code): bool
    {
        return (bool) preg_match('/^[A-Z2-7]{10}$/', self::normaliserCodeSecours($code));
    }

    /**
     * Génère NB_SECOURS codes de secours (10 caractères base32 = 50 bits, affichés « XXXXX-XXXXX »).
     * Hachés par password_hash() (bcrypt) : SecurePasswordHasher (PBKDF2 600 000 itérations) rendrait
     * la vérification des 8 codes beaucoup trop lente, et ces codes aléatoires n'en ont pas besoin.
     *
     * @return array{codes: string[], haches: string[]} codes en clair (affichés UNE fois) + hachés (stockés)
     */
    public static function genererCodesSecours(): array
    {
        $codes  = [];
        $haches = [];
        for ($i = 0; $i < self::NB_SECOURS; $i++) {
            $c        = substr(self::base32Encoder(random_bytes(7)), 0, 10);
            $codes[]  = substr($c, 0, 5) . '-' . substr($c, 5);
            $haches[] = password_hash($c, PASSWORD_DEFAULT);
        }

        return ['codes' => $codes, 'haches' => $haches];
    }

    /**
     * Consomme un code de secours : retourne la liste des hachés restants (sans celui utilisé),
     * ou null si le code ne correspond à aucun.
     *
     * @param string[] $haches
     * @return string[]|null
     */
    public static function consommerCodeSecours(array $haches, string $code): ?array
    {
        if (!self::estFormatCodeSecours($code)) {
            return null;
        }
        $code = self::normaliserCodeSecours($code);
        foreach ($haches as $i => $h) {
            if (is_string($h) && password_verify($code, $h)) {
                unset($haches[$i]);

                return array_values($haches);
            }
        }

        return null;
    }
}
