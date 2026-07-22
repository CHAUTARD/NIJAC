<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Menu nominateur (E020), portage CI4 de Nominateur/menu.php.
 *
 * Tableau de bord (JA actifs, nominations à valider, convocations à envoyer,
 * rencontres sans JA, prochaine journée) + grille de boutons vers les
 * fonctions de nomination. Pas de Model : agrégations ponctuelles multi-
 * tables pour le tableau de bord, comme le fait le fichier legacy —
 * réutilise getPDO() directement.
 */
class NominateurMenuController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $stats = [
            'ja_actifs'            => 0,
            'nominations_valider'  => 0,
            'convocations_envoyer' => 0,
            'rencontres_sans_ja'   => 0,
            'prochaine_date'       => null,
            'prochaine_journee'    => null,
            'prochaine_saison'     => null,
        ];

        try {
            $pdo = getPDO();

            $deptsAutorises = getDepartementsAutorises($u['id_departement'] ?? null);
            $deptPh         = $deptsAutorises ? implode(',', array_fill(0, count($deptsAutorises), '?')) : "''";

            // JA actifs du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM ja j
                    LEFT JOIN Club    cl ON cl.Id_Club    = j.Id_Club
                    LEFT JOIN Salle   s  ON s.Id_Club     = cl.Id_Club AND s.EstPrincipale = 1
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                    WHERE j.Actif = 1
                      AND LEFT(lp.CodePostal, 2) IN ($deptPh)
                ");
                $stmt->execute($deptsAutorises);
                $stats['ja_actifs'] = (int) $stmt->fetchColumn();
            }

            $saison = getConfig('saison', '');

            // Prochaine journée du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT r.Date AS d, r.Journee FROM rencontre r
                    JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                    WHERE r.Date >= CURDATE()
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
                    ORDER BY r.Date ASC LIMIT 1
                ");
                $stmt->execute($deptsAutorises);
                $row = $stmt->fetch();
            } else {
                $row = [];
            }
            $stats['prochaine_date']    = $row['d'] ?? null;
            $stats['prochaine_journee'] = $row['Journee'] ?? null;
            $stats['prochaine_saison']  = $saison;

            // Nominations à valider du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM nomination n
                    JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed    ON ed.Id_Equipe   = r.Id_EquipeDom
                    WHERE n.Valide = 0 AND r.Date >= CURDATE()
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
                ");
                $stmt->execute($deptsAutorises);
                $stats['nominations_valider'] = (int) $stmt->fetchColumn();
            }

            // Convocations validées mais email non envoyé du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM nomination n
                    JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed    ON ed.Id_Equipe   = r.Id_EquipeDom
                    WHERE n.Valide = 1 AND n.EmailEnvoye = 0 AND r.Date >= CURDATE()
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
                ");
                $stmt->execute($deptsAutorises);
                $stats['convocations_envoyer'] = (int) $stmt->fetchColumn();
            }

            // Rencontres à venir sans JA nominé du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM rencontre r
                    JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                    WHERE r.Date >= CURDATE()
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
                      AND NOT EXISTS (
                          SELECT 1 FROM nomination n
                          WHERE n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
                      )
                ");
                $stmt->execute($deptsAutorises);
                $stats['rencontres_sans_ja'] = (int) $stmt->fetchColumn();
            }
        } catch (\Throwable) {
            // Tableau de bord non disponible — on continue sans bloquer
        }

        // Formater la prochaine date en français
        $prochaineDateFr = '';
        if ($stats['prochaine_date']) {
            $mois = ['', 'jan.', 'fév.', 'mar.', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sep.', 'oct.', 'nov.', 'déc.'];
            $d    = new \DateTime($stats['prochaine_date']);
            $prochaineDateFr = (int) $d->format('j') . ' ' . $mois[(int) $d->format('n')] . ' ' . $d->format('Y');
        }

        $data = [
            'nomComplet'      => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'     => $u['id_departement'] ?? '',
            'changeLogin'     => !empty($u['change_login']),
            'isAdmin'         => !empty($u['is_admin']),
            'stats'           => $stats,
            'prochaineDateFr' => $prochaineDateFr,
        ];

        return view('nominateur_menu_index', $data);
    }

    /**
     * Détail de la carte « Convocations à envoyer » : une ligne par nomination
     * validée mais non convoquée (mêmes critères que le compteur ci-dessus),
     * avec date de rencontre + nom du JA, pour affichage dans une modale au
     * clic sur la carte.
     */
    public function convocationsAEnvoyer(): ResponseInterface
    {
        $u              = $_SESSION['utilisateur'] ?? [];
        $deptsAutorises = getDepartementsAutorises($u['id_departement'] ?? null);

        if (!$deptsAutorises) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }

        $pdo    = getPDO();
        $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

        $stmt = $pdo->prepare("
            SELECT r.Date, r.Heure, r.Journee, ed.Division,
                   ed.Nom AS NomDom, ee.Nom AS NomExt,
                   ja.Nom, ja.Prenom
            FROM nomination n
            JOIN rencontre r    ON r.Id_Rencontre   = n.Id_Rencontre
            JOIN equipe ed      ON ed.Id_Equipe      = r.Id_EquipeDom
            LEFT JOIN equipe ee ON ee.Id_Equipe      = r.Id_EquipeExt
            JOIN disponible dn  ON dn.Id_Disponible  = n.Id_Disponible
            JOIN ja             ON ja.Id_JA          = dn.Id_JA
            WHERE n.Valide = 1 AND n.EmailEnvoye = 0 AND r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
            ORDER BY r.Date, r.Heure, ja.Nom, ja.Prenom
        ");
        $stmt->execute($deptsAutorises);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    /**
     * Détail de la carte « Rencontres sans JA » : une ligne par rencontre à
     * venir sans nomination validée (mêmes critères que le compteur), pour
     * affichage dans une modale au clic sur la carte.
     */
    public function rencontresSansJa(): ResponseInterface
    {
        $u              = $_SESSION['utilisateur'] ?? [];
        $deptsAutorises = getDepartementsAutorises($u['id_departement'] ?? null);

        if (!$deptsAutorises) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }

        $pdo    = getPDO();
        $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

        $stmt = $pdo->prepare("
            SELECT r.Date, r.Heure, r.Journee, ed.Division,
                   ed.Nom AS NomDom, ee.Nom AS NomExt
            FROM rencontre r
            JOIN equipe ed      ON ed.Id_Equipe = r.Id_EquipeDom
            LEFT JOIN equipe ee ON ee.Id_Equipe = r.Id_EquipeExt
            WHERE r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
              AND NOT EXISTS (
                  SELECT 1 FROM nomination n
                  WHERE n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
              )
            ORDER BY r.Date, r.Heure
        ");
        $stmt->execute($deptsAutorises);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }
}
