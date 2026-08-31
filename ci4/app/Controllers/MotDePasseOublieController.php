<?php

namespace App\Controllers;

/**
 * NIJAC – Mot de passe oublié (E007) + réinitialisation via lien email (E008).
 *
 * Aucune session ni colonne BDD : le lien envoyé par email porte un jeton HMAC
 * signé avec le hash du mot de passe courant (genererJetonResetMdp), donc à usage
 * unique — il cesse d'être valide dès que le mot de passe a changé. Le modèle
 * d'email est le type « Mot de passe oublié » de la table messagerie (éditable
 * en EA93), marqueur {URL_RESET_MDP}.
 *
 * Routes publiques (aucun filtre auth) — le filtre csrf global couvre les POST.
 */
class MotDePasseOublieController extends BaseController
{
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
                try {
                    // ponytail: pas de cooldown par compte — un tiers connaissant un login
                    // valide peut redéclencher l'envoi. Ajouter un garde-fou (clé configuration
                    // « mdp_reset_last_<id> », refus si < 2 min) si le mailbomb devient un souci.
                    $this->envoyerLien($ident);
                } catch (\Throwable $e) {
                    error_log('[NIJAC] reset mdp : ' . $e->getMessage());
                }
                // Réponse neutre systématique : pas d'énumération de comptes.
                $envoye      = true;
                $status      = "Si un compte correspond à cette information, un email contenant un lien "
                             . "de réinitialisation vient d'être envoyé. Ce lien est valable 1 heure.";
                $statutClass = 'text-success';
            }
        }

        return view('mdp_oublie_index', compact('status', 'statutClass', 'envoye'));
    }

    private function envoyerLien(string $ident): void
    {
        $pdo  = getPDO();
        // Deux placeholders distincts : getPDO() désactive ATTR_EMULATE_PREPARES,
        // et un prepare natif MySQL refuse un même paramètre nommé réutilisé
        // (SQLSTATE[HY093]) — sinon envoyerLien() lève toujours et aucun mail ne part.
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

        assurerTemplateMotDePasseOublie($pdo);
        $tpl = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Mot de passe oublié' LIMIT 1")->fetch();
        if (!$tpl) {
            return;
        }

        $jeton = genererJetonResetMdp((int) $u['Id_Utilisateur'], (string) $u['Password']);
        $url   = site_url('reinitialiser-mot-de-passe') . '?t=' . $jeton;

        $marqueurs = [
            '{URL_RESET_MDP}' => $url,
            '{UTI_PRENOM}'    => $u['Prenom'] ?? '',
            '{UTI_NOM}'       => $u['Nom'] ?? '',
            '{URL_LIGUE}'     => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
        ];
        $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);

        $mail = getNijacMailer();
        $mail->isHTML(strip_tags($rendu['corps']) !== $rendu['corps']);
        $mail->addAddress(getEmailDestinataire($u['Email']));
        $mail->Subject = $rendu['sujet'];
        $mail->Body    = $rendu['corps'];
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
                $pdo->prepare('UPDATE Utilisateur SET Password = ?, ChangeLogin = 0 WHERE Id_Utilisateur = ?')
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
