<?php

namespace App\Controllers;

/**
 * NIJAC – Mot de passe oublié (E007) + réinitialisation via lien email (E008).
 *
 * Aucune session ni colonne BDD : le lien envoyé par email porte un jeton HMAC
 * signé avec le hash du mot de passe courant + le pepper .env (genererJetonResetMdp /
 * cleJetonResetMdp), donc à usage unique — il cesse d'être valide dès que le mot de
 * passe a changé. E007 : limite par IP (mdpreset_ip:<ip>) + par compte (mdpreset:<id>),
 * envoi différé après la réponse HTTP (temps de réponse identique). Le modèle
 * d'email est le type « Mot de passe oublié » de la table messagerie (éditable
 * en EA93), marqueur {URL_RESET_MDP}.
 *
 * Routes publiques (aucun filtre auth) — le filtre csrf global couvre les POST.
 */
class MotDePasseOublieController extends BaseController
{
    /** Vrai quand un envoi de lien a été programmé après la réponse (voir differerEnvoiLien()). */
    private bool $envoiDiffere = false;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
    }

    /** E007 : formulaire « identifiant ou email », puis envoi du lien de réinitialisation. */
    public function demande()
    {
        $status      = 'Saisissez votre identifiant ou votre adresse email.';
        $statutClass = 'text-secondary';
        $envoye      = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ident = trim($this->request->getPost('identifiant') ?? '');

            if ($ident === '') {
                $status      = 'Veuillez saisir votre identifiant ou votre email.';
                $statutClass = 'text-warning';
            } else {
                // Limite par IP (mdpreset_ip:<ip>, 5 demandes / 15 min) : au-delà, réponse
                // identique mais aucun envoi. Sinon l'envoi (recherche du compte + SMTP) est
                // différé APRÈS la réponse HTTP : même temps de réponse, compte existant ou non.
                if (autoriserDemandeResetMdpIp()) {
                    $this->differerEnvoiLien($ident, site_url('reinitialiser-mot-de-passe'));
                }
                // Réponse neutre systématique : pas d'énumération de comptes.
                $envoye      = true;
                $status      = "Si un compte correspond à cette information, un email contenant un lien "
                             . "de réinitialisation vient d'être envoyé. Ce lien est valable 1 heure.";
                $statutClass = 'text-success';
            }
        }

        $html = view('mdp_oublie_index', compact('status', 'statutClass', 'envoye'));
        if ($this->envoiDiffere) {
            // Longueur connue + fermeture de connexion : le navigateur (mod_php sans
            // fastcgi_finish_request()) considère la réponse complète dès le flush, avant l'envoi.
            $this->response->setHeader('Connection', 'close');
            if (!ini_get('zlib.output_compression')) {
                $this->response->setHeader('Content-Length', (string) strlen($html));
            }
        }

        return $this->response->setBody($html);
    }

    /**
     * Programme envoyerLien() après l'envoi de la réponse HTTP (fonction d'arrêt) : la réponse
     * est d'abord vidée vers le client (fastcgi_finish_request() en PHP-FPM, sinon vidage des
     * tampons + flush()), ignore_user_abort() garantit l'envoi même si le navigateur ferme.
     * Le mode Développement (NijacMailer) s'applique comme avant ; toute erreur reste journalisée.
     * $urlReset est calculée avant (services CI4 encore en place).
     */
    private function differerEnvoiLien(string $ident, string $urlReset): void
    {
        $this->envoiDiffere = true;
        register_shutdown_function(function () use ($ident, $urlReset) {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }
            try {
                $this->envoyerLien($ident, $urlReset);
            } catch (\Throwable $e) {
                error_log('[NIJAC] reset mdp : ' . $e->getMessage());
            }
        });
    }

    private function envoyerLien(string $ident, string $urlReset): void
    {
        $pdo  = getPDO();
        // Deux placeholders distincts (:login / :email) plutôt qu'un même paramètre nommé
        // réutilisé : getPDO() active aujourd'hui ATTR_EMULATE_PREPARES (config/db.php, erreur
        // 1615 sur le mutualisé), qui tolère la réutilisation, mais un retour aux requêtes
        // préparées natives MySQL la refuserait (SQLSTATE[HY093]) — forme sûre dans les deux cas.
        $stmt = $pdo->prepare(
            'SELECT Id_Utilisateur, Nom, Prenom, Password, Email
             FROM Utilisateur
             WHERE Actif = 1 AND Email IS NOT NULL AND Email <> "" AND (Login = :login OR Email = :email)
             LIMIT 1'
        );
        $stmt->execute([':login' => $ident, ':email' => $ident]);
        $u = $stmt->fetch();
        if (!$u) {
            return;
        }

        // Cooldown par compte (pas par IP : on veut protéger LE COMPTE ciblé, qui
        // peut être mail-bombé par un tiers depuis des IP différentes) — un login
        // valide connu de tous permettrait sinon de redéclencher l'envoi à volonté.
        // Réponse déjà neutre côté demande() dans tous les cas, donc pas de fuite
        // d'info en sautant silencieusement l'envoi ici.
        $cle = 'mdpreset:' . $u['Id_Utilisateur'];
        if (checkTentativesRateLimit($cle, 3, 15) !== null) {
            return;
        }
        enregistrerTentative($cle, 15);

        assurerTemplateMotDePasseOublie($pdo);
        $tpl = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Mot de passe oublié' LIMIT 1")->fetch();
        if (!$tpl) {
            return;
        }

        $jeton = genererJetonResetMdp((int) $u['Id_Utilisateur'], (string) $u['Password']);
        $url   = $urlReset . '?t=' . $jeton;

        $marqueurs = [
            '{URL_RESET_MDP}' => $url,
            '{UTI_PRENOM}'    => $u['Prenom'] ?? '',
            '{UTI_NOM}'       => $u['Nom'] ?? '',
            '{URL_LIGUE}'     => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
        ];
        $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);

        $mail = getNijacMailer();
        $estHtml = strip_tags($rendu['corps']) !== $rendu['corps'];
        $mail->isHTML($estHtml);
        $mail->addAddress(getEmailDestinataire($u['Email']));
        $mail->Subject = $rendu['sujet'];
        $mail->Body    = $rendu['corps'];
        if ($estHtml) {
            $mail->AltBody = altBodyDepuisHtml($rendu['corps']);
        }
        $mail->send();
    }

    /** E008 : validation du jeton du lien, double saisie du nouveau mot de passe. */
    public function reinitialiser()
    {
        $pdo   = getPDO();
        $jeton = trim(($this->request->getGet('t') ?? $this->request->getPost('t')) ?? '');

        $lookupHash = static function (int $id) use ($pdo): ?string {
            $s = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? AND Actif = 1 LIMIT 1');
            $s->execute([$id]);
            $h = $s->fetchColumn();
            return $h !== false && $h !== '' ? (string) $h : null;
        };

        $idUser = verifierJetonResetMdp($jeton, $lookupHash);

        if ($idUser === null) {
            return view('mdp_reset_index', [
                'jetonValide' => false,
                'jeton'       => '',
                'status'      => "Ce lien de réinitialisation est invalide ou a expiré. Merci de refaire une demande.",
                'statutClass' => 'text-danger',
                'succes'      => false,
            ]);
        }

        $status      = 'Choisissez un nouveau mot de passe.';
        $statutClass = 'text-secondary';
        $succes      = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nouveau  = trim($this->request->getPost('mdp_nouveau')  ?? '');
            $confirme = trim($this->request->getPost('mdp_confirme') ?? '');

            if ($nouveau === '' || $confirme === '') {
                $erreur = 'Veuillez remplir les deux champs.';
            } elseif ($nouveau !== $confirme) {
                $erreur = 'Les deux saisies ne correspondent pas.';
            } else {
                $erreur = validerRobustesseMotDePasse($nouveau);
            }

            if ($erreur !== null) {
                $status      = $erreur;
                $statutClass = 'text-warning';
            } else {
                $hash = \SecurePasswordHasher::hash($nouveau);
                // PwdVersion + 1 (si présente, EA98) : toute session ouverte sur ce compte est
                // détruite à sa prochaine revalidation (revaliderSessionUtilisateur()).
                $pdo->prepare('UPDATE Utilisateur SET Password = ?, ChangeLogin = 0' . sqlIncrementPwdVersion($pdo)
                        . ' WHERE Id_Utilisateur = ?')
                    ->execute([$hash, $idUser]);

                $succes      = true;
                $status      = 'Mot de passe réinitialisé. Vous pouvez maintenant vous connecter.';
                $statutClass = 'text-success';
            }
        }

        return view('mdp_reset_index', [
            'jetonValide' => true,
            'jeton'       => $jeton,
            'status'      => $status,
            'statutClass' => $statutClass,
            'succes'      => $succes,
        ]);
    }
}
