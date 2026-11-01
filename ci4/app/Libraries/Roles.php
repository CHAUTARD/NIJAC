<?php

namespace App\Libraries;

/**
 * Rôles multiples : table de référence `role` + liaison `utilisateur_role` (un utilisateur ↔ plusieurs
 * rôles). Détection du schéma (ancien / Id_Role), lecture des rôles d'un compte ou de la session,
 * conversion nom → Id_Role, fragments SQL (sqlARole()). La migration de ces tables reste dans
 * config/app_config.php (assurerRolesUtilisateurs(), EA98).
 *
 * Code déplacé tel quel depuis config/app_config.php : les fonctions globales du même nom (aRole(),
 * rolesUtilisateur(), schemaRoles()…) y délèguent, les constantes ROLES_REFERENCE et
 * LIBELLES_ROLES_OBSOLETES y restent. Les caches `static` sont ceux des méthodes.
 */
class Roles
{
    /**
     * La table utilisateur_role existe-t-elle ? (créée par EA98). Cache statique, $rafraichir = true après
     * création (assurerRolesUtilisateurs()).
     */
    public static function tableRolesExiste(\PDO $pdo, bool $rafraichir = false): bool
    {
        static $existe = null;
        if ($existe === null || $rafraichir) {
            try {
                $existe = (bool) $pdo->query(
                    "SELECT 1 FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'utilisateur_role'"
                )->fetchColumn();
            } catch (\PDOException $e) {
                $existe = false;
            }
        }

        return $existe;
    }

    /** L'ancienne colonne utilisateur.Role existe-t-elle encore ? (repli avant EA98). Cache statique. */
    public static function utilisateurAColonneRole(\PDO $pdo, bool $rafraichir = false): bool
    {
        static $existe = null;
        if ($existe === null || $rafraichir) {
            try {
                $existe = (bool) $pdo->query(
                    "SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'utilisateur' AND COLUMN_NAME = 'Role'"
                )->fetchColumn();
            } catch (\PDOException $e) {
                $existe = false;
            }
        }

        return $existe;
    }

    /**
     * Forme du schéma des rôles (cache statique, $rafraichir = true après migration) :
     *  - 'nom'  : colonne du nom du rôle dans `role` — 'Nom' (schéma Id_Role) ou 'Code' (ancien schéma) ;
     *  - 'roleId' / 'urIdCol' / 'urRole' : présence de role.Id_Role, utilisateur_role.Id_Role, utilisateur_role.Role ;
     *  - 'urId' : utilisateur_role référence role.Id_Role (colonne Id_Role présente ET ancienne colonne Role
     *             supprimée) ; false = ancien schéma (ou migration interrompue) : ur.Role = nom du rôle.
     * Le reste du code manipule toujours des NOMS de rôles ; seules les requêtes SQL dépendent de ce schéma.
     */
    public static function schemaRoles(\PDO $pdo, bool $rafraichir = false): array
    {
        static $schema = null;
        if ($schema === null || $rafraichir) {
            $schema = ['nom' => 'Code', 'roleId' => false, 'urIdCol' => false, 'urRole' => true, 'urId' => false];
            try {
                $cols = $pdo->query(
                    "SELECT LOWER(TABLE_NAME) AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN ('role', 'utilisateur_role')
                       AND COLUMN_NAME IN ('Nom', 'Code', 'Id_Role', 'Role')"
                )->fetchAll(\PDO::FETCH_ASSOC);
                $a = [];
                foreach ($cols as $c) {
                    $a[$c['t'] . '.' . $c['c']] = true;
                }
                $schema['nom']     = isset($a['role.Nom']) ? 'Nom' : 'Code';
                $schema['roleId']  = isset($a['role.Id_Role']);
                $schema['urIdCol'] = isset($a['utilisateur_role.Id_Role']);
                $schema['urRole']  = isset($a['utilisateur_role.Role']);
                $schema['urId']    = $schema['roleId'] && $schema['urIdCol'] && !$schema['urRole'];
            } catch (\PDOException $e) {
                error_log('[NIJAC] schemaRoles : ' . $e->getMessage());
            }
        }

        return $schema;
    }

    /**
     * Jointure de utilisateur_role (alias $ur) vers le nom du rôle : [clause JOIN, expression du nom].
     * Schéma Id_Role : ["JOIN role $r ON $r.Id_Role = $ur.Id_Role", "$r.Nom"] ; ancien schéma : ['', "$ur.Role"].
     * $ur / $r : alias SQL fixés par le code appelant (jamais une saisie).
     */
    public static function sqlNomRoleUr(\PDO $pdo, string $ur = 'ur', string $r = 'r'): array
    {
        $s = schemaRoles($pdo);
        if ($s['urId']) {
            return ["JOIN role $r ON $r.Id_Role = $ur.Id_Role", "$r.{$s['nom']}"];
        }

        return ['', "$ur.Role"];
    }

    /**
     * Rôles d'un utilisateur : rôle principal d'abord, puis par priorité (table role). Avant EA98
     * (utilisateur_role absente) : la colonne utilisateur.Role si elle existe encore, sinon aucun rôle.
     */
    public static function rolesUtilisateur(\PDO $pdo, int $idUtilisateur): array
    {
        if ($idUtilisateur <= 0) {
            return [];
        }
        if (tableRolesExiste($pdo)) {
            // Schéma Id_Role : nom lu dans role.Nom ; ancien schéma : ur.Role = nom (role.Code ou role.Nom).
            $s = schemaRoles($pdo);
            if ($s['urId']) {
                $sql = "SELECT r.{$s['nom']} FROM utilisateur_role ur
                        JOIN role r ON r.Id_Role = ur.Id_Role
                        WHERE ur.Id_Utilisateur = ?
                        ORDER BY ur.estPrincipal DESC, r.Priorite, r.{$s['nom']}";
            } else {
                $sql = "SELECT ur.Role FROM utilisateur_role ur
                        LEFT JOIN role r ON r.{$s['nom']} = ur.Role
                        WHERE ur.Id_Utilisateur = ?
                        ORDER BY ur.estPrincipal DESC, COALESCE(r.Priorite, 99), ur.Role";
            }
            $st = $pdo->prepare($sql);
            $st->execute([$idUtilisateur]);

            return array_values(array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        }
        if (utilisateurAColonneRole($pdo)) {
            $st = $pdo->prepare('SELECT Role FROM utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
            $st->execute([$idUtilisateur]);
            $role = (string) $st->fetchColumn();

            return $role !== '' ? [$role] : [];
        }

        return [];
    }

    /** Rôle principal d'un utilisateur ('' si aucun rôle). */
    public static function rolePrincipalUtilisateur(\PDO $pdo, int $idUtilisateur): string
    {
        return rolesUtilisateur($pdo, $idUtilisateur)[0] ?? '';
    }

    /**
     * Rôles retenus pour la session d'un compte (connexion) : rolesUtilisateur() tels quels. Rôle JA exclusif
     * (EA86 / EN15 refusent tout cumul) : un compte JA sans fiche est refusé par compteJaRefuse() /
     * revaliderSessionUtilisateur(). Compte hérité cumulant JA et d'autres rôles (antérieur à la règle) :
     * rien n'est retiré ni supprimé en base, seulement journalisé — à régulariser dans EA86.
     */
    public static function rolesEffectifsCompte(\PDO $pdo, int $idUtilisateur): array
    {
        $roles = rolesUtilisateur($pdo, $idUtilisateur);
        if (in_array('JA', $roles, true) && count($roles) > 1) {
            error_log('[NIJAC] compte ' . $idUtilisateur . ' cumulant JA et d\'autres rôles (' . implode(', ', $roles)
                . ') : rôle JA exclusif, à régulariser dans EA86');
        }

        return $roles;
    }

    /** Rôles de la session courante ($_SESSION['utilisateur']['roles'], repli ['role'] d'une session ancienne). */
    public static function rolesSession(): array
    {
        return rolesDe($_SESSION['utilisateur'] ?? []);
    }

    /** Rôles d'un tableau de session (clé 'roles', sinon 'role' seul). */
    public static function rolesDe(?array $u): array
    {
        if (!is_array($u)) {
            return [];
        }
        if (isset($u['roles']) && is_array($u['roles'])) {
            return array_values(array_map('strval', $u['roles']));
        }
        $role = (string) ($u['role'] ?? '');

        return $role !== '' ? [$role] : [];
    }

    /** L'utilisateur ($u, défaut = session) a-t-il au moins un des rôles $roles ? */
    public static function aRole(string|array $roles, ?array $u = null): bool
    {
        $u ??= $_SESSION['utilisateur'] ?? [];

        return (bool) array_intersect((array) $roles, rolesDe($u));
    }

    /** L'utilisateur ($u, défaut = session) est-il administrateur ? (rôle Administrateur ou drapeau is_admin) */
    public static function estAdmin(?array $u = null): bool
    {
        $u ??= $_SESSION['utilisateur'] ?? [];

        return !empty($u['is_admin']) || aRole('Administrateur', $u);
    }

    /** Route du menu d'un rôle (role.RouteMenu, repli ROLES_REFERENCE puis 'nominateur-menu'). */
    public static function menuDuRole(string $role): string
    {
        static $routes = null;
        if ($routes === null) {
            $routes = array_map(static fn (array $r): string => $r[2], ROLES_REFERENCE);
            try {
                $pdo = getPDO();
                if (tableRolesExiste($pdo)) {
                    $nom = schemaRoles($pdo)['nom'];
                    foreach ($pdo->query("SELECT $nom AS Nom, RouteMenu FROM role")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                        if (trim((string) $r['RouteMenu']) !== '') {
                            $routes[(string) $r['Nom']] = trim((string) $r['RouteMenu']);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[NIJAC] menuDuRole : lecture de la table role impossible : ' . $e->getMessage());
            }
        }

        return $routes[$role] ?? 'nominateur-menu';
    }

    /** Noms des rôles valides (table role, par priorité ; repli ROLES_REFERENCE avant EA98). */
    public static function rolesValides(\PDO $pdo): array
    {
        if (tableRolesExiste($pdo)) {
            try {
                $nom   = schemaRoles($pdo)['nom'];
                $codes = $pdo->query("SELECT $nom FROM role ORDER BY Priorite, $nom")->fetchAll(\PDO::FETCH_COLUMN);
                if ($codes) {
                    return array_values(array_map('strval', $codes));
                }
            } catch (\PDOException $e) {
                error_log('[NIJAC] rolesValides : ' . $e->getMessage());
            }
        }

        return array_keys(ROLES_REFERENCE);
    }

    /** Libellés des rôles [Nom => Libelle] (table role, par priorité ; repli ROLES_REFERENCE avant EA98) — EA86. */
    public static function libellesRoles(\PDO $pdo): array
    {
        if (tableRolesExiste($pdo)) {
            try {
                $libelles = [];
                $nom      = schemaRoles($pdo)['nom'];
                foreach ($pdo->query("SELECT $nom AS Nom, Libelle FROM role ORDER BY Priorite, $nom")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $code = (string) $r['Nom'];
                    $lib  = trim((string) ($r['Libelle'] ?? ''));
                    if (isset(ROLES_REFERENCE[$code]) && in_array($lib, LIBELLES_ROLES_OBSOLETES[$code] ?? [''], true)) {
                        $lib = ''; // ancien libellé amorcé (ex. « Juge-arbitre ») → libellé de référence
                    }
                    $libelles[$code] = $lib !== '' ? $lib : (ROLES_REFERENCE[$code][0] ?? $code);
                }
                if ($libelles) {
                    return $libelles;
                }
            } catch (\PDOException $e) {
                error_log('[NIJAC] libellesRoles : ' . $e->getMessage());
            }
        }

        return array_map(static fn (array $r): string => (string) $r[0], ROLES_REFERENCE);
    }

    /**
     * Remplace les rôles d'un utilisateur (DELETE + INSERT, SANS transaction propre : à l'appelant d'en
     * ouvrir une). $principal absent de $roles y est ajouté ; vide → premier rôle de la liste. Un seul
     * estPrincipal = 1 par utilisateur. Avant EA98 (utilisateur_role absente) : repli sur la colonne
     * utilisateur.Role (rôle principal seul) si elle existe encore. Noms convertis en Id_Role (idsRoles()) :
     * un rôle absent de la table role lève une RuntimeException avant toute suppression.
     */
    public static function enregistrerRolesUtilisateur(\PDO $pdo, int $idUtilisateur, array $roles, string $principal): void
    {
        $roles = array_values(array_unique(array_filter(array_map(static fn ($r): string => trim((string) $r), $roles), 'strlen')));
        $principal = trim($principal);
        if ($principal === '') {
            $principal = $roles[0] ?? '';
        } elseif (!in_array($principal, $roles, true)) {
            array_unshift($roles, $principal);
        }

        if (!tableRolesExiste($pdo)) {
            if (count($roles) > 1) {
                throw new \RuntimeException('Plusieurs rôles impossibles : table utilisateur_role absente, chargez EA98.');
            }
            if (utilisateurAColonneRole($pdo) && $principal !== '') {
                $pdo->prepare('UPDATE utilisateur SET Role = ? WHERE Id_Utilisateur = ?')->execute([$principal, $idUtilisateur]);
            }

            return;
        }

        // Conversion nom → Id_Role AVANT toute écriture : un rôle inconnu lève une exception, rien n'est supprimé.
        $ids = idsRoles($pdo, $roles);
        $pdo->prepare('DELETE FROM utilisateur_role WHERE Id_Utilisateur = ?')->execute([$idUtilisateur]);
        foreach ($roles as $role) {
            insererRoleUtilisateur($pdo, $idUtilisateur, $role, $role === $principal, $ids);
        }
    }

    /**
     * Noms de rôles → [Nom => Id_Role] (table role). Rôle inconnu → RuntimeException. Ancien schéma
     * (role sans Id_Role) : [Nom => null], l'existence du nom étant tout de même vérifiée.
     */
    public static function idsRoles(\PDO $pdo, array $noms): array
    {
        $noms = array_values(array_unique(array_map('strval', $noms)));
        if (!$noms) {
            return [];
        }
        $s     = schemaRoles($pdo);
        $col   = $s['nom'];
        $id    = $s['roleId'] ? 'Id_Role' : 'NULL';
        $st    = $pdo->prepare("SELECT $col AS Nom, $id AS Id_Role FROM role WHERE $col IN (" . implode(',', array_fill(0, count($noms), '?')) . ')');
        $st->execute($noms);
        $trouves = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $trouves[(string) $r['Nom']] = $r['Id_Role'] !== null ? (int) $r['Id_Role'] : null;
        }
        $res = [];
        foreach ($noms as $n) {
            if (!array_key_exists($n, $trouves)) {
                throw new \RuntimeException('Rôle inconnu : « ' . $n . ' ».');
            }
            $res[$n] = $trouves[$n];
        }

        return $res;
    }

    /**
     * Insère une ligne utilisateur_role pour le rôle $nom (schéma Id_Role : Id_Role seul ; ancien schéma :
     * Role = nom, plus Id_Role si la colonne existe déjà — migration interrompue). $ids = idsRoles() déjà calculé.
     */
    public static function insererRoleUtilisateur(\PDO $pdo, int $idUtilisateur, string $nom, bool $principal, ?array $ids = null): void
    {
        $ids ??= idsRoles($pdo, [$nom]);
        if (!array_key_exists($nom, $ids)) {
            $ids += idsRoles($pdo, [$nom]);
        }
        $s = schemaRoles($pdo);
        if ($s['urId']) {
            $pdo->prepare('INSERT INTO utilisateur_role (Id_Utilisateur, Id_Role, estPrincipal) VALUES (?, ?, ?)')
                ->execute([$idUtilisateur, $ids[$nom], $principal ? 1 : 0]);
        } elseif ($s['urIdCol'] && $ids[$nom] !== null) {
            $pdo->prepare('INSERT INTO utilisateur_role (Id_Utilisateur, Role, Id_Role, estPrincipal) VALUES (?, ?, ?, ?)')
                ->execute([$idUtilisateur, $nom, $ids[$nom], $principal ? 1 : 0]);
        } else {
            $pdo->prepare('INSERT INTO utilisateur_role (Id_Utilisateur, Role, estPrincipal) VALUES (?, ?, ?)')
                ->execute([$idUtilisateur, $nom, $principal ? 1 : 0]);
        }
    }

    /**
     * Fragment SQL « l'utilisateur $colId a le rôle $placeholder » ($placeholder = '?' ou littéral SQL
     * déjà quoté, ex. "'JA'" — jamais une saisie utilisateur). Avant EA98 (utilisateur_role absente) :
     * test direct « $colId IS NOT NULL AND <table de $colId>.Role = $placeholder » (sans sous-requête,
     * évite l'erreur MySQL 1093 dans un UPDATE utilisateur) si l'ancienne colonne Role existe, sinon toujours faux (0 = 1),
     * en gardant le même nombre de marqueurs.
     * $colId DOIT être préfixé (table.colonne ou alias.colonne) : un nom nu serait résolu dans la
     * sous-requête (ur.Id_Utilisateur = Id_Utilisateur → toujours vrai) → InvalidArgumentException.
     */
    public static function sqlARole(string $colId, string $placeholder): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $colId)) {
            throw new \InvalidArgumentException("sqlARole : colonne « $colId » non préfixée (attendu table.colonne).");
        }
        try {
            $pdo = getPDO();
            if (!tableRolesExiste($pdo)) {
                // Repli sans sous-requête : un EXISTS sur `utilisateur` dans un UPDATE utilisateur
                // déclencherait l'erreur MySQL 1093 ; on lit la colonne Role de la table/alias de $colId.
                $table = strstr($colId, '.', true);
                return utilisateurAColonneRole($pdo)
                    ? "($colId IS NOT NULL AND $table.Role = $placeholder)"
                    : "(0 = 1 AND $placeholder IS NULL)";
            }
            // Schéma Id_Role : jointure sur role pour comparer le nom (alias distincts de ceux de l'appelant).
            [$join, $nom] = sqlNomRoleUr($pdo, 'ur_ar', 'r_ar');

            return "EXISTS(SELECT 1 FROM utilisateur_role ur_ar $join WHERE ur_ar.Id_Utilisateur = $colId AND $nom = $placeholder)";
        } catch (\Throwable $e) {
            error_log('[NIJAC] sqlARole : ' . $e->getMessage());
            // Repli selon le schéma connu (cache de schemaRoles()) : ur.Role n'existe plus sur le schéma Id_Role.
            try {
                $s = schemaRoles(getPDO());
                if ($s['urId']) {
                    return "EXISTS(SELECT 1 FROM utilisateur_role ur_ar JOIN role r_ar ON r_ar.Id_Role = ur_ar.Id_Role WHERE ur_ar.Id_Utilisateur = $colId AND r_ar.{$s['nom']} = $placeholder)";
                }
            } catch (\Throwable $e2) {
                error_log('[NIJAC] sqlARole (repli) : ' . $e2->getMessage());
            }
        }

        return "EXISTS(SELECT 1 FROM utilisateur_role ur WHERE ur.Id_Utilisateur = $colId AND ur.Role = $placeholder)";
    }
}
