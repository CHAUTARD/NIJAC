<?php
/**
 * Réplique exacte en PHP du SecurePasswordHasher.cs C#.
 *
 * Algorithme : PBKDF2-HMAC-SHA256
 * Itérations : 600 000  (OWASP 2026)
 * Sel        : 16 octets (extrait du début du Base64 stocké)
 * Clé        : 32 octets
 * Stockage   : Base64( sel[16] || hash[32] ) → VARCHAR(64) en BDD
 */
class SecurePasswordHasher
{
    private const ITERATIONS = 600_000;
    private const SALT_SIZE  = 16;   // octets
    private const KEY_SIZE   = 32;   // octets
    private const ALGO       = 'sha256';

    /**
     * Vérifie qu'un mot de passe en clair correspond au hash Base64 stocké en BDD.
     * Comparaison en temps constant (protection timing-attack).
     */
    public static function verify(string $password, string $storedBase64): bool
    {
        if ($password === '' || $storedBase64 === '') {
            return false;
        }

        // 1. Décoder le Base64
        $combined = base64_decode($storedBase64, strict: true);
        if ($combined === false) {
            return false; // Base64 invalide
        }

        // 2. Vérification de longueur minimale
        if (strlen($combined) <= self::SALT_SIZE) {
            return false;
        }

        // 3. Extraire le sel (16 premiers octets) et le hash attendu (le reste)
        $salt         = substr($combined, 0, self::SALT_SIZE);
        $expectedHash = substr($combined, self::SALT_SIZE);

        // 4. Recalculer le hash avec les mêmes paramètres
        $computedHash = hash_pbkdf2(
            algo:       self::ALGO,
            password:   $password,
            salt:       $salt,
            iterations: self::ITERATIONS,
            length:     self::KEY_SIZE,
            binary:     true   // retourne des octets bruts, pas du hex
        );

        // 5. Comparaison en temps constant (équivalent de CryptographicOperations.FixedTimeEquals)
        return hash_equals($expectedHash, $computedHash);
    }

    /**
     * Hache un mot de passe en clair (pour créer / modifier un compte).
     * Génère un sel aléatoire cryptographiquement sûr.
     */
    public static function hash(string $password): string
    {
        if ($password === '') {
            throw new InvalidArgumentException('Le mot de passe ne peut pas être vide.');
        }

        $salt = random_bytes(self::SALT_SIZE); // cryptographiquement sûr (CSPRNG)

        $hash = hash_pbkdf2(
            algo:       self::ALGO,
            password:   $password,
            salt:       $salt,
            iterations: self::ITERATIONS,
            length:     self::KEY_SIZE,
            binary:     true
        );

        return base64_encode($salt . $hash);
    }
}
