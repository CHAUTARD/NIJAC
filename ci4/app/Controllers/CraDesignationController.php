<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Désignation CRA (EC73), rôle « CRA Convoc ».
 *
 * Désignation des JA et adjoints d'une compétition CRA_Competition, stockée
 * dans CRA_Designation (créée par initTableConfiguration()). Accès depuis E009,
 * filtre "craconvocauth" sur toutes les routes. Raw PDO, pas de Model.
 *
 * Éligibilité (hiérarchie JA1 < JA2 < JA3 < JAN < JAI, colonnes ja.JA1…JAI à 1) :
 *  - JA : au moins un des grades de NiveauJA (« JAN JA3 » = l'un ou l'autre) ou supérieur,
 *    soit au moins le plus bas des grades listés ;
 *  - Adjoint : au moins JA2.
 * Quantités : NbrJA = nombre TOTAL de JA, adjoints compris ; NbrAdjoint = adjoints
 * parmi ce total → NbrJA − NbrAdjoint listes « JA » + NbrAdjoint listes « Adjoint ».
 * Règle bloquante : un JA n'est jamais désigné sur deux compétitions dont les plages
 * [DateDebut, COALESCE(DateFin, DateDebut)] se chevauchent (save() refuse tout).
 */
class CraDesignationController extends BaseController
{
    private const GRADES = ['JA1', 'JA2', 'JA3', 'JAN', 'JAI'];

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('cra_designation_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
        ]);
    }

    /**
     * GET cra-designation/data : compétitions (+ nombre de désignés) et JA de la
     * région (départements actifs) ayant au moins JA2 — seuls candidats possibles.
     */
    public function data(): ResponseInterface
    {
        $pdo = getPDO();
        $competitions = $pdo->query('SELECT c.Id_CRA_Competition, c.Numero, c.DateDebut, c.DateFin, c.Libelle,
                c.NbTablesMin, c.NbTablesMax, c.Lieu, c.Id_Club, c.NomClub, c.NiveauJA, c.NbrJA, c.NbrAdjoint,
                SUBSTRING(NULLIF(c.Id_Club, \'\'), 3, 2) AS CodeDept,
                (SELECT COUNT(*) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Role = \'JA\') AS NbDesJA,
                (SELECT COUNT(*) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Role = \'Adjoint\') AS NbDesAdj,
                (SELECT MAX(d.Rang) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Role = \'JA\') AS RangMaxJA,
                (SELECT MAX(d.Rang) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Role = \'Adjoint\') AS RangMaxAdj,
                (SELECT COUNT(d.DateConvocation) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition) AS NbConvoques,
                (SELECT COUNT(d.DateConvocation) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Role = \'Adjoint\') AS NbConvAdj,
                (SELECT MAX(d.DateConvocation) FROM CRA_Designation d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition) AS DerniereConvocation,
                (SELECT COUNT(*) FROM CRA_Designation d JOIN ja j ON j.Id_JA = d.Id_JA
                    WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND COALESCE(j.Email, \'\') = \'\') AS NbSansEmail
            FROM CRA_Competition c ORDER BY c.DateDebut, c.Numero')->fetchAll(\PDO::FETCH_ASSOC);
        // Quotas (NbrJA = total, adjoints compris) et état : désignations au-delà
        // des quotas (anciennes, avant la règle « total ») = « à corriger ».
        foreach ($competitions as &$c) {
            [$c['NbPrincipaux'], $c['NbAdjoints']] = $this->quotas($c);
            $c['ACorriger'] = (int) $c['RangMaxJA'] > $c['NbPrincipaux'] || (int) $c['RangMaxAdj'] > $c['NbAdjoints'];
            $c['Complete']  = !$c['ACorriger'] && (int) $c['NbDesJA'] >= $c['NbPrincipaux'] && (int) $c['NbDesAdj'] >= $c['NbAdjoints'];
        }
        unset($c);

        $depts = array_column(getDeptActifs(), 'CodeDept');
        $where = '(j.JA2 = 1 OR j.JA3 = 1 OR j.JAN = 1 OR j.JAI = 1)';
        if ($depts) {
            $where .= ' AND j.CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')';
        }
        $stmt = $pdo->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.JA2, j.JA3, j.JAN, j.JAI, cl.Nom AS NomClub,
                COALESCE(NULLIF(j.CodeDept, ''), SUBSTRING(NULLIF(j.Id_Club, ''), 3, 2)) AS CodeDept
            FROM ja j LEFT JOIN Club cl ON cl.Id_Club = j.Id_Club
            WHERE $where ORDER BY j.Nom, j.Prenom");
        $stmt->execute($depts);

        return $this->response->setJSON(['ok' => true, 'competitions' => $competitions, 'jas' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'modeDev' => isModeDeveloppement()]);
    }

    /**
     * GET cra-designation/(:num) : désignations enregistrées + JA indisponibles
     * (déjà désignés sur une autre compétition aux dates qui se chevauchent).
     */
    public function show($id = null): ResponseInterface
    {
        $id = (int) $id;
        if (!$this->competition($id)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Compétition introuvable.']);
        }
        $stmt = getPDO()->prepare('SELECT Role, Rang, Id_JA FROM CRA_Designation WHERE Id_CRA_Competition = ? ORDER BY Role, Rang');
        $stmt->execute([$id]);

        // Disponibilités EC74 (simple information : n'interdit aucun choix). Table absente (EA98 pas encore passé) = rien.
        $dispos = [];
        try {
            $d = getPDO()->prepare('SELECT Id_JA, Disponible FROM CRA_Dispo WHERE Id_CRA_Competition = ?');
            $d->execute([$id]);
            $dispos = $d->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
        }

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'indispos' => $this->indisponibles(getPDO(), $id), 'dispos' => $dispos]);
    }

    /** POST cra-designation/(:num) : ja[] / adjoint[] positionnels (rang = index + 1, vide = non désigné). */
    public function save($id = null): ResponseInterface
    {
        $id = (int) $id;
        $c  = $this->competition($id);
        if (!$c) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Compétition introuvable.']);
        }

        [$nbJa, $nbAdj] = $this->quotas($c);
        $roles = [
            'JA'      => [(array) ($this->request->getPost('ja') ?? []), $nbJa, $this->rangMin($c['NiveauJA'])],
            'Adjoint' => [(array) ($this->request->getPost('adjoint') ?? []), $nbAdj, 1],
        ];
        $lignes = [];
        $vus    = [];
        $check  = getPDO()->prepare('SELECT Nom, Prenom, JA1, JA2, JA3, JAN, JAI FROM ja WHERE Id_JA = ?');
        foreach ($roles as $role => [$ids, $max, $rangMin]) {
            foreach (array_values($ids) as $i => $idJa) {
                $idJa = trim((string) $idJa);
                if ($idJa === '') {
                    continue; // une liste « en trop » vidée est acceptée (elle sera supprimée)
                }
                if ($i >= $max) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "« $role n°" . ($i + 1) . " » dépasse le nombre attendu ($nbJa JA principal(aux) + $nbAdj adjoint(s), soit {$c['NbrJA']} JA en tout) : videz cette liste « à corriger » avant d'enregistrer."]);
                }
                if (!ctype_digit($idJa)) {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Identifiant de JA invalide.']);
                }
                $check->execute([(int) $idJa]);
                $ja = $check->fetch(\PDO::FETCH_ASSOC);
                if (!$ja) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Le JA $idJa n'existe pas."]);
                }
                $nom = trim($ja['Nom'] . ' ' . $ja['Prenom']);
                if (isset($vus[$idJa])) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "$nom est désigné plusieurs fois."]);
                }
                if (!$this->eligible($ja, $rangMin)) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "$nom n'a pas le grade requis pour « $role n°" . ($i + 1) . ' ».']);
                }
                $vus[$idJa] = true;
                $lignes[]   = [$id, $role, $i + 1, (int) $idJa];
            }
        }

        $pdo = getPDO();
        $uid = isset($_SESSION['utilisateur']['id']) ? (int) $_SESSION['utilisateur']['id'] : null;
        try {
            $pdo->beginTransaction();
            // Règle bloquante : un JA n'est jamais désigné sur deux compétitions aux dates qui se chevauchent.
            $indispos = $this->indisponibles($pdo, $id);
            $erreurs  = [];
            foreach ($lignes as [, , , $idJa]) {
                foreach ($indispos[$idJa] ?? [] as $x) {
                    $erreurs[] = "{$x['Nom']} : déjà désigné sur EC n°{$x['Numero']} {$x['Libelle']} ({$this->dates($x['DateDebut'], $x['DateFin'])})";
                }
            }
            if ($erreurs) {
                $pdo->rollBack();
                $err = "Enregistrement refusé, JA déjà désigné(s) aux mêmes dates :\n- " . implode("\n- ", $erreurs);

                return $this->response->setJSON(['ok' => false, 'err' => $err, 'msg' => $err]);
            }
            // DateConvocation conservée pour un JA maintenu dans le même rôle (remplacement en bloc).
            $conv = $pdo->prepare('SELECT CONCAT(Role, \'#\', Id_JA), DateConvocation FROM CRA_Designation WHERE Id_CRA_Competition = ? AND DateConvocation IS NOT NULL');
            $conv->execute([$id]);
            $conv = $conv->fetchAll(\PDO::FETCH_KEY_PAIR);
            $pdo->prepare('DELETE FROM CRA_Designation WHERE Id_CRA_Competition = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO CRA_Designation (Id_CRA_Competition, Role, Rang, Id_JA, Id_Utilisateur, DateConvocation) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($lignes as $l) {
                $ins->execute([...$l, $uid, $conv[$l[1] . '#' . $l[3]] ?? null]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return $this->response->setJSON(['ok' => false, 'msg' => "Erreur lors de l'enregistrement de la désignation."]);
        }

        return $this->response->setJSON([
            'ok' => true, 'msg' => 'Désignation enregistrée (' . count($lignes) . ' / ' . ($nbJa + $nbAdj) . ' JA en tout).',
        ]);
    }

    public function delete($id = null): ResponseInterface
    {
        getPDO()->prepare('DELETE FROM CRA_Designation WHERE Id_CRA_Competition = ?')->execute([(int) $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Désignation effacée.']);
    }

    /**
     * POST cra-designation/convocations : id (compétition), renvoyer (0/1), cc (0/1).
     * Un appel par compétition (la boucle sur les compétitions cochées est côté client,
     * comme EC74 / EN15) : un email HTML par JA principal désigné. Modèles = messages de la table
     * messagerie (Type, voir modeleConvocation() : copie perso, sinon système ; absent → envoi refusé) :
     * « CRA Convocation JA + adjoint » dès qu'un adjoint est attendu (NbrAdjoint ≥ 1)
     * ou désigné (≥ 1 ligne Role='Adjoint'), quel que soit leur nombre ; sinon « CRA Convocation JA ».
     * Le modèle avec adjoint invite le JA principal à solliciter son adjoint « parmi les personnes
     * disponibles ci-dessous » → {LISTE_JA_DISPONIBLES} = JA disponibles EC74 (pas les désignés) ; si des
     * adjoints sont déjà désignés (« validés ») : tous là → le tableau ne liste qu'eux (aucun JA disponible),
     * en partie → eux puis les JA disponibles pour les restants ({ADJOINTS_INTRO}/{ADJOINTS_CONTACT} accordés).
     * Chaque adjoint désigné (Role='Adjoint') reçoit « CRA Convocation adjoint » (sujet
     * « CRA – Convocation adjoint – … »), qui lui présente le(s) JA principal(aux) désigné(s) : {NOM_JA_PRINCIPAL},
     * {TEL_JA_PRINCIPAL}, {EMAIL_JA_PRINCIPAL}. Aucun JA principal désigné → rien n'est envoyé (adjoints compris).
     * Déjà convoqués ignorés sauf renvoyer=1 ; sans email listés, jamais envoyés.
     * Succès → CRA_Designation.DateConvocation = NOW() (JA comme adjoint).
     */
    public function convocations(): ResponseInterface
    {
        $pdo      = getPDO();
        $moi      = $_SESSION['utilisateur'] ?? [];
        $id       = (int) ($this->request->getPost('id') ?? 0);
        $renvoyer = $this->request->getPost('renvoyer') === '1';

        // Salle du club organisateur : principale en priorité, sinon la première.
        $stmt = $pdo->prepare('SELECT c.Id_CRA_Competition, c.Numero, c.DateDebut, c.DateFin, c.Libelle, c.Lieu,
                c.NbTablesMin, c.NbTablesMax, c.NbrJA, c.NbrAdjoint, c.NiveauJA,
                s.Nom AS SalleNom, s.Adresse AS SalleAdresse, s.Cp AS SalleCp, s.Ville AS SalleVille
            FROM CRA_Competition c
            LEFT JOIN salle s ON s.Id_Salle = (SELECT s2.Id_Salle FROM salle s2 WHERE s2.Id_Club = c.Id_Club
                                               ORDER BY s2.EstPrincipale DESC, s2.Id_Salle LIMIT 1)
            WHERE c.Id_CRA_Competition = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$c) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Compétition introuvable.']);
        }
        $titre = "n°{$c['Numero']} {$c['Libelle']}";

        $stmt = $pdo->prepare('SELECT d.Id_CRA_Designation, d.Role, d.Rang, d.DateConvocation, j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone
            FROM CRA_Designation d JOIN ja j ON j.Id_JA = d.Id_JA
            WHERE d.Id_CRA_Competition = ? ORDER BY d.Role, d.Rang');
        $stmt->execute([$id]);
        $des = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $principaux = array_values(array_filter($des, fn ($d) => $d['Role'] === 'JA'));
        if (!$principaux) {
            return $this->response->setJSON(['ok' => false, 'titre' => $titre, 'msg' => 'aucun JA principal désigné (adjoints non convoqués : aucun JA principal à leur présenter).']);
        }

        [, $nbAdj] = $this->quotas($c);
        $avecAdj   = $nbAdj > 0 || array_filter($des, fn ($d) => $d['Role'] === 'Adjoint');
        $uid       = (int) ($moi['id'] ?? 0);
        $type      = $avecAdj ? 'CRA Convocation JA + adjoint' : 'CRA Convocation JA';
        $modele    = self::modeleConvocation($pdo, $type, $uid);
        if (!$modele) {
            return $this->response->setJSON(['ok' => false, 'titre' => $titre, 'msg' => "Modèle de convocation « $type » introuvable dans la messagerie (EA93)."]);
        }
        $adjoints = array_values(array_filter($des, fn ($d) => $d['Role'] === 'Adjoint')); // triés par Rang (ORDER BY)
        $ctx      = self::ctxConvocation($c, count($adjoints));
        if ($avecAdj) {
            // Adjoints validés (désignés) : seuls listés s'ils sont tous là ; sinon complétés par les JA disponibles.
            $ctx['adjoints_valides']       = count($adjoints);
            $ctx['liste_adjoints_valides'] = array_map(fn ($d) => [
                'Nom' => $d['Nom'], 'Prenom' => $d['Prenom'], 'Telephone' => $d['Telephone'], 'Email' => $d['Email'], 'Rang' => $d['Rang'],
            ], $adjoints);
            if (count($adjoints) < $ctx['nb_adjoints']) {
                $ctx['ja_disponibles'] = $this->jaDisponibles($pdo, $id);
            }
        }
        $modeleAdj = $avecAdj ? self::modeleConvocation($pdo, 'CRA Convocation adjoint', $uid) : null;
        $ctxAdj    = self::ctxConvocation($c, 0, $principaux);

        // envoyes = JA principaux, envoyesAdj = adjoints ; autres listes : « (adjoint) » après le nom.
        $cr = ['envoyes' => [], 'envoyesAdj' => [], 'echecs' => [], 'sansEmail' => [], 'ignores' => []];
        $modeDev = isModeDeveloppement();
        $nomMoi  = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''));
        $maj     = $pdo->prepare('UPDATE CRA_Designation SET DateConvocation = NOW() WHERE Id_CRA_Designation = ?');
        // Session rouverte (fermée par le filtre) pour que la fenêtre de checkRateLimit() persiste.
        demarrerSessionNijac();
        foreach ($des as $d) {
            $adj = $d['Role'] === 'Adjoint';
            $nom = trim($d['Prenom'] . ' ' . $d['Nom']) . ($adj ? ' (adjoint)' : '');
            if ($adj && !$modeleAdj) {
                $cr['echecs'][] = "$nom : Modèle de convocation « CRA Convocation adjoint » introuvable dans la messagerie (EA93).";
                continue;
            }
            if ($d['DateConvocation'] && !$renvoyer) {
                $cr['ignores'][] = "$nom (déjà convoqué le " . date('d/m/Y', strtotime($d['DateConvocation'])) . ')';
                continue;
            }
            if (empty($d['Email'])) {
                $cr['sansEmail'][] = $nom;
                continue;
            }
            $errRl = checkRateLimit(1);
            if ($errRl !== null) {
                $cr['echecs'][] = "$nom : $errRl";
                $cr['stop'] = true;
                break;
            }
            $tpl = $adj ? $modeleAdj : $modele;
            [$sujet, $corps] = self::rendreConvocation($tpl['Message'], $d, $moi, $adj ? $ctxAdj : $ctx, $tpl['Sujet']);
            $dest = getEmailDestinataire($d['Email']);
            try {
                $mail = getNijacMailer();
                $mail->addAddress($dest, trim($d['Prenom'] . ' ' . $d['Nom']));
                if (!empty($moi['email'])) {
                    // Flags du message (EA93) : ReplyTo = 1 → Reply-To ; Cc = 1 → copie si « M'envoyer une copie » est coché.
                    if ((int) $tpl['ReplyTo'] === 1) {
                        $mail->addReplyTo($moi['email'], $nomMoi);
                    }
                    if ((int) $tpl['Cc'] === 1 && $this->request->getPost('cc') === '1') {
                        $mail->addCC(getEmailDestinataire($moi['email']), $nomMoi);
                    }
                }
                $mail->Subject = ($modeDev && $dest !== $d['Email']) ? "[DEV] $sujet" : $sujet;
                $mail->msgHTML($corps); // images data:base64 du modèle → pièces jointes inline (CID), AltBody généré
                $mail->send();
                enregistrerEnvois(1);
                $maj->execute([(int) $d['Id_CRA_Designation']]);
                $cr[$adj ? 'envoyesAdj' : 'envoyes'][] = trim($d['Prenom'] . ' ' . $d['Nom']);
            } catch (\Exception $e) {
                error_log('[NIJAC] EC73 convocation : ' . $e->getMessage());
                $cr['echecs'][] = "$nom : " . messageErreur($e, "échec de l'envoi.");
            }
        }
        session_write_close();

        return $this->response->setJSON(['ok' => true, 'titre' => $titre, 'modele' => $type] + $cr);
    }

    /**
     * Modèle de convocation CRA $type (Type messagerie) : ['Sujet', 'Message', 'Cc', 'ReplyTo'].
     * Copie personnelle de l'utilisateur, sinon message système (table messagerie, EA93) ; null si aucun.
     */
    public static function modeleConvocation(\PDO $pdo, string $type, int $idUtilisateur): ?array
    {
        return resoudreModeleMessagerieParType($pdo, $type, $idUtilisateur);
    }

    /**
     * Contexte de construireMarqueursMessage() pour une compétition CRA (salle du club, sinon Lieu pour la ville).
     * nb_adjoints (pluriel de « CRA Convocation JA + adjoint ») = NbrAdjoint attendu, ou nombre désigné s'il est supérieur, au moins 1.
     * $principaux (lignes Nom/Prenom/Telephone/Email des JA principaux désignés) → ja_principaux de « CRA Convocation adjoint ».
     */
    public static function ctxConvocation(array $c, int $nbAdjDesignes = 0, array $principaux = []): array
    {
        $min = (string) ($c['NbTablesMin'] ?? '');
        $max = (string) ($c['NbTablesMax'] ?? '');

        return [
            'ja_principaux' => array_map(fn ($p) => [
                'nom' => $p['Nom'] ?? '', 'prenom' => $p['Prenom'] ?? '', 'telephone' => $p['Telephone'] ?? '', 'email' => $p['Email'] ?? '',
            ], $principaux),
            'epreuve'       => (string) $c['Libelle'],
            'date'          => $c['DateDebut'],
            'date_fin'      => $c['DateFin'] ?? null,
            'nb_tables'     => $min !== '' && $max !== '' && $min !== $max ? "$min à $max" : ($min !== '' ? $min : $max),
            'salle_nom'     => $c['SalleNom'] ?? '',
            'salle_adresse' => $c['SalleAdresse'] ?? '',
            'salle_cp'      => $c['SalleCp'] ?? '',
            'salle_ville'   => ($c['SalleVille'] ?? '') !== '' ? $c['SalleVille'] : (string) ($c['Lieu'] ?? ''),
            'nb_adjoints'   => max(1, (int) ($c['NbrAdjoint'] ?? 0), $nbAdjDesignes),
        ];
    }

    /**
     * [sujet, corps] : marqueurs remplacés ; valeurs échappées dans le corps HTML,
     * sauf {LISTE_JA_DISPONIBLES} (HTML déjà échappé par construireMarqueursMessage()).
     */
    public static function rendreConvocation(string $modele, array $ja, array $moi, array $ctx, string $sujet = 'CRA – Convocation – {EPREUVE} – {DATE_LONGUE}'): array
    {
        $m   = construireMarqueursMessage($ja, $moi, $ctx);
        $esc = [];
        foreach ($m as $k => $v) {
            $esc[$k] = $k === '{LISTE_JA_DISPONIBLES}' ? $v : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }

        return [strtr($sujet, $m), strtr($modele, $esc)];
    }

    /**
     * {LISTE_JA_DISPONIBLES} (aucun ou une partie des adjoints désignés) : JA ayant répondu Disponible / À confirmer / Sous condition (EC74)
     * pour cette compétition, au moins JA2, hors JA désignés ici ou sur une compétition aux
     * dates qui se chevauchent. Statut précisé après le nom s'il n'est pas « Disponible ».
     */
    private function jaDisponibles(\PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.Telephone, j.Email, d.Disponible
            FROM CRA_Dispo d JOIN ja j ON j.Id_JA = d.Id_JA
            WHERE d.Id_CRA_Competition = ? AND d.Disponible IN ('Disponible', 'À confirmer', 'Disponible sous condition')
              AND (j.JA2 = 1 OR j.JA3 = 1 OR j.JAN = 1 OR j.JAI = 1)
              AND j.Id_JA NOT IN (SELECT x.Id_JA FROM CRA_Designation x WHERE x.Id_CRA_Competition = ?)
            ORDER BY d.Disponible <> 'Disponible', j.Nom, j.Prenom");
        $stmt->execute([$id, $id]);
        $pris = $this->indisponibles($pdo, $id);
        $out  = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if (isset($pris[(int) $r['Id_JA']])) {
                continue;
            }
            if ($r['Disponible'] !== 'Disponible') {
                $r['Nom'] .= $r['Disponible'] === 'À confirmer' ? ' (à confirmer)' : ' (sous condition)';
            }
            $out[] = $r;
        }

        return $out;
    }

    private function competition(int $id): ?array
    {
        $stmt = getPDO()->prepare('SELECT Id_CRA_Competition, DateDebut, DateFin, NiveauJA, NbrJA, NbrAdjoint FROM CRA_Competition WHERE Id_CRA_Competition = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * [JA principaux, adjoints] : NbrJA est le TOTAL, adjoints compris (règle EC71 :
     * NbrAdjoint ≤ NbrJA − 1). Une ligne antérieure hors règle (ex. 1 JA + 1 adjoint
     * saisis séparément) est ramenée à au moins un JA principal, total = NbrJA.
     */
    private function quotas(array $c): array
    {
        $total = max(0, (int) $c['NbrJA']);
        $adj   = min(max(0, (int) $c['NbrAdjoint']), max(0, $total - 1));

        return [$total - $adj, $adj];
    }

    /** Rang (index dans GRADES) du plus bas des codes de NiveauJA ; JA2 par défaut. Partagé avec EC74. */
    public static function rangMin(string $niveau): int
    {
        $rangs = array_filter(array_map(fn ($g) => array_search($g, self::GRADES, true), preg_split('/\s+/', trim($niveau))), 'is_int');

        return $rangs ? min($rangs) : 1;
    }

    public static function eligible(array $ja, int $rangMin): bool
    {
        foreach (array_slice(self::GRADES, $rangMin) as $g) {
            if ((int) $ja[$g] === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * JA désignés (JA ou Adjoint) sur une AUTRE compétition dont la plage
     * [DateDebut, COALESCE(DateFin, DateDebut)] chevauche celle-ci — règle bloquante.
     * [Id_JA => [[Nom, Numero, Libelle, DateDebut, DateFin], ...]], en une requête.
     */
    private function indisponibles(\PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT d2.Id_JA, CONCAT(UPPER(j.Nom), \' \', j.Prenom) AS Nom,
                c2.Numero, c2.Libelle, c2.DateDebut, c2.DateFin
            FROM CRA_Competition c
            JOIN CRA_Competition c2 ON c2.Id_CRA_Competition <> c.Id_CRA_Competition
              AND c2.DateDebut <= COALESCE(c.DateFin, c.DateDebut)
              AND COALESCE(c2.DateFin, c2.DateDebut) >= c.DateDebut
            JOIN CRA_Designation d2 ON d2.Id_CRA_Competition = c2.Id_CRA_Competition
            JOIN ja j ON j.Id_JA = d2.Id_JA
            WHERE c.Id_CRA_Competition = ?
            ORDER BY c2.DateDebut, c2.Numero');
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['Id_JA']][] = $r;
        }

        return $out;
    }

    /** 'AAAA-MM-JJ' (+ fin éventuelle) → 'JJ/MM/AAAA' ou 'JJ/MM/AAAA - JJ/MM/AAAA'. */
    private function dates(string $debut, ?string $fin): string
    {
        $f = fn ($d) => implode('/', array_reverse(explode('-', $d)));

        return $fin && $fin !== $debut ? $f($debut) . ' - ' . $f($fin) : $f($debut);
    }
}
