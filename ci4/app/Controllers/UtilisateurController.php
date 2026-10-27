<?php

namespace App\Controllers;

use App\Models\UtilisateurModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des utilisateurs (EA86), portage CI4 de utilisateur.php.
 */
class UtilisateurController extends BaseController
{
    protected UtilisateurModel $utilisateurModel;

    /**
     * Login du compte réservé (accès EA96 / EA97 / EA98, contrôle `login === 'CHAUTARD'`).
     * Ce compte ne peut être modifié ni supprimé que par lui-même, son login ne peut pas
     * changer et ce login ne peut être attribué à aucun autre compte (création ou renommage).
     */
    private const LOGIN_RESERVE = 'CHAUTARD';

    /** Refus d'un jeu de rôles mêlant JA et un autre rôle (rôle JA exclusif, non cumulable). */
    private const MSG_JA_EXCLUSIF = 'Le rôle JA est exclusif : un compte JA n\'a aucun autre rôle, et un compte d\'un autre rôle ne peut pas recevoir le rôle JA.';

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
        $this->utilisateurModel = new UtilisateurModel();
    }

    /** Colonnes lues pour la liste / la fiche (les rôles sont dans utilisateur_role). */
    private function colonnesLecture(): string
    {
        return 'Id_Utilisateur, Login, Nom, Prenom, Id_Departement, Actif, ChangeLogin, Email';
    }

    /**
     * Normalise une liste de rôles renvoyée par rolesUtilisateur() / rolesValides() en liste de codes
     * (chaînes) : éléments tableaux (clé Role / Code), clés texte, ou valeurs texte.
     */
    private function codesRoles(array $liste): array
    {
        $codes = [];
        foreach ($liste as $k => $v) {
            if (is_array($v)) {
                $code = (string) ($v['Role'] ?? $v['Code'] ?? $v['code'] ?? '');
            } elseif (is_string($k)) {
                $code = $k;
            } else {
                $code = (string) $v;
            }
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** Rôles possibles (table role) : [Code => Libellé], pour les cases à cocher d'EA86. */
    private function rolesDisponibles(): array
    {
        // Valeur = code (rolesValides()), affichage = libellé (libellesRoles(), repli : le code).
        $pdo      = getPDO();
        $libelles = libellesRoles($pdo);
        $liste    = [];
        foreach (rolesValides($pdo) as $code) {
            $code = (string) $code;
            if ($code !== '') {
                $liste[$code] = (string) ($libelles[$code] ?? $code);
            }
        }

        return $liste;
    }

    /** Codes des rôles actuels d'un compte (utilisateur_role). */
    private function rolesDuCompte(int $id): array
    {
        return $this->codesRoles(rolesUtilisateur(getPDO(), $id));
    }

    /**
     * Rôles cochés (`roles[]`) + rôle principal (`principal`) : au moins un rôle, tous ⊂ rolesValides(),
     * principal ⊂ cochés, JA jamais combiné à un autre rôle (MSG_JA_EXCLUSIF). $principalForce impose le
     * principal (compte JA créé : JA).
     * Retourne [roles, principal] ou un message d'erreur.
     */
    private function extraireRoles(array $input, ?string $principalForce = null)
    {
        $brut  = $input['roles'] ?? [];
        $brut  = is_array($brut) ? $brut : [$brut];
        $roles = [];
        foreach ($brut as $r) {
            if (is_scalar($r) && ($r = trim((string) $r)) !== '' && !in_array($r, $roles, true)) {
                $roles[] = $r;
            }
        }
        if (!$roles) {
            return 'Cochez au moins un rôle.';
        }
        // Rôle JA exclusif : un compte JA n'a aucun autre rôle (convention Id_Utilisateur = Id_JA).
        if (in_array('JA', $roles, true) && count($roles) > 1) {
            return self::MSG_JA_EXCLUSIF;
        }
        if (count($roles) > 1 && !tableRolesExiste(getPDO())) {
            return 'Plusieurs rôles nécessitent la table utilisateur_role : chargez EA98.';
        }
        $valides = array_keys($this->rolesDisponibles());
        foreach ($roles as $r) {
            if (!in_array($r, $valides, true)) {
                return 'Rôle invalide.';
            }
        }
        $principal = $principalForce ?? trim((string) (is_scalar($input['principal'] ?? null) ? $input['principal'] : ''));
        if (!in_array($principal, $roles, true)) {
            return 'Le rôle principal doit faire partie des rôles cochés.';
        }

        return [$roles, $principal];
    }

    /**
     * GET utilisateur/fiches-ja?q=… : fiches ja pour la création d'un compte de rôle JA (EA86).
     * Recherche par n° de fiche (préfixe) ou nom / prénom ; JA1 actifs d'abord ; 20 résultats max.
     * `compte_login` / `compte_role` (rôles, principal en tête, séparés par des virgules) = compte qui porte
     * déjà l'Id_Utilisateur = n° de fiche (convention des comptes JA), null sinon — la validation serveur
     * (creerCompteJaEa86() / etatCompteJa()) refuse de toute façon ce cas.
     */
    public function fichesJa(): ResponseInterface
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }
        $pdo    = getPDO();
        $like   = '%' . addcslashes($q, '%_\\') . '%';
        $prefix = addcslashes($q, '%_\\') . '%';
        // Avant EA98 (utilisateur_role absente) : repli sur l'ancienne colonne utilisateur.Role.
        if (tableRolesExiste($pdo)) {
            $compteRole = '(SELECT GROUP_CONCAT(ur.Role ORDER BY ur.estPrincipal DESC, ur.Role SEPARATOR \',\')
                      FROM utilisateur_role ur WHERE ur.Id_Utilisateur = u.Id_Utilisateur)';
        } else {
            $compteRole = utilisateurAColonneRole($pdo) ? 'u.Role' : 'NULL';
        }
        $st = $pdo->prepare('
            SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.JA1, ja.Email,
                   u.Login AS compte_login,
                   ' . $compteRole . ' AS compte_role
            FROM ja
            LEFT JOIN utilisateur u ON u.Id_Utilisateur = ja.Id_JA
            WHERE CAST(ja.Id_JA AS CHAR) LIKE ? OR ja.Nom LIKE ? OR ja.Prenom LIKE ?
               OR CONCAT(ja.Nom, \' \', ja.Prenom) LIKE ? OR CONCAT(ja.Prenom, \' \', ja.Nom) LIKE ?
            ORDER BY ja.JA1 DESC, ja.Nom, ja.Prenom
            LIMIT 20
        ');
        $st->execute([$prefix, $like, $like, $like, $like]);

        return $this->response->setJSON(['ok' => true, 'data' => $st->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    /** Libellé « licence — Nom Prénom » d'une fiche ja (null si introuvable). */
    private function libelleFicheJa(?int $idJa): ?string
    {
        if (!$idJa) {
            return null;
        }
        $st = getPDO()->prepare('SELECT Id_JA, Nom, Prenom FROM ja WHERE Id_JA = ?');
        $st->execute([$idJa]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);

        return $r ? $r['Id_JA'] . ' — ' . trim($r['Nom'] . ' ' . $r['Prenom']) : null;
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'moiId'       => (int) ($moi['id'] ?? 0),
            'deptActifs'  => getDeptActifs(),
            'roles'       => $this->rolesDisponibles(), // [Code => Libellé]
        ];

        return view('utilisateur_index', $data);
    }

    /**
     * Liste EA86 : `Roles` = codes séparés par des virgules, principal en tête (GROUP_CONCAT),
     * `RolePrincipal` = rôle principal (estPrincipal = 1). Agrégat en sous-requête : pas de
     * GROUP BY sur les colonnes d'utilisateur (ONLY_FULL_GROUP_BY de MariaDB).
     */
    public function data(): ResponseInterface
    {
        $pdo     = getPDO();
        $colonnes = 'u.Id_Utilisateur, u.Login, u.Nom, u.Prenom, u.Id_Departement, u.Actif, u.ChangeLogin, u.Email';
        if (tableRolesExiste($pdo)) {
            $sql = '
            SELECT ' . $colonnes . ', r.Roles, r.RolePrincipal
            FROM utilisateur u
            LEFT JOIN (
                SELECT Id_Utilisateur,
                       GROUP_CONCAT(Role ORDER BY estPrincipal DESC, Role SEPARATOR \',\') AS Roles,
                       MAX(CASE WHEN estPrincipal = 1 THEN Role END) AS RolePrincipal
                FROM utilisateur_role
                GROUP BY Id_Utilisateur
            ) r ON r.Id_Utilisateur = u.Id_Utilisateur
            ORDER BY u.Nom, u.Prenom';
        } else {
            // Avant EA98 (utilisateur_role absente) : repli sur l'ancienne colonne utilisateur.Role.
            $role = utilisateurAColonneRole($pdo) ? 'u.Role' : 'NULL';
            $sql  = 'SELECT ' . $colonnes . ', ' . $role . ' AS Roles, ' . $role . ' AS RolePrincipal
                     FROM utilisateur u ORDER BY u.Nom, u.Prenom';
        }
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function show($id = null): ResponseInterface
    {
        $id  = (int) $id;
        $row = $this->utilisateurModel
            ->select($this->colonnesLecture())
            ->find($id);
        if ($row) {
            $row['Roles']         = $this->rolesDuCompte($id);
            $row['RolePrincipal'] = rolePrincipalUtilisateur(getPDO(), $id);
            // Compte JA : sa fiche est celle de même Id (convention Id_Utilisateur = ja.Id_JA).
            $row['FicheJa'] = in_array('JA', $row['Roles'], true) ? $this->libelleFicheJa($id) : null;
            // Compte hérité cumulant JA et d'autres rôles (antérieur à la règle JA exclusif) : à régulariser.
            $row['JaCumule'] = in_array('JA', $row['Roles'], true) && count($row['Roles']) > 1;
        }

        return $this->response->setJSON(
            $row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']
        );
    }

    /** INSERT / UPDATE d'utilisateur (colonnes = clés internes de extractFields(), jamais du client). */
    private function ecrireUtilisateur(\PDO $pdo, array $fields, int $id = 0): int
    {
        $cols = array_keys($fields);
        if ($id > 0) {
            $pdo->prepare('UPDATE utilisateur SET ' . implode(', ', array_map(static fn ($c) => $c . ' = ?', $cols))
                . ' WHERE Id_Utilisateur = ?')
                ->execute([...array_values($fields), $id]);

            return $id;
        }
        $pdo->prepare('INSERT INTO utilisateur (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($fields));

        return (int) $pdo->lastInsertId();
    }

    public function store(): ResponseInterface
    {
        $input = $this->request->getPost();
        $roles = $input['roles'] ?? [];

        // Rôle JA coché : convention Id_Utilisateur = Id_JA, Login = Email, JA seul (exclusif, creerCompteJaEa86()).
        if (is_array($roles) ? in_array('JA', $roles, true) : $roles === 'JA') {
            return $this->creerCompteJaEa86($input);
        }

        $mdpGenere = null;
        $roles     = [];
        $principal = '';
        $fields    = $this->extractFields($input, true, $mdpGenere, 0, $roles, $principal);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        $pdo = getPDO();
        try {
            $pdo->beginTransaction();
            $id = $this->ecrireUtilisateur($pdo, $fields);
            enregistrerRolesUtilisateur($pdo, $id, $roles, $principal);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EA86 création utilisateur : ' . $e->getMessage());
            $msg = ($e instanceof \RuntimeException && !($e instanceof \PDOException))
                ? $e->getMessage()
                : 'Création impossible (erreur base de données).';

            return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur créé.', 'id' => $id, 'mdp' => $mdpGenere]);
    }

    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;

        // Compte réservé : seul son titulaire peut le modifier (Login lu en base, jamais du client).
        $refus = $this->refusCompteReserve($id);
        if ($refus !== null) {
            return $refus;
        }

        $pdo   = getPDO();
        $input = $this->request->getRawInput();
        $st    = $pdo->prepare('SELECT Login, Nom, Prenom, Email, Actif, Id_Departement FROM utilisateur WHERE Id_Utilisateur = ?');
        $st->execute([$id]);
        $existant = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$existant) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Utilisateur introuvable.']);
        }

        // Rôle JA exclusif. Compte JA (JA seul) : seul l'email (= login) est modifiable (majEmailCompteJa()).
        // Compte hérité cumulant JA et d'autres rôles (avant la règle) : conservé tel quel en base, et
        // régularisable ici — soit JA seul (passe alors par majEmailCompteJa()), soit sans JA.
        $rolesActuels = $this->rolesDuCompte($id);
        $estJa        = $rolesActuels === ['JA'];
        $mixte        = !$estJa && in_array('JA', $rolesActuels, true);

        $r = $this->extraireRoles($input);
        if (is_string($r)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $r]);
        }
        if ($id === (int) ($_SESSION['utilisateur']['id'] ?? 0)
            && in_array('Administrateur', $rolesActuels, true) && !in_array('Administrateur', $r[0], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas retirer votre propre rôle Administrateur.']);
        }
        if ($estJa || ($mixte && $r[0] === ['JA'])) {
            return $this->majEmailCompteJa($id, $input, $existant, $r[0], $mixte);
        }

        // Un compte non-JA ne GAGNE jamais le rôle JA : son Id ne vaudrait pas celui de la fiche
        // (convention Id_Utilisateur = Id_JA) — créer le compte JA avec « Nouveau » ou depuis EN15.
        if (in_array('JA', $r[0], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Un compte existant ne peut pas recevoir le rôle JA : créez le compte JA avec « Nouveau » (identifiant = n° de fiche JA) ou depuis l\'onglet « Invitation JA » d\'EN15.']);
        }

        $mdpGenere = null;
        $roles     = [];
        $principal = '';
        $fields    = $this->extractFields($input, false, $mdpGenere, $id, $roles, $principal);

        if (is_string($fields)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $fields]);
        }

        // Le login du compte réservé ne change jamais (sinon l'accès EA96/EA97/EA98 serait perdu
        // et le login réservé redeviendrait attribuable à un autre compte).
        if ($this->estLoginReserve((string) $existant['Login'])
            && (string) $fields['Login'] !== (string) $existant['Login']) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le login de ce compte ne peut pas être modifié.']);
        }

        // Garde-fou anti-verrouillage : on ne se désactive pas soi-même et on ne se retire pas son propre
        // rôle Administrateur (le dernier administrateur perdrait tout accès aux écrans EA).
        if ($id === (int) ($_SESSION['utilisateur']['id'] ?? 0)) {
            if ((int) $fields['Actif'] !== 1) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas désactiver votre propre compte.']);
            }
            if (in_array('Administrateur', $rolesActuels, true) && !in_array('Administrateur', $roles, true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas retirer votre propre rôle Administrateur.']);
            }
        }

        try {
            $pdo->beginTransaction();
            $this->ecrireUtilisateur($pdo, $fields, $id);
            enregistrerRolesUtilisateur($pdo, $id, $roles, $principal);
            // « Écraser » : nouveau mot de passe → PwdVersion + 1 (si présente, EA98), les sessions
            // ouvertes sur ce compte sont détruites à leur prochaine revalidation (1 fois / minute).
            // Désactivation, changement de rôles : la revalidation (revaliderSessionUtilisateur()) s'en charge.
            if (isset($fields['Password']) && utilisateurAPwdVersion($pdo)) {
                $pdo->prepare('UPDATE utilisateur SET PwdVersion = PwdVersion + 1 WHERE Id_Utilisateur = ?')->execute([$id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EA86 mise à jour utilisateur ' . $id . ' : ' . $e->getMessage());
            $msg = ($e instanceof \RuntimeException && !($e instanceof \PDOException))
                ? $e->getMessage()
                : 'Mise à jour impossible (erreur base de données).';

            return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
        }
        if ($mixte) {
            error_log('[NIJAC] EA86 : compte ' . $id . ' (JA cumulé avec d\'autres rôles, antérieur à la règle JA exclusif) régularisé : rôle JA retiré');
        }

        // Modification de son propre compte : le profil de session (département, rôles) est
        // relu en base, sans reconnexion. Jamais touché pour un autre utilisateur.
        if ($id === (int) ($_SESSION['utilisateur']['id'] ?? 0)) {
            $this->rafraichirSessionPropre($id);
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur mis à jour.', 'id' => $id, 'mdp' => $mdpGenere]);
    }

    /**
     * Réécrit $_SESSION['utilisateur'] depuis la base (même format qu'à la connexion, voir
     * construireSessionUtilisateur()). Le filtre adminauth a déjà refermé la session : reprise
     * par un session_start() BRUT, pas demarrerSessionNijac() (voir ChangerMotDePasseController).
     * Aucun cache de périmètre en session ou fichier : getDepartementsAutorises() relit la
     * configuration à chaque appel, le nouveau département est donc vu dès la requête suivante.
     */
    private function rafraichirSessionPropre(int $id): void
    {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare(
                'SELECT ' . colonnesSessionUtilisateur($pdo) . '
                 FROM utilisateur WHERE Id_Utilisateur = ? AND Actif = 1 LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return; // compte introuvable/inactif : on laisse la session telle quelle
            }
            // Rôles relus par construireSessionUtilisateur() (utilisateur_role, repli utilisateur.Role).
            unset($row['Roles']);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['utilisateur'] = construireSessionUtilisateur($row, $pdo);
            session_write_close();
        } catch (\Throwable $e) {
            error_log('[NIJAC] EA86 : rafraîchissement de la session impossible : ' . $e->getMessage());
        }
    }

    public function delete($id = null): ResponseInterface
    {

        $id  = (int) $id;
        $moi = $_SESSION['utilisateur'] ?? [];

        if ($id === (int) ($moi['id'] ?? 0)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        // Compte réservé : jamais supprimé par un autre compte (et pas par lui-même, garde ci-dessus).
        $refus = $this->refusCompteReserve($id);
        if ($refus !== null) {
            return $refus;
        }

        $db = \Config\Database::connect();
        $db->table('Messagerie')->where('Id_Utilisateur', $id)->delete();
        if (tableRolesExiste(getPDO())) {
            $db->table('utilisateur_role')->where('Id_Utilisateur', $id)->delete();
        }

        $this->utilisateurModel->delete($id);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Utilisateur supprimé.']);
    }

    /** Le login donné est-il le login réservé ? (trim, insensible à la casse) */
    private function estLoginReserve(string $login): bool
    {
        return strcasecmp(trim($login), self::LOGIN_RESERVE) === 0;
    }

    /**
     * Refus si le compte $id porte en base le login réservé et que l'utilisateur connecté n'est
     * pas ce compte (Id de session). Login relu en base, jamais pris du client. null = autorisé.
     */
    private function refusCompteReserve(int $id): ?ResponseInterface
    {
        $row = $this->utilisateurModel->select('Login')->find($id);
        if ($row && $this->estLoginReserve((string) $row['Login'])
            && $id !== (int) ($_SESSION['utilisateur']['id'] ?? 0)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ce compte est réservé.']);
        }

        return null;
    }

    /**
     * Création d'un compte de rôle JA depuis EA86 (« Nouveau » + rôle JA + fiche) — mêmes règles que
     * l'invitation EN15 (etatCompteJa() / creerCompteJa()) : Id_Utilisateur = n° de la fiche choisie
     * (INSERT avec Id explicite, seul lien compte ↔ fiche), Login = Email = adresse saisie, Prenom vide, Nom = nom de la fiche, Actif 1,
     * ChangeLogin 1, département = celui de la fiche (sinon celui choisi). Login / nom / prénom saisis
     * sont ignorés. Refus (motif) si l'Id est déjà pris par un autre compte (jamais réattribué), si la
     * fiche a déjà un compte, si l'adresse est invalide ou déjà utilisée. Mot de passe provisoire
     * renvoyé à l'administrateur comme pour les autres créations EA86 (aucun email envoyé ici).
     */
    private function creerCompteJaEa86(array $input): ResponseInterface
    {
        $pdo   = getPDO();
        $idJa  = (int) ($input['id_ja'] ?? 0);
        $email = trim((string) ($input['email'] ?? ''));

        if ($idJa <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'La fiche juge-arbitre est obligatoire pour le rôle JA.']);
        }
        $st = $pdo->prepare('SELECT Nom, CodeDept FROM ja WHERE Id_JA = ?');
        $st->execute([$idJa]);
        $fiche = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$fiche) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fiche juge-arbitre introuvable.']);
        }
        if ($email === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => "L'adresse email est obligatoire (elle sert de login au compte JA)."]);
        }

        // Rôles cochés : JA seul (exclusif, refus MSG_JA_EXCLUSIF sinon), donc principal.
        $r = $this->extraireRoles($input, 'JA');
        if (is_string($r)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $r]);
        }
        [$roles, $principal] = $r;

        $etat = etatCompteJa($pdo, $idJa, $email);
        if ($etat['statut'] !== 'nouveau') {
            $motif = $etat['statut'] === 'existant'
                ? 'compte déjà existant (login « ' . $etat['compte']['Login'] . ' ») — régénération du mot de passe depuis EN15'
                : $etat['motif'];

            return $this->response->setJSON(['ok' => false, 'msg' => 'Compte JA non créé : ' . $motif . '.']);
        }
        $dept = departementCompteJa($pdo, (string) $fiche['CodeDept'], (string) ($input['dept'] ?? ''));
        if ($dept === null) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le département est obligatoire.']);
        }

        $mdpGenere = genererMotDePasseAleatoire();
        try {
            $pdo->beginTransaction();
            creerCompteJa($pdo, $idJa, $email, (string) $fiche['Nom'], $dept, \SecurePasswordHasher::hash($mdpGenere));
            enregistrerRolesUtilisateur($pdo, $idJa, $roles, $principal);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EA86 création compte JA (Id_JA ' . $idJa . ') : ' . $e->getMessage());
            $msg = ($e instanceof \RuntimeException && !($e instanceof \PDOException))
                ? $e->getMessage()
                : 'Création impossible (identifiant ou login déjà pris).';

            return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Compte JA créé (identifiant n° ' . $idJa . ').', 'id' => $idJa, 'mdp' => $mdpGenere]);
    }

    /**
     * Modification d'un compte JA (EA86, rôle JA seul — exclusif) : SEUL l'email est modifiable, le login
     * suit (Login = Email). Login, nom, prénom, rôles, département, statut Actif et « Écraser » sont
     * verrouillés : toute valeur reçue différente de la base est refusée (le client les désactive).
     * Unicité, insensible à la casse : la nouvelle adresse ne doit être ni le login d'un autre compte, ni
     * (si elle change) l'email d'un autre compte ; login réservé refusé. Changer l'adresse change le login :
     * les sessions ouvertes de ce JA sont fermées à la revalidation suivante (« login modifié »).
     * $regularisation : compte hérité cumulant JA et d'autres rôles, ramené ici à JA seul (fiche de même
     * Id obligatoire) — ses rôles sont alors réécrits (JA principal), journalisé.
     */
    private function majEmailCompteJa(int $id, array $input, array $existant, array $roles, bool $regularisation = false): ResponseInterface
    {
        $pdo   = getPDO();
        $email = trim((string) ($input['email'] ?? ''));
        $login = trim((string) ($input['login'] ?? ''));

        $verrouille = $roles !== ['JA']
            || trim((string) ($input['nom'] ?? '')) !== trim((string) $existant['Nom'])
            || trim((string) ($input['prenom'] ?? '')) !== trim((string) $existant['Prenom'])
            // Département : liste EA86 limitée aux départements actifs, champ vide toléré (= inchangé).
            || ((string) ($input['dept'] ?? '') !== '' && (int) $input['dept'] !== (int) $existant['Id_Departement'])
            || (($input['actif'] ?? '0') === '1' ? 1 : 0) !== (int) $existant['Actif']
            || ($input['ecraser'] ?? '0') === '1'
            || ($login !== '' && strcasecmp($login, (string) $existant['Login']) !== 0 && strcasecmp($login, $email) !== 0);
        if ($verrouille) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Compte JA : seule l\'adresse email (= login) est modifiable ; login, nom, prénom, rôle (JA exclusif), département, statut et mot de passe sont verrouillés (nouveau mot de passe : onglet « Invitation JA » d\'EN15).']);
        }
        if ($regularisation && !ficheJaExiste($pdo, $id)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune fiche juge-arbitre de même identifiant : ce compte ne peut pas devenir un compte JA, décochez le rôle JA.']);
        }

        if ($email === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => "L'adresse email est obligatoire (elle sert de login au compte JA)."]);
        }
        $max = min(150, longueurMaxLoginUtilisateur($pdo));
        if (mb_strlen($email) > $max || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Adresse email invalide (' . $max . ' caractères maximum, elle sert de login au compte JA).']);
        }
        if ($this->estLoginReserve($email)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Ce login est réservé.']);
        }
        $doublon = $pdo->prepare('SELECT 1 FROM utilisateur WHERE LOWER(Login) = LOWER(?) AND Id_Utilisateur <> ? LIMIT 1');
        $doublon->execute([$email, $id]);
        if ($doublon->fetchColumn()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Cette adresse est déjà le login d\'un autre compte.']);
        }
        if (strcasecmp($email, (string) $existant['Email']) !== 0) {
            $doublon = $pdo->prepare('SELECT 1 FROM utilisateur WHERE LOWER(Email) = LOWER(?) AND Id_Utilisateur <> ? LIMIT 1');
            $doublon->execute([$email, $id]);
            if ($doublon->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Cette adresse est déjà utilisée par un autre compte.']);
            }
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE utilisateur SET Login = ?, Email = ? WHERE Id_Utilisateur = ?')
                ->execute([$email, $email, $id]);
            if ($regularisation) {
                enregistrerRolesUtilisateur($pdo, $id, ['JA'], 'JA');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EA86 mise à jour compte JA ' . $id . ' : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Mise à jour impossible (erreur base de données).']);
        }
        if ($regularisation) {
            error_log('[NIJAC] EA86 : compte ' . $id . ' (JA cumulé avec d\'autres rôles, antérieur à la règle JA exclusif) régularisé : JA seul');
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Adresse email (et login) du compte JA mise à jour.', 'id' => $id, 'mdp' => null]);
    }

    /**
     * Extrait et valide les champs du formulaire. Retourne un message d'erreur
     * (string) en cas de validation échouée, sinon le tableau de champs prêt
     * pour ecrireUtilisateur() ; rôles cochés et principal validés (extraireRoles())
     * renvoyés dans $roles / $principal. Jamais appelé pour un compte JA (création : creerCompteJaEa86(),
     * modification : majEmailCompteJa()).
     *
     * Mot de passe : plus de saisie manuelle. À la création, ou si la case
     * « Écraser » est cochée, un mot de passe aléatoire est généré, le compte
     * passe en ChangeLogin = 1, et le mot de passe en clair est renvoyé via
     * $mdpGenere (pour affichage à l'admin). Sinon aucune clé Password/ChangeLogin
     * n'est écrite (valeurs BDD inchangées).
     */
    private function extractFields(array $input, bool $isNew, ?string &$mdpGenere = null, int $idCourant = 0,
                                   ?array &$roles = null, ?string &$principal = null)
    {
        $mdpGenere = null;

        $login   = trim((string) ($input['login'] ?? ''));
        $nom     = trim((string) ($input['nom'] ?? ''));
        $prenom  = trim((string) ($input['prenom'] ?? ''));
        $dept    = (int) ($input['dept'] ?? 0);
        $email   = trim((string) ($input['email'] ?? ''));
        $actif   = ($input['actif'] ?? '0') === '1' ? 1 : 0;
        $ecraser = ($input['ecraser'] ?? '0') === '1';

        $r = $this->extraireRoles($input);
        if (is_string($r)) {
            return $r;
        }
        [$roles, $principal] = $r;

        if ($login === '') {
            return 'Le login ne peut pas être vide.';
        }
        if (mb_strlen($login) > longueurMaxLoginUtilisateur(getPDO())) {
            return 'Le login ne doit pas dépasser ' . longueurMaxLoginUtilisateur(getPDO()) . ' caractères.';
        }
        // Login réservé : jamais attribué à un autre compte (création ou renommage). Le compte
        // qui le porte déjà en base le conserve (update() interdit d'en changer).
        if ($this->estLoginReserve($login)) {
            $actuel = $idCourant > 0 ? $this->utilisateurModel->select('Login')->find($idCourant) : null;
            if (!$actuel || !$this->estLoginReserve((string) $actuel['Login'])) {
                return 'Ce login est réservé.';
            }
        }
        $doublon = getPDO()->prepare('SELECT 1 FROM utilisateur WHERE Login = ? AND Id_Utilisateur <> ? LIMIT 1');
        $doublon->execute([$login, $idCourant]);
        if ($doublon->fetchColumn()) {
            return 'Ce login est déjà utilisé par un autre compte.';
        }
        // Obligatoire : le code de sécurité de connexion (E010) part à cette adresse.
        // Pas de contrôle d'unicité : des comptes partagent déjà une adresse en base.
        if ($email === '') {
            return "L'adresse email est obligatoire (envoi du code de sécurité de connexion).";
        }
        if (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse email invalide.';
        }
        if ($nom === '') {
            return 'Le nom ne peut pas être vide.';
        }
        if ($prenom === '') {
            return 'Le prénom ne peut pas être vide.';
        }
        if ($dept <= 0) {
            return 'Le département est obligatoire.';
        }

        $fields = [
            'Login'          => $login,
            'Email'          => $email,
            'Nom'            => $nom,
            'Prenom'         => $prenom,
            'Id_Departement' => $dept,
            'Actif'          => $actif,
        ];

        if ($isNew || $ecraser) {
            $mdpGenere              = genererMotDePasseAleatoire();
            $fields['Password']     = \SecurePasswordHasher::hash($mdpGenere);
            $fields['ChangeLogin']  = 1;
        }

        return $fields;
    }
}
