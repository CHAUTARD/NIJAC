<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des messages (E026), portage CI4 de Nominateur/messagerie.php.
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

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/csrf.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function isAdmin(): bool
    {
        return !empty($_SESSION['utilisateur']['is_admin']);
    }

    /**
     * Réplique à l'identique messagerie.php legacy : la session ne contient
     * pas de clé 'id_utilisateur' (seulement 'id', voir CLAUDE.md), donc cette
     * valeur vaut toujours 0 côté legacy. Conservé tel quel pour un portage
     * fidèle — voir la tâche de fond ouverte pour corriger ce bug d'origine.
     */
    private function idCurrentUser(): int
    {
        return (int) ($_SESSION['utilisateur']['id_utilisateur'] ?? 0);
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
            'csrfToken'     => csrfToken(),
            'isAdmin'       => $this->isAdmin(),
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

        if ($isAdmin) {
            $rows = $pdo->query(
                "SELECT m.Id_Messagerie, m.Type, m.Sujet, m.Message, m.Id_Utilisateur,
                        CONCAT(u.Nom, ' ', u.Prenom) AS NomUtilisateur,
                        (m.Id_Messagerie BETWEEN 1 AND $nbSys) AS EstSysteme
                 FROM messagerie m
                 LEFT JOIN Utilisateur u ON u.Id_Utilisateur = m.Id_Utilisateur
                 ORDER BY (m.Id_Messagerie BETWEEN 1 AND $nbSys) DESC, m.Id_Messagerie * (m.Id_Messagerie BETWEEN 1 AND $nbSys), m.Type, m.Sujet"
            )->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, NULL AS NomUtilisateur,
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
        $stmt = getPDO()->prepare('SELECT Id_Messagerie, Type, Sujet, Message FROM messagerie WHERE Id_Messagerie = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    public function store(): ResponseInterface
    {
        csrfVerify(true);

        $pdo    = getPDO();
        $fields = $this->extractFields($this->request->getPost(), $pdo);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $idUser = $this->isAdmin() ? null : $this->idCurrentUser();
        $stmt   = $pdo->prepare('INSERT INTO messagerie (Id_Utilisateur, Type, Sujet, Message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$idUser, $fields['type'], $fields['sujet'], $fields['message']]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message créé.', 'id' => (int) $pdo->lastInsertId()]);
    }

    public function update($id = null): ResponseInterface
    {
        csrfVerify(true);

        $id     = (int) $id;
        $pdo    = getPDO();
        $fields = $this->extractFields($this->request->getRawInput(), $pdo);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $row = $pdo->prepare('SELECT Id_Utilisateur FROM messagerie WHERE Id_Messagerie = ?');
        $row->execute([$id]);
        $existing = $row->fetch();

        $isAdmin = $this->isAdmin();
        if ($existing && ($existing['Id_Utilisateur'] === null || ($id >= 1 && $id <= self::NB_MESSAGES_SYSTEME)) && !$isAdmin) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ce message système ne peut être modifié que par un administrateur.']);
        }
        if ($existing && $existing['Id_Utilisateur'] !== null && !$isAdmin && (int) $existing['Id_Utilisateur'] !== $this->idCurrentUser()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez modifier que vos propres messages.']);
        }

        $stmt = $pdo->prepare('UPDATE messagerie SET Type=?, Sujet=?, Message=? WHERE Id_Messagerie=?');
        $stmt->execute([$fields['type'], $fields['sujet'], $fields['message'], $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message mis à jour.', 'id' => $id]);
    }

    public function duplicate($id = null): ResponseInterface
    {
        csrfVerify(true);

        $id  = (int) $id;
        $pdo = getPDO();

        $src = $pdo->prepare('SELECT Type, Sujet, Message FROM messagerie WHERE Id_Messagerie = ?');
        $src->execute([$id]);
        $orig = $src->fetch();

        if (!$orig) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Message introuvable.']);
        }

        $stmt = $pdo->prepare('INSERT INTO messagerie (Id_Utilisateur, Type, Sujet, Message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$this->idCurrentUser(), $orig['Type'], $orig['Sujet'], $orig['Message']]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Message copié. Vous pouvez maintenant le personnaliser.', 'id' => (int) $pdo->lastInsertId()]);
    }

    public function delete($id = null): ResponseInterface
    {
        csrfVerify(true);

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

        return ['type' => $type, 'sujet' => $sujet, 'message' => $message];
    }
}
