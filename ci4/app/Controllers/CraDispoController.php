<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Disponibilités CRA (EC74), rôle « CRA Convoc ».
 *
 * Écran interne (filtre "craconvocauth") : demande par email aux JA éligibles
 * (même règle que EC73 : grade ≥ NiveauJA de la compétition, JA2+ pour les
 * adjoints, JA des départements actifs) de leurs disponibilités pour une ou
 * plusieurs compétitions CRA, relance, saisie manuelle (réponse par téléphone).
 *
 * Page PUBLIQUE dispo-cra?ja=TOKEN (aucun filtre d'auth, comme EN22) : le JA
 * répond (Disponible / Disponible sous condition / À confirmer / Indisponible)
 * pour chaque compétition qui lui a été demandée. Token Obfuscator AVEC pepper
 * (pas de repli sans pepper : aucun lien EC74 n'a été émis avant l'activation du pepper).
 *
 * Table CRA_Dispo (initTableConfiguration(), EA98) : une ligne par
 * (compétition, JA) ; Disponible = ENUM des 5 statuts de la matrice de suivi,
 * « Non renseigné » = demandé sans réponse (valeur par défaut).
 */
class CraDispoController extends BaseController
{
    /** Les 5 valeurs de CRA_Dispo.Disponible (ordre de l'ENUM). */
    public const STATUTS = ['Non renseigné', 'Indisponible', 'Disponible', 'À confirmer', 'Disponible sous condition'];

    /** Réponses possibles du JA sur la page publique (« Non renseigné » n'est pas un choix). */
    public const REPONSES_JA = ['Disponible', 'Disponible sous condition', 'À confirmer', 'Indisponible'];

    public const NON_RENSEIGNE = 'Non renseigné';

    /** Choix masqués à la saisie quand le réglage `cra_dispo_choix_etendus` vaut '0' (valeurs déjà enregistrées toujours affichées). */
    public const CHOIX_ETENDUS = ['À confirmer', 'Disponible sous condition'];

    /** Clé `configuration` : '1' (défaut) = « À confirmer » et « Disponible sous condition » proposés à la saisie, '0' = masqués. */
    public const CLE_CHOIX_ETENDUS = 'cra_dispo_choix_etendus';

    public static function choixEtendus(): bool
    {
        return getConfig(self::CLE_CHOIX_ETENDUS, '1') !== '0';
    }

    /**
     * Valeur proposable à la saisie : toujours si choix étendus, sinon hors CHOIX_ETENDUS,
     * sauf si c'est la valeur actuelle de la ligne (conservée telle quelle).
     */
    private static function choixAutorise(string $valeur, ?string $actuelle): bool
    {
        return self::choixEtendus() || !in_array($valeur, self::CHOIX_ETENDUS, true) || $valeur === $actuelle;
    }

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';
        require_once __DIR__ . '/../../../vendor/autoload.php'; // PhpSpreadsheet (import de la matrice xlsx)
    }

    private function obf(): \Obfuscator
    {
        return new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
    }

    // ── Écran interne EC74 ───────────────────────────────────────────────────

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('cra_dispo_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
        ]);
    }

    /** GET cra-dispo/data : compétitions (+ compteurs de demandes/réponses) et JA2+ de la région (comme EC73). */
    public function data(): ResponseInterface
    {
        $pdo = getPDO();
        $nb = fn ($cond) => "(SELECT COUNT(*) FROM CRA_Dispo d WHERE d.Id_CRA_Competition = c.Id_CRA_Competition AND $cond)";
        $competitions = $pdo->query('SELECT c.Id_CRA_Competition, c.Numero, c.DateDebut, c.DateFin, c.Libelle, c.Lieu, c.NiveauJA,
                (COALESCE(c.DateFin, c.DateDebut) < CURDATE()) AS Passee,
                ' . $nb('d.DateDemande IS NOT NULL') . ' AS NbDemandes,
                ' . $nb("d.Disponible = 'Disponible'") . ' AS NbDispo,
                ' . $nb("d.Disponible = 'À confirmer'") . ' AS NbConfirmer,
                ' . $nb("d.Disponible = 'Disponible sous condition'") . ' AS NbCondition,
                ' . $nb("d.Disponible = 'Indisponible'") . ' AS NbIndispo,
                ' . $nb("d.DateDemande IS NOT NULL AND d.Disponible = 'Non renseigné'") . ' AS NbSans
            FROM CRA_Competition c ORDER BY c.DateDebut, c.Numero')->fetchAll(\PDO::FETCH_ASSOC);

        [$where, $depts] = $this->filtreJa();
        $stmt = $pdo->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.JA2, j.JA3, j.JAN, j.JAI, cl.Nom AS NomClub,
                (j.Email IS NOT NULL AND j.Email <> '') AS AEmail
            FROM ja j LEFT JOIN Club cl ON cl.Id_Club = j.Id_Club
            WHERE $where ORDER BY j.Nom, j.Prenom");
        $stmt->execute($depts);

        return $this->response->setJSON([
            'ok' => true, 'competitions' => $competitions, 'jas' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'modeDev' => isModeDeveloppement(), 'choixEtendus' => self::choixEtendus(),
        ]);
    }

    /** POST cra-dispo/reglage : valeur '1'/'0' → configuration.cra_dispo_choix_etendus (choix proposés à la saisie). */
    public function reglage(): ResponseInterface
    {
        $v = (string) ($this->request->getPost('valeur') ?? '');
        if (!in_array($v, ['0', '1'], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Valeur invalide.']);
        }
        try {
            getPDO()->prepare('INSERT INTO configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
                ->execute([self::CLE_CHOIX_ETENDUS, $v]);
        } catch (\PDOException $e) {
            error_log('[NIJAC] EC74 réglage : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur lors de l\'enregistrement du réglage.')]);
        }

        return $this->response->setJSON(['ok' => true, 'choixEtendus' => $v === '1',
            'msg' => $v === '1' ? '« À confirmer » et « Sous condition » sont proposés.' : '« À confirmer » et « Sous condition » ne sont plus proposés.']);
    }

    /** GET cra-dispo/(:num) : statut de chaque JA (demande / réponse) pour la compétition. */
    public function show($id = null): ResponseInterface
    {
        $stmt = getPDO()->prepare("SELECT d.Id_JA, d.Disponible, d.Commentaire, d.DateDemande, d.DateReponse, d.Source,
                TRIM(CONCAT(COALESCE(u.Prenom, ''), ' ', COALESCE(u.Nom, ''))) AS NomUtilisateur
            FROM CRA_Dispo d LEFT JOIN Utilisateur u ON u.Id_Utilisateur = d.Id_Utilisateur
            WHERE d.Id_CRA_Competition = ?");
        $stmt->execute([(int) $id]);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    /**
     * POST cra-dispo/envoyer : UN email à UN JA (la boucle sur les JA est côté
     * client, comme EN15, pour le compte-rendu et éviter un timeout SMTP).
     * id_ja, competitions[] (cochées), relance (0/1), date_limite (AAAA-MM-JJ, optionnel), cc (0/1).
     * Le JA reçoit les compétitions cochées, à venir, pour lesquelles il est éligible
     * (relance : seulement celles demandées et encore sans réponse).
     */
    public function envoyer(): ResponseInterface
    {
        $pdo     = getPDO();
        $moi     = $_SESSION['utilisateur'] ?? [];
        $idJa    = (int) ($this->request->getPost('id_ja') ?? 0);
        $ids     = array_values(array_filter(array_map('intval', (array) ($this->request->getPost('competitions') ?? []))));
        $relance = $this->request->getPost('relance') === '1';
        $limite  = trim((string) ($this->request->getPost('date_limite') ?? ''));
        if ($limite !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $limite)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Date limite invalide.']);
        }

        $ja = $this->jaRegion($idJa);
        if (!$ja || !$ids) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'JA ou compétitions invalides.']);
        }
        $nom = trim($ja['Prenom'] . ' ' . $ja['Nom']);

        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT c.Id_CRA_Competition, c.Numero, c.DateDebut, c.DateFin, c.Libelle, c.Lieu, c.NiveauJA,
                d.Id_CRA_Dispo, d.Disponible, d.DateDemande
            FROM CRA_Competition c
            LEFT JOIN CRA_Dispo d ON d.Id_CRA_Competition = c.Id_CRA_Competition AND d.Id_JA = ?
            WHERE c.Id_CRA_Competition IN ($ph) AND COALESCE(c.DateFin, c.DateDebut) >= CURDATE()
            ORDER BY c.DateDebut, c.Numero");
        $stmt->execute([$idJa, ...$ids]);
        $compets = array_values(array_filter(
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
            fn ($c) => CraDesignationController::eligible($ja, CraDesignationController::rangMin((string) $c['NiveauJA']))
                && (!$relance || ($c['DateDemande'] !== null && $c['Disponible'] === self::NON_RENSEIGNE))
        ));
        if (!$compets) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nom, 'msg' => 'aucune compétition concernée.']);
        }
        if (empty($ja['Email'])) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'sansEmail' => true, 'nom' => $nom, 'msg' => "pas d'email."]);
        }

        // Session rouverte (fermée par le filtre) pour que la fenêtre de checkRateLimit() persiste.
        demarrerSessionNijac();
        $errRl = checkRateLimit(1);
        if ($errRl !== null) {
            session_write_close();

            return $this->response->setJSON(['ok' => false, 'stop' => true, 'nom' => $nom, 'msg' => $errRl]);
        }

        $corps   = $this->corpsEmail($ja, $compets, $moi, $relance, $limite);
        $modeDev = isModeDeveloppement();
        $dest    = getEmailDestinataire($ja['Email']);
        $sujet   = 'CRA – Demande de disponibilités';
        try {
            $mail = getNijacMailer();
            $mail->isHTML(true);
            $mail->addAddress($dest, $nom);
            if (!empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
                if ($this->request->getPost('cc') === '1') {
                    $mail->addCC(getEmailDestinataire($moi['email']), trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
                }
            }
            $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujet" : $sujet;
            $mail->Body    = $corps;
            $mail->AltBody = html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $corps)), ENT_QUOTES, 'UTF-8');
            $mail->send();
            enregistrerEnvois(1);
            session_write_close();
        } catch (\Exception $e) {
            session_write_close();
            error_log('[NIJAC] EC74 mail error : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'nom' => $nom, 'msg' => messageErreur($e, "échec de l'envoi.")]);
        }

        // Demande enregistrée après envoi réussi ; un statut ≠ « Non renseigné » n'est jamais écrasé
        // (Disponible absent de l'UPDATE : seul DateDemande change).
        $uid = isset($moi['id']) ? (int) $moi['id'] : null;
        $ins = $pdo->prepare("INSERT INTO CRA_Dispo (Id_CRA_Competition, Id_JA, DateDemande, Id_Utilisateur) VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE DateDemande = NOW(),
                Id_Utilisateur = IF(Disponible = 'Non renseigné', VALUES(Id_Utilisateur), Id_Utilisateur)");
        foreach ($compets as $c) {
            $ins->execute([(int) $c['Id_CRA_Competition'], $idJa, $uid]);
        }

        return $this->response->setJSON(['ok' => true, 'nom' => $nom, 'nb' => count($compets)]);
    }

    /**
     * POST cra-dispo/(:num)/saisie : id_ja, valeur ∈ STATUTS, commentaire (optionnel, 255).
     * « Non renseigné » = effacer la réponse (ligne sans demande supprimée, sinon retour à « demandé sans réponse »).
     */
    public function saisie($id = null): ResponseInterface
    {
        $id     = (int) $id;
        $idJa   = (int) ($this->request->getPost('id_ja') ?? 0);
        $valeur = (string) ($this->request->getPost('valeur') ?? '');
        $com    = trim((string) ($this->request->getPost('commentaire') ?? ''));
        $pdo    = getPDO();

        $stmt = $pdo->prepare('SELECT NiveauJA FROM CRA_Competition WHERE Id_CRA_Competition = ?');
        $stmt->execute([$id]);
        $niveau = $stmt->fetchColumn();
        $ja     = $this->jaRegion($idJa);
        if ($niveau === false || !$ja || !in_array($valeur, self::STATUTS, true)
            || !CraDesignationController::eligible($ja, CraDesignationController::rangMin((string) $niveau))) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
        }
        if (mb_strlen($com) > 255) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Commentaire limité à 255 caractères.']);
        }
        if (!self::choixEtendus() && in_array($valeur, self::CHOIX_ETENDUS, true)) {
            $stmt = $pdo->prepare('SELECT Disponible FROM CRA_Dispo WHERE Id_CRA_Competition = ? AND Id_JA = ?');
            $stmt->execute([$id, $idJa]);
            $actuelle = $stmt->fetchColumn();
            if (!self::choixAutorise($valeur, $actuelle === false ? null : (string) $actuelle)) {
                return $this->response->setJSON(['ok' => false, 'msg' => "« $valeur » n'est plus proposé (réglage de l'écran)."]);
            }
        }

        if ($valeur === self::NON_RENSEIGNE) {
            $pdo->prepare('DELETE FROM CRA_Dispo WHERE Id_CRA_Competition = ? AND Id_JA = ? AND DateDemande IS NULL')->execute([$id, $idJa]);
            $pdo->prepare("UPDATE CRA_Dispo SET Disponible = 'Non renseigné', Commentaire = NULL, DateReponse = NULL, Source = NULL
                WHERE Id_CRA_Competition = ? AND Id_JA = ?")->execute([$id, $idJa]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Réponse effacée.']);
        }

        $uid = isset($_SESSION['utilisateur']['id']) ? (int) $_SESSION['utilisateur']['id'] : null;
        $pdo->prepare("INSERT INTO CRA_Dispo (Id_CRA_Competition, Id_JA, Disponible, Commentaire, DateReponse, Source, Id_Utilisateur)
                VALUES (?, ?, ?, ?, NOW(), 'Saisie', ?)
            ON DUPLICATE KEY UPDATE Disponible = VALUES(Disponible), Commentaire = VALUES(Commentaire), DateReponse = NOW(),
                Source = 'Saisie', Id_Utilisateur = VALUES(Id_Utilisateur)")->execute([$id, $idJa, $valeur, $com === '' ? null : $com, $uid]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Disponibilité enregistrée.']);
    }

    // ── Import de la matrice Excel (classeur « Suivi des disponibilités ») ───

    /** Texte d'une cellule de la matrice (normalisé) → valeur de Disponible ; null = « Non renseigné » (aucune écriture). */
    private const STATUTS_MATRICE = [
        'disponible'                => 'Disponible',
        'indisponible'              => 'Indisponible',
        'a confirmer'               => 'À confirmer',
        'disponible sous condition' => 'Disponible sous condition',
        'non renseigne'             => null,
        ''                          => null,
    ];

    /** POST cra-dispo/import/apercu : fichier `xlsx` (multipart), `ecraser` (0/1) → aperçu, AUCUNE écriture. */
    public function importApercu(): ResponseInterface
    {
        return $this->importMatrice(false);
    }

    /** POST cra-dispo/import/valider : mêmes paramètres (le navigateur renvoie le fichier) → écriture en transaction. */
    public function importValider(): ResponseInterface
    {
        return $this->importMatrice(true);
    }

    private function importMatrice(bool $ecrire): ResponseInterface
    {
        // Le fichier reste dans le temporaire d'upload PHP (supprimé en fin de requête) : jamais déplacé ni conservé.
        $file = $this->request->getFile('xlsx');
        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier trop volumineux (5 Mo maximum).']);
        }
        if (strtolower($file->getClientExtension()) !== 'xlsx'
            || !in_array($file->getMimeType(), ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format Excel .xlsx est accepté.']);
        }
        try {
            $m = self::lireMatrice($file->getTempName());
        } catch (\Throwable $e) {
            error_log('[NIJAC] EC74 import matrice : ' . get_class($e));

            return $this->response->setJSON(['ok' => false, 'msg' => $e instanceof \RuntimeException ? $e->getMessage() : 'Classeur illisible.']);
        }

        $pdo         = getPDO();
        $ecraser     = $this->request->getPost('ecraser') === '1';
        $divergences = $m['divergences'];

        // 1. JA : licence (feuille Référents) contrôlée par le nom, sinon nom/prénom unique dans `ja`.
        $parCle = [];
        $parId  = [];
        foreach ($pdo->query('SELECT Id_JA, Nom, Prenom FROM ja')->fetchAll(\PDO::FETCH_ASSOC) as $j) {
            $cle                = self::cleNom($j['Nom'] . ' ' . $j['Prenom']);
            $parCle[$cle][]     = (int) $j['Id_JA'];
            $parId[(int) $j['Id_JA']] = $cle;
        }
        $jaCol    = [];   // colonne de la matrice → Id_JA
        $nonRappr = [];
        foreach ($m['jas'] as $col => $j) {
            $cle = self::cleNom($j['nom']);
            $id  = null;
            if ($j['licence'] !== null && isset($parId[$j['licence']])) {
                if ($parId[$j['licence']] === $cle) {
                    $id = $j['licence'];
                } else {
                    $nonRappr[] = ['nom' => $j['nom'], 'raison' => 'licence ' . $j['licence'] . ' attribuée à un autre nom'];
                    continue;
                }
            } else {
                $ids = $parCle[$cle] ?? [];
                if (count($ids) !== 1) {
                    $nonRappr[] = ['nom' => $j['nom'], 'raison' => $ids ? 'homonymes' : 'absent de la base'];
                    continue;
                }
                $id = $ids[0];
            }
            if (in_array($id, $jaCol, true)) {
                $nonRappr[] = ['nom' => $j['nom'], 'raison' => 'JA déjà présent dans une autre colonne'];
                continue;
            }
            $jaCol[$col] = $id;
        }

        // 2. Épreuves : date + libellé, sinon position (ligne r = n° r−1).
        $compets = $pdo->query('SELECT Id_CRA_Competition, Numero, DateDebut, Libelle FROM CRA_Competition')->fetchAll(\PDO::FETCH_ASSOC);
        $parDateLib = [];
        $parNumero  = [];
        foreach ($compets as $c) {
            $parDateLib[$c['DateDebut'] . '|' . self::norm($c['Libelle'])][] = $c;
            $parNumero[(int) $c['Numero']] = $c;
        }
        $competLigne = [];   // ligne → Id_CRA_Competition
        foreach ($m['epreuves'] as $r => $e) {
            $trouve = $parDateLib[$e['date'] . '|' . self::norm($e['libelle'])] ?? [];
            if (count($trouve) === 1) {
                $c = $trouve[0];
                if ((int) $c['Numero'] !== $r - 1) {
                    $divergences[] = "Ligne $r : rapprochée par date et libellé avec la compétition n°{$c['Numero']} (position attendue : n°" . ($r - 1) . ').';
                }
            } elseif (isset($parNumero[$r - 1])) {
                $c = $parNumero[$r - 1];
                $divergences[] = "Ligne $r : « {$e['texte']} » rapprochée par position avec la compétition n°{$c['Numero']} ("
                    . date('d/m/Y', strtotime($c['DateDebut'])) . ' | ' . $c['Libelle'] . ') : date ou libellé différent.';
            } else {
                $divergences[] = "Ligne $r : « {$e['texte']} » sans compétition correspondante, ignorée.";
                continue;
            }
            if (in_array((int) $c['Id_CRA_Competition'], $competLigne, true)) {
                $divergences[] = "Ligne $r : compétition n°{$c['Numero']} déjà rapprochée par une autre ligne, ignorée.";
                continue;
            }
            $competLigne[$r] = (int) $c['Id_CRA_Competition'];
        }

        // 3. Opérations : création / complément / écrasement / conservation.
        $existant = [];
        foreach ($pdo->query('SELECT Id_CRA_Competition, Id_JA, Disponible FROM CRA_Dispo')->fetchAll(\PDO::FETCH_ASSOC) as $d) {
            $existant[$d['Id_CRA_Competition'] . '|' . $d['Id_JA']] = $d['Disponible'];
        }
        $ops       = ['creer' => [], 'maj' => []];
        $nb        = ['nonRenseigne' => 0, 'conservees' => 0, 'inconnues' => 0];
        $parStatut = array_fill_keys(array_filter(self::STATUTS_MATRICE), 0);   // effectifs écrits (créés + mis à jour)
        foreach ($competLigne as $r => $idC) {
            foreach ($jaCol as $col => $idJa) {
                $texte = $m['cellules'][$r][$col] ?? '';
                $cle   = self::norm($texte);
                if (!array_key_exists($cle, self::STATUTS_MATRICE)) {
                    $nb['inconnues']++;
                    $divergences[] = "Ligne $r, colonne $col : valeur « $texte » inconnue, ignorée.";
                    continue;
                }
                $v = self::STATUTS_MATRICE[$cle];
                if ($v === null) {
                    $nb['nonRenseigne']++;
                    continue;
                }
                // Réponse existante = statut ≠ « Non renseigné » : conservée sauf case « Écraser ».
                $k = "$idC|$idJa";
                if (!array_key_exists($k, $existant)) {
                    $ops['creer'][] = [$idC, $idJa, $v];
                } elseif ($existant[$k] === self::NON_RENSEIGNE || $ecraser) {
                    $ops['maj'][] = [$idC, $idJa, $v];
                } else {
                    $nb['conservees']++;
                    continue;
                }
                $parStatut[$v]++;
            }
        }
        // Réglage « choix étendus » à non : ces statuts sont importés quand même (données source conservées), l'aperçu le signale.
        $etendus  = self::choixEtendus();
        $masquees = $etendus ? 0 : array_sum(array_intersect_key($parStatut, array_flip(self::CHOIX_ETENDUS)));

        $res = [
            'ok' => true, 'ecrit' => false, 'nbJa' => count($m['jas']), 'jaRapproches' => count($jaCol), 'nonRapproches' => $nonRappr,
            'nbEpreuves' => count($m['epreuves']), 'competRapprochees' => count($competLigne),
            'creer' => count($ops['creer']), 'maj' => count($ops['maj']), 'parStatut' => $parStatut, 'ignNonRenseigne' => $nb['nonRenseigne'],
            'ignConservees' => $nb['conservees'], 'ignInconnues' => $nb['inconnues'], 'divergences' => $divergences,
            'choixEtendus' => $etendus, 'masquees' => $masquees,
        ];
        if (!$ecrire) {
            return $this->response->setJSON($res);
        }

        $uid = isset($_SESSION['utilisateur']['id']) ? (int) $_SESSION['utilisateur']['id'] : null;
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO CRA_Dispo (Id_CRA_Competition, Id_JA, Disponible, DateReponse, Source, Id_Utilisateur)
                VALUES (?, ?, ?, NOW(), 'Saisie', ?)");
            foreach ($ops['creer'] as [$idC, $idJa, $dispo]) {
                $ins->execute([$idC, $idJa, $dispo, $uid]);
            }
            // Commentaire remis à NULL : il se rapportait à l'ancienne réponse.
            $upd = $pdo->prepare("UPDATE CRA_Dispo SET Disponible = ?, Commentaire = NULL, DateReponse = NOW(), Source = 'Saisie', Id_Utilisateur = ?
                WHERE Id_CRA_Competition = ? AND Id_JA = ?");
            foreach ($ops['maj'] as [$idC, $idJa, $dispo]) {
                $upd->execute([$dispo, $uid, $idC, $idJa]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EC74 import matrice : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur lors de l\'enregistrement, aucune donnée importée.')]);
        }

        return $this->response->setJSON(['ecrit' => true] + $res);
    }

    /**
     * Lit le classeur (sans base) : feuille « Matrice » (ligne 1 = JA « Prénom NOM » en B…,
     * col. A = « JJ/MM/AAAA | libellé » à partir de la ligne 2) et feuille « Référents »
     * (col. A = nom, B = licence). Retourne jas[col] = {nom, licence|null},
     * epreuves[ligne] = {date AAAA-MM-JJ|null, libelle, texte}, cellules[ligne][col] = texte, divergences[].
     * Public static : testable hors CodeIgniter.
     */
    public static function lireMatrice(string $chemin): array
    {
        $lecteur = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');   // format imposé, pas de détection
        $noms    = [];
        foreach ($lecteur->listWorksheetNames($chemin) as $n) {
            $noms[self::norm($n)] = $n;
        }
        if (!isset($noms['matrice'])) {
            throw new \RuntimeException('Feuille « Matrice » introuvable dans le classeur.');
        }
        $lecteur->setReadDataOnly(true);
        $lecteur->setLoadSheetsOnly(array_values(array_intersect_key($noms, ['matrice' => 1, 'referents' => 1])));
        $classeur = $lecteur->load($chemin);
        $txt      = fn ($f, $col, $r) => trim(preg_replace('/\s+/u', ' ', (string) $f->getCell($col . $r)->getValue()));

        $licences = [];
        if (isset($noms['referents'])) {
            $f = $classeur->getSheetByName($noms['referents']);
            for ($r = 2, $max = $f->getHighestDataRow(); $r <= $max; $r++) {
                $lic = preg_replace('/\D/', '', $txt($f, 'B', $r));
                if ($txt($f, 'A', $r) !== '' && $lic !== '') {
                    $licences[self::cleNom($txt($f, 'A', $r))] = (int) $lic;
                }
            }
        }

        $f    = $classeur->getSheetByName($noms['matrice']);
        $maxC = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($f->getHighestDataColumn());
        $maxR = $f->getHighestDataRow();
        $res  = ['jas' => [], 'epreuves' => [], 'cellules' => [], 'divergences' => []];
        for ($i = 2; $i <= $maxC; $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $nom = $txt($f, $col, 1);
            if ($nom !== '') {
                $res['jas'][$col] = ['nom' => $nom, 'licence' => $licences[self::cleNom($nom)] ?? null];
            }
        }
        for ($r = 2; $r <= $maxR; $r++) {
            $a = $txt($f, 'A', $r);
            if ($a === '') {
                continue;
            }
            [$d, $lib] = array_map('trim', explode('|', $a, 2)) + [1 => ''];
            // « JJ/MM/AAAA » ou plage « JJ-JJ/MM/AAAA » : date de début.
            $date = preg_match('#^(\d{1,2})(?:-\d{1,2})?/(\d{1,2})/(\d{4})$#', $d, $x) && checkdate((int) $x[2], (int) $x[1], (int) $x[3])
                ? sprintf('%04d-%02d-%02d', $x[3], $x[2], $x[1]) : null;
            if ($date === null) {
                $res['divergences'][] = "Ligne $r : date illisible dans « $a ».";
            }
            $res['epreuves'][$r] = ['date' => $date, 'libelle' => $lib, 'texte' => $a];
            foreach (array_keys($res['jas']) as $col) {
                $res['cellules'][$r][$col] = $txt($f, $col, $r);
            }
        }
        if (!$res['jas'] || !$res['epreuves']) {
            throw new \RuntimeException('Feuille « Matrice » vide ou mal structurée (JA en ligne 1, épreuves en colonne A).');
        }

        return $res;
    }

    /** Minuscules, sans accents, espaces/ponctuation réduits à un espace. */
    public static function norm(string $s): string
    {
        $s = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize(mb_strtolower($s, 'UTF-8'), \Normalizer::FORM_D));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /** Clé de rapprochement d'un nom : mots normalisés triés (indépendante de l'ordre Nom/Prénom et des tirets). */
    public static function cleNom(string $s): string
    {
        $mots = explode(' ', self::norm($s));
        sort($mots);

        return implode(' ', $mots);
    }

    // ── Page publique dispo-cra?ja=TOKEN ─────────────────────────────────────

    /** GET dispo-cra?ja=TOKEN */
    public function formulaire()
    {
        $token = trim((string) ($this->request->getGet('ja') ?? ''));
        $ja    = $this->jaDepuisToken($token);
        if (!$ja) {
            return view('cra_dispo_public', ['erreur' => 'Lien invalide ou expiré. Utilisez le lien reçu par email ou contactez la Commission Régionale d\'Arbitrage.']);
        }
        // Valeurs pré-remplies = réponses enregistrées (repli de la vue quand $valeurs est absent).
        return view('cra_dispo_public', ['ja' => $ja, 'token' => $token, 'lignes' => $this->lignesPubliques((int) $ja['Id_JA']),
            'choixEtendus' => self::choixEtendus()]);
    }

    /**
     * POST dispo-cra : ja (token), dispo[Id_CRA_Competition] ∈ REPONSES_JA, commentaire[Id_CRA_Competition]
     * (obligatoire pour « Disponible sous condition » : la condition à préciser — seulement si choix étendus).
     * Réglage choix étendus à '0' : « À confirmer » / « Disponible sous condition » refusés sauf valeur déjà enregistrée.
     */
    public function valider()
    {
        $token = trim((string) ($this->request->getPost('ja') ?? ''));
        $ja    = $this->jaDepuisToken($token);
        if (!$ja) {
            return view('cra_dispo_public', ['erreur' => 'Lien invalide ou expiré. Utilisez le lien reçu par email ou contactez la Commission Régionale d\'Arbitrage.']);
        }
        $idJa   = (int) $ja['Id_JA'];
        $lignes = $this->lignesPubliques($idJa);
        $dispos = (array) ($this->request->getPost('dispo') ?? []);
        $coms   = (array) ($this->request->getPost('commentaire') ?? []);

        $modifiables = [];
        foreach ($lignes as $l) {
            if ($l['Modifiable']) {
                $modifiables[(int) $l['Id_CRA_Competition']] = $l;
            }
        }

        // Valeurs saisies (réaffichées en cas d'erreur) + validation serveur.
        $valeurs = [];
        $erreur  = null;
        foreach ($lignes as $l) {
            $valeurs[$l['Id_CRA_Competition']] = ['dispo' => $l['Disponible'], 'com' => $l['Commentaire']];
        }
        foreach (array_keys($dispos + $coms) as $idC) {
            if (!isset($modifiables[(int) $idC])) {
                $erreur = 'Une des compétitions envoyées ne vous a pas été demandée ou n\'est plus modifiable (date passée).';
            }
        }
        $etendus = self::choixEtendus();
        $maj     = [];
        foreach ($modifiables as $idC => $l) {
            $d   = (string) ($dispos[$idC] ?? '');
            $com = trim((string) ($coms[$idC] ?? ''));
            $valeurs[$idC] = ['dispo' => $d, 'com' => $com];
            // Choix masqués par le réglage refusés, sauf la valeur déjà enregistrée sur la ligne (conservée).
            if (!in_array($d, self::REPONSES_JA, true) || !self::choixAutorise($d, $l['Disponible'])) {
                $erreur ??= 'Merci d\'indiquer votre disponibilité pour chaque compétition.';
            } elseif (mb_strlen($com) > 255) {
                $erreur ??= 'Un commentaire dépasse 255 caractères.';
            } elseif ($etendus && $d === 'Disponible sous condition' && $com === '') {
                $erreur ??= 'Merci de préciser la condition en commentaire pour « Disponible sous condition ».';
            }
            $maj[] = [$d, $com === '' ? null : $com, (int) $l['Id_CRA_Dispo'], $idJa];
        }
        if (!$maj) {
            $erreur ??= 'Aucune compétition modifiable.';
        }
        if ($erreur !== null) {
            return view('cra_dispo_public', ['ja' => $ja, 'token' => $token, 'lignes' => $lignes, 'valeurs' => $valeurs, 'msgErreur' => $erreur, 'choixEtendus' => $etendus]);
        }

        $pdo = getPDO();
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE CRA_Dispo SET Disponible = ?, Commentaire = ?, DateReponse = NOW(), Source = 'JA'
                WHERE Id_CRA_Dispo = ? AND Id_JA = ?");
            foreach ($maj as $m) {
                $upd->execute($m);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[NIJAC] EC74 public : ' . $e->getMessage());

            return view('cra_dispo_public', ['ja' => $ja, 'token' => $token, 'lignes' => $lignes, 'valeurs' => $valeurs, 'choixEtendus' => $etendus,
                'msgErreur' => messageErreur($e, 'Erreur technique, merci de réessayer.')]);
        }

        return view('cra_dispo_public', ['ja' => $ja, 'token' => $token, 'lignes' => $this->lignesPubliques($idJa), 'merci' => true, 'choixEtendus' => $etendus]);
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    /** Clause WHERE + paramètres des JA candidats (JA2+, départements actifs) — même périmètre que EC73. */
    private function filtreJa(): array
    {
        $depts = array_column(getDeptActifs(), 'CodeDept');
        $where = '(j.JA2 = 1 OR j.JA3 = 1 OR j.JAN = 1 OR j.JAI = 1)';
        if ($depts) {
            $where .= ' AND j.CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')';
        }

        return [$where, $depts];
    }

    /** JA candidat (périmètre EC73) d'Id donné, ou null. */
    private function jaRegion(int $idJa): ?array
    {
        [$where, $depts] = $this->filtreJa();
        $stmt = getPDO()->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.JA1, j.JA2, j.JA3, j.JAN, j.JAI
            FROM ja j WHERE j.Id_JA = ? AND $where");
        $stmt->execute([$idJa, ...$depts]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** JA (Id, Nom, Prénom) d'un token Obfuscator avec pepper, ou null (-1 = invalide). */
    private function jaDepuisToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 32) {
            return null;
        }
        $id = $this->obf()->deobfuscate($token);
        if ($id <= 0) {
            return null;
        }
        $stmt = getPDO()->prepare('SELECT Id_JA, Nom, Prenom FROM ja WHERE Id_JA = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Compétitions demandées à ce JA (et à lui seul : aucune donnée d'un autre JA). */
    private function lignesPubliques(int $idJa): array
    {
        $stmt = getPDO()->prepare('SELECT d.Id_CRA_Dispo, d.Disponible, d.Commentaire, d.DateReponse,
                c.Id_CRA_Competition, c.Numero, c.DateDebut, c.DateFin, c.Libelle, c.Lieu, c.NiveauJA,
                (COALESCE(c.DateFin, c.DateDebut) >= CURDATE()) AS Modifiable
            FROM CRA_Dispo d JOIN CRA_Competition c ON c.Id_CRA_Competition = d.Id_CRA_Competition
            WHERE d.Id_JA = ? AND d.DateDemande IS NOT NULL
            ORDER BY c.DateDebut, c.Numero');
        $stmt->execute([$idJa]);

        return array_map(function ($l) {
            $l['Modifiable'] = (bool) $l['Modifiable'];
            $l['Dates']      = self::dates($l['DateDebut'], $l['DateFin']);

            return $l;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** 'AAAA-MM-JJ' (+ fin éventuelle) → 'JJ/MM/AAAA' ou 'JJ/MM/AAAA - JJ/MM/AAAA'. */
    private static function dates(string $debut, ?string $fin): string
    {
        $f = fn ($d) => date('d/m/Y', strtotime($d));

        return $fin && $fin !== $debut ? $f($debut) . ' - ' . $f($fin) : $f($debut);
    }

    /** Corps HTML de la demande (texte construit ici : pas de modèle `messagerie` pour EC74). */
    private function corpsEmail(array $ja, array $compets, array $moi, bool $relance, string $limite): string
    {
        $h   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $url = site_url('dispo-cra') . '?ja=' . $this->obf()->obfuscate((int) $ja['Id_JA']);
        $td  = 'border:1px solid #c8d4e8;padding:5px 8px;text-align:left;';

        $lignes = '';
        foreach ($compets as $i => $c) {
            $bg = $i % 2 === 0 ? 'background:#f0f4fa;' : '';
            $lignes .= '<tr><td style="' . $td . $bg . '">' . $h($c['Numero']) . '</td><td style="' . $td . $bg . '">' . $h(self::dates($c['DateDebut'], $c['DateFin']))
                . '</td><td style="' . $td . $bg . '">' . $h($c['Libelle']) . '</td><td style="' . $td . $bg . '">' . $h($c['Lieu']) . '</td></tr>';
        }
        $signature = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''));

        return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">'
            . '<p>Bonjour ' . $h(trim($ja['Prenom'] . ' ' . $ja['Nom'])) . ',</p>'
            . ($relance ? '<p><b>Rappel :</b> nous n\'avons pas encore reçu votre réponse.</p>' : '')
            . '<p>La Commission Régionale d\'Arbitrage prépare les désignations des juges-arbitres pour '
            . (count($compets) > 1 ? 'les compétitions suivantes' : 'la compétition suivante') . '. Merci de nous indiquer vos disponibilités :</p>'
            . '<table cellspacing="0" style="border-collapse:collapse;font-size:13px;"><tr style="background:#00695c;color:#fff;">'
            . '<th style="' . $td . '">N°</th><th style="' . $td . '">Dates</th><th style="' . $td . '">Compétition</th><th style="' . $td . '">Lieu</th></tr>'
            . $lignes . '</table>'
            . '<p style="margin:22px 0;"><a href="' . $h($url) . '" style="background:#00695c;color:#fff;padding:10px 18px;border-radius:5px;text-decoration:none;font-weight:bold;">Indiquer mes disponibilités</a></p>'
            . '<p style="font-size:12px;color:#555;">Si le bouton ne fonctionne pas, valider ce lien dans votre navigateur :<br>' . $h($url) . '</p>'
            . ($limite !== '' ? '<p><b>Merci de répondre avant le ' . $h(date('d/m/Y', strtotime($limite))) . '.</b></p>' : '')
            . '<p>Cordialement,<br>' . $h($signature) . ($signature !== '' ? '<br>' : '') . 'Commission Régionale d\'Arbitrage'
            . (!empty($moi['email']) ? '<br>' . $h($moi['email']) : '') . '</p></div>';
    }
}
