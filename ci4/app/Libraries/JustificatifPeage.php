<?php

namespace App\Libraries;

/**
 * NIJAC – Justificatif de péage d'une nomination (colonne nomination.FichierPeage, dossier _Peage/).
 *
 * Logique commune de stockage / validation / suppression partagée par EN28 (SuiviNominationController::modifier(),
 * lecture authentifiée peageFichier()) et EN21 (ConvocationJaController::joindreJustificatif() / voirJustificatif(),
 * dépôt et consultation via le lien jetonné du JA, nom lu en base uniquement). Le nom reçu d'un client n'est jamais utilisé comme chemin : seuls les
 * noms générés ici (PEAGE_MOTIF_NOM) et retrouvés dans fichiers() (liste serveur) sont lus ou supprimés.
 */
class JustificatifPeage
{
    /** Taille maximale d'un justificatif de péage. */
    public const TAILLE_MAX = 5 * 1024 * 1024;

    /** Nom accepté pour un justificatif : horodatage YmdHis + 3 chiffres de millisecondes + extension (liste blanche). */
    public const MOTIF_NOM = '/^\d{17}\.(jpg|jpeg|png|gif|webp|pdf)$/D';   // D : pas de « \n » final accepté

    /** Type MIME détecté (finfo) → extension enregistrée (jamais celle du client). */
    public const MIMES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];

    /** Extension → Content-Type servi par la lecture (EN28 peageFichier(), EN21 voirJustificatif(), via reponse()). */
    public const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    /**
     * Dossier _Peage/ (racine du projet, jamais servi en HTTP : .htaccess racine « RewriteRule ^_Peage/ - [F,L] »
     * + _Peage/.htaccess), chemin canonique (realpath) ; créé s'il manque. null si inaccessible.
     */
    public static function dossier(): ?string
    {
        $dir = __DIR__ . '/../../../_Peage';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $reel = realpath($dir);

        return $reel === false ? null : $reel;
    }

    /**
     * Justificatifs de _Peage/ (nom → chemin canonique). Chemins construits côté serveur uniquement : glob() sur un motif
     * fixe, nom contrôlé (MOTIF_NOM), fichier ordinaire (pas de lien symbolique) dont le realpath est directement
     * dans _Peage/.
     */
    public static function fichiers(): array
    {
        $dir = self::dossier();
        if ($dir === null) {
            return [];
        }
        $res = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.*') ?: [] as $f) {
            $nom  = basename($f);
            $reel = realpath($f);
            if (!preg_match(self::MOTIF_NOM, $nom) || is_link($f) || $reel === false || !is_file($reel)
                || dirname($reel) !== $dir) {
                continue;
            }
            $res[$nom] = $reel;
        }

        return $res;
    }

    /** Nom d'un justificatif : date('YmdHis') + millisecondes sur 3 chiffres (même instant microtime) + '.' + extension. */
    public static function nom(string $ext, ?float $instant = null): string
    {
        $t  = $instant ?? microtime(true);
        $s  = (int) floor($t);
        $ms = min(999, (int) floor(($t - $s) * 1000));

        return date('YmdHis', $s) . sprintf('%03d', $ms) . '.' . $ext;
    }

    /** Extension déduite du contenu réel du fichier (finfo), null si le type n'est pas dans la liste blanche. */
    public static function extension(string $chemin): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($chemin) ?: '';

        return self::MIMES[$mime] ?? null;
    }

    /** Contrôle d'un justificatif téléversé (UploadedFile CI4) : message de refus affichable, ou null si accepté. */
    public static function controler($file): ?string
    {
        if (!$file->isValid()) {
            return 'Justificatif de péage non reçu (téléversement interrompu ou trop volumineux).';
        }
        if ($file->getSize() <= 0 || $file->getSize() > self::TAILLE_MAX) {
            return 'Justificatif de péage trop volumineux (5 Mo maximum) ou vide.';
        }
        $ext = self::extension($file->getTempName());
        if ($ext === null) {
            return 'Justificatif de péage : seuls les formats image (JPEG, PNG, GIF, WebP) et PDF sont acceptés.';
        }
        // Image : contenu réellement décodable ; PDF : signature %PDF- en tête.
        if ($ext === 'pdf') {
            $h = @fopen($file->getTempName(), 'rb');
            $sig = $h ? fread($h, 5) : '';
            if ($h) {
                fclose($h);
            }
            if ($sig !== '%PDF-') {
                return 'Justificatif de péage : fichier PDF invalide.';
            }
        } elseif (@getimagesize($file->getTempName()) === false) {
            return 'Justificatif de péage : image illisible.';
        }

        return null;
    }

    /**
     * Enregistre un justificatif (déjà contrôlé) dans _Peage/ sous un nom généré par le serveur (jamais le nom ni
     * l'extension du client). Nom réservé par création exclusive (fopen 'x') : jamais d'écrasement d'un autre fichier.
     * Retourne le nom. RuntimeException (message affichable) en cas d'échec. $ecran : préfixe des traces (EN28, EN21).
     */
    public static function enregistrer($file, string $ecran = 'EN28'): string
    {
        $dir = self::dossier();
        if ($dir === null || !is_writable($dir)) {
            throw new \RuntimeException('Dossier _Peage inaccessible en écriture : justificatif non enregistré.');
        }
        $ext = self::extension($file->getTempName());
        if ($ext === null) {
            throw new \RuntimeException('Format de justificatif non accepté.');
        }
        for ($essai = 0; $essai < 5; $essai++) {
            $nom    = self::nom($ext);
            $chemin = $dir . DIRECTORY_SEPARATOR . $nom;
            $h = @fopen($chemin, 'x');   // réservation exclusive du nom
            if ($h === false) {
                usleep(2000);
                continue;
            }
            fclose($h);
            try {
                $file->move($dir, $nom, true);   // remplace la réservation vide (créée ci-dessus par cette requête)
            } catch (\Throwable $e) {
                error_log("[NIJAC] $ecran justificatif péage : " . get_class($e));
            }
            if (!$file->hasMoved()) {
                @unlink($chemin);
                throw new \RuntimeException('Enregistrement du justificatif impossible.');
            }
            @chmod($chemin, 0640);

            return $nom;
        }
        throw new \RuntimeException('Enregistrement du justificatif impossible : réessayez.');
    }

    /**
     * Supprime un justificatif de _Peage/ : seulement si le nom respecte MOTIF_NOM et est retrouvé dans
     * fichiers() (liste serveur), dont le chemin est supprimé. Échec journalisé, jamais bloquant.
     */
    public static function supprimer(?string $nom, string $ecran = 'EN28'): void
    {
        if ($nom === null || !preg_match(self::MOTIF_NOM, $nom)) {
            return;
        }
        $chemin = self::fichiers()[$nom] ?? null;
        if ($chemin !== null && !@unlink($chemin)) {
            error_log("[NIJAC] $ecran justificatif péage non supprimé : $nom");
        }
        clearstatcache();
    }

    /**
     * Réponse HTTP d'affichage (inline) d'un justificatif, partagée par EN28 (peageFichier()) et EN21
     * (ConvocationJaController::voirJustificatif()). $cible = chemin issu de fichiers() (jamais d'un client), null si
     * l'accès a déjà été refusé → 404 texte neutre. Lecture impossible journalisée. $nomAffiche : nom proposé dans
     * Content-Disposition (EN28 : nom stocké ; EN21 : nom neutre, le nom stocké n'est pas exposé).
     */
    public static function reponse(\CodeIgniter\HTTP\ResponseInterface $response, ?string $nom, ?string $cible,
        string $ecran, string $nomAffiche): \CodeIgniter\HTTP\ResponseInterface
    {
        $contenu = $cible !== null ? @file_get_contents($cible) : false;
        if ($contenu === false && $cible !== null) {
            error_log("[NIJAC] $ecran : lecture impossible de " . $cible . ' (droits ?)');
        }
        $ext = $nom !== null ? strtolower(pathinfo($nom, PATHINFO_EXTENSION)) : '';
        if ($contenu === false || !isset(self::TYPES[$ext])) {
            return $response->setStatusCode(404)
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContentType('text/plain', 'UTF-8')
                ->setBody('Justificatif introuvable.');
        }

        return $response
            ->setContentType(self::TYPES[$ext], '')   // binaire : pas de charset
            ->setHeader('Content-Disposition', 'inline; filename="' . $nomAffiche . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($contenu);
    }

    /**
     * Libellé court d'un justificatif pour l'affichage au JA (EN21) : « image » / « PDF » + date de dépôt lue dans le
     * nom (YmdHis), jamais le chemin ni le contenu. null si le nom ne respecte pas MOTIF_NOM.
     */
    public static function libelle(?string $nom): ?string
    {
        if ($nom === null || !preg_match(self::MOTIF_NOM, $nom)) {
            return null;
        }
        $d = \DateTime::createFromFormat('YmdHis', substr($nom, 0, 14));
        $type = str_ends_with($nom, '.pdf') ? 'PDF' : 'image';

        return $d ? "$type déposé le " . $d->format('d/m/Y à H:i') : $type;
    }
}
