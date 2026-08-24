<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Saisie des disponibilités JA par le nominateur (E021), portage CI4
 * de Nominateur/disponibilites.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth").
 * Un seul point d'API en lecture (JA actifs par département) ; le lien vers
 * la fiche de disponibilité utilise l'Id_JA réel dans l'URL, pas de token
 * Obfuscator — ni le fichier legacy ni ce portage n'importent
 * Classes/Obfuscator.php (voir SPECIFICATION.md, section E021).
 *
 * Pas de Model : jointure ponctuelle Club/laposte pour une seule liste en
 * lecture — reste proche d'un Query Builder simple, mais gardé en raw PDO
 * pour cohérence avec le reste du portage de cette famille d'écrans.
 */
class DisponibilitesController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        $pdo = getPDO();
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS competition_regionale (
                Id_CompetitionRegionale INT AUTO_INCREMENT PRIMARY KEY,
                Date                    DATE NOT NULL,
                Heure                   TIME NOT NULL,
                UNIQUE KEY uq_date_heure (Date, Heure)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // Le département n'est pas un attribut de la date/heure elle-même : c'est un choix
        // propre à chaque JA, saisi via E036 (dispo-regionale-ja) et stocké dans
        // disponible_regionale.Departement — voir DispoRegionaleJaController.
        $col = $pdo->query("SHOW COLUMNS FROM competition_regionale WHERE Field = 'Departement'")->fetch();
        if ($col) {
            $pdo->exec('ALTER TABLE competition_regionale DROP COLUMN Departement');
        }
        // Calendrier "Disponibilités JA 2ème phase 2025-2026 (v2)" (Importation/) : dates/horaires
        // des rencontres régionales, à défaut d'un import FFTT (Régionale 3/4 non couvertes par
        // ImportRencontresController) — seed une seule fois, à la création de la table.
        if ((int) $pdo->query('SELECT COUNT(*) FROM competition_regionale')->fetchColumn() === 0) {
            $stmt = $pdo->prepare('INSERT INTO competition_regionale (Date, Heure) VALUES (?, ?)');
            foreach ([
                ['2026-09-19', '16:00'], ['2026-09-20', '14:00'],
                ['2026-10-03', '16:00'], ['2026-10-04', '14:00'],
                ['2026-10-17', '16:00'], ['2026-10-18', '14:00'],
                ['2026-11-07', '16:00'], ['2026-11-08', '14:00'],
                ['2026-11-21', '16:00'], ['2026-11-22', '14:00'],
                ['2026-12-05', '16:00'], ['2026-12-06', '14:00'],
                ['2026-12-12', '16:00'], ['2026-12-13', '14:00'],
            ] as [$date, $heure]) {
                $stmt->execute([$date, $heure]);
            }
        }

        // Commentaire libre par date (ex. horaire du dimanche différent selon le secteur en
        // Régionale Masculine, voir onglet "Instructions" du fichier Importation/) — voir E014.
        if (!$pdo->query("SHOW COLUMNS FROM competition_regionale WHERE Field = 'Commentaire'")->fetch()) {
            $pdo->exec('ALTER TABLE competition_regionale ADD COLUMN Commentaire VARCHAR(255) NULL');
        }
        // Rétro-remplissage des dimanches existants (Heure = 14:00, seed ci-dessus) sans écraser
        // une note déjà saisie par un admin.
        $pdo->prepare("
            UPDATE competition_regionale
            SET Commentaire = 'Dimanche 09h00 : départements 27 et 76 — Dimanche 14h00 : départements 14, 50, 61'
            WHERE Heure = '14:00:00' AND Commentaire IS NULL
        ")->execute();
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'      => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'     => $u['id_departement'] ?? '',
            'changeLogin'     => !empty($u['change_login']),
            'isAdmin'         => !empty($u['is_admin']),
            'deptActifs'      => getDeptActifs(),
            'deptLimitrophes' => getDepartementsLimitrophes(),
        ];

        return view('disponibilites_index', $data);
    }

    public function jaDept(): ResponseInterface
    {
        $dept = trim((string) ($this->request->getGet('dept') ?? ''));
        if ($dept === '') {
            return $this->response->setJSON(['ok' => false, 'err' => 'Aucun département']);
        }

        // Ex. un nominateur du 76 (Seine-Maritime) voit aussi le 27 (Eure) —
        // règle définie dans configuration.regles_departements, jamais en dur.
        $depts = getDepartementsAutorises($dept);

        $pdo = getPDO();

        // Département d'exercice (club en priorité, sinon Cp/laposte) : voir
        // JugearbitreController::resolveCodeDept(). Filtrer/dériver depuis le code
        // postal personnel du JA (comme avant) excluait à tort les JA qui résident
        // hors région mais officient pour un club de la région.
        $placeholders = implode(',', array_fill(0, count($depts), '?'));
        $stmt         = $pdo->prepare("
            SELECT ja.Id_JA,
                   ja.Nom,
                   ja.Prenom,
                   ja.Grade,
                   cl.Nom      AS Club,
                   COALESCE(lp.CodePostal, ja.Cp)    AS Cp,
                   COALESCE(lp.Nom,        ja.Ville) AS Ville,
                   ja.CodeDept AS Dept,
                   (SELECT COUNT(*) FROM disponible d WHERE d.Id_JA = ja.Id_JA) AS HasDispo
            FROM ja
            LEFT JOIN Club    cl ON cl.Id_Club    = ja.Id_Club
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Actif = 1
              AND ja.CodeDept IN ($placeholders)
            ORDER BY ja.CodeDept, ja.Nom, ja.Prenom
        ");
        $stmt->execute(array_values($depts));

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
}
