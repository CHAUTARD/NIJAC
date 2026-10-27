<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Menu nominateur (E003), portage CI4 de Nominateur/menu.php.
 *
 * Tableau de bord (prochaine journée, JA actifs, convocations à envoyer,
 * rencontres sans JA, arbitrages club sans date de saisie) + grille de boutons vers les
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
            'convocations_envoyer' => 0,
            'rencontres_sans_ja'   => 0,
            'club_sans_saisie'     => 0,
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
                    WHERE j.JA1 = 1
                      AND j.CodeDept IN ($deptPh)
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

            // Convocations (nominations) dont l'email n'est pas encore envoyé, du département
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM nomination n
                    JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed    ON ed.Id_Equipe   = r.Id_EquipeDom
                    WHERE n.EmailEnvoye = 0 AND r.Date >= CURDATE()
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
                          WHERE n.Id_Rencontre = r.Id_Rencontre
                      )
                ");
                $stmt->execute($deptsAutorises);
                $stats['rencontres_sans_ja'] = (int) $stmt->fetchColumn();
            }

            // Arbitrages club (rencontre.ArbitrageCRA = 0) déjà joués de la saison, sans date de saisie
            if ($deptsAutorises) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM nomination n
                    JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed   ON ed.Id_Equipe   = r.Id_EquipeDom
                    WHERE " . $this->sqlCritereClubSansSaisie($deptPh) . "
                ");
                $stmt->execute(array_merge([$this->debutSaison()], $deptsAutorises));
                $stats['club_sans_saisie'] = (int) $stmt->fetchColumn();
            }
        } catch (\Throwable $e) {
            // Tableau de bord non disponible — on continue sans bloquer
            error_log('[NIJAC] E003 tableau de bord : ' . $e->getMessage());
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
            // Bouton « Accéder à mon espace JA » (POST changer-compte) : compte JA lié utilisable.
            'compteJaLie'     => $this->compteJaLieUtilisable((int) ($u['id'] ?? 0)),
        ];

        return view('nominateur_menu_index', $data);
    }

    /** Le compte a-t-il un compte JA lié utilisable (idCompteJaLie()) ? Base injoignable : non. */
    private function compteJaLieUtilisable(int $id): bool
    {
        try {
            return idCompteJaLie(getPDO(), $id) > 0;
        } catch (\Throwable $e) {
            error_log('[NIJAC] E003 compte JA lié : ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Détail de la carte « Convocations à envoyer » : une ligne par nomination
     * non convoquée (mêmes critères que le compteur ci-dessus),
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
            WHERE n.EmailEnvoye = 0 AND r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
            ORDER BY r.Date, r.Heure, ja.Nom, ja.Prenom
        ");
        $stmt->execute($deptsAutorises);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    /**
     * Détail de la carte « Rencontres sans JA » : une ligne par rencontre à
     * venir sans nomination (mêmes critères que le compteur), pour
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
                  WHERE n.Id_Rencontre = r.Id_Rencontre
              )
            ORDER BY r.Date, r.Heure
        ");
        $stmt->execute($deptsAutorises);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    /**
     * Détail de la carte « Arbitrages club sans date de saisie » : une ligne
     * par nomination (mêmes critères que le compteur), avec date, rencontre,
     * JA et club recevant, pour affichage dans une modale au clic sur la carte.
     */
    public function clubSansSaisie(): ResponseInterface
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
                   ja.Nom, ja.Prenom, cl.Nom AS NomClub
            FROM nomination n
            JOIN rencontre r    ON r.Id_Rencontre   = n.Id_Rencontre
            JOIN equipe ed      ON ed.Id_Equipe      = r.Id_EquipeDom
            LEFT JOIN equipe ee ON ee.Id_Equipe      = r.Id_EquipeExt
            LEFT JOIN club cl   ON cl.Id_Club        = ed.Id_Club
            LEFT JOIN disponible dn ON dn.Id_Disponible = n.Id_Disponible
            LEFT JOIN ja        ON ja.Id_JA          = dn.Id_JA
            WHERE " . $this->sqlCritereClubSansSaisie($deptPh) . "
            ORDER BY r.Date, r.Heure, ja.Nom, ja.Prenom
        ");
        $stmt->execute(array_merge([$this->debutSaison()], $deptsAutorises));

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    /**
     * Critère commun (compteur + détail) des arbitrages club sans date de
     * saisie : rencontre en arbitrage club, jouée (date <= aujourd'hui, la
     * saisie des frais n'étant possible qu'à partir du jour de la rencontre)
     * depuis le début de la saison (1er paramètre), nomination sans
     * DateSaisie, club recevant dans les départements autorisés.
     */
    private function sqlCritereClubSansSaisie(string $deptPh): string
    {
        return "r.ArbitrageCRA = 0
                      AND r.Date >= ? AND r.Date <= CURDATE()
                      AND (n.DateSaisie IS NULL OR n.DateSaisie = '0000-00-00')
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)";
    }

    /**
     * Début de la saison en cours (Y-m-d) : dernier passage au début de
     * phase 1 (`phase1_debut`, format MM/JJ ou MM-JJ, EA91, défaut 09/01).
     */
    private function debutSaison(): string
    {
        $p1 = getConfig('phase1_debut', '09/01');
        $m  = (int) substr($p1, 0, 2);
        $j  = (int) substr($p1, 3, 2);
        if (!checkdate($m, $j, 2000)) {
            $m = 9;
            $j = 1;
        }
        $annee = (int) date('Y');
        if ((int) date('n') * 100 + (int) date('j') < $m * 100 + $j) {
            $annee--;
        }

        return sprintf('%04d-%02d-%02d', $annee, $m, $j);
    }
}
