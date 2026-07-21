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
        $colsJa = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
        if (!in_array('DateValidationFFTT', $colsJa)) {
            $pdo->exec('ALTER TABLE ja ADD COLUMN DateValidationFFTT VARCHAR(10) NULL DEFAULT NULL');
        }

        // Le département est dérivé du code postal (laposte), pas de ja.CodeDept :
        // cette colonne n'est renseignée par aucun import/écran de l'application
        // (voir JugearbitreController::majBdd) et un filtre dessus ne retournait
        // donc jamais aucune ligne. COALESCE avec ja.Cp/ja.Ville (copies
        // dénormalisées) : des JA actifs et validés FFTT ont Id_LaPoste vide
        // mais Cp/Ville renseignés directement sur la fiche, et disparaissaient
        // sinon de la liste faute de jointure laposte résolue.
        $placeholders = implode(',', array_fill(0, count($depts), '?'));
        $stmt         = $pdo->prepare("
            SELECT ja.Id_JA,
                   ja.Nom,
                   ja.Prenom,
                   ja.Grade,
                   cl.Nom      AS Club,
                   COALESCE(lp.CodePostal, ja.Cp)    AS Cp,
                   COALESCE(lp.Nom,        ja.Ville) AS Ville,
                   LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) AS Dept,
                   (SELECT COUNT(*) FROM disponible d WHERE d.Id_JA = ja.Id_JA) AS HasDispo
            FROM ja
            LEFT JOIN Club    cl ON cl.Id_Club    = ja.Id_Club
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Actif = 1
              AND ja.DateValidationFFTT IS NOT NULL AND ja.DateValidationFFTT != ''
              AND LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) IN ($placeholders)
            ORDER BY LEFT(COALESCE(lp.CodePostal, ja.Cp), 2), ja.Nom, ja.Prenom
        ");
        $stmt->execute(array_values($depts));

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
}
