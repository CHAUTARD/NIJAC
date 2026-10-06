<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Convocation et frais JA (EN21), portage CI4 de
 * Nominateur/convocation_ja.php.
 *
 * Écran non documenté dans ECRANS.md/SPECIFICATION.md avant ce portage — code
 * EN21 attribué dans la bande nominateur/public (EN11-EN30).
 *
 * Page PUBLIQUE (sans authentification). URL jetonnée par un token `cnv`
 * (Obfuscator de l'Id_Nomination), servie en segments de chemin
 * `convocation-ja/<Id_Nomination>/<tokenCnv>` — forme sans `?`/`=`/`&`, qui se
 * faisaient tronquer dans les emails en texte brut (quoted-printable + auto-lien
 * des webmails) : le lien arrivait alors sans le paramètre `nomination`.
 * L'ancienne forme `?nomination=<id>&cnv=<token>` reste acceptée pour les
 * convocations déjà envoyées. Générée depuis EN14 (NominationController) et EN15
 * (CentrenvoyeController), consultée/imprimée par le JA.
 */
class ConvocationJaController extends BaseController
{
    private \Obfuscator $obf;
    private \Obfuscator $obfSansPepper;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
        // ponytail: repli temporaire pour les jetons envoyés avant l'activation
        // d'OBFUSCATOR_PEPPER en prod — à retirer une fois ces convocations renvoyées/expirées.
        $this->obfSansPepper = new \Obfuscator(OBFUSCATOR_SEED);
    }

    /**
     * @param string|null $nomSeg Id_Nomination passé en segment d'URL (forme chemin
     *                            .../convocation-ja/<id>/<token>), sinon ?nomination=
     * @param string|null $cnvSeg Token cnv passé en segment d'URL, sinon ?cnv=
     */
    public function index($nomSeg = null, $cnvSeg = null)
    {
        // Page publique (JA via lien email) mais aussi ouverte depuis la session
        // d'un nominateur/admin (ex. lien de la fenêtre d'envoi EN14) : bouton Retour +
        // mode aperçu (actions du JA masquées, refusées côté serveur).
        $estConnecte = $this->sessionUtilisateur();

        $pdo          = getPDO();
        $idNomination = (int) ($nomSeg ?? $this->request->getGet('nomination') ?? 0);
        $tokenCnv     = trim((string) ($cnvSeg ?? $this->request->getGet('cnv') ?? ''));
        $idJa         = 0;
        $idRencontre  = 0;
        $erreur       = '';

        // Le lien est jetonné (comme adresse-ja/disponibilite-ja) depuis l'ajout
        // du token ?cnv= : un ?nomination=N seul (anciens liens déjà envoyés par
        // email avant ce changement) n'est plus suffisant pour consulter/saisir
        // les frais d'une convocation.
        $tokenValide = $idNomination > 0 && $tokenCnv !== '' && (
            $this->obf->deobfuscate($tokenCnv) === $idNomination
            || $this->obfSansPepper->deobfuscate($tokenCnv) === $idNomination
        );

        if ($tokenValide) {
            // Résolution en étapes pour un diagnostic précis quand ça échoue :
            // nomination absente / Id_Disponible cassé / rencontre absente.
            $nom = $pdo->prepare('SELECT Id_Disponible, Id_Rencontre FROM nomination WHERE Id_Nomination = ?');
            $nom->execute([$idNomination]);
            $nom = $nom->fetch();

            if (!$nom) {
                $erreur = "Convocation n° $idNomination inconnue en base — le lien est probablement obsolète "
                        . "(données de saison réinitialisées depuis l'envoi). Demandez au responsable des "
                        . "désignations de vous renvoyer votre convocation.";
            } else {
                $idRencontre = (int) ($nom['Id_Rencontre'] ?? 0);
                if (!empty($nom['Id_Disponible'])) {
                    $d = $pdo->prepare('SELECT Id_JA FROM disponible WHERE Id_Disponible = ?');
                    $d->execute([$nom['Id_Disponible']]);
                    $idJa = (int) $d->fetchColumn();
                }
                if (!$idJa || !$idRencontre) {
                    $erreur = "Convocation n° $idNomination incomplète en base : "
                            . (!$idJa
                                ? "le juge-arbitre rattaché n'est plus retrouvable (la disponibilité liée a été supprimée). "
                                : "la rencontre associée est absente. ")
                            . "Le responsable des désignations doit refaire la nomination puis renvoyer la convocation.";
                }
            }
        }

        $ja            = null;
        $rencontre     = null;
        $correspondant = null;
        $frais         = null;

        if ($idJa && $idRencontre) {
            try {
                $stmtJa = $pdo->prepare('
                    SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                           ja.PuissanceFiscale, ja.VehiculeElectrique, ja.Defiscalisation,
                           cl.Nom AS Association,
                           lp.CodePostal AS Cp,
                           lp.Nom        AS Ville,
                           ja.Id_LaPoste,
                           lp.Latitude  AS JaLat,
                           lp.Longitude AS JaLon
                    FROM ja
                    LEFT JOIN Club     cl ON cl.Id_Club      = ja.Id_Club
                    LEFT JOIN laposte  lp ON lp.Id_LaPoste   = ja.Id_LaPoste
                    WHERE ja.Id_JA = ?
                ');
                $stmtJa->execute([$idJa]);
                $ja = $stmtJa->fetch();

                $stmtR = $pdo->prepare("
                    SELECT r.Id_Rencontre, r.Journee, r.Date, r.Heure, r.Poule,
                           r.Phase, r.ArbitrageCRA,
                           d.Division AS DivisionCode, d.Nom AS DivisionNom,
                           ed.Nom     AS NomDom,  ed.Id_Club AS IdClubDom,
                           ee.Nom     AS NomExt,
                           COALESCE(s_r.Nom,  s_c.Nom)                        AS NomSalle,
                           COALESCE(lp_r.CodePostal, lp_c.CodePostal)         AS CpSalle,
                           COALESCE(lp_r.Nom, lp_c.Nom)                       AS VilleSalle,
                           COALESCE(s_r.Adresse, s_c.Adresse)                 AS AdresseSalle,
                           COALESCE(lp_r.Latitude,  lp_c.Latitude)            AS VenueLat,
                           COALESCE(lp_r.Longitude, lp_c.Longitude)           AS VenueLon
                    FROM rencontre r
                    JOIN  equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
                    JOIN  division d    ON d.Division  = ed.Division
                    LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                    LEFT JOIN salle   s_r  ON s_r.Id_Salle  = r.id_Salle
                    LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
                    LEFT JOIN salle   s_c  ON s_c.Id_Club = ed.Id_Club AND s_c.EstPrincipale = 1
                    LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
                    WHERE r.Id_Rencontre = ?
                ");
                $stmtR->execute([$idRencontre]);
                $rencontre = $stmtR->fetch();

                if (!$rencontre) {
                    $erreur = "Rencontre #$idRencontre introuvable.";
                }

                if ($rencontre) {
                    $stmtC = $pdo->prepare(
                        'SELECT CorNom AS Nom, CorEmail AS Email, CorTelephone AS Telephone
                         FROM Club WHERE Id_Club = ? AND CorNom IS NOT NULL LIMIT 1'
                    );
                    $stmtC->execute([$rencontre['IdClubDom']]);
                    $correspondant = $stmtC->fetch() ?: null;
                }

                try {
                    $stmtF = $pdo->prepare('
                        SELECT Peage, Kilometre, RapportAccueil, RapportEquipements, Defiscalisation, DateSaisie
                        FROM nomination WHERE Id_Nomination = ?
                    ');
                    $stmtF->execute([$idNomination]);
                    $frais = $stmtF->fetch();
                } catch (\PDOException $ignored) {
                }
            } catch (\PDOException $e) {
                error_log('[NIJAC] EN21 : ' . $e->getMessage());
                $erreur = messageErreur($e, 'Erreur technique : impossible de charger la convocation pour le moment.');
            }
        } elseif (!$idNomination) {
            $erreur = 'Paramètre nomination manquant.';
        } elseif (!$tokenValide) {
            $erreur = 'Lien de convocation invalide. Merci de redemander l\'envoi de votre convocation.';
        }
        // Jeton valide mais données incomplètes : $erreur a déjà été renseigné
        // précisément dans le bloc de résolution ci-dessus.

        // Arbitrage Club : ni indemnité, ni péage, ni km (seul le rapport JA reste à saisir).
        $arbitrageClub    = $rencontre && !(int) $rencontre['ArbitrageCRA'];
        $indemniteForfait = $arbitrageClub ? 0.0 : (float) getConfig('indemnite_forfaitaire', '25.00');
        $tauxKm           = (float) getConfig('frais_kilometrique', '0.30');
        $peages           = $arbitrageClub ? 0 : ($frais['Peage'] ?? 0);
        $km               = $arbitrageClub ? 0 : ($frais['Kilometre'] ?? 0); // 0 par défaut (départ du domicile)
        $total            = $indemniteForfait + $peages + ($km * $tauxKm);

        // Tarif défiscalisation en €/km pour ce JA (barème fiscal selon sa
        // puissance fiscale + majoration véhicule électrique). null si la
        // puissance fiscale n'est pas renseignée. Le km d'une convocation reste
        // toujours dans la 1re tranche du barème (≤ 5000 km) : on récupère le
        // tarif unitaire via une sonde à 1000 km pour éviter l'arrondi à 2 déc.
        $defiscEuroParKm = null;
        if ($ja && ($ja['PuissanceFiscale'] ?? null) !== null) {
            $m = montantBaremeKilometrique(
                chargerBaremeKilometrique($pdo),
                (int) $ja['PuissanceFiscale'],
                1000,
                (bool) $ja['VehiculeElectrique']
            );
            $defiscEuroParKm = $m !== null ? $m / 1000 : null;
        }

        // Case cochée : choix déjà saisi sur la nomination, sinon valeur par défaut de la fiche JA.
        $defiscCoche = ($frais && $frais['DateSaisie'] !== null)
            ? !empty($frais['Defiscalisation'])
            : !empty($ja['Defiscalisation']);

        $dateFormatee = '';
        if ($rencontre && $rencontre['Date']) {
            $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            $d = new \DateTime($rencontre['Date']);
            $dateFormatee = $jours[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . $mois[(int) $d->format('n')] . ' ' . $d->format('Y');
        }
        $heure = $rencontre ? substr($rencontre['Heure'] ?? '09:00', 0, 5) : '';

        // Accusé de réception (colonne nomination.AccuseReception, EA98) : bloc masqué
        // tant que la colonne n'existe pas ou en cas d'erreur.
        $accuse = null;
        if ($ja && $rencontre && !$erreur && nominationAAccuseReception($pdo)) {
            try {
                $st = $pdo->prepare('SELECT AccuseReception FROM nomination WHERE Id_Nomination = ?');
                $st->execute([$idNomination]);
                $dt = $st->fetchColumn();
                $accuse = [
                    'date'      => $dt ? date('d/m/Y H:i', strtotime($dt)) : null,
                    'accusable' => convocationAccusable($pdo, $idNomination),
                    'passee'    => $rencontre['Date'] && $rencontre['Date'] < date('Y-m-d'),
                ];
            } catch (\PDOException $e) {
                error_log('[NIJAC] EN21 accusé : ' . $e->getMessage());
                $accuse = null;
            }
        }

        return view('convocation_ja_index', [
            'idNomination'     => $idNomination,
            'tokenCnv'         => $tokenValide ? $tokenCnv : '',
            'idJa'             => $idJa,
            'idRencontre'      => $idRencontre,
            'ja'               => $ja,
            'rencontre'        => $rencontre,
            'correspondant'    => $correspondant,
            'frais'            => $frais,
            'erreur'           => $erreur,
            'estConnecte'      => $estConnecte,
            'indemniteForfait' => $indemniteForfait,
            'tauxKm'           => $tauxKm,
            'peages'           => $peages,
            'km'               => $km,
            'total'            => $total,
            'arbitrageClub'    => $arbitrageClub,
            'defiscEuroParKm'  => $defiscEuroParKm,
            'defiscCoche'      => $defiscCoche,
            'dateFormatee'     => $dateFormatee,
            'heure'            => $heure,
            'accuse'           => $accuse,
            // Même règle que fraisBloquesSansAccuse() : convocation accusable mais pas encore accusée.
            'fraisBloques'     => $accuse !== null && !$accuse['date'] && $accuse['accusable'],
        ]);
    }

    /**
     * Vrai si la page est ouverte dans une session NIJAC (Nominateur/Admin/CSR…) : un JA ne
     * se connecte jamais, donc toute session = aperçu nominateur, sans action réservée au JA.
     */
    private function sessionUtilisateur(): bool
    {
        demarrerSessionNijac();
        $connecte = !empty($_SESSION['utilisateur']['role'] ?? null);
        session_write_close();
        unset($_SESSION); // évite que CI4 redémarre son service Session (cf. DisponibiliteJaController)

        return $connecte;
    }

    private const ERR_APERCU = 'Aperçu nominateur : action réservée au JA.';

    /**
     * Saisie des frais bloquée tant que le JA n'a pas accusé réception (EN21) : vrai si la colonne
     * nomination.AccuseReception existe (EA98), vaut NULL et que la convocation est encore accusable
     * (Valide = 1, rencontre du jour ou à venir — mêmes critères que convocationAccusable()).
     * Exceptions volontaires : rencontre passée sans accusé (le JA doit pouvoir saisir ses frais réels)
     * et colonne absente avant EA98 → saisie autorisée.
     */
    public static function fraisBloquesSansAccuse(\PDO $pdo, int $idNomination, bool $colonneExiste): bool
    {
        if (!$colonneExiste) {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT 1
             FROM nomination n
             JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
             JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
             WHERE n.Id_Nomination = ? AND n.AccuseReception IS NULL
               AND d.Id_JA IS NOT NULL AND n.Valide = 1 AND r.Date >= CURDATE()'
        );
        $st->execute([$idNomination]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Accusé de réception de la convocation par le JA (POST convocation-ja/accuse).
     * Jeton cnv vérifié comme sauvegarderFrais() ; seule la nomination du lien est accusable
     * (`cible` vide ou égale à son id, sinon « Action non disponible »). Règles (Valide = 1,
     * rencontre du jour ou à venir, idempotence) : accuserReceptionConvocation() (config/app_config.php). Aucun email.
     */
    public function accuser(): ResponseInterface
    {
        if ($this->sessionUtilisateur()) {
            return $this->response->setJSON(['ok' => false, 'err' => self::ERR_APERCU]);
        }
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim((string) ($this->request->getPost('cnv') ?? ''));
            $cible    = trim((string) ($this->request->getPost('cible') ?? ''));
            $tokenValide = $idNomP > 0 && $tokenCnv !== '' && (
                $this->obf->deobfuscate($tokenCnv) === $idNomP
                || $this->obfSansPepper->deobfuscate($tokenCnv) === $idNomP
            );
            if (!$tokenValide) {
                return $this->response->setJSON(['ok' => false, 'err' => "Lien de convocation invalide. Merci de redemander l'envoi de votre convocation."]);
            }
            if ($cible !== '' && $cible !== (string) $idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Action non disponible.']);
            }
            if (!nominationAAccuseReception($pdo)) {
                return $this->response->setJSON(['ok' => false, 'err' => "L'accusé de réception n'est pas encore disponible. Merci de réessayer plus tard."]);
            }

            return $this->response->setJSON(accuserReceptionConvocation($pdo, $idNomP, $cible));
        } catch (\PDOException $e) {
            error_log('[NIJAC] EN21 accuser : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, "Erreur technique : l'accusé de réception n'a pas pu être enregistré.")]);
        }
    }

    // Frais saisis par le JA ; le nominateur les corrige via EN28 (route distincte, non concernée).
    public function sauvegarderFrais(): ResponseInterface
    {
        if ($this->sessionUtilisateur()) {
            return $this->response->setJSON(['ok' => false, 'err' => self::ERR_APERCU]);
        }
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim($this->request->getPost('cnv') ?? '');
            if (!$idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètre id_nomination manquant.']);
            }
            $tokenValide = $tokenCnv !== '' && (
                $this->obf->deobfuscate($tokenCnv) === $idNomP
                || $this->obfSansPepper->deobfuscate($tokenCnv) === $idNomP
            );
            if (!$tokenValide) {
                return $this->response->setJSON(['ok' => false, 'err' => "Lien de convocation invalide. Merci de redemander l'envoi de votre convocation."]);
            }
            $rowNom = $pdo->prepare('
                SELECT d.Id_JA, n.Id_Rencontre, r.ArbitrageCRA
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
                WHERE n.Id_Nomination = ?
            ');
            $rowNom->execute([$idNomP]);
            $rowNom = $rowNom->fetch();
            if (!$rowNom) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Nomination introuvable.']);
            }
            if (self::fraisBloquesSansAccuse($pdo, $idNomP, nominationAAccuseReception($pdo))) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Accusez réception de la convocation avant de saisir vos frais.']);
            }

            $peagesRaw = trim($this->request->getPost('peages') ?? '');
            $kmRaw     = trim($this->request->getPost('km') ?? '');
            $rapAcc    = trim($this->request->getPost('rapport_accueil') ?? '');
            $rapEq     = trim($this->request->getPost('rapport_equipements') ?? '');
            $defisc    = $this->request->getPost('defiscalisation') ? 1 : 0;

            $peages = $peagesRaw !== '' ? (float) str_replace(',', '.', $peagesRaw) : null;
            $km     = $kmRaw !== '' ? (int) $kmRaw : null;

            // Arbitrage Club : aucun frais remboursable, on force 0 quoi que le client envoie.
            if (!(int) $rowNom['ArbitrageCRA']) {
                $peages = 0.0;
                $km     = 0;
                $peagesRaw = $kmRaw = '0';
                $defisc = 0;
            }

            $maxPeage = (float) getConfig('frais_max_peages', '80');
            $maxKm    = (int) getConfig('frais_max_km', '200');
            $erreurs  = [];

            if ($peages !== null) {
                if (!is_numeric(str_replace(',', '.', $peagesRaw))) {
                    $erreurs[] = "Le montant des péages n'est pas un nombre valide.";
                } elseif ($peages < 0) {
                    $erreurs[] = 'Le montant des péages ne peut pas être négatif.';
                } elseif ($peages > $maxPeage) {
                    $erreurs[] = "Le montant des péages semble aberrant (maximum {$maxPeage} €).";
                }
            }

            if ($km !== null) {
                if (!ctype_digit($kmRaw) && !(substr($kmRaw, 0, 1) === '-' && ctype_digit(substr($kmRaw, 1)))) {
                    $erreurs[] = "Le nombre de kilomètres n'est pas un entier valide.";
                } elseif ($km < 0) {
                    $erreurs[] = 'Le nombre de kilomètres ne peut pas être négatif.';
                } elseif ($km > $maxKm) {
                    $erreurs[] = "Le nombre de kilomètres semble aberrant (maximum {$maxKm} km).";
                }
            }

            if ($erreurs) {
                return $this->response->setJSON(['ok' => false, 'err' => implode(' ', $erreurs)]);
            }

            $params = [$peages, $km, $rapAcc ?: null, $rapEq ?: null, $defisc, $idNomP];

            $stmt = $pdo->prepare('
                UPDATE `nomination` SET
                    Peage              = ?,
                    Kilometre          = ?,
                    RapportAccueil     = ?,
                    RapportEquipements = ?,
                    Defiscalisation    = ?,
                    DateSaisie         = CURDATE()
                WHERE Id_Nomination = ?
            ');
            $stmt->execute($params);

            return $this->response->setJSON(['ok' => true, 'affected' => $stmt->rowCount()]);
        } catch (\PDOException $e) {
            error_log('[NIJAC] EN21 sauvegarderFrais : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, "Erreur technique : les frais n'ont pas pu être enregistrés.")]);
        }
    }
}
