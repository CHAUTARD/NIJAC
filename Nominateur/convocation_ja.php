<?php
/**
 * NIJAC – Convocation et frais JA (E023)
 *
 * Page de convocation officielle d'un Juge-Arbitre pour une rencontre donnée.
 * Affiche les détails de la rencontre, confirme la nomination et permet
 * au JA de saisir ses frais de déplacement (km, péages, indemnité forfaitaire).
 * Accessible via un lien tokenisé (obfusqué) ou en accès direct interne.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/app_config.php';
require __DIR__ . '/../Classes/Obfuscator.php';

// Session nécessaire pour le token CSRF de la page de convocation publique
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/csrf.php';

// DÃ©codage du paramÃ¨tre obfusquÃ© ?ja=TOKEN 
if (isset($_GET['ja']) && $_GET['ja'] !== '') {
    $decoded = $_obf->deobfuscate(trim($_GET['ja']));
    if ($decoded > 0) $_GET['id_ja'] = $decoded;
}

$pdo          = getPDO();
$idJa         = (int)($_GET['id_ja']     ?? 0);
$idRencontre  = (int)($_GET['rencontre'] ?? 0);

// Action AJAX : sauvegarder les frais
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'sauvegarder_frais') {
    header('Content-Type: application/json; charset=utf-8');
    csrfVerify(true);
    try {
        $idJaP   = (int)($_POST['id_ja']       ?? 0);
        $idRencP = (int)($_POST['id_rencontre'] ?? 0);

        if (!$idJaP || !$idRencP) {
            echo json_encode(['ok' => false, 'err' => 'Paramètres manquants.']); exit;
        }

        // ── Parsing ──────────────────────────────────────────────────────────
        $peagesRaw = trim($_POST['peages'] ?? '');
        $kmRaw     = trim($_POST['km']     ?? '');
        $rapAcc    = trim($_POST['rapport_accueil']     ?? '');
        $rapEq     = trim($_POST['rapport_equipements'] ?? '');

        $peages = $peagesRaw !== '' ? (float)str_replace(',', '.', $peagesRaw) : null;
        $km     = $kmRaw     !== '' ? (int)$kmRaw : null;

        // ── Validation ───────────────────────────────────────────────────────
        $maxPeages = (float)getConfig('frais_max_peages', '80');
        $maxKm     = (int)getConfig('frais_max_km', '200');
        $erreurs   = [];

        if ($peages !== null) {
            if (!is_numeric(str_replace(',', '.', $peagesRaw))) {
                $erreurs[] = 'Le montant des péages n\'est pas un nombre valide.';
            } elseif ($peages < 0) {
                $erreurs[] = 'Le montant des péages ne peut pas être négatif.';
            } elseif ($peages > $maxPeages) {
                $erreurs[] = "Le montant des péages semble aberrant (maximum {$maxPeages} €).";
            }
        }

        if ($km !== null) {
            if (!ctype_digit($kmRaw) && !(substr($kmRaw, 0, 1) === '-' && ctype_digit(substr($kmRaw, 1)))) {
                $erreurs[] = 'Le nombre de kilomètres n\'est pas un entier valide.';
            } elseif ($km < 0) {
                $erreurs[] = 'Le nombre de kilomètres ne peut pas être négatif.';
            } elseif ($km > $maxKm) {
                $erreurs[] = "Le nombre de kilomètres semble aberrant (maximum {$maxKm} km).";
            }
        }

        if (mb_strlen($rapAcc) > 2000) {
            $erreurs[] = 'Le rapport d\'accueil ne doit pas dépasser 2 000 caractères.';
        }
        if (mb_strlen($rapEq) > 2000) {
            $erreurs[] = 'Le rapport équipements ne doit pas dépasser 2 000 caractères.';
        }

        if ($erreurs) {
            echo json_encode(['ok' => false, 'err' => implode(' ', $erreurs)]); exit;
        }

        // ── Enregistrement ───────────────────────────────────────────────────
        $pdo->prepare("
            UPDATE `nomination` SET
                Peages             = ?,
                Kilometres         = ?,
                RapportAccueil     = ?,
                RapportEquipements = ?,
                DateSaisie         = CURDATE()
            WHERE Id_JA = ? AND Id_Rencontre = ?
        ")->execute([$peages, $km, $rapAcc ?: null, $rapEq ?: null, $idJaP, $idRencP]);

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
    }
    exit;
}

// Chargement des donnÃ©es 
$erreur = '';

if ($idJa && $idRencontre) {
    try {
        //  JA 
        $jaCols   = array_column($pdo->query('DESCRIBE ja')->fetchAll(), 'Field');
        $hasCp    = in_array('Cp',    $jaCols);
        $hasVille = in_array('Ville', $jaCols);
        $cpExpr    = $hasCp    ? 'COALESCE(lp.CodePostal, ja.Cp)'    : 'lp.CodePostal';
        $villeExpr = $hasVille ? 'COALESCE(lp.Nom, ja.Ville)'        : 'lp.Nom';

        $stmtJa = $pdo->prepare("
            SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                   cl.Nom AS Association,
                   $cpExpr    AS Cp,
                   $villeExpr AS Ville,
                   ja.Id_LaPoste,
                   lp.Latitude  AS JaLat,
                   lp.Longitude AS JaLon
            FROM ja
            LEFT JOIN Club     cl ON cl.Id_Club      = ja.Id_Club
            LEFT JOIN laposte  lp ON lp.Id_LaPoste   = ja.Id_LaPoste
            WHERE ja.Id_JA = ?
        ");
        $stmtJa->execute([$idJa]);
        $ja = $stmtJa->fetch();

        //  Rencontre
        $rencCols = array_column($pdo->query('DESCRIBE rencontre')->fetchAll(), 'Field');
        // NumÃ©ro de convocation (ref PDF : 457)
        $convNum  = in_array('NumConvocation', $rencCols) ? 'r.NumConvocation' : 'r.Id_Rencontre';
        $hasPhase = in_array('Phase', $rencCols);
        $phaseSel = $hasPhase ? 'r.Phase,' : 'NULL AS Phase,';
        $hasIdJa  = in_array('Id_JA', $rencCols);

        $stmtR = $pdo->prepare("
            SELECT r.Id_Rencontre, r.Saison, r.Journee, r.Date, r.Heure, r.Poule,
                   $convNum  AS NumConvocation,
                   $phaseSel
                   d.Division AS DivisionCode, d.Nom AS DivisionNom,
                   ed.Nom     AS NomDom,  ed.Id_Club AS IdClubDom,
                   ee.Nom     AS NomExt,
                   -- Salle
                   COALESCE(s_r.Nom,  s_c.Nom)                        AS NomSalle,
                   COALESCE(lp_r.CodePostal, lp_c.CodePostal)         AS CpSalle,
                   COALESCE(lp_r.Nom, lp_c.Nom)                       AS VilleSalle,
                   COALESCE(s_r.Adresse, s_c.Adresse)                 AS AdresseSalle,
                   COALESCE(lp_r.Latitude,  lp_c.Latitude)            AS VenueLat,
                   COALESCE(lp_r.Longitude, lp_c.Longitude)           AS VenueLon
            FROM rencontre r
            JOIN  division d    ON d.Id_Division  = r.Id_Division
            JOIN  equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
            LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
            LEFT JOIN salle   s_r  ON s_r.Id_Salle  = r.id_Salle
            LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
            LEFT JOIN salle   s_c  ON s_c.Id_Club = ed.Id_Club AND s_c.EstPrincipale = 1
            LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
            WHERE r.Id_Rencontre = ?
        ");
        $stmtR->execute([$idRencontre]);
        $rencontre = $stmtR->fetch();

        if (!$rencontre) $erreur = "Rencontre #$idRencontre introuvable.";

        //  Correspondant du club recevant 
        $correspondant = null;
        if ($rencontre) {
            $stmtC = $pdo->prepare("
                SELECT Nom, Telephone FROM Correspondant
                WHERE Id_Club = ?
                ORDER BY Id_Correspondant LIMIT 1
            ");
            $stmtC->execute([$rencontre['IdClubDom']]);
            $correspondant = $stmtC->fetch();
        }

        //  Frais dÃ©jÃ  saisis 
        try {
            $stmtF = $pdo->prepare("
                SELECT Peages, Kilometres, RapportAccueil, RapportEquipements
                FROM nomination WHERE Id_JA = ? AND Id_Rencontre = ?
            ");
            $stmtF->execute([$idJa, $idRencontre]);
            $frais = $stmtF->fetch();
        } catch (PDOException $e) { /* ignore */ }

        //  Distance Haversine 
        $kmCalc = null;
        if ($ja && $rencontre
            && $ja['JaLat']       && $ja['JaLon']
            && $rencontre['VenueLat'] && $rencontre['VenueLon']) {
            $lat1 = deg2rad($ja['JaLat']);   $lon1 = deg2rad($ja['JaLon']);
            $lat2 = deg2rad($rencontre['VenueLat']); $lon2 = deg2rad($rencontre['VenueLon']);
            $kmCalc = (int)round(6371 * acos(max(-1, min(1,
                cos($lat1)*cos($lat2)*cos($lon2-$lon1) + sin($lat1)*sin($lat2)
            ))));
        }

    } catch (PDOException $e) {
        $erreur = 'Erreur BDD : ' . $e->getMessage();
    }
} elseif (!$idJa || !$idRencontre) {
    $erreur = 'ParamÃ¨tres manquants (id_ja et rencontre requis).';
}

//  Valeurs d'affichage 
$indemniteForfait = (float)getConfig('indemnite_forfaitaire', '25.00');
$tauxKm           = (float)getConfig('frais_kilometrique', '0.30');
$peages           = $frais['Peages']      ?? 0;
$km               = $frais['Kilometres']  ?? $kmCalc ?? 0;
$total            = $indemniteForfait + $peages + ($km * $tauxKm);

// Formater les dates
$dateFormatee = '';
if ($rencontre && $rencontre['Date']) {
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois  = ['','janvier','fÃ©vrier','mars','avril','mai','juin','juillet','aoÃ»t','septembre','octobre','novembre','dÃ©cembre'];
    $d = new DateTime($rencontre['Date']);
    $dateFormatee = $jours[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n')] . ' ' . $d->format('Y');
}
$heure = $rencontre ? substr($rencontre['Heure'] ?? '09:00', 0, 5) : '';

$saisonAffich = $rencontre['Saison'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Convocation JA (E023)<?= $ja ? ' – ' . htmlspecialchars($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
<link rel="stylesheet" href="../asset/css/bootstrap.min.css">
<link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
<style>
    /*  Variables  */
    :root {
        --fftt-blue:   #003087;
        --fftt-red:    #c8102e;
        --border-dark: #333;
        --border-med:  #666;
        --border-light:#aaa;
    }

    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; background: #f5f5f5; }

    /*  Barre d'actions (masquÃ©e Ã  l'impression)  */
    #action-bar {
        background: var(--fftt-blue);
        color: #fff;
        padding: .6rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    #action-bar h1 { font-size: 1rem; font-weight: 700; margin: 0; flex: 1; }

    /*  Feuille A4  */
    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 1.5rem auto;
        background: #fff;
        box-shadow: 0 2px 12px rgba(0,0,0,.18);
        padding: 14mm 16mm 12mm;
        box-sizing: border-box;
        position: relative;
    }

    /*  EncadrÃ© numÃ©ro + phase (coin haut droit) */
    .num-phase {
        position: absolute;
        top: 10mm;
        right: 14mm;
        text-align: right;
        font-size: 11px;
    }
    .num-phase .conv-num { font-size: 20px; font-weight: 900; }
    .num-phase .phase-line { font-size: 10px; color: #444; }

    /* En-tÃªte : logo + titre */
    .conv-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 6mm;
    }
    .conv-header .fftt-logo {
        width: 90px;
        flex-shrink: 0;
    }
    .conv-header .fftt-adresse {
        font-size: 10px;
        line-height: 1.4;
        flex: 1;
    }
    .conv-header .fftt-adresse strong { display: block; }
    .conv-title {
        font-size: 18px;
        font-weight: 900;
        text-align: center;
        letter-spacing: 2px;
        margin-bottom: 5mm;
        border-top: 2px solid var(--border-dark);
        border-bottom: 2px solid var(--border-dark);
        padding: 3px 0;
    }

    /* IdentitÃ© JA  */
    table.tbl-ja {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5mm;
        font-size: 11px;
    }
    table.tbl-ja td { padding: 2px 4px; }
    table.tbl-ja .lbl { font-weight: 700; text-align: right; width: 42mm; padding-right: 6px; }
    table.tbl-ja .val { font-weight: 700; font-size: 12px; }

    /* Corps texte */
    .conv-body-text { font-size: 11px; margin: 4mm 0 3mm; }

    /* Tableau rencontre */
    table.tbl-renc {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4mm;
    }
    table.tbl-renc th, table.tbl-renc td {
        border: 1px solid var(--border-dark);
        padding: 3px 6px;
        font-size: 11px;
    }
    table.tbl-renc th { font-weight: 700; background: #f0f0f0; }
    table.tbl-renc .val-center { text-align: center; font-weight: 700; }
    table.tbl-renc .val-bold   { font-weight: 700; font-size: 12px; }

    /* Adresse salle */
    .salle-bloc {
        font-size: 11px;
        margin-bottom: 4mm;
        display: flex;
        gap: 6px;
        align-items: baseline;
    }
    .salle-bloc .salle-lbl { font-weight: 700; white-space: nowrap; }
    .salle-bloc .salle-val { font-weight: 700; font-size: 12px; letter-spacing: .5px; }

    /*  Correspondant  */
    .correspondant-bloc {
        font-size: 11px;
        margin-bottom: 5mm;
        border: 1px solid var(--border-light);
        padding: 3px 8px;
        background: #fafafa;
        border-radius: 3px;
    }
    .correspondant-bloc .corr-lbl { font-weight: 700; }
    .correspondant-bloc .corr-val { font-size: 12px; font-weight: 700; }

    /*  Tableau indemnitÃ©s  */
    table.tbl-indem {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5mm;
    }
    table.tbl-indem th, table.tbl-indem td {
        border: 1px solid var(--border-dark);
        padding: 4px 8px;
        font-size: 11px;
        text-align: center;
    }
    table.tbl-indem th { font-weight: 700; background: #e8e8e8; }
    table.tbl-indem .sep { border: none; width: 8px; padding: 0; font-weight: 700; font-size: 13px; }
    table.tbl-indem .val-money { font-weight: 900; font-size: 13px; }
    table.tbl-indem .total-cell {
        font-weight: 900;
        font-size: 15px;
        background: #e8f5e9;
        color: #1b5e20;
        border: 2px solid #333;
    }
    /* Champs Ã©ditables */
    table.tbl-indem input[type="number"],
    table.tbl-indem input[type="text"] {
        width: 100%;
        border: none;
        border-bottom: 2px solid var(--fftt-blue);
        text-align: center;
        font-weight: 900;
        font-size: 13px;
        background: transparent;
        outline: none;
        padding: 0;
        font-family: Arial, Helvetica, sans-serif;
    }
    table.tbl-indem input:focus { border-bottom-color: var(--fftt-red); background: #fffde7; }

    /*  Rapport JA  */
    table.tbl-rapport {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6mm;
    }
    table.tbl-rapport th, table.tbl-rapport td {
        border: 1px solid var(--border-dark);
        padding: 4px 8px;
        font-size: 11px;
        vertical-align: top;
    }
    table.tbl-rapport th { font-weight: 700; width: 35mm; }
    table.tbl-rapport textarea {
        width: 100%;
        border: none;
        resize: none;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 11px;
        background: transparent;
        outline: none;
        min-height: 28px;
    }
    table.tbl-rapport textarea:focus { background: #fffde7; }
    .rapport-titre-cell {
        background: #e8e8e8;
        font-weight: 700;
        text-align: center;
        font-size: 10px;
        font-style: italic;
    }

    /*  Formule de politesse  */
    .conv-footer-text { font-size: 11px; margin-bottom: 4mm; }
    .conv-signature {
        text-align: right;
        font-size: 11px;
        margin-bottom: 6mm;
        font-style: italic;
    }

    /*  Bande info bas  */
    .conv-bas {
        background: #1a1a1a;
        color: #fff;
        font-size: 10px;
        padding: 4px 8px;
        line-height: 1.6;
    }

    /*  Date Ã©mission  */
    .conv-date-emission {
        text-align: right;
        font-size: 10px;
        color: #555;
        margin-top: 2mm;
    }

    /*  Bouton sauvegarder (hors impression)  */
    #btn-save-frais {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        z-index: 999;
        box-shadow: 0 4px 12px rgba(0,0,0,.3);
    }
    #save-status { display: none; font-size: .8rem; color: #155724; margin-left: .5rem; }

    /*  Impression  */
    @media print {
        body { background: #fff !important; font-size: 11px; }
        #action-bar, #btn-save-frais { display: none !important; }
        .page {
            width: auto;
            margin: 0;
            box-shadow: none;
            padding: 8mm 12mm;
        }
        table.tbl-indem input,
        table.tbl-rapport textarea {
            border-bottom: 1px solid #aaa !important;
        }
    }
</style>
</head>
<body>

<!--  Barre d'actions  -->
<div id="action-bar">
    <h1><i class="bi bi-file-earmark-text me-2"></i>Convocation Juge-Arbitre</h1>
    <?php if ($ja && $rencontre): ?>
    <button class="btn btn-sm btn-light" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Imprimer / PDF
    </button>
    <?php endif; ?>
</div>

<?php if ($erreur): ?>
<div class="alert alert-danger m-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($erreur) ?>
</div>
<?php elseif (!$ja || !$rencontre): ?>
<div class="alert alert-warning m-3">
    <i class="bi bi-info-circle me-2"></i>
    Veuillez fournir les paramÃ¨tres <code>id_ja</code> (ou <code>ja</code>) et <code>rencontre</code>.
</div>
<?php else: ?>

<!--  Page A4  -->
<div class="page">

    <!-- NumÃ©ro convocation + phase -->
    <div class="num-phase">
        <div class="conv-num"><?= htmlspecialchars($rencontre['NumConvocation'] ?? $idRencontre) ?></div>
        <?php if ($rencontre['Phase']): ?>
        <div class="phase-line"><?= htmlspecialchars($rencontre['Phase']) ?></div>
        <div class="phase-line">ARB 2.4</div>
        <?php endif; ?>
    </div>

    <!-- En-tÃªte FFTT -->
    <div class="conv-header">
        <img src="../img/JA.png" class="fftt-logo" alt="FFTT" onerror="this.style.display='none'">
        <div class="fftt-adresse">
            <strong>FÃ‰DÃ‰RATION FRANÃ‡AISE DE TENNIS DE TABLE</strong>
            3, rue DieudonnÃ© Costes â€“ BP 40348<br>
            75625 PARIS Cedex 13<br>
            TÃ©l : 01 53 94 50 00
        </div>
    </div>

    <!-- Titre -->
    <div class="conv-title">CONVOCATION</div>

    <!-- IdentitÃ© JA -->
    <table class="tbl-ja">
        <tr>
            <td class="lbl">NOM DU JUGE-ARBITRE :</td>
            <td class="val"><?= htmlspecialchars(strtoupper($ja['Nom']) . ' ' . $ja['Prenom']) ?></td>
        </tr>
        <tr>
            <td class="lbl">NÂ° de licence :</td>
            <td class="val"><?= htmlspecialchars($idJa) ?></td>
        </tr>
        <tr>
            <td class="lbl">ASSOCIATION :</td>
            <td class="val"><?= htmlspecialchars($ja['Association'] ?? 'â€”') ?></td>
        </tr>
        <tr>
            <td class="lbl">LIGUE :</td>
            <td class="val">NORMANDIE</td>
        </tr>
    </table>

    <!-- Texte intro -->
    <div class="conv-body-text">
        J'ai l'avantage de vous informer que vous Ãªtes dÃ©signÃ©(e) pour diriger la rencontre suivante du<br>
        <strong>CHAMPIONNAT DE FRANCE PAR ÉQUIPES</strong>
    </div>

    <!-- Tableau rencontre -->
    <table class="tbl-renc">
        <tr>
            <th>JournÃ©e nÂ°</th>
            <th colspan="2">Division :</th>
            <th>Poule :</th>
        </tr>
        <tr>
            <td class="val-center"><?= htmlspecialchars($rencontre['Journee'] ?? 'â€”') ?></td>
            <td class="val-center" colspan="2"><?= htmlspecialchars($rencontre['DivisionCode'] ?? 'â€”') ?></td>
            <td class="val-center"><?= htmlspecialchars($rencontre['Poule'] ?? 'â€”') ?></td>
        </tr>
        <tr>
            <th>Opposant</th>
            <td class="val-bold" style="width:38%"><?= htmlspecialchars($rencontre['NomDom'] ?? 'â€”') ?></td>
            <td style="text-align:center;font-size:10px;width:4%">Ã </td>
            <td class="val-bold"><?= htmlspecialchars($rencontre['NomExt'] ?? 'â€”') ?></td>
        </tr>
        <tr>
            <th>le</th>
            <td class="val-bold" colspan="2"><?= htmlspecialchars($dateFormatee) ?></td>
            <td class="val-center">Ã  <?= htmlspecialchars($heure) ?></td>
        </tr>
    </table>

    <!-- Adresse salle -->
    <div class="salle-bloc">
        <span class="salle-lbl">ADRESSE DE LA SALLE :</span>
        <span class="salle-val">
            <?php
            $salleText = implode('  ', array_filter([
                $rencontre['NomSalle']    ?? null,
                $rencontre['AdresseSalle']?? null,
                $rencontre['CpSalle']     ?? null,
                $rencontre['VilleSalle']  ?? null,
            ]));
            echo htmlspecialchars($salleText ?: 'â€”');
            ?>
        </span>
    </div>

    <!-- Correspondant -->
    <div class="correspondant-bloc">
        <span class="corr-lbl">NOM â€“ PRÃ‰NOM et ADRESSE du CORRESPONDANT du CLUB RECEVANT :</span><br>
        <span class="corr-val"><?= htmlspecialchars($correspondant['Nom'] ?? '') ?></span>
        <?php if ($correspondant && $correspondant['Telephone']): ?>
        &nbsp;&nbsp;&nbsp;
        <span style="font-size:11px;">TÃ©l : <strong><?= htmlspecialchars($correspondant['Telephone']) ?></strong></span>
        <?php else: ?>
        &nbsp;&nbsp;&nbsp;<span style="font-size:11px; color:#999">TÃ©l : â€”</span>
        <?php endif; ?>
    </div>

    <!--  Tableau indemnitÃ©s / frais  -->
    <table class="tbl-indem" id="tbl-frais">
        <thead>
            <tr>
                <th>INDEMNITÃ‰ FIXE</th>
                <th class="sep"></th>
                <th>PÃ‰AGES</th>
                <th style="width:8mm"></th>
                <th colspan="2">DÃ‰PLACEMENT</th>
                <th style="width:8mm"></th>
                <th>TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="val-money" id="td-indem"><?= number_format($indemniteForfait, 2, ',', ' ') ?> â‚¬</td>
                <td class="sep">+</td>
                <td>
                    <input type="number" id="inp-peages" min="0" step="0.01"
                           value="<?= htmlspecialchars(number_format($peages, 2, '.', '')) ?>"
                           placeholder="0,00"> â‚¬
                </td>
                <td class="sep">+</td>
                <td>
                    <input type="number" id="inp-km" min="0" step="1"
                           value="<?= (int)$km ?>"
                           placeholder="0">
                </td>
                <td style="white-space:nowrap;padding-left:2px;">
                    km &nbsp;Ã—&nbsp; <?= number_format($tauxKm, 2, ',', ' ') ?> â‚¬
                </td>
                <td class="sep">=</td>
                <td class="total-cell" id="td-total"><?= number_format($total, 2, ',', ' ') ?> â‚¬</td>
            </tr>
        </tbody>
    </table>

    <!--  Rapport JA  -->
    <table class="tbl-rapport">
        <tr>
            <th>Rapport de Juge-Arbitre</th>
            <td class="rapport-titre-cell">remplir si vous rencontrez des problÃ¨mes</td>
        </tr>
        <tr>
            <th>Accueil, ambiance</th>
            <td><textarea id="inp-rapport-accueil" rows="2"><?= htmlspecialchars($frais['RapportAccueil'] ?? '') ?></textarea></td>
        </tr>
        <tr>
            <th>Ã‰quipements, salleâ€¦</th>
            <td><textarea id="inp-rapport-eq" rows="2"><?= htmlspecialchars($frais['RapportEquipements'] ?? '') ?></textarea></td>
        </tr>
    </table>

    <!-- Formule de politesse -->
    <div class="conv-footer-text">Veuillez agrÃ©er mes meilleurs sentiments.</div>
    <div class="conv-signature">
        Pour le PrÃ©sident de la Commission<br>
        RÃ©gionale d'Arbitrage
    </div>

    <!-- Bande basse -->
    <div class="conv-bas">
        Vos indemnitÃ©s de juge-arbitrage vous seront payÃ©es en fin de phase directement par la ligue.<br>
        Pensez Ã  transmettre un RIB Ã  la ligue.
    </div>

    <!-- Date Ã©mission -->
    <div class="conv-date-emission"><?= date('d/m/Y') ?></div>

</div><!-- /.page -->

<!--  Bouton sauvegarder (hors impression)  -->
<div style="text-align:center;margin-bottom:2rem">
    <button id="btn-save-frais" class="btn btn-success btn-lg">
        <i class="bi bi-floppy me-1"></i>Enregistrer les frais
    </button>
    <span id="save-status"><i class="bi bi-check-circle-fill me-1"></i>EnregistrÃ© !</span>
</div>

<?php endif; ?>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
    <script src="../asset/js/nijac-csrf.js"></script>
<script>
'use strict';
const INDEM   = <?= json_encode((float)$indemniteForfait) ?>;
const TAUX_KM = <?= json_encode((float)$tauxKm) ?>;
const ID_JA         = <?= (int)$idJa ?>;
const ID_RENCONTRE  = <?= (int)$idRencontre ?>;

function recalcTotal() {
    const peages = parseFloat($('#inp-peages').val()) || 0;
    const km     = parseInt($('#inp-km').val())       || 0;
    const total  = INDEM + peages + km * TAUX_KM;
    $('#td-total').text(total.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' â‚¬');
}

$('#inp-peages, #inp-km').on('input', recalcTotal);

$('#btn-save-frais').on('click', function () {
    const $btn = $(this).prop('disabled', true);
    $.post('convocation_ja.php', {
        action:              'sauvegarder_frais',
        id_ja:               ID_JA,
        id_rencontre:        ID_RENCONTRE,
        peages:              $('#inp-peages').val(),
        km:                  $('#inp-km').val(),
        rapport_accueil:     $('#inp-rapport-accueil').val(),
        rapport_equipements: $('#inp-rapport-eq').val(),
    }, function (r) {
        $btn.prop('disabled', false);
        if (r.ok) {
            $('#save-status').fadeIn().delay(2500).fadeOut();
        } else {
            alert('Erreur : ' + (r.err || 'inconnue'));
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false);
        alert('Erreur rÃ©seau.');
    });
});

// Recalc initial
recalcTotal();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

