<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Adresse domicile JA (EN19), portage CI4 de Nominateur/adresse_ja.php.
 *
 * Page PUBLIQUE (sans authentification), tokenisée par le paramètre `?ja=TOKEN`
 * (Obfuscator) — un JA renseigne ou corrige son code postal/ville sans se
 * connecter. Deux actions ("token", "envoyerDemandeAdresse") exigent en
 * revanche une session Nominateur/Admin, comme le fait le legacy en
 * ré-incluant auth_required.php uniquement pour ces actions ; ici ce sont des
 * routes CI4 distinctes sous le filtre "auth".
 *
 * Session native démarrée manuellement dans index() (comme AuthController /
 * DesiderataClubController) : aucun filtre "auth" n'ouvre la session sur la
 * route publique. La protection CSRF (filtre global "csrf") est en mode
 * cookie et ne dépend donc pas de cette session.
 */
class AdresseJaController extends BaseController
{
    private const ID_MESSAGE_DEMANDE_ADRESSE = 5;

    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
    }

    private function startSession(): void
    {
        demarrerSessionNijac();
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            error_log('[NIJAC] EN19 : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, 'Erreur technique, merci de réessayer.')]);
        }
    }

    /** Id_JA d'un jeton `?ja=` (GET) ou `ja` (POST), ou 0. Le jeton est le seul secret des actions publiques. */
    private function idJaDuJeton(string $token): int
    {
        $token = trim($token);

        return $token === '' ? 0 : max(0, $this->obf->deobfuscate($token));
    }

    public function index()
    {
        $this->startSession();

        $tokenGet = trim($this->request->getGet('ja') ?? '');
        $idJa     = $this->idJaDuJeton($tokenGet);

        $pdo    = getPDO();
        $ja     = null;
        $erreur = '';

        if ($idJa > 0) {
            try {
                $stmt = $pdo->prepare('
                    SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                           ja.Id_LaPoste,
                           COALESCE(lp.CodePostal, ja.Cp)  AS Cp,
                           COALESCE(lp.Nom,        ja.Ville) AS Ville
                    FROM ja
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
                    WHERE ja.Id_JA = ?
                ');
                $stmt->execute([$idJa]);
                $ja = $stmt->fetch();
                if (!$ja) {
                    $erreur = "Juge-Arbitre #$idJa introuvable.";
                }
            } catch (\PDOException $e) {
                error_log('[NIJAC] EN19 index : ' . $e->getMessage());
                $erreur = messageErreur($e, 'Erreur technique : impossible de charger la page pour le moment.');
            }
        } else {
            $erreur = 'Lien invalide ou paramètre manquant.';
        }

        session_write_close();
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé pour toute
        // réponse HTML non-AJAX, donc à chaque F5) de démarrer le service Session
        // de CI4 — voir Auth.php pour le détail du conflit avec la session native.
        unset($_SESSION);

        return view('adresse_ja_index', [
            'idJa'   => $idJa,
            'token'  => $idJa > 0 ? $tokenGet : '',   // rejoué par le JS sur recherche-laposte / sauvegarder
            'ja'     => $ja,
            'erreur' => $erreur,
        ]);
    }

    /**
     * Génère le lien tokenisé (?ja=TOKEN) pour un Id_JA donné — requiert une
     * session Nominateur/Admin (filtre "auth" sur la route).
     */
    public function token(): ResponseInterface
    {
        $id = (int) ($this->request->getGet('id') ?? $this->request->getPost('id') ?? 0);
        if (!$id) {
            return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant']);
        }

        $token = $this->obf->obfuscate($id);
        $url   = site_url('adresse-ja') . '?ja=' . $token;

        return $this->response->setJSON(['ok' => true, 'token' => $token, 'url' => $url]);
    }

    /**
     * Envoie au JA le message système "Demande adresse" avec son lien
     * personnalisé — requiert une session Nominateur/Admin.
     */
    public function envoyerDemandeAdresse(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo      = getPDO();
            $moi      = $_SESSION['utilisateur'] ?? [];
            $idJaPost = (int) ($this->request->getPost('id_ja') ?? 0);
            if (!$idJaPost) {
                return $this->response->setJSON(['ok' => false, 'err' => 'JA manquant.']);
            }

            try {
                $stmtJa = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Email FROM ja WHERE Id_JA = ?');
                $stmtJa->execute([$idJaPost]);
                $ja = $stmtJa->fetch();
                if (!$ja) {
                    return $this->response->setJSON(['ok' => false, 'err' => 'JA introuvable.']);
                }
                if (!$ja['Email']) {
                    return $this->response->setJSON(['ok' => false, 'err' => "Ce JA n'a pas d'adresse email."]);
                }

                $idUtilisateurCourant = (int) ($_SESSION['utilisateur']['id'] ?? 0);
                $modele = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_DEMANDE_ADRESSE, $idUtilisateurCourant);
                if (!$modele) {
                    return $this->response->setJSON(['ok' => false, 'err' => 'Modèle "Demande adresse" (Id_Messagerie=' . self::ID_MESSAGE_DEMANDE_ADRESSE . ') introuvable en base.']);
                }

                $marqueurs = construireMarqueursMessage($ja, $moi);
                $rendu     = remplacerMarqueursMessage($modele['Sujet'], $modele['Message'], $marqueurs);
                $sujet     = $rendu['sujet'];
                $corps     = preg_replace('/ {3,}/', ' ', $rendu['corps']);

                $errRl = checkRateLimit(1);
                if ($errRl !== null) {
                    return $this->response->setJSON(['ok' => false, 'err' => $errRl]);
                }

                $dest    = getEmailDestinataire($ja['Email']);
                $isHtml  = strip_tags($corps) !== $corps;
                $mail    = getNijacMailer();
                $mail->isHTML($isHtml);
                $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
                $modeDev = isModeDeveloppement();
                $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujet" : $sujet;
                $mail->Body    = $corps;
                if ($isHtml) {
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                }
                $mail->send();
                enregistrerEnvois(1);

                return $this->response->setJSON(['ok' => true, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom'], 'url' => $marqueurs['{URL_ADRESSE_JA}']]);
            } catch (\Exception $e) {
                error_log('[NIJAC] EN19 envoi demande adresse : ' . $e->getMessage());

                return $this->response->setJSON(['ok' => false, 'err' => messageErreur($e, 'Envoi impossible (voir le journal des erreurs).')]);
            }
        });
    }

    /**
     * Recherche une commune (laposte) par CP / ville — action publique.
     */
    public function rechercheLaposte(): ResponseInterface
    {
        return $this->tryJson(function () {
            // Action publique : sans jeton JA valide, cette recherche restait ouverte à n'importe quel script.
            if (!$this->idJaDuJeton((string) $this->request->getPost('ja'))) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Lien invalide.']);
            }

            $pdo   = getPDO();
            $cp    = trim($this->request->getPost('cp') ?? '');
            $ville = normaliserVille($this->request->getPost('ville') ?? '');

            if ($cp === '' && $ville === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CP et ville vides.']);
            }

            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare(
                    "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                     WHERE CodePostal = ?
                       AND REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                     LIMIT 1"
                );
                $stmt->execute([$cp, $ville . '%']);
                $row = $stmt->fetch();
                if ($row) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                }
            }

            if ($cp !== '') {
                $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
                $stmt->execute([$cp]);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    $sugg = array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);

                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                }
            }

            if ($ville !== '') {
                $stmt = $pdo->prepare(
                    "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                     WHERE REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                     ORDER BY CodePostal, Nom LIMIT 20"
                );
                $stmt->execute([$ville . '%']);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    $sugg = array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);

                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                }
            }

            return $this->response->setJSON(['ok' => false, 'msg' => 'Commune non trouvée.']);
        });
    }

    /**
     * Enregistre l'adresse choisie pour le JA — action publique.
     */
    public function sauvegarder(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            // L'Id_JA vient UNIQUEMENT du jeton : accepter un id_ja en clair permettait à n'importe qui de
            // réécrire l'adresse (donc distances, kilomètres, rattachement) de n'importe quel JA.
            $id        = $this->idJaDuJeton((string) $this->request->getPost('ja'));
            $idLaPoste = (int) ($this->request->getPost('id_laposte') ?? 0);

            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Lien invalide. Merci de redemander l\'envoi du lien.']);
            }
            if (!$idLaPoste) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Veuillez sélectionner une commune valide dans la liste laposte.']);
            }

            $check = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                return $this->response->setJSON(['ok' => false, 'err' => 'JA introuvable.']);
            }

            // CP / ville recopiés depuis laposte (pas depuis le formulaire) : cohérents avec Id_LaPoste.
            $lp = $pdo->prepare('SELECT CodePostal, Nom FROM laposte WHERE Id_LaPoste = ?');
            $lp->execute([$idLaPoste]);
            $commune = $lp->fetch();
            if (!$commune) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Commune inconnue : veuillez la sélectionner à nouveau.']);
            }

            $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
                ->execute([$idLaPoste, $commune['CodePostal'], $commune['Nom'], $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
