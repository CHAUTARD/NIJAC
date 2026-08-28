<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des messages (EA93), portage CI4 de Nominateur/messagerie.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * un Nominateur consulte les messages système + les siens et peut dupliquer un
 * message système pour le personnaliser ; seul un Administrateur peut modifier/
 * supprimer un message système (Id_Messagerie 1 à NB_MESSAGES_SYSTEME ou
 * Id_Utilisateur NULL) et voit tous les messages personnels de tous les
 * nominateurs — vérifié individuellement dans chaque méthode, comme le fait
 * messagerie.php.
 *
 * Pas de Model : lecture dynamique de l'ENUM `messagerie.Type` en base, jointure
 * conditionnelle Utilisateur, autorisation par ligne trop éloignées du Query
 * Builder simple — réutilise getPDO() directement, comme le fichier legacy.
 */
class MessagerieController extends BaseController
{
    private const NB_MESSAGES_SYSTEME = 6;

    /** Message destiné aux correspondants de club (Réengagements) — seul message visible/éditable par le rôle CSR, voir isCsr(). */
    private const ID_MESSAGE_CSR = 6;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Auto-migration (comme DesiderataClubController pour salle.Cp) : colonne
        // Cc ajoutée en base de dev sans passer par initTableConfiguration() —
        // se crée seule ici au premier chargement de l'écran en production.
        $pdo  = getPDO();
        $cols = array_column($pdo->query('SHOW COLUMNS FROM messagerie')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
        if (!in_array('Cc', $cols, true)) {
            $pdo->exec('ALTER TABLE messagerie ADD COLUMN Cc TINYINT(1) NOT NULL DEFAULT 0 AFTER Id_Utilisateur');
        }

        // Idem pour ReplyTo (Reply-To = email du nominateur courant) — ajoutée en fin de
        // table, utilisée par NominationController::envoyerConvocations() (EN14) et
        // CentrenvoyeController::envoyerUn() (EN15). Activée par défaut uniquement sur le
        // message système n°3 "Convocation" (NominationController::ID_MESSAGE_CONVOCATION).
        if (!in_array('ReplyTo', $cols, true)) {
            $pdo->exec('ALTER TABLE messagerie ADD COLUMN ReplyTo TINYINT(1) NOT NULL DEFAULT 0');
            $pdo->exec('UPDATE messagerie SET ReplyTo = 1 WHERE Id_Messagerie = 3');
        }

        // Gabarit système du rappel d'expiration API FFTT (voir config/app_config.php) — créé ici
        // pour qu'il soit visible/éditable dès l'ouverture de cet écran, avant même que la fenêtre
        // des 60 jours ne déclenche l'envoi réel à la connexion admin (AuthController::index()).
        assurerTemplateExpirationFfttApi($pdo);
        assurerTemplateDispoRegionale($pdo);
    }

    private function isAdmin(): bool
    {
        return !empty($_SESSION['utilisateur']['is_admin']);
    }

    /** Rôle CSR (Commission Sportive Régionale) — ne voit/modifie que le message n°6 (Réengagements), déjà utilisé par EN12 pour les correspondants de club. */
    private function isCsr(): bool
    {
        return ($_SESSION['utilisateur']['role'] ?? '') === 'CSR';
    }

    private function idCurrentUser(): int
    {
        return (int) ($_SESSION['utilisateur']['id'] ?? 0);
    }

    private function typesValides(\PDO $pdo): array
    {
        $col   = $pdo->query("SHOW COLUMNS FROM messagerie WHERE Field = 'Type'")->fetch();
        $types = [];
        if ($col && preg_match("/^enum\((.+)\)$/i", $col['Type'], $m)) {
            foreach (str_getcsv($m[1], ',', "'") as $v) {
                $types[] = trim($v);
            }
        }

        return $types;
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];
        $pdo = getPDO();

        $data = [
            'nomComplet'    => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'   => $moi['id_departement'] ?? '',
            'changeLogin'   => !empty($moi['change_login']),
            'isAdmin'       => $this->isAdmin(),
            'isCsr'         => $this->isCsr(),
            'idCurrentUser' => $this->idCurrentUser(),
            'enumTypes'     => $this->typesValides($pdo),
        ];

        return view('messagerie_index', $data);
    }

    public function data(): ResponseInterface
    {
        $isAdmin       = $this->isAdmin();
        $idCurrentUser = $this->idCurrentUser();
        $pdo           = getPDO();
        $nbSys         = self::NB_MESSAGES_SYSTEME;

        if ($this->isCsr()) {
            $stmt = $pdo->prepare(
                'SELECT Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo, NULL AS NomUtilisateur, 1 AS EstSysteme
                 FROM messagerie WHERE Id_Messagerie = ?'
            );
            $stmt->execute([self::ID_MESSAGE_CSR]);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        if ($isAdmin) {
            $rows = $pdo->query(
                "SELECT m.Id_Messagerie, m.Type, m.Sujet, m.Message, m.Id_Utilisateur, m.Cc, m.ReplyTo,
                        CONCAT(u.Nom, ' ', u.Prenom) AS NomUtilisateur,
                        (m.Id_Messagerie BETWEEN 1 AND $nbSys) AS EstSysteme
                 FROM messagerie m
                 LEFT JOIN Utilisateur u ON u.Id_Utilisateur = m.Id_Utilisateur
                 ORDER BY (m.Id_Messagerie BETWEEN 1 AND $nbSys) DESC, m.Id_Messagerie * (m.Id_Messagerie BETWEEN 1 AND $nbSys), m.Type, m.Sujet"
            )->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo, NULL AS NomUtilisateur,
                        (Id_Messagerie BETWEEN 1 AND $nbSys) AS EstSysteme
                 FROM messagerie
                 WHERE Id_Messagerie BETWEEN 1 AND $nbSys OR Id_Utilisateur IS NULL OR Id_Utilisateur = ?
                 ORDER BY (Id_Messagerie BETWEEN 1 AND $nbSys) DESC, Id_Messagerie * (Id_Messagerie BETWEEN 1 AND $nbSys), Type, Sujet"
            );
            $stmt->execute([$idCurrentUser]);
            $rows = $stmt->fetchAll();
        }

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($id = null): ResponseInterface
    {
        $id   = (int) $id;
        $stmt = getPDO()->prepare('SELECT Id_Messagerie, Type, Sujet, Message, Cc, ReplyTo, Id_Utilisateur FROM messagerie WHERE Id_Messagerie = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if ($row && $this->isCsr() && $id !== self::ID_MESSAGE_CSR) {
            $row = false; // le rôle CSR ne voit que le message n°6 (Réengagements)
        } elseif ($row && !$this->isAdmin() && !$this->isCsr()) {
            // Même restriction que data() : un nominateur ne voit que les messages
            // système et les siens, jamais le message personnel d'un autre nominateur.
            $estSysteme = $id >= 1 && $id <= self::NB_MESSAGES_SYSTEME;
            $estAMoi    = $row['Id_Utilisateur'] === null || (int) $row['Id_Utilisateur'] === $this->idCurrentUser();
            if (!$estSysteme && !$estAMoi) {
                $row = false;
            }
        }

        if ($row) {
            unset($row['Id_Utilisateur']);
        }

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {
        if ($this->isCsr()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le rôle CSR ne peut pas créer de nouveau message.']);
        }

        $pdo    = getPDO();
        $fields = $this->extractFields($this->request->getPost(), $pdo);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $idUser = $this->isAdmin() ? null : $this->idCurrentUser();
        $stmt   = $pdo->prepare('INSERT INTO messagerie (Id_Utilisateur, Type, Sujet, Message, Cc, ReplyTo) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$idUser, $fields['type'], $fields['sujet'], $fields['message'], $fields['cc'], $fields['replyto']]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message créé.', 'id' => (int) $pdo->lastInsertId()]);
    }

    public function update($id = null): ResponseInterface
    {

        $id  = (int) $id;
        $pdo = getPDO();

        $row = $pdo->prepare('SELECT Id_Utilisateur, Type FROM messagerie WHERE Id_Messagerie = ?');
        $row->execute([$id]);
        $existing = $row->fetch();

        // Le rôle CSR ne modifie que le champ Message du message n°6 (Réengagements) — Type/Sujet/Cc
        // restent figés côté serveur quel que soit le contenu envoyé par le client.
        if ($this->isCsr()) {
            if (!$existing || $id !== self::ID_MESSAGE_CSR) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Introuvable.']);
            }
            $message = trim($this->request->getRawInput()['message'] ?? '');
            if ($message === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le message ne peut pas être vide.']);
            }
            $pdo->prepare('UPDATE messagerie SET Message=? WHERE Id_Messagerie=?')->execute([$message, $id]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Message mis à jour.', 'id' => $id]);
        }

        $fields = $this->extractFields($this->request->getRawInput(), $pdo);
        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $isAdmin = $this->isAdmin();
        if ($existing && ($existing['Id_Utilisateur'] === null || ($id >= 1 && $id <= self::NB_MESSAGES_SYSTEME)) && !$isAdmin) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ce message système ne peut être modifié que par un administrateur.']);
        }
        if ($existing && $existing['Id_Utilisateur'] !== null && !$isAdmin && (int) $existing['Id_Utilisateur'] !== $this->idCurrentUser()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez modifier que vos propres messages.']);
        }

        $stmt = $pdo->prepare('UPDATE messagerie SET Type=?, Sujet=?, Message=?, Cc=?, ReplyTo=? WHERE Id_Messagerie=?');
        $stmt->execute([$fields['type'], $fields['sujet'], $fields['message'], $fields['cc'], $fields['replyto'], $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message mis à jour.', 'id' => $id]);
    }

    public function duplicate($id = null): ResponseInterface
    {
        if ($this->isCsr()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le rôle CSR ne peut pas dupliquer de message.']);
        }

        $id  = (int) $id;
        $pdo = getPDO();

        $src = $pdo->prepare('SELECT Type, Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Id_Messagerie = ?');
        $src->execute([$id]);
        $orig = $src->fetch();

        if (!$orig) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Message introuvable.']);
        }

        $stmt = $pdo->prepare('INSERT INTO messagerie (Id_Utilisateur, Type, Sujet, Message, Cc, ReplyTo) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$this->idCurrentUser(), $orig['Type'], $orig['Sujet'], $orig['Message'], $orig['Cc'], $orig['ReplyTo']]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message copié. Vous pouvez maintenant le personnaliser.', 'id' => (int) $pdo->lastInsertId()]);
    }

    public function delete($id = null): ResponseInterface
    {
        if ($this->isCsr()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le rôle CSR ne peut pas supprimer de message.']);
        }

        $id  = (int) $id;
        $pdo = getPDO();

        $row = $pdo->prepare('SELECT Id_Utilisateur FROM messagerie WHERE Id_Messagerie = ?');
        $row->execute([$id]);
        $existing = $row->fetch();

        $isAdmin = $this->isAdmin();
        if ($existing && ($existing['Id_Utilisateur'] === null || ($id >= 1 && $id <= self::NB_MESSAGES_SYSTEME)) && !$isAdmin) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Les messages système ne peuvent pas être supprimés.']);
        }
        if ($existing && $existing['Id_Utilisateur'] !== null && !$isAdmin && (int) $existing['Id_Utilisateur'] !== $this->idCurrentUser()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez supprimer que vos propres messages.']);
        }

        $pdo->prepare('DELETE FROM messagerie WHERE Id_Messagerie = ?')->execute([$id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message supprimé.']);
    }

    /**
     * Extrait et valide type/sujet/message. Retourne un message d'erreur
     * (string) en cas de validation échouée, sinon le tableau de champs —
     * mêmes règles que messagerie.php legacy (Type validé contre l'ENUM lu
     * dynamiquement en base).
     */
    private function extractFields(array $input, \PDO $pdo)
    {
        $type    = trim($input['type']    ?? '');
        $sujet   = trim($input['sujet']   ?? '');
        $message = trim($input['message'] ?? '');

        if ($sujet === '') {
            return 'Le sujet ne peut pas être vide.';
        }
        if ($message === '') {
            return 'Le message ne peut pas être vide.';
        }
        if (!in_array($type, $this->typesValides($pdo), true)) {
            return 'Type invalide.';
        }

        $cc      = (($input['cc'] ?? '0') === '1') ? 1 : 0;
        $replyto = (($input['replyto'] ?? '0') === '1') ? 1 : 0;

        return ['type' => $type, 'sujet' => $sujet, 'message' => $message, 'cc' => $cc, 'replyto' => $replyto];
    }
}
