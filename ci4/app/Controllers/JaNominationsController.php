<?php

namespace App\Controllers;

use App\Libraries\JustificatifPeage;

/**
 * NIJAC – Requête partagée des nominations du JA (écran EJA4 « Mes nominations et frais », JaFraisController).
 *
 * Ancien écran EJA3 « Mes nominations » fusionné dans EJA4 : il ne reste ici que nominationsDuJa()
 * (TOUTES les nominations, passées et à venir, du JA $idJa). Colonnes posées par EA98 lues seulement si
 * présentes (AccuseReception, FichierPeage, rencontre.Forfait).
 */
class JaNominationsController extends BaseController
{
    /**
     * Nominations du JA $idJa (rattachement nomination → disponible.Id_JA), date croissante (plus ancienne → plus future, ordre par défaut d'EJA4).
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
            ORDER BY r.Date ASC, r.Heure ASC, r.Id_Rencontre ASC, n.Id_Nomination ASC
        ");
        $st->execute([$idJa]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        // Comme EN21 : seul le libellé du justificatif (type + date de dépôt) sort, jamais le nom stocké.
        foreach ($rows as &$row) {
            $row['Justificatif'] = JustificatifPeage::libelle($row['FichierPeage'] !== null ? (string) $row['FichierPeage'] : null);
            unset($row['FichierPeage']);
        }
        unset($row);

        return self::ajusterKilometresDuJour($rows);
    }

    /**
     * Règle d'affichage : un JA arbitrant plusieurs rencontres CRA le même jour ne garde les kilomètres que
     * de la première de la journée ; les suivantes affichent 0 (KilometreAffiche). Ordre déterministe : heure
     * de la rencontre, puis Id de rencontre croissant (l'ordre SQL de nominationsDuJa()). `Kilometre` (valeur
     * enregistrée) n'est JAMAIS modifié — il alimente la saisie des frais ; seul KilometreAffiche sert aux
     * colonnes Km / Frais kilométriques / Total estimé et aux totaux de l'écran EJA4.
     */
    private static function ajusterKilometresDuJour(array $rows): array
    {
        $vu = [];
        foreach ($rows as &$row) {
            $row['KilometreAffiche'] = $row['Kilometre'];
            if ((int) $row['ArbitrageCRA'] !== 1) {
                continue;
            }
            $jour = (string) $row['Date'];
            if (isset($vu[$jour])) {
                $row['KilometreAffiche'] = 0;
            }
            $vu[$jour] = true;
        }
        unset($row);

        return $rows;
    }
}
