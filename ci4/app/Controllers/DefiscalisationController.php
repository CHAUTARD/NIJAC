<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Défiscalisation JA (ED51), rôle Defiscalisateur.
 *
 * La défiscalisation s'applique sur l'année civile en cours (1er janvier -
 * 31 décembre), pas sur la saison/phase NIJAC — pas de sélecteur de période.
 * Liste tous les JA actifs ayant coché "Défiscalisation" (ja.Actif=1 AND
 * ja.Defiscalisation=1), y compris ceux sans mission cette année (LEFT JOIN
 * depuis `ja`), avec le cumul péages/kilomètres de leurs nominations de
 * l'année (nomination → disponible → ja). Export CSV pour la génération des
 * reçus. Accessible rôle Defiscalisateur + Administrateur (filtre
 * "defiscauth"). Pas de Model : agrégations ponctuelles, raw PDO comme
 * ComptaController.
 *
 * Barème kilométrique : la colonne "Frais défiscalisables (barème)" applique
 * le barème fiscal voiture (table ComptaDefiscalisation) au cumul annuel de km
 * du JA, selon sa puissance fiscale (ja.PuissanceFiscale) et sa motorisation
 * (ja.VehiculeElectrique → majoration +20 %). Les péages n'entrent pas dans ce
 * montant. Puissance et électrique se saisissent directement dans le tableau.
 */
class DefiscalisationController extends BaseController
{
    /** Valeurs de puissance fiscale proposées dans ED51 (7 = "7 CV et plus"). */
    private const CV_AUTORISES = [3, 4, 5, 6, 7];

    /** Modèle messagerie « Administratif » n°10 : relance véhicule non renseigné. */
    private const ID_MESSAGE_RELANCE_VEHICULE = 10;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
            'annee'       => (int) date('Y'),
            'cvOptions'   => self::CV_AUTORISES,
        ];

        return view('defiscalisation_index', $data);
    }

    /**
     * Barème kilométrique : les 5 tranches de puissance + le coefficient de
     * majoration électrique (clé de config comptadefisc_majoration_electrique,
     * en %). Retour : ['lignes' => [...], 'majoration' => 1.20].
     */
    private function chargerBareme(\PDO $pdo): array
    {
        $lignes = $pdo->query(
            'SELECT Cv_Min, Cv_Max, Coef_T1, Coef_T2, Fixe_T2, Coef_T3
             FROM ComptaDefiscalisation ORDER BY Cv_Min'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $pct = (float) getConfig('comptadefisc_majoration_electrique', '20');

        return ['lignes' => $lignes, 'majoration' => 1 + $pct / 100];
    }

    /**
     * Applique le barème au cumul annuel $km pour la puissance fiscale $cv.
     * Retourne null si la puissance n'est pas renseignée / hors barème.
     */
    private function montantBareme(array $bareme, ?int $cv, float $km, bool $electrique): ?float
    {
        if ($cv === null) {
            return null;
        }

        foreach ($bareme['lignes'] as $l) {
            if ($cv < (int) $l['Cv_Min'] || $cv > (int) $l['Cv_Max']) {
                continue;
            }
            if ($km <= 5000) {
                $montant = $km * (float) $l['Coef_T1'];
            } elseif ($km <= 20000) {
                $montant = $km * (float) $l['Coef_T2'] + (float) $l['Fixe_T2'];
            } else {
                $montant = $km * (float) $l['Coef_T3'];
            }
            if ($electrique) {
                $montant *= $bareme['majoration'];
            }

            return round($montant, 2);
        }

        return null;
    }

    /**
     * Requête commune à donnees()/exportCsv() : tous les JA actifs défiscalisés,
     * avec le cumul péages/km de leurs nominations tombant dans l'année civile
     * [debut, fin] — LEFT JOIN depuis `ja` (pas depuis `nomination`) pour que
     * les JA sans mission cette année apparaissent aussi, avec des totaux à 0.
     */
    private function requeteAgregee(\PDO $pdo, string $dateDebut, string $dateFin): array
    {
        $tauxKm  = (float) getConfig('frais_kilometrique', '0.30');
        $bareme  = $this->chargerBareme($pdo);

        $stmt = $pdo->prepare('
            SELECT
                j.Id_JA, j.Nom, j.Prenom, j.Cp, j.Ville,
                j.PuissanceFiscale, j.VehiculeElectrique,
                MAX(CASE WHEN j.Email IS NOT NULL AND LENGTH(TRIM(j.Email)) > 0 THEN 1 ELSE 0 END) AS HasEmail,
                COUNT(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Id_Nomination END) AS NbMissions,
                COALESCE(SUM(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Peage     END), 0) AS Peage,
                COALESCE(SUM(CASE WHEN r.Id_Rencontre IS NOT NULL THEN n.Kilometre END), 0) AS Kilometre
            FROM ja j
            LEFT JOIN disponible d ON d.Id_JA = j.Id_JA
            LEFT JOIN nomination n ON n.Id_Disponible = d.Id_Disponible
                AND (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
            LEFT JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                AND r.Date BETWEEN :debut AND :fin
            WHERE j.Actif = 1 AND j.Defiscalisation = 1
            GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Cp, j.Ville, j.PuissanceFiscale, j.VehiculeElectrique
            ORDER BY j.Nom, j.Prenom
        ');
        $stmt->execute([':debut' => $dateDebut, ':fin' => $dateFin]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $km  = (float) $r['Kilometre'];
            $cv  = $r['PuissanceFiscale'] !== null ? (int) $r['PuissanceFiscale'] : null;
            $r['PuissanceFiscale']   = $cv;
            $r['VehiculeElectrique'] = (int) $r['VehiculeElectrique'];
            $r['HasEmail']           = (int) $r['HasEmail'];
            $r['FraisKmPeages']      = round($km * $tauxKm + (float) $r['Peage'], 2);
            $r['MontantBareme']      = $this->montantBareme($bareme, $cv, $km, (bool) $r['VehiculeElectrique']);
        }
        unset($r);

        return $rows;
    }

    private function anneeCivile(): array
    {
        $annee = date('Y');

        return ["$annee-01-01", "$annee-12-31"];
    }

    public function donnees(): ResponseInterface
    {
        return $this->tryJson(function () {
            [$debut, $fin] = $this->anneeCivile();
            $rows = $this->requeteAgregee(getPDO(), $debut, $fin);

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    /**
     * Enregistre la puissance fiscale / motorisation d'un JA (saisie inline dans
     * le tableau ED51). PuissanceFiscale vide => NULL (barème non calculé).
     */
    public function vehicule(): ResponseInterface
    {
        return $this->tryJson(function () {
            $idJa = (int) $this->request->getPost('Id_JA');
            if ($idJa <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'JA inconnu.']);
            }

            $cvBrut = $this->request->getPost('PuissanceFiscale');
            $cv     = ($cvBrut === null || $cvBrut === '') ? null : (int) $cvBrut;
            if ($cv !== null && !in_array($cv, self::CV_AUTORISES, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Puissance fiscale invalide.']);
            }
            $elec = $this->request->getPost('VehiculeElectrique') ? 1 : 0;

            $stmt = getPDO()->prepare(
                'UPDATE ja SET PuissanceFiscale = :cv, VehiculeElectrique = :elec WHERE Id_JA = :id'
            );
            $stmt->execute([':cv' => $cv, ':elec' => $elec, ':id' => $idJa]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    /**
     * Relance par email les JA cochés dans le tableau ED51 (POST `ids[]`), pour
     * qu'ils renseignent la puissance et la motorisation de leur véhicule via le
     * lien de l'attestation (ED53). Les cases des lignes sans puissance fiscale
     * sont pré-cochées côté client, mais le défiscalisateur peut ajuster la
     * sélection. Filtre serveur : Actif=1, Defiscalisation=1, email présent. Un
     * seul mailer réutilisé (SMTP keep-alive), Reply-To = email du défiscalisateur.
     */
    public function relancerVehicule(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            $moi = $_SESSION['utilisateur'] ?? [];

            assurerTemplateRelanceVehicule($pdo);
            $modele = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_RELANCE_VEHICULE, (int) ($moi['id'] ?? 0));
            if (!$modele) {
                return $this->response->setJSON([
                    'ok' => false,
                    'msg' => 'Modèle « Administratif » (Id_Messagerie=' . self::ID_MESSAGE_RELANCE_VEHICULE . ') introuvable en base.',
                ]);
            }

            $ids = $this->request->getPost('ids');
            $ids = is_array($ids)
                ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)))
                : [];
            if ($ids === []) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun JA coché.']);
            }

            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                SELECT Id_JA, Nom, Prenom, Email
                FROM ja
                WHERE Id_JA IN ($in)
                  AND Actif = 1 AND Defiscalisation = 1
                  AND Email IS NOT NULL AND TRIM(Email) <> ''
                ORDER BY Nom, Prenom
            ");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows === []) {
                return $this->response->setJSON([
                    'ok' => false, 'envoyes' => 0, 'total' => 0,
                    'msg' => 'Aucun destinataire valide parmi les JA cochés (inactif, non défiscalisé ou sans email).',
                ]);
            }
            $ignores = count($ids) - count($rows);

            $rl = checkRateLimit(count($rows));
            if ($rl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $rl]);
            }

            $modeDev  = isModeDeveloppement();
            $isHtml   = strip_tags((string) $modele['Message']) !== (string) $modele['Message'];
            $replyTo  = (!empty($modele['ReplyTo']) && filter_var($moi['email'] ?? '', FILTER_VALIDATE_EMAIL))
                ? $moi['email'] : null;

            $mail = getNijacMailer();
            $mail->SMTPKeepAlive = true;
            $mail->isHTML($isHtml);
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo, trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')) ?: 'NIJAC');
            }

            $envoyes = 0;
            $erreurs = [];
            foreach ($rows as $ja) {
                try {
                    $marqueurs = construireMarqueursMessage($ja, $moi);
                    $rendu     = remplacerMarqueursMessage($modele['Sujet'], $modele['Message'], $marqueurs);

                    $dest = getEmailDestinataire($ja['Email']);
                    $mail->clearAddresses();
                    $mail->addAddress($dest, trim($ja['Prenom'] . ' ' . $ja['Nom']));
                    $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? '[DEV] ' . $rendu['sujet'] : $rendu['sujet'];
                    $mail->Body    = $rendu['corps'];
                    if ($isHtml) {
                        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $rendu['corps']));
                    }
                    $mail->send();
                    $envoyes++;
                } catch (\Throwable $e) {
                    $erreurs[] = trim($ja['Prenom'] . ' ' . $ja['Nom']) . ' : ' . $e->getMessage();
                }
            }
            try {
                $mail->smtpClose();
            } catch (\Throwable $e) {
                // sans importance
            }
            if ($envoyes > 0) {
                enregistrerEnvois($envoyes);
            }

            return $this->response->setJSON([
                'ok'      => $envoyes > 0,
                'envoyes' => $envoyes,
                'total'   => count($rows),
                'erreurs' => $erreurs,
                'msg'     => $envoyes . ' email(s) envoyé(s) sur ' . count($rows)
                           . ($erreurs ? ' — ' . count($erreurs) . ' échec(s)' : '')
                           . ($ignores > 0 ? ' — ' . $ignores . ' JA coché(s) ignoré(s) (inactif / sans email)' : '')
                           . '.',
            ]);
        });
    }

    public function exportCsv(): ResponseInterface
    {
        return $this->tryJson(function () {
            [$debut, $fin] = $this->anneeCivile();
            $rows = $this->requeteAgregee(getPDO(), $debut, $fin);

            $lignes = [];
            foreach ($rows as $r) {
                $lignes[] = implode(';', [
                    $r['Nom'],
                    $r['Prenom'],
                    $r['Cp'] ?? '',
                    $r['Ville'] ?? '',
                    $r['NbMissions'],
                    number_format((float) $r['Peage'], 2, ',', ''),
                    number_format((float) $r['Kilometre'], 2, ',', ''),
                    number_format((float) $r['FraisKmPeages'], 2, ',', ''),
                    $r['PuissanceFiscale'] !== null ? ($r['PuissanceFiscale'] === 7 ? '7+' : $r['PuissanceFiscale']) : '',
                    $r['VehiculeElectrique'] ? 'oui' : 'non',
                    $r['MontantBareme'] !== null ? number_format($r['MontantBareme'], 2, ',', '') : '',
                ]);
            }

            return $this->response->setJSON(['ok' => true, 'csv' => implode("\n", $lignes)]);
        });
    }
}
