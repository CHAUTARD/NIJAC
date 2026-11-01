<?php

namespace App\Libraries;

/**
 * Règles de mot de passe : robustesse, génération aléatoire, jeton de réinitialisation E007/E008.
 * Logique pure : aucun accès base ni session. Les fonctions globales de config/app_config.php
 * (validerRobustesseMotDePasse(), genererMotDePasseAleatoire(), genererJetonResetMdp()…) délèguent
 * ici en fournissant OBFUSCATOR_SEED et le pepper getObfuscatorPepper().
 */
class MotDePasse
{
    /**
     * Règle de robustesse du mot de passe (E008 « mot de passe oublié ») : 10 caractères
     * minimum, avec au moins une minuscule, une majuscule, un chiffre et un caractère
     * spécial. Retourne null si conforme, sinon le message d'erreur à afficher.
     */
    public static function verifierRobustesse(string $mdp): ?string
    {
        if (strlen($mdp) < 10)                     return 'Le mot de passe doit contenir au moins 10 caractères.';
        if (!preg_match('/[a-z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre minuscule.';
        if (!preg_match('/[A-Z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre majuscule.';
        if (!preg_match('/\d/', $mdp))             return 'Le mot de passe doit contenir au moins un chiffre.';
        if (!preg_match('/[^a-zA-Z0-9]/', $mdp))   return 'Le mot de passe doit contenir au moins un caractère spécial.';
        return null;
    }

    /**
     * Génère un mot de passe aléatoire conforme à verifierRobustesse()
     * (au moins un minuscule, un majuscule, un chiffre, un caractère spécial).
     * Caractères ambigus (l/1/I, O/0…) exclus pour la lisibilité. Utilisé par EA86
     * quand l'admin coche « Écraser » (mot de passe provisoire à communiquer).
     */
    public static function genererAleatoire(int $longueur = 12): string
    {
        $minu = 'abcdefghijkmnpqrstuvwxyz';
        $maju = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $chif = '23456789';
        $spec = '!@#$%*-_=+';
        $tous = $minu . $maju . $chif . $spec;

        $car = [
            $minu[random_int(0, strlen($minu) - 1)],
            $maju[random_int(0, strlen($maju) - 1)],
            $chif[random_int(0, strlen($chif) - 1)],
            $spec[random_int(0, strlen($spec) - 1)],
        ];
        for ($i = count($car); $i < max($longueur, 10); $i++) {
            $car[] = $tous[random_int(0, strlen($tous) - 1)];
        }
        for ($i = count($car) - 1; $i > 0; $i--) {           // mélange Fisher-Yates
            $j = random_int(0, $i);
            [$car[$i], $car[$j]] = [$car[$j], $car[$i]];
        }

        return implode('', $car);
    }

    /**
     * Jeton de réinitialisation de mot de passe SANS stockage BDD : la signature HMAC
     * est calculée avec le hash du mot de passe actuel comme clé, donc le jeton devient
     * automatiquement invalide dès que le mot de passe a été changé (usage unique).
     * Format : "<idUtilisateur>-<timestampExpiration>-<hmacSha256>" (tout URL-safe).
     * $maintenant : instant de référence (défaut : time()), pour les tests.
     */
    public static function genererJeton(int $idUtilisateur, string $hashMdpActuel, int $dureeSecondes, int $seed, string $pepper, ?int $maintenant = null): string
    {
        $exp = ($maintenant ?? time()) + $dureeSecondes;
        $sig = hash_hmac('sha256', $idUtilisateur . '.' . $exp, self::cleJeton($hashMdpActuel, $seed, $pepper));
        return $idUtilisateur . '-' . $exp . '-' . $sig;
    }

    /**
     * Clé HMAC du jeton E008 : hash du mot de passe courant + OBFUSCATOR_SEED (public, documenté)
     * + pepper secret getObfuscatorPepper() (.env, OBFUSCATOR_PEPPER). Le pepper rend le jeton
     * infalsifiable par qui lirait les hash en base (fuite de sauvegarde SQL…) sans le .env.
     * Pepper vide : la clé est EXACTEMENT l'ancienne (hash . seed), donc les liens déjà émis restent
     * valables, mais le gain est nul (seule protection = secret du hash). Pepper renseigné : seule la
     * nouvelle clé est acceptée — les liens émis avant sa mise en place (valables 1 h) sont perdus.
     */
    public static function cleJeton(string $hashMdpActuel, int $seed, string $pepper): string
    {
        return $hashMdpActuel . $seed . ($pepper !== '' ? '|reset-mdp|' . $pepper : '');
    }

    /**
     * Vérifie un jeton produit par genererJeton() : retourne l'Id_Utilisateur si
     * le jeton est bien formé, non expiré et signé avec le hash de mot de passe COURANT
     * de l'utilisateur, sinon null. $lookupHash(int $id): ?string doit rendre le hash
     * Password actuel (null si utilisateur inconnu/inactif).
     */
    public static function verifierJeton(string $jeton, callable $lookupHash, int $seed, string $pepper, ?int $maintenant = null): ?int
    {
        if (!preg_match('/^(\d+)-(\d+)-([0-9a-f]{64})$/', $jeton, $m)) {
            return null;
        }
        [, $id, $exp, $sig] = $m;
        if ((int) $exp < ($maintenant ?? time())) {
            return null;
        }
        $hash = $lookupHash((int) $id);
        if (!$hash) {
            return null;
        }
        $attendu = hash_hmac('sha256', $id . '.' . $exp, self::cleJeton($hash, $seed, $pepper));
        return hash_equals($attendu, $sig) ? (int) $id : null;
    }
}
