<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Convocation et frais JA (EN21), portage CI4 de
 * Nominateur/convocation_ja.php.
 *
 * Écran non documenté dans ECRANS.md/SPECIFICATION.md avant ce portage — code
 * EN21 attribué dans la bande nominateur/public (EN11-EN30), à la suite de
 * EN20 (JA/info_rencontre.php).
 *
 * Page PUBLIQUE (sans authentification, sans même de token obfusqué : l'URL
 * porte directement `?nomination=<Id_Nomination>` en clair) — comportement
 * identique au legacy, préservé tel quel. Générée depuis EN14 (NominationController,
 * envoi des convocations par email) et consultée/imprimée par le JA.
 */
class ConvocationJaController extends BaseController
{
    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);
    }

    public function index()
    {
        $pdo          = getPDO();
        $idNomination = (int) ($this->request->getGet('nomination') ?? 0);
        $tokenCnv     = trim($this->request->getGet('cnv') ?? '');
        $idJa         = 0;
        $idRencontre  = 0;

        // Le lien est jetonné (comme adresse-ja/disponibilite-ja) depuis l'ajout
        // du token ?cnv= : un ?nomination=N seul (anciens liens déjà envoyés par
        // email avant ce changement) n'est plus suffisant pour consulter/saisir
        // les frais d'une convocation.
        $tokenValide = $idNomination > 0 && $tokenCnv !== '' && $this->obf->deobfuscate($tokenCnv) === $idNomination;

        if ($tokenValide) {
            $row = $pdo->prepare('
                SELECT d.Id_JA, n.Id_Rencontre
                FROM nomination n JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                WHERE n.Id_Nomination = ?
            ');
            $row->execute([$idNomination]);
            $row = $row->fetch();
            if ($row) {
                $idJa        = (int) $row['Id_JA'];
                $idRencontre = (int) $row['Id_Rencontre'];
            }
        }

        $erreur        = '';
        $ja            = null;
        $rencontre     = null;
        $correspondant = null;
        $frais         = null;
        $kmCalc        = null;

        if ($idJa && $idRencontre) {
            try {
                $stmtJa = $pdo->prepare('
                    SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
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

                $rencCols = array_column($pdo->query('DESCRIBE rencontre')->fetchAll(), 'Field');
                $convNum  = in_array('NumConvocation', $rencCols) ? 'r.NumConvocation' : 'r.Id_Rencontre';
                $hasPhase = in_array('Phase', $rencCols);
                $phaseSel = $hasPhase ? 'r.Phase,' : 'NULL AS Phase,';

                $stmtR = $pdo->prepare("
                    SELECT r.Id_Rencontre, r.Journee, r.Date, r.Heure, r.Poule,
                           $convNum  AS NumConvocation,
                           $phaseSel
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
                        SELECT Peage, Kilometre, RapportAccueil, RapportEquipements
                        FROM nomination WHERE Id_Nomination = ?
                    ');
                    $stmtF->execute([$idNomination]);
                    $frais = $stmtF->fetch();
                } catch (\PDOException $ignored) {
                }

                if ($ja && $rencontre
                    && $ja['JaLat'] && $ja['JaLon']
                    && $rencontre['VenueLat'] && $rencontre['VenueLon']) {
                    $lat1 = deg2rad($ja['JaLat']);
                    $lon1 = deg2rad($ja['JaLon']);
                    $lat2 = deg2rad($rencontre['VenueLat']);
                    $lon2 = deg2rad($rencontre['VenueLon']);
                    $kmCalc = (int) round(6371 * acos(max(-1, min(1,
                        cos($lat1) * cos($lat2) * cos($lon2 - $lon1) + sin($lat1) * sin($lat2)
                    ))));
                }
            } catch (\PDOException $e) {
                $erreur = 'Erreur BDD : ' . $e->getMessage();
            }
        } elseif (!$idNomination) {
            $erreur = 'Paramètre nomination manquant.';
        } elseif (!$tokenValide) {
            $erreur = 'Lien de convocation invalide. Merci de redemander l\'envoi de votre convocation.';
        }

        $indemniteForfait = (float) getConfig('indemnite_forfaitaire', '25.00');
        $tauxKm           = (float) getConfig('frais_kilometrique', '0.30');
        $peages           = $frais['Peage'] ?? 0;
        $km               = $frais['Kilometre'] ?? $kmCalc ?? 0;
        $total            = $indemniteForfait + $peages + ($km * $tauxKm);

        $dateFormatee = '';
        if ($rencontre && $rencontre['Date']) {
            $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            $d = new \DateTime($rencontre['Date']);
            $dateFormatee = $jours[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . $mois[(int) $d->format('n')] . ' ' . $d->format('Y');
        }
        $heure = $rencontre ? substr($rencontre['Heure'] ?? '09:00', 0, 5) : '';

        return view('convocation_ja_index', [
            'idNomination'     => $idNomination,
            'tokenCnv'         => $tokenValide ? $tokenCnv : '',
            'idJa'             => $idJa,
            'ja'               => $ja,
            'rencontre'        => $rencontre,
            'correspondant'    => $correspondant,
            'frais'            => $frais,
            'erreur'           => $erreur,
            'indemniteForfait' => $indemniteForfait,
            'tauxKm'           => $tauxKm,
            'peages'           => $peages,
            'km'               => $km,
            'total'            => $total,
            'dateFormatee'     => $dateFormatee,
            'heure'            => $heure,
        ]);
    }

    public function sauvegarderFrais(): ResponseInterface
    {
        try {
            $pdo      = getPDO();
            $idNomP   = (int) ($this->request->getPost('id_nomination') ?? 0);
            $tokenCnv = trim($this->request->getPost('cnv') ?? '');
            if (!$idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètre id_nomination manquant.']);
            }
            if ($tokenCnv === '' || $this->obf->deobfuscate($tokenCnv) !== $idNomP) {
                return $this->response->setJSON(['ok' => false, 'err' => "Lien de convocation invalide. Merci de redemander l'envoi de votre convocation."]);
            }
            $rowNom = $pdo->prepare('
                SELECT d.Id_JA, n.Id_Rencontre
                FROM nomination n JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                WHERE n.Id_Nomination = ?
            ');
            $rowNom->execute([$idNomP]);
            $rowNom = $rowNom->fetch();
            if (!$rowNom) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Nomination introuvable.']);
            }

            $peagesRaw = trim($this->request->getPost('peages') ?? '');
            $kmRaw     = trim($this->request->getPost('km') ?? '');
            $rapAcc    = trim($this->request->getPost('rapport_accueil') ?? '');
            $rapEq     = trim($this->request->getPost('rapport_equipements') ?? '');

            $peages = $peagesRaw !== '' ? (float) str_replace(',', '.', $peagesRaw) : null;
            $km     = $kmRaw !== '' ? (int) $kmRaw : null;

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

            // Journal de debug conservé à l'identique du fichier legacy (fichier
            // .log local, jamais exposé ni lu par l'application elle-même).
            $params  = [$peages, $km, $rapAcc ?: null, $rapEq ?: null, $idNomP];
            $logFile = __DIR__ . '/../../../logs/convocation_debug.log';
            @mkdir(dirname($logFile), 0755, true);
            file_put_contents(
                $logFile,
                date('[Y-m-d H:i:s] ') .
                "UPDATE nomination SET Peage=$params[0], Kilometre=$params[1], " .
                'RapportAccueil=' . var_export($params[2], true) . ', ' .
                'RapportEquipements=' . var_export($params[3], true) . ', DateSaisie=CURDATE() ' .
                "WHERE Id_Nomination=$idNomP" . PHP_EOL,
                FILE_APPEND
            );

            $stmt = $pdo->prepare('
                UPDATE `nomination` SET
                    Peage              = ?,
                    Kilometre          = ?,
                    RapportAccueil     = ?,
                    RapportEquipements = ?,
                    DateSaisie         = CURDATE()
                WHERE Id_Nomination = ?
            ');
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            file_put_contents(
                $logFile,
                date('[Y-m-d H:i:s] ') . "=> Lignes modifiées : $affected" . PHP_EOL,
                FILE_APPEND
            );

            return $this->response->setJSON(['ok' => true, 'affected' => $affected]);
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }
}
