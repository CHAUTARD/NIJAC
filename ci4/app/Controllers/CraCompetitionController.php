<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Compétitions CRA (EC71), rôle « CRA Convoc ».
 *
 * CRUD de la table CRA_Competition (calendrier des compétitions régionales,
 * créée et seedée par initTableConfiguration()). Accès depuis E009, filtre
 * "craconvocauth" (rôle CRA Convoc ou Administrateur) sur toutes les routes.
 * Raw PDO, pas de Model.
 */
class CraCompetitionController extends BaseController
{
    private const NIVEAUX = ['JA2', 'JA3', 'JAN JA3'];
    private const COLONNES = 'Id_CRA_Competition, Numero, DateDebut, DateFin, Libelle, NbTablesMin, NbTablesMax, Lieu, Id_Club, NomClub, NiveauJA, NbrJA, NbrAdjoint';
    /** Normalisation SQL commune au Lieu saisi : tirets/apostrophes → espaces (collation _ci = casse/accents ignorés). */
    private const NORM = "REPLACE(REPLACE(%s, '-', ' '), '''', ' ')";

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('cra_competition_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'niveaux'     => self::NIVEAUX,
        ]);
    }

    public function data(): ResponseInterface
    {
        // CodeDept = positions 3-4 de l'Id_Club (ex. 09760004 → 76), NULL si pas de club.
        $rows = getPDO()->query('SELECT ' . self::COLONNES . ', SUBSTRING(NULLIF(Id_Club, \'\'), 3, 2) AS CodeDept
            FROM CRA_Competition ORDER BY DateDebut, Numero')
            ->fetchAll(\PDO::FETCH_ASSOC);

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    /**
     * GET cra-competition/clubs?q=<lieu> : jusqu'à 15 clubs dont le nom ou la ville
     * d'une salle correspond au Lieu. "Saint(e)" est ramené à "St(e)" comme dans
     * les villes La Poste/FFTT. exact = nom ou ville identique au Lieu normalisé.
     */
    public function clubs(): ResponseInterface
    {
        $q = trim(preg_replace('/[\s\'-]+/u', ' ', (string) $this->request->getGet('q')));
        $q = preg_replace(['/\bsainte\b/iu', '/\bsaint\b/iu'], ['STE', 'ST'], $q);
        if (mb_strlen($q) < 2) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Saisissez au moins 2 caractères dans le Lieu.']);
        }

        $nom   = sprintf(self::NORM, 'c.Nom');
        $ville = sprintf(self::NORM, 's.Ville');
        $like  = '%' . addcslashes($q, '%_\\') . '%';
        $stmt  = getPDO()->prepare("
            SELECT c.Id_Club, c.Nom,
                   COALESCE(MAX(CASE WHEN s.EstPrincipale = 1 THEN s.Ville END), MAX(s.Ville)) AS Ville,
                   MAX($nom = :q1 OR $ville = :q2) AS exact
            FROM Club c
            LEFT JOIN Salle s ON s.Id_Club = c.Id_Club
            GROUP BY c.Id_Club, c.Nom
            HAVING MAX($nom LIKE :l1 OR $ville LIKE :l2) = 1
            ORDER BY exact DESC, c.Nom
            LIMIT 15");
        $stmt->execute([':q1' => $q, ':q2' => $q, ':l1' => $like, ':l2' => $like]);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    public function store(): ResponseInterface
    {
        [$f, $err] = $this->valider($this->request->getPost(), null);
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        getPDO()->prepare('INSERT INTO CRA_Competition
            (Numero, DateDebut, DateFin, Libelle, NbTablesMin, NbTablesMax, Lieu, Id_Club, NomClub, NiveauJA, NbrJA, NbrAdjoint)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($f));

        return $this->response->setJSON(['ok' => true, 'msg' => 'Compétition créée.', 'id' => (int) getPDO()->lastInsertId()]);
    }

    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        $existe = getPDO()->prepare('SELECT 1 FROM CRA_Competition WHERE Id_CRA_Competition = ?');
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Compétition introuvable.']);
        }

        [$f, $err] = $this->valider($this->request->getRawInput(), $id);
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        getPDO()->prepare('UPDATE CRA_Competition SET Numero = ?, DateDebut = ?, DateFin = ?, Libelle = ?,
            NbTablesMin = ?, NbTablesMax = ?, Lieu = ?, Id_Club = ?, NomClub = ?, NiveauJA = ?,
            NbrJA = ?, NbrAdjoint = ? WHERE Id_CRA_Competition = ?')
            ->execute([...array_values($f), $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Compétition mise à jour.', 'id' => $id]);
    }

    public function delete($id = null): ResponseInterface
    {
        $stmt = getPDO()->prepare('DELETE FROM CRA_Competition WHERE Id_CRA_Competition = ?');
        $stmt->execute([(int) $id]);

        return $this->response->setJSON($stmt->rowCount()
            ? ['ok' => true, 'msg' => 'Compétition supprimée.']
            : ['ok' => false, 'msg' => 'Compétition introuvable.']);
    }

    /**
     * Valide et normalise les champs du formulaire.
     * Retourne [champs ordonnés comme les colonnes INSERT, message d'erreur ou null].
     */
    private function valider(array $in, ?int $idExclu): array
    {
        $numero  = trim((string) ($in['numero'] ?? ''));
        $debut   = trim((string) ($in['date_debut'] ?? ''));
        $fin     = trim((string) ($in['date_fin'] ?? ''));
        $libelle = trim((string) ($in['libelle'] ?? ''));
        $min     = trim((string) ($in['nb_tables_min'] ?? ''));
        $max     = trim((string) ($in['nb_tables_max'] ?? ''));
        $lieu    = trim((string) ($in['lieu'] ?? ''));
        $niveau  = trim((string) ($in['niveau_ja'] ?? ''));
        $nbrJa   = trim((string) ($in['nbr_ja'] ?? ''));
        $nbrAdj  = trim((string) ($in['nbr_adjoint'] ?? ''));
        $nbrJa   = $nbrJa === '' ? '1' : $nbrJa;   // défauts si absents
        $nbrAdj  = $nbrAdj === '' ? '0' : $nbrAdj;

        if ($numero === '' || $debut === '' || $libelle === '' || $lieu === '' || $niveau === '') {
            return [null, 'N°, date de début, libellé, lieu et niveau JA sont obligatoires.'];
        }
        if (!ctype_digit($numero) || (int) $numero < 1) {
            return [null, 'Le n° doit être un entier positif.'];
        }
        if (!$this->dateValide($debut)) {
            return [null, 'Date de début invalide.'];
        }
        if ($fin !== '' && !$this->dateValide($fin)) {
            return [null, 'Date de fin invalide.'];
        }
        if ($fin !== '' && $fin < $debut) {
            return [null, 'La date de fin doit être postérieure ou égale à la date de début.'];
        }
        foreach (['minimum' => $min, 'maximum' => $max] as $lib => $v) {
            if ($v !== '' && (!ctype_digit($v) || (int) $v < 1)) {
                return [null, "Le nombre de tables $lib doit être un entier positif."];
            }
        }
        if ($min !== '' && $max !== '' && (int) $min > (int) $max) {
            return [null, 'Le nombre de tables minimum doit être inférieur ou égal au maximum.'];
        }
        if (mb_strlen($libelle) > 150 || mb_strlen($lieu) > 100) {
            return [null, 'Libellé (150 car.) ou lieu (100 car.) trop long.'];
        }
        if (!in_array($niveau, self::NIVEAUX, true)) {
            return [null, 'Niveau JA invalide.'];
        }
        if (!ctype_digit($nbrJa) || (int) $nbrJa < 1 || (int) $nbrJa > 20) {
            return [null, 'Le nombre total de JA (adjoints compris) doit être un entier entre 1 et 20.'];
        }
        if (!ctype_digit($nbrAdj) || (int) $nbrAdj > 20) {
            return [null, "Le nombre d'adjoints doit être un entier entre 0 et 20."];
        }
        // NbrJA = total, adjoints compris : au moins un JA principal.
        if ((int) $nbrAdj > (int) $nbrJa - 1) {
            return [null, "Trop d'adjoints : « JA (total) » inclut les adjoints et il faut au moins un JA principal, donc au plus " . ((int) $nbrJa - 1) . ' adjoint(s) pour ' . (int) $nbrJa . ' JA en tout.'];
        }

        // NomClub n'est jamais lu depuis le formulaire : recopié de Club.Nom.
        $idClub  = trim((string) ($in['id_club'] ?? ''));
        $nomClub = null;
        if ($idClub !== '') {
            $club = getPDO()->prepare('SELECT Nom FROM Club WHERE Id_Club = ?');
            $club->execute([$idClub]);
            $nomClub = $club->fetchColumn();
            if ($nomClub === false) {
                return [null, "Le club $idClub n'existe pas."];
            }
        }

        $doublon = getPDO()->prepare('SELECT 1 FROM CRA_Competition WHERE Numero = ? AND Id_CRA_Competition <> ?');
        $doublon->execute([(int) $numero, $idExclu ?? 0]);
        if ($doublon->fetchColumn()) {
            return [null, "Le n° $numero est déjà utilisé par une autre compétition."];
        }

        return [[
            (int) $numero, $debut, $fin ?: null, $libelle,
            $min === '' ? null : (int) $min, $max === '' ? null : (int) $max,
            $lieu, $idClub ?: null, $nomClub, $niveau, (int) $nbrJa, (int) $nbrAdj,
        ], null];
    }

    private function dateValide(string $d): bool
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', $d);

        return $dt && $dt->format('Y-m-d') === $d;
    }
}
