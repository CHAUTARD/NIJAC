<?php

namespace App\Controllers;

use App\Libraries\JustificatifPeage;
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
    protected \Obfuscator $obf;
    private \Obfuscator $obfSansPepper;

    /**
     * Code écran des traces error_log et de JustificatifPeage (EN21 ; 'EJA4' pour JaFraisController,
     * qui réutilise accuser(), sauvegarderFrais(), joindreJustificatif() et voirJustificatif() en
     * redéfinissant seulement nominationAutorisee() / messageLienInvalide() / loginSession() /
     * tracerActionConnecte()).
     */
    protected string $ecran = 'EN21';

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
        // Page publique : le lien jetonné fait foi. Ouverte depuis une session NIJAC
        // (ex. lien de la fenêtre d'envoi EN14), seul le bouton Retour est ajouté —
        // accusé et saisie des frais restent possibles, mêmes règles que pour le JA.
        $estConnecte = $this->loginSession() !== null;

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
                           -- Salle de la rencontre (r.id_Salle) si elle existe, sinon salle
                           -- principale du club recevant : tous les champs viennent de la
                           -- MÊME salle (règle EN14) ; CP/Ville via laposte, repli salle.Cp/Ville.
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN s_r.Nom ELSE s_c.Nom END                  AS NomSalle,
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN COALESCE(lp_r.CodePostal, s_r.Cp)
                                ELSE COALESCE(lp_c.CodePostal, s_c.Cp) END     AS CpSalle,
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN COALESCE(lp_r.Nom, s_r.Ville)
                                ELSE COALESCE(lp_c.Nom, s_c.Ville) END         AS VilleSalle,
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN s_r.Adresse ELSE s_c.Adresse END          AS AdresseSalle,
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN lp_r.Latitude ELSE lp_c.Latitude END      AS VenueLat,
                           CASE WHEN r.id_Salle IS NOT NULL AND s_r.Id_Salle IS NOT NULL
                                THEN lp_r.Longitude ELSE lp_c.Longitude END    AS VenueLon
                    FROM rencontre r
                    JOIN  equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
                    JOIN  division d    ON d.Division  = ed.Division
                    LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                    LEFT JOIN salle   s_r  ON s_r.Id_Salle  = r.id_Salle
                    LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
                    " . sqlJoinSallePrincipale('ed.Id_Club', 's_c') . "
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
                    'passee'    => $rencontre['Date'] && substr((string) $rencontre['Date'], 0, 10) < jourConvocation(),
                ];
            } catch (\PDOException $e) {
                error_log('[NIJAC] EN21 accusé : ' . $e->getMessage());
                $accuse = null;
            }
        }
        $etatFrais = $rencontre
            ? fraisSaisissables($rencontre['Date'], $accuse !== null && !$accuse['date'] && $accuse['accusable'])
            : ['ok' => false, 'err' => null, 'attendAccuse' => false];

        // Justificatif de péage (colonne nomination.FichierPeage, EA98) : null = fonction indisponible (colonne absente,
        // arbitrage club, erreur) → ni bouton ni popup. Seul le fait qu'un justificatif est joint (+ type/date lus dans
        // le nom généré par le serveur) est exposé, jamais le chemin ni le contenu.
        $justificatif = null;
        if ($ja && $rencontre && !$erreur && !$arbitrageClub && nominationAFichierPeage($pdo)) {
            try {
                $st = $pdo->prepare('SELECT FichierPeage FROM nomination WHERE Id_Nomination = ?');
                $st->execute([$idNomination]);
                $rowJ = $st->fetch();
                if ($rowJ) {
                    $justificatif = ['libelle' => JustificatifPeage::libelle($rowJ['FichierPeage'] !== null ? (string) $rowJ['FichierPeage'] : null)];
                }
            } catch (\PDOException $e) {
                error_log('[NIJAC] EN21 justificatif : ' . $e->getMessage());
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
            // Même règle que sauvegarderFrais() (fraisSaisissables()) : J >= D, et le jour même
            // accusé de réception requis s'il manque (convocation accusable mais pas encore accusée).
            'etatFrais'        => $etatFrais,
            'fraisBloques'     => !$etatFrais['ok'],
            'justificatif'     => $justificatif,
        ]);
    }

    /**
     * Login de l'utilisateur NIJAC connecté (Nominateur/Admin/CSR…) si la page est ouverte dans
     * une session, sinon null. N'autorise ni ne bloque rien (le lien jetonné fait foi) : sert au
     * bouton Retour et à la trace error_log des actions faites via le lien par un connecté.
     */
    protected function loginSession(): ?string
    {
        demarrerSessionNijac();
        $login = rolesSession() !== []
            ? (string) ($_SESSION['utilisateur']['login'] ?? '')
            : null;
        session_write_close();
        unset($_SESSION); // évite que CI4 redémarre son service Session (cf. DisponibiliteJaController)

        return $login;
    }

    /** Trace (sans donnée du JA) d'un accusé/saisie fait via le lien par un utilisateur connecté. */
    protected function tracerActionConnecte(?string $login, string $action, int $idNomination): void
    {
        if ($login !== null) {
            error_log("[NIJAC] EN21 : $action via lien par utilisateur connecté "
                . preg_replace('/[^\w.@-]/u', '_', $login) . " (nomination $idNomination)");
        }
    }

    /**
     * La requête vise-t-elle une nomination autorisée ? EN21 : jeton cnv (Obfuscator + pepper, repli
     * sans pepper) de cette nomination — le lien fait foi. Redéfinie par JaFraisController (EJA4) :
     * nomination appartenant au JA de la session, jeton ignoré.
     */
    protected function nominationAutorisee(int $idNomination, string $tokenCnv): bool
    {
        return $idNomination > 0 && $tokenCnv !== '' && (
            $this->obf->deobfuscate($tokenCnv) === $idNomination
            || $this->obfSansPepper->deobfuscate($tokenCnv) === $idNomination
        );
    }

    /** Message de refus quand nominationAutorisee() échoue. */
    protected function messageLienInvalide(): string
    {
        return "Lien de convocation invalide. Merci de redemander l'envoi de votre convocation.";
    }

    /**
     * État de la saisie des frais (EN21) d'une nomination, règles de dates centralisées dans
     * fraisSaisissables() (config/app_config.php) : refusée avant la date de la rencontre (J < D) ;
     * le jour même (J = D), conditionnée à l'accusé de réception s'il manque (colonne
     * nomination.AccuseReception présente (EA98), NULL, JA rattaché) ; après (J > D),
     * libre. Colonne absente avant EA98 → pas de condition d'accusé.
     * Retourne le tableau de fraisSaisissables() ['ok', 'err', 'attendAccuse'].
     */
    public static function etatSaisieFrais(\PDO $pdo, int $idNomination): array
    {
        $colonne = nominationAAccuseReception($pdo);
        $st = $pdo->prepare(
            'SELECT r.Date, '
            . ($colonne
                ? '(n.AccuseReception IS NULL AND d.Id_JA IS NOT NULL)'
                : '0') . ' AS AccuseManquant
             FROM nomination n
             LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
             JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
             WHERE n.Id_Nomination = ?'
        );
        $st->execute([$idNomination]);
        $row = $st->fetch();

        return fraisSaisissables($row ? (string) $row['Date'] : null, $row && (int) $row['AccuseManquant']);
    }

    /**
     * Accusé de réception de la convocation par le JA (POST convocation-ja/accuse).
     * Jeton cnv vérifié comme sauvegarderFrais() ; seule la nomination du lien est accusable
     * (`cible` vide ou égale à son id, sinon « Action non disponible »). Règles (nomination
     * du lien existante, rencontre du jour ou à venir, idempotence) : accuserReceptionConvocation() (config/app_config.php). Aucun email.
     * Le lien fait foi : accepté aussi depuis une session NIJAC (tracé dans error_log).
     */
    public function accuser(): ResponseInterface
    {
        $login = $this->loginSession();
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim((string) ($this->request->getPost('cnv') ?? ''));
            $cible    = trim((string) ($this->request->getPost('cible') ?? ''));
            if (!$this->nominationAutorisee($idNomP, $tokenCnv)) {
                return $this->response->setJSON(['ok' => false, 'err' => $this->messageLienInvalide()]);
            }
            if ($cible !== '' && $cible !== (string) $idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Action non disponible.']);
            }
            if (!nominationAAccuseReception($pdo)) {
                return $this->response->setJSON(['ok' => false, 'err' => "L'accusé de réception n'est pas encore disponible. Merci de réessayer plus tard."]);
            }

            $res = accuserReceptionConvocation($pdo, $idNomP, $cible);
            if ($res['ok']) {
                $this->tracerActionConnecte($login, 'accusé', $idNomP);
            }

            return $this->response->setJSON($res);
        } catch (\PDOException $e) {
            error_log('[NIJAC] ' . $this->ecran . ' accuser : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, "Erreur technique : l'accusé de réception n'a pas pu être enregistré.")]);
        }
    }

    // Frais saisis via le lien (JA, ou tout détenteur du lien — le lien fait foi, session tracée) ;
    // le nominateur peut aussi les corriger via EN28 (route distincte, non concernée).
    public function sauvegarderFrais(): ResponseInterface
    {
        $login = $this->loginSession();
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim($this->request->getPost('cnv') ?? '');
            if (!$idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètre id_nomination manquant.']);
            }
            if (!$this->nominationAutorisee($idNomP, $tokenCnv)) {
                return $this->response->setJSON(['ok' => false, 'err' => $this->messageLienInvalide()]);
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
            // Règles de dates (J >= D) + accusé requis le jour même : fraisSaisissables().
            $etatFrais = self::etatSaisieFrais($pdo, $idNomP);
            if (!$etatFrais['ok']) {
                return $this->response->setJSON(['ok' => false, 'err' => $etatFrais['err']]);
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
            $this->tracerActionConnecte($login, 'saisie', $idNomP);

            // Péage > 0 enregistré : la page propose de joindre le justificatif (popup), si la colonne existe (EA98).
            return $this->response->setJSON([
                'ok'           => true,
                'affected'     => $stmt->rowCount(),
                'justificatif' => $peages !== null && $peages > 0 && nominationAFichierPeage($pdo),
            ]);
        } catch (\PDOException $e) {
            error_log('[NIJAC] ' . $this->ecran . ' sauvegarderFrais : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, "Erreur technique : les frais n'ont pas pu être enregistrés.")]);
        }
    }

    /**
     * Justificatif de péage joint par le JA via le lien (POST convocation-ja/justificatif, multipart : id_nomination,
     * cnv, fichier `justificatif`). Mêmes contrôles que sauvegarderFrais() : jeton cnv (Obfuscator + pepper) de la
     * nomination du lien (aucun autre Id accepté, nomination existante), saisie des frais ouverte (fraisSaisissables(), J >= D),
     * plus Peage > 0 enregistré. Stockage / contrôle (5 Mo, finfo, liste blanche, nom serveur) : JustificatifPeage,
     * partagé avec EN28. Remplace le justificatif existant (l'ancien fichier n'est supprimé qu'après l'UPDATE réussi,
     * motif strict + liste serveur) ; aucune suppression par le JA (consultation : voirJustificatif()). Colonne FichierPeage absente
     * (pré-EA98) : refus sans erreur technique. Le lien fait foi : accepté aussi depuis une session NIJAC (tracé).
     */
    public function joindreJustificatif(): ResponseInterface
    {
        $login = $this->loginSession();
        $nouveau = null;
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim((string) ($this->request->getPost('cnv') ?? ''));
            if (!$this->nominationAutorisee($idNomP, $tokenCnv)) {
                return $this->refusJustificatif($this->messageLienInvalide());
            }
            if (!nominationAFichierPeage($pdo)) {
                return $this->refusJustificatif("Le dépôt du justificatif n'est pas encore disponible. Merci de réessayer plus tard.");
            }

            $st = $pdo->prepare('
                SELECT n.Peage, n.FichierPeage, r.ArbitrageCRA
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre  r ON r.Id_Rencontre  = n.Id_Rencontre
                WHERE n.Id_Nomination = ?
            ');
            $st->execute([$idNomP]);
            $row = $st->fetch();
            if (!$row) {
                return $this->refusJustificatif('Nomination introuvable.');
            }
            if (!(int) $row['ArbitrageCRA']) {
                return $this->refusJustificatif('Action non disponible.');
            }
            $etatFrais = self::etatSaisieFrais($pdo, $idNomP);
            if (!$etatFrais['ok']) {
                return $this->refusJustificatif((string) $etatFrais['err']);
            }
            if ((float) $row['Peage'] <= 0) {
                return $this->refusJustificatif('Enregistrez d\'abord un montant de péage avant de joindre le justificatif.');
            }

            $upload = $this->request->getFile('justificatif');
            if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                return $this->refusJustificatif('Choisissez le fichier du justificatif (image ou PDF).');
            }
            $refus = JustificatifPeage::controler($upload);
            if ($refus !== null) {
                return $this->refusJustificatif($refus);
            }
            try {
                $nouveau = JustificatifPeage::enregistrer($upload, $this->ecran);
            } catch (\RuntimeException $e) {
                return $this->refusJustificatif($e->getMessage());
            }

            $ancien = $row['FichierPeage'] !== null ? (string) $row['FichierPeage'] : null;
            $pdo->prepare('UPDATE nomination SET FichierPeage = ? WHERE Id_Nomination = ?')
                ->execute([$nouveau, $idNomP]);
            $enregistre = $nouveau;
            $nouveau = null;   // fichier désormais référencé : plus supprimé en cas d'erreur ci-dessous

            // Ancien justificatif remplacé : supprimé seulement maintenant (motif strict + liste serveur).
            if ($ancien !== null && $ancien !== $enregistre) {
                JustificatifPeage::supprimer($ancien, $this->ecran);
            }
            $this->tracerActionConnecte($login, 'justificatif péage', $idNomP);

            return $this->response->setJSON([
                'ok'      => true,
                'msg'     => 'Justificatif de péage enregistré. Merci !',
                'libelle' => JustificatifPeage::libelle($enregistre),
            ]);
        } catch (\Throwable $e) {
            JustificatifPeage::supprimer($nouveau, $this->ecran);   // fichier stocké mais nomination non mise à jour
            error_log('[NIJAC] ' . $this->ecran . ' joindreJustificatif : ' . $e->getMessage());
            $msg = messageErreur($e, "Erreur technique : le justificatif n'a pas pu être enregistré.");

            return $this->response->setJSON(['ok' => false, 'msg' => $msg, 'err' => $msg]);
        }
    }

    /**
     * Consultation par le JA du justificatif de péage qu'il a déposé (GET convocation-ja/justificatif/voir?id_nomination=…&cnv=…).
     * Mêmes contrôles que joindreJustificatif() : jeton cnv (Obfuscator + pepper) de la nomination du lien uniquement,
     * nomination existante, colonne FichierPeage présente (EA98). Le nom du fichier vient UNIQUEMENT de nomination.FichierPeage de
     * cette nomination (jamais du client), validé par JustificatifPeage::MOTIF_NOM puis retrouvé dans
     * JustificatifPeage::fichiers() (liste serveur). Réponse inline (JustificatifPeage::reponse(), partagée avec EN28) sous
     * un nom neutre (le nom stocké n'est pas exposé) ; sinon 404 texte neutre.
     */
    public function voirJustificatif(): ResponseInterface
    {
        $nom   = null;
        $cible = null;
        try {
            $idNomP   = (int) ($this->request->getGet('id_nomination') ?? 0);
            $tokenCnv = trim((string) ($this->request->getGet('cnv') ?? ''));
            $tokenValide = $this->nominationAutorisee($idNomP, $tokenCnv);
            $pdo = getPDO();
            if ($tokenValide && nominationAFichierPeage($pdo)) {
                $st = $pdo->prepare('SELECT FichierPeage FROM nomination WHERE Id_Nomination = ?');
                $st->execute([$idNomP]);
                $val = $st->fetchColumn();
                if ($val !== false && $val !== null && preg_match(JustificatifPeage::MOTIF_NOM, (string) $val)) {
                    $nom   = (string) $val;
                    $cible = JustificatifPeage::fichiers()[$nom] ?? null;
                    if ($cible === null) {
                        error_log('[NIJAC] ' . $this->ecran . ' voirJustificatif : ' . $nom . ' absent de la liste serveur de _Peage/ (dossier : '
                            . (JustificatifPeage::dossier() ?? 'inaccessible') . ", nomination $idNomP)");
                    }
                } elseif ($val !== false && $val !== null) {
                    error_log("[NIJAC] {$this->ecran} voirJustificatif : nom stocké invalide (nomination $idNomP)");
                }
            }
        } catch (\Throwable $e) {
            error_log('[NIJAC] ' . $this->ecran . ' voirJustificatif : ' . $e->getMessage());
        }
        $ext = $nom !== null ? strtolower(pathinfo($nom, PATHINFO_EXTENSION)) : '';

        return JustificatifPeage::reponse($this->response, $nom, $cible, $this->ecran . ' voirJustificatif', 'justificatif-peage.' . $ext);
    }

    /** Refus (message affichable) de joindreJustificatif() : `msg` + `err` (clé lue par le reste de la page EN21). */
    private function refusJustificatif(string $msg): ResponseInterface
    {
        return $this->response->setJSON(['ok' => false, 'msg' => $msg, 'err' => $msg]);
    }
}
