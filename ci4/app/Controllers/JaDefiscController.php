<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Ma défiscalisation (EJA3), menu JA (E011), rôle « JA » (filtre "jaauth").
 *
 * Réutilise ce que voit le défiscalisateur (ED51/ED53/ED54) : colonnes ja.Defiscalisation,
 * ja.PuissanceFiscale, ja.VehiculeElectrique (mêmes valeurs que l'attestation ED53 : CV 3 à 7), dossier
 * _Defiscalisation/ (attestation signée `{Id_JA}.pdf` déposée par ED53, carte grise `{Id_JA}_cg.{pdf|jpg|png}`
 * déposée ici ou par ED53). Aucune table ni colonne nouvelle. La fiche est TOUJOURS idJaCompteSession()
 * (jamais un id du client) ; les noms de fichiers sont construits côté serveur, jamais d'après le client.
 * L'attestation reste produite par ED53 (signature à l'écran, PDF jsPDF) : un lien jetonné vers sa propre fiche
 * y mène ; cet écran n'en affiche que l'état.
 */
class JaDefiscController extends BaseController
{
    private const DIR          = __DIR__ . '/../../../_Defiscalisation';
    private const CV_AUTORISES = [3, 4, 5, 6, 7];
    private const TAILLE_MAX   = 5 * 1024 * 1024;
    /** Type MIME détecté (finfo) → extension enregistrée (celles que lit ED54). */
    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    private const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    /** Taux de réduction d'impôt du don (art. 200 CGI) : 66 % de la note de frais, plafonné à 20 % du revenu imposable — source : Documentation/Plaquette_Defiscalisation.pdf (aucune clé de config). */
    private const TAUX_REDUCTION_IMPOT = 0.66;
    /** Puissance fiscale utilisée pour la simulation quand ja.PuissanceFiscale est vide. */
    private const CV_SIMULATION_DEFAUT = 6;

    private int $idJa;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';
        // Garde-fou propre au contrôleur, en plus de "jaauth" : rôle JA ET fiche de la session ; 0 = tout refusé.
        $this->idJa = idJaCompteSession();
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[NIJAC] EJA3 : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur technique, merci de réessayer.')]);
        }
    }

    /** Carte grise déposée du JA : ['chemin' => ..., 'ext' => ..., 'date' => ...] ou null (glob serveur, pas de lien symbolique). */
    private function carteGrise(): ?array
    {
        foreach (glob(self::DIR . '/' . $this->idJa . '_cg.*') ?: [] as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (is_file($f) && !is_link($f) && isset(self::TYPES[$ext])) {
                return ['chemin' => $f, 'ext' => $ext, 'date' => (int) filemtime($f)];
            }
        }

        return null;
    }

    public function index()
    {
        if ($this->idJa <= 0) {
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        $st = getPDO()->prepare('SELECT Nom, Prenom, Defiscalisation, PuissanceFiscale, VehiculeElectrique FROM ja WHERE Id_JA = ?');
        $st->execute([$this->idJa]);
        $ja = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$ja) {
            return redirect()->to(site_url('login'));
        }

        $att = self::DIR . '/' . $this->idJa . '.pdf';
        $cg  = $this->carteGrise();
        $obf = new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
        $annee = (int) getConfig('annee_fiscale', date('Y')) ?: (int) date('Y');

        return view('ja_defisc_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'ja'          => [
                'nom'        => trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')),
                'defisc'     => (int) $ja['Defiscalisation'] === 1,
                'cv'         => $ja['PuissanceFiscale'] !== null ? (int) $ja['PuissanceFiscale'] : null,
                'electrique' => (int) $ja['VehiculeElectrique'] === 1,
                'etape1'     => (int) $ja['Defiscalisation'] === 1 && $ja['PuissanceFiscale'] !== null,
            ],
            'cvAutorises' => self::CV_AUTORISES,
            'attestation' => is_file($att) ? (int) filemtime($att) : null,
            'carteGrise'  => $cg ? ['ext' => $cg['ext'], 'date' => $cg['date']] : null,
            'urlAttestation' => site_url('attestation-defisc') . '?ja=' . $obf->obfuscate($this->idJa),
            'annee'       => $annee,
            'simulation'  => $this->simulation($annee, $ja),
            'tauxKm'      => (string) getConfig('frais_kilometrique', '0.30'),
            'tauxCv'      => $this->tauxParCv(),
            'majoration'  => rtrim(rtrim(number_format((float) getConfig('comptadefisc_majoration_electrique', '20'), 2, ',', ''), '0'), ','),
        ]);
    }

    /**
     * Simulation indicative sur l'année civile $annee : km des rencontres CRA passées du JA (jour inclus), avec la règle
     * d'EJA4 (nominationsDuJa() → KilometreAffiche : une seule prestation par jour garde ses km), valorisés (a) au taux de
     * remboursement de la Ligue (config frais_kilometrique, comme la colonne « Frais kilométriques » d'EJA4) et (b) au barème
     * fiscal (ComptaDefiscalisation, majoration électrique) selon la puissance fiscale (6 CV si vide), puis réduction d'impôt.
     * Lecture seule, fiche de la session uniquement. null = aucune mission passée.
     */
    private function simulation(int $annee, array $ja): ?array
    {
        $pdo      = getPDO();
        $jour     = date('Y-m-d');
        $missions = 0;
        $km       = 0.0;
        foreach (JaNominationsController::nominationsDuJa($pdo, $this->idJa) as $n) {
            $d = (string) $n['Date'];
            if ((int) $n['ArbitrageCRA'] !== 1 || $d > $jour || substr($d, 0, 4) !== (string) $annee) {
                continue;
            }
            $missions++;
            $km += (float) $n['KilometreAffiche'];
        }
        if ($missions === 0) {
            return null;
        }
        $cvRenseigne = $ja['PuissanceFiscale'] !== null && (int) $ja['PuissanceFiscale'] > 0;
        $cv          = $cvRenseigne ? (int) $ja['PuissanceFiscale'] : self::CV_SIMULATION_DEFAUT;
        $electrique  = (int) $ja['VehiculeElectrique'] === 1;
        $recu        = montantBaremeKilometrique(chargerBaremeKilometrique($pdo), $cv, $km, $electrique);

        return [
            'missions'      => $missions,
            'km'            => $km,
            'cv'            => $cv,
            'cvRenseigne'   => $cvRenseigne,
            'electrique'    => $electrique,
            'remboursement' => round($km * (float) getConfig('frais_kilometrique', '0.30'), 2),
            'recu'          => $recu,
            'reduction'     => $recu !== null ? round($recu * self::TAUX_REDUCTION_IMPOT, 2) : null,
            'tauxReduction' => (int) round(self::TAUX_REDUCTION_IMPOT * 100),
        ];
    }

    /**
     * Taux kilométrique fiscal de la 1re tranche (≤ 5 000 km, Coef_T1 du barème ComptaDefiscalisation) pour chaque
     * puissance proposée dans la liste : [cv => taux €/km]. Absent = pas de ligne de barème pour cette puissance.
     */
    private function tauxParCv(): array
    {
        $taux = [];
        foreach (chargerBaremeKilometrique(getPDO())['lignes'] as $l) {
            foreach (self::CV_AUTORISES as $cv) {
                if ($cv >= (int) $l['Cv_Min'] && $cv <= (int) $l['Cv_Max']) {
                    $taux[$cv] = (float) $l['Coef_T1'];
                }
            }
        }

        return $taux;
    }

    /** Enregistre la case Défiscalisation, la puissance fiscale et la motorisation de SA fiche. */
    public function sauvegarder(): ResponseInterface
    {
        return $this->tryJson(function () {
            if ($this->idJa <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Accès non autorisé.']);
            }
            $cvBrut = $this->request->getPost('puissance');
            $cv     = ($cvBrut === null || $cvBrut === '') ? null : (int) $cvBrut;
            if ($cv !== null && !in_array($cv, self::CV_AUTORISES, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Puissance fiscale invalide.']);
            }
            $defisc = $this->request->getPost('defiscalisation') ? 1 : 0;
            $elec   = $this->request->getPost('electrique') ? 1 : 0;

            getPDO()->prepare('UPDATE ja SET Defiscalisation = :d, PuissanceFiscale = :cv, VehiculeElectrique = :e WHERE Id_JA = :id')
                ->execute([':d' => $defisc, ':cv' => $cv, ':e' => $elec, ':id' => $this->idJa]);

            return $this->response->setJSON([
                'ok'     => true,
                'msg'    => 'Informations enregistrées.',
                'etape1' => $defisc === 1 && $cv !== null,
            ]);
        });
    }

    /**
     * Dépose (ou remplace) la carte grise du JA : multipart `carte_grise`, ≤ 5 Mo, type détecté par finfo
     * (PDF / JPEG / PNG) + signature %PDF- ou image décodable. Fichier `{Id_JA}_cg.{ext}` (nom serveur) ; l'ancienne
     * carte grise n'est supprimée qu'une fois la nouvelle écrite.
     */
    public function deposerCarteGrise(): ResponseInterface
    {
        return $this->tryJson(function () {
            if ($this->idJa <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Accès non autorisé.']);
            }
            $st = getPDO()->prepare('SELECT Defiscalisation, PuissanceFiscale FROM ja WHERE Id_JA = ?');
            $st->execute([$this->idJa]);
            $ja = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$ja || (int) $ja['Defiscalisation'] !== 1 || $ja['PuissanceFiscale'] === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Enregistrez d'abord l'étape 1 (défiscalisation souhaitée et puissance fiscale)."]);
            }
            $f = $this->request->getFile('carte_grise');
            if ($f === null || !$f->isValid()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise non reçue (téléversement interrompu ou trop volumineux).']);
            }
            if ($f->getSize() <= 0 || $f->getSize() > self::TAILLE_MAX) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise vide ou trop volumineuse (5 Mo maximum).']);
            }
            $tmp  = $f->getTempName();
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
            $ext  = self::MIMES[$mime] ?? null;
            if ($ext === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise : seuls les formats PDF, JPEG et PNG sont acceptés.']);
            }
            if ($ext === 'pdf') {
                $h   = @fopen($tmp, 'rb');
                $sig = $h ? fread($h, 5) : '';
                if ($h) {
                    fclose($h);
                }
                if ($sig !== '%PDF-') {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise : fichier PDF invalide.']);
                }
            } elseif (@getimagesize($tmp) === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Carte grise : image illisible.']);
            }

            if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true) && !is_dir(self::DIR)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Dossier de dépôt inaccessible : carte grise non enregistrée.']);
            }
            $ancien = $this->carteGrise();
            $cible  = self::DIR . '/' . $this->idJa . '_cg.' . $ext;
            $neuf   = self::DIR . '/' . $this->idJa . '_cg.tmp' . bin2hex(random_bytes(4));
            if (!@move_uploaded_file($tmp, $neuf) && !@copy($tmp, $neuf)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Écriture de la carte grise impossible.']);
            }
            // Retire d'abord toute ancienne carte grise (autre extension comprise), puis installe la nouvelle.
            foreach (glob(self::DIR . '/' . $this->idJa . '_cg.*') ?: [] as $vieux) {
                if ($vieux !== $neuf) {
                    @unlink($vieux);
                }
            }
            if (!@rename($neuf, $cible)) {
                @unlink($neuf);

                return $this->response->setJSON(['ok' => false, 'msg' => 'Écriture de la carte grise impossible.']);
            }
            error_log('[NIJAC] EJA3 : carte grise ' . ($ancien ? 'remplacée' : 'déposée') . ' (fiche JA ' . $this->idJa . ', ' . $ext . ')');

            return $this->response->setJSON([
                'ok' => true, 'msg' => 'Carte grise enregistrée.', 'ext' => strtoupper($ext), 'date' => date('d/m/Y H:i'),
            ]);
        });
    }

    /** Sert au JA SA carte grise (fiche de la session uniquement, nom jamais fourni par le client). */
    public function voirCarteGrise(): ResponseInterface
    {
        $cg = $this->idJa > 0 ? $this->carteGrise() : null;
        if ($cg === null) {
            return $this->response->setStatusCode(404)->setBody('Carte grise introuvable.');
        }

        return $this->response
            ->setHeader('Content-Type', self::TYPES[$cg['ext']])
            ->setHeader('Content-Disposition', 'inline; filename="carte-grise.' . $cg['ext'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody((string) file_get_contents($cg['chemin']));
    }
}
