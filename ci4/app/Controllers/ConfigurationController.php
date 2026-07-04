<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Configuration générale (E015), portage CI4 de configuration.php.
 *
 * Gestion des paramètres applicatifs stockés dans la table `configuration`
 * (clé/valeur). Administrateur uniquement (filtre "adminauth"). Pas de Model :
 * table clé/valeur générique avec validations spécifiques par clé, reste en
 * raw PDO comme le reste de cette famille d'écrans.
 */
class ConfigurationController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/csrf.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] configuration.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] configuration.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $pdo = getPDO();
        try {
            initTableConfiguration($pdo);
            $etatCourant       = getConfig('etat_logiciel', 'Developpement');
            $emailDev          = getConfig('email_developpement', 'patrick.chautard@free.fr');
            $deptsActifs       = getConfig('departements_actifs', '14,27,50,61,76');
            $reglesDepts       = getConfig('regles_departements', '{"76":["27"]}');
            $deptsLimitrophes  = getConfig('departements_limitrophes', '60,80,95,78,28,53,72,35');
            $urlLigue          = getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr');
            $indemniteForfait  = getConfig('indemnite_forfaitaire', '25.00');
            $fraisKm           = getConfig('frais_kilometrique', '0.30');
            $cpteFreisKm       = getConfig('compte_frais_km', '62511');
            $cptePrestations   = getConfig('compte_prestations', '62261');
            $codeAnalytique    = getConfig('code_analytique_compta', '04EPR232');
            $phase1Debut       = getConfig('phase1_debut', '09-01');
            $phase1Fin         = getConfig('phase1_fin', '01-31');
            $phase2Debut       = getConfig('phase2_debut', '02-01');
            $phase2Fin         = getConfig('phase2_fin', '06-30');
            $smtpProdHost      = getConfig('smtp_host', '');
            $smtpProdPort      = getConfig('smtp_port', '587');
            $smtpProdSecure    = getConfig('smtp_secure', 'tls');
            $smtpProdCredsOk   = getSmtpUser() !== '' && getSmtpPassword() !== '';
            $smtpProdFrom      = getConfig('smtp_from', '');
            $smtpProdFromName  = getConfig('smtp_from_name', '');
        } catch (\Throwable $e) {
            $etatCourant      = 'Developpement';
            $emailDev         = 'patrick.chautard@free.fr';
            $deptsActifs      = '14,27,50,61,76';
            $reglesDepts      = '{"76":["27"]}';
            $deptsLimitrophes = '60,80,95,78,28,53,72,35';
            $urlLigue         = 'https://www.ligue-normandie-tt.fr';
            $indemniteForfait = '25.00';
            $fraisKm          = '0.30';
            $cpteFreisKm      = '62511';
            $cptePrestations  = '62261';
            $codeAnalytique   = '04EPR232';
            $phase1Debut      = '09-01';
            $phase1Fin        = '01-31';
            $phase2Debut      = '02-01';
            $phase2Fin        = '06-30';
            $smtpProdHost = $smtpProdPort = $smtpProdFrom = $smtpProdFromName = '';
            $smtpProdSecure  = 'tls';
            $smtpProdCredsOk = getSmtpUser() !== '' && getSmtpPassword() !== '';
        }
        $deptsActifsArray = array_map('trim', explode(',', $deptsActifs));

        $data = [
            'nomComplet'        => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'       => $u['id_departement'] ?? '',
            'changeLogin'       => !empty($u['change_login']),
            'isAdmin'           => !empty($u['is_admin']),
            'csrfToken'         => csrfToken(),
            'etatCourant'       => $etatCourant,
            'emailDev'          => $emailDev,
            'deptsActifs'       => $deptsActifs,
            'deptsActifsArray'  => $deptsActifsArray,
            'reglesDepts'       => $reglesDepts,
            'deptsLimitrophes'  => $deptsLimitrophes,
            'urlLigue'          => $urlLigue,
            'indemniteForfait'  => $indemniteForfait,
            'fraisKm'           => $fraisKm,
            'cpteFreisKm'       => $cpteFreisKm,
            'cptePrestations'   => $cptePrestations,
            'codeAnalytique'    => $codeAnalytique,
            'phase1Debut'       => $phase1Debut,
            'phase1Fin'         => $phase1Fin,
            'phase2Debut'       => $phase2Debut,
            'phase2Fin'         => $phase2Fin,
            'smtpProdHost'      => $smtpProdHost,
            'smtpProdPort'      => $smtpProdPort,
            'smtpProdSecure'    => $smtpProdSecure,
            'smtpProdCredsOk'   => $smtpProdCredsOk,
            'smtpProdFrom'      => $smtpProdFrom,
            'smtpProdFromName'  => $smtpProdFromName,
        ];

        return view('configuration_index', $data);
    }

    public function lire(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query('SELECT cle, valeur, description FROM configuration ORDER BY cle')->fetchAll();

            return $this->response->setJSON(['ok' => true, 'params' => $rows]);
        });
    }

    public function enregistrer(): ResponseInterface
    {
        csrfVerify(true);

        return $this->tryJson(function () {
            $pdo    = getPDO();
            $cle    = trim($this->request->getPost('cle') ?? '');
            $valeur = trim($this->request->getPost('valeur') ?? '');

            if ($cle === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Clé manquante.']);
            }

            // smtp_user et smtp_password ne sont plus stockés en base (voir .env)
            if (in_array($cle, ['smtp_user', 'smtp_password'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "« $cle » est géré via .env, non modifiable ici."]);
            }

            // Validation JSON pour regles_departements
            if ($cle === 'regles_departements' && $valeur !== '') {
                $decoded = json_decode($valeur, true);
                if (!is_array($decoded)) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Format de règles invalide (JSON attendu).']);
                }
            }

            // Valeurs autorisées pour etat_logiciel
            if ($cle === 'etat_logiciel' && !in_array($valeur, ['Operationnel', 'Developpement'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Valeur invalide pour ce paramètre.']);
            }

            // Validation email pour email_developpement
            if ($cle === 'email_developpement') {
                if ($valeur === '' || !filter_var($valeur, FILTER_VALIDATE_EMAIL)) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Adresse email invalide.']);
                }
            }

            // Validation URL pour url_ligue
            if ($cle === 'url_ligue') {
                if ($valeur === '' || !filter_var($valeur, FILTER_VALIDATE_URL)) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Adresse du site invalide.']);
                }
            }

            // Validation numérique pour indemnité forfaitaire et frais kilométriques
            if (in_array($cle, ['indemnite_forfaitaire', 'frais_kilometrique'], true)) {
                $valeurNum = str_replace(',', '.', $valeur);
                if ($valeurNum === '' || !is_numeric($valeurNum) || (float) $valeurNum < 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Valeur numérique positive attendue.']);
                }
                $valeur = number_format((float) $valeurNum, 2, '.', '');
            }

            // Validation départements_actifs : liste de numéros séparés par virgule
            if ($cle === 'departements_actifs') {
                $deptsValides = ['14', '27', '50', '61', '76'];
                $depts        = array_filter(array_map('trim', explode(',', $valeur)));
                foreach ($depts as $d) {
                    if (!in_array($d, $deptsValides, true)) {
                        return $this->response->setJSON(['ok' => false, 'msg' => "Département « $d » non reconnu."]);
                    }
                }
                $valeur = implode(',', $depts);
            }

            // Validation départements_limitrophes : liste de numéros séparés par virgule
            if ($cle === 'departements_limitrophes') {
                $deptsValides = ['28', '35', '53', '60', '72', '78', '80', '95'];
                $depts        = array_filter(array_map('trim', explode(',', $valeur)));
                foreach ($depts as $d) {
                    if (!in_array($d, $deptsValides, true)) {
                        return $this->response->setJSON(['ok' => false, 'msg' => "Département limitrophe « $d » non reconnu."]);
                    }
                }
                $valeur = implode(',', $depts);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO configuration (cle, valeur) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
            );
            $stmt->execute([$cle, $valeur]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Paramètre enregistré.', 'cle' => $cle, 'valeur' => $valeur]);
        });
    }

    public function smtpTestProd(): ResponseInterface
    {
        csrfVerify(true);

        return $this->tryJson(function () {
            $destinataire = trim($this->request->getPost('destinataire') ?? '');
            if ($destinataire === '' || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Adresse destinataire invalide.']);
            }
            $mail = getNijacMailer('smtp_');
            $mail->addAddress($destinataire);
            $mail->Subject = '[NIJAC] Test SMTP production';
            $mail->isHTML(false);
            $mail->Body = "Cet email confirme que la configuration SMTP de production est opérationnelle.\n\nEnvoyé depuis " . gethostname();
            $mail->send();

            return $this->response->setJSON(['ok' => true, 'msg' => "Email de test envoyé à $destinataire."]);
        });
    }

    public function tableCreer(): ResponseInterface
    {
        csrfVerify(true);

        return $this->tryJson(function () {
            $pdo         = getPDO();
            $cle         = trim($this->request->getPost('cle') ?? '');
            $valeur      = (string) ($this->request->getPost('valeur') ?? '');
            $description = (string) ($this->request->getPost('description') ?? '');

            if ($cle === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'La clé est obligatoire.']);
            }
            if (!preg_match('/^[a-z0-9_]+$/i', $cle)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'La clé ne doit contenir que lettres, chiffres et underscores.']);
            }
            if (in_array($cle, ['smtp_user', 'smtp_password'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "« $cle » est géré via .env, non gérable ici."]);
            }

            $existe = $pdo->prepare('SELECT 1 FROM configuration WHERE cle = ?');
            $existe->execute([$cle]);
            if ($existe->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => "La clé « $cle » existe déjà."]);
            }

            $pdo->prepare('INSERT INTO configuration (cle, valeur, description) VALUES (?, ?, ?)')
                ->execute([$cle, $valeur, $description ?: null]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne créée.']);
        });
    }

    public function tableModifier(): ResponseInterface
    {
        csrfVerify(true);

        return $this->tryJson(function () {
            $pdo          = getPDO();
            $cleOriginale = trim($this->request->getPost('cle_originale') ?? '');
            $cle          = trim($this->request->getPost('cle') ?? '');
            $valeur       = (string) ($this->request->getPost('valeur') ?? '');
            $description  = (string) ($this->request->getPost('description') ?? '');

            if ($cleOriginale === '' || $cle === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Clé manquante.']);
            }
            if (!preg_match('/^[a-z0-9_]+$/i', $cle)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'La clé ne doit contenir que lettres, chiffres et underscores.']);
            }
            if (in_array($cle, ['smtp_user', 'smtp_password'], true) || in_array($cleOriginale, ['smtp_user', 'smtp_password'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "« $cle » est géré via .env, non gérable ici."]);
            }

            if ($cle !== $cleOriginale) {
                $existe = $pdo->prepare('SELECT 1 FROM configuration WHERE cle = ?');
                $existe->execute([$cle]);
                if ($existe->fetchColumn()) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "La clé « $cle » existe déjà."]);
                }
            }

            $pdo->prepare('UPDATE configuration SET cle = ?, valeur = ?, description = ? WHERE cle = ?')
                ->execute([$cle, $valeur, $description ?: null, $cleOriginale]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne modifiée.']);
        });
    }

    public function tableSupprimer(): ResponseInterface
    {
        csrfVerify(true);

        return $this->tryJson(function () {
            $cle = trim($this->request->getPost('cle') ?? '');
            if ($cle === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Clé manquante.']);
            }
            getPDO()->prepare('DELETE FROM configuration WHERE cle = ?')->execute([$cle]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne supprimée.']);
        });
    }
}
