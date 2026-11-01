<?php

namespace App\Libraries;

/**
 * Anti brute-force générique : compteurs d'horodatages par clé arbitraire (IP, Id_Utilisateur,
 * login saisi…) dans un fichier JSON partagé (verrou flock) — pas $_SESSION : un tiers qui tente
 * de deviner un mot de passe peut simplement ignorer le cookie de session à chaque essai et
 * repartir avec un compteur à zéro.
 *
 * Les fonctions globales de config/app_config.php (checkTentativesRateLimit(),
 * enregistrerTentative(), tentativesLire()) délèguent ici en fournissant tentativesFichier().
 * Les clés et seuils métier (login:<ip>, login_compte:<login>, mfa_essai:<id>…) restent dans
 * app_config.php.
 */
class Tentatives
{
    /**
     * Durée de conservation (minutes) des horodatages du fichier, toutes clés confondues : au moins
     * la plus longue fenêtre utilisée (plafond journalier mfa_essai:<id>). Avant, la purge utilisait
     * la fenêtre de la clé en cours d'écriture (15 min), ce qui effaçait les compteurs à fenêtre plus
     * longue (login_compte 60 min) à chaque échec par IP.
     */
    public const RETENTION_MINUTES = 1440;

    /**
     * Retourne null si la tentative est autorisée, ou un message d'erreur sinon (au moins $max
     * tentatives de la clé dans les $fenetreMinutes dernières minutes). $now : instant de référence
     * (défaut time()), pour les tests.
     */
    public static function verifier(string $fichier, string $cle, int $max, int $fenetreMinutes, ?int $now = null): ?string
    {
        $now   = $now ?? time();
        $debut = $now - $fenetreMinutes * 60;

        $tentatives = array_filter(self::lire($fichier)[$cle] ?? [], fn (int $ts) => $ts > $debut);
        if (count($tentatives) >= $max) {
            $attente = (int) ceil((min($tentatives) + $fenetreMinutes * 60 - $now) / 60);
            return "Trop de tentatives. Réessayez dans environ {$attente} minute(s).";
        }
        return null;
    }

    /** Enregistre une tentative pour la clé donnée (purge au passage les horodatages expirés de toutes les clés). */
    public static function enregistrer(string $fichier, string $cle, int $fenetreMinutes, ?int $now = null): void
    {
        $now   = $now ?? time();
        $debut = $now - max($fenetreMinutes, self::RETENTION_MINUTES) * 60;

        $fp = fopen($fichier, 'c+');
        if (!$fp) return;

        flock($fp, LOCK_EX);
        $data = json_decode(stream_get_contents($fp) ?: '', true) ?: [];

        // Purge des horodatages expirés (toutes clés) pour éviter que le fichier ne grossisse indéfiniment.
        foreach ($data as $k => $timestamps) {
            $data[$k] = array_values(array_filter($timestamps, fn (int $ts) => $ts > $debut));
            if (!$data[$k]) unset($data[$k]);
        }
        $data[$cle][] = $now;

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** Lecture brute du fichier de tentatives (séparée pour rester appelable sans écrire). */
    public static function lire(string $fichier): array
    {
        if (!is_file($fichier)) return [];
        return json_decode(file_get_contents($fichier) ?: '', true) ?: [];
    }
}
