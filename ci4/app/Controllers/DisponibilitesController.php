<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Saisie des disponibilités JA par le nominateur (EN13), portage CI4
 * de Nominateur/disponibilites.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth").
 * Un seul point d'API en lecture (JA actifs par département) ; le lien vers
 * la fiche de disponibilité utilise l'Id_JA réel dans l'URL, pas de token
 * Obfuscator — ni le fichier legacy ni ce portage n'importent
 * Classes/Obfuscator.php (voir SPECIFICATION.md, section EN13).
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
        // La table competition_regionale (et son seed initial) est créée par initTableConfiguration() (EA98) ;
        // édition ensuite via EA84. Ce constructeur la recréait + réinsérait les dates 2026 à chaque requête
        // dès qu'elle était vide (ex. calendrier d'une nouvelle saison vidé dans EA84).
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

        // Pour chaque département actif : ses voisins de la région déjà pas
        // inclus d'office (regles_departements) — proposés en cases à cocher
        // dans EN13 pour étendre l'affichage aux départements limitrophes.
        $data['limitrophesParDept'] = [];
        foreach ($data['deptActifs'] as $d) {
            $code = (string) $d['CodeDept'];
            $auto = getDepartementsAutorises($code);
            $data['limitrophesParDept'][$code] = array_values(array_filter(
                getLimitrophesRegion($code),
                static fn ($l) => !in_array($l['CodeDept'], $auto, true)
            ));
        }

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

        // Départements limitrophes cochés dans EN13 : on ne garde que ceux
        // réellement voisins de $dept dans la région (pas de code arbitraire).
        $extra = array_filter(array_map('trim', explode(',', (string) $this->request->getGet('extra'))));
        if ($extra) {
            $voisinsOk = array_column(getLimitrophesRegion($dept), 'CodeDept');
            $depts     = array_values(array_unique(array_merge($depts, array_intersect($extra, $voisinsOk))));
        }

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
            WHERE ja.JA1 = 1
              AND ja.CodeDept IN ($placeholders)
            ORDER BY ja.CodeDept, ja.Nom, ja.Prenom
        ");
        $stmt->execute(array_values($depts));

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
}
