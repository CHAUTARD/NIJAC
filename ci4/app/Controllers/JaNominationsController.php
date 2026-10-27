<?php

namespace App\Controllers;

use App\Libraries\JustificatifPeage;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Mes nominations (EJA3), menu JA (E011), rôle « JA » (filtre "jaauth").
 *
 * Lecture seule : TOUTES les nominations (passées et à venir) du JA de la session — date, compétition,
 * division, rencontre, club recevant, accusé de réception, frais saisis. La fiche est celle du compte
 * connecté (idJaCompteSession() : rôle JA + fiche d'Id = Id_Utilisateur, jamais un id client). Requête partagée avec EJA4 (JaFraisController) :
 * nominationsDuJa(). Colonnes posées par EA98 lues seulement si présentes (AccuseReception,
 * FichierPeage, rencontre.Forfait).
 */
class JaNominationsController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        if (idJaCompteSession() <= 0) { // garde-fou : rôle JA + fiche d'Id = Id_Utilisateur
            return redirect()->to(site_url('login'));
        }
        $u = $_SESSION['utilisateur'] ?? [];

        return view('ja_nominations_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
        ]);
    }

    /** GET ja/nominations/data : liste des nominations du JA connecté. */
    public function data(): ResponseInterface
    {
        try {
            $idJa = idJaCompteSession(); // 0 → liste vide

            return $this->response->setJSON(['ok' => true, 'data' => self::nominationsDuJa(getPDO(), $idJa)]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] EJA3 data : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur technique : impossible de charger vos nominations.')]);
        }
    }

    /**
     * Nominations du JA $idJa (rattachement nomination → disponible.Id_JA), date croissante (plus ancienne → plus future, ordre par défaut d'EJA3 / EJA4).
     * Aucune donnée d'un autre JA ne peut sortir d'ici : filtre d.Id_JA = ? obligatoire.
     */
    public static function nominationsDuJa(\PDO $pdo, int $idJa): array
    {
        if ($idJa <= 0) {
            return [];
        }
        $colAccuse  = nominationAAccuseReception($pdo) ? 'n.AccuseReception' : 'NULL';
        $colFichier = nominationAFichierPeage($pdo) ? 'n.FichierPeage' : 'NULL';
        $colForfait = rencontreAForfait($pdo) ? 'r.Forfait' : '0';

        $st = $pdo->prepare("
            SELECT n.Id_Nomination, r.Id_Rencontre, r.Date, TIME_FORMAT(r.Heure, '%H:%i') AS Heure,
                   r.Journee, r.Phase, r.ArbitrageCRA, $colForfait AS Forfait,
                   ed.Division AS DivisionCode, dv.Nom AS DivisionNom,
                   ed.Nom AS NomDom, ee.Nom AS NomExt, cl.Nom AS ClubRecevant,
                   n.Peage, n.Kilometre, n.Defiscalisation, n.DateSaisie,
                   n.RapportAccueil, n.RapportEquipements,
                   $colAccuse AS AccuseReception, $colFichier AS FichierPeage
            FROM nomination n
            JOIN disponible d   ON d.Id_Disponible = n.Id_Disponible
            JOIN rencontre  r   ON r.Id_Rencontre  = n.Id_Rencontre
            JOIN equipe     ed  ON ed.Id_Equipe    = r.Id_EquipeDom
            LEFT JOIN division dv ON dv.Division   = ed.Division
            LEFT JOIN equipe   ee ON ee.Id_Equipe  = r.Id_EquipeExt
            LEFT JOIN Club     cl ON cl.Id_Club    = ed.Id_Club
            WHERE d.Id_JA = ?
            ORDER BY r.Date ASC, r.Heure ASC, n.Id_Nomination ASC
        ");
        $st->execute([$idJa]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        // Comme EN21 : seul le libellé du justificatif (type + date de dépôt) sort, jamais le nom stocké.
        foreach ($rows as &$row) {
            $row['Justificatif'] = JustificatifPeage::libelle($row['FichierPeage'] !== null ? (string) $row['FichierPeage'] : null);
            unset($row['FichierPeage']);
        }
        unset($row);

        return $rows;
    }
}
