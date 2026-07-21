<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>Convocation JA (E031)<?= $ja ? ' – ' . esc($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
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

    /*  Barre d'actions (masquée à l'impression)  */
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

    /*  Encadré numéro + phase (coin haut droit) */
    .num-phase {
        position: absolute;
        top: 10mm;
        right: 14mm;
        text-align: right;
        font-size: 11px;
    }
    .num-phase .conv-num { font-size: 20px; font-weight: 900; }
    .num-phase .phase-line { font-size: 10px; color: #444; }

    /* En-tête : logo + titre */
    .conv-header {
        margin-bottom: 6mm;
        border-collapse: collapse;
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

    /* Identité JA  */
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

    /*  Tableau indemnités  */
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
        background: var(--fftt-blue);
        color: #fff;
        font-size: 10px;
        padding: 4px 8px;
        line-height: 1.6;
    }

    /*  Date émission  */
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

    /*  Impression / Export PDF  */
    @media print {
        @page { size: A4 portrait; margin: 12mm 14mm; }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body {
            background: #fff !important;
            font-size: 11px;
            color: #000 !important;
        }

        #action-bar,
        #btn-save-frais,
        .btn, .alert-info,
        .modal, .modal-backdrop,
        script, style { display: none !important; }

        .page {
            width: auto;
            margin: 0;
            box-shadow: none !important;
            border: none !important;
            padding: 0;
        }

        table { border-collapse: collapse !important; }
        table td, table th { border: 1px solid #555 !important; }

        table.tbl-indem input,
        table.tbl-rapport textarea {
            border: none !important;
            border-bottom: 1px solid #333 !important;
            background: transparent !important;
        }

        .page > * { page-break-inside: avoid; }
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
    <a href="javascript:history.back()" class="btn btn-sm btn-outline-light ms-auto">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>

<?php if ($erreur): ?>
<div class="alert alert-danger m-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($erreur) ?>
</div>
<?php elseif (!$ja || !$rencontre): ?>
<div class="alert alert-warning m-3">
    <i class="bi bi-info-circle me-2"></i>
    Paramètre <code>nomination</code> manquant dans l'URL.
</div>
<?php else: ?>

<!--  Page A4  -->
<div class="page">

    <!-- Numéro convocation + phase -->
    <div class="num-phase">
        <div class="conv-num"><?= esc($rencontre['NumConvocation'] ?? $idRencontre) ?></div>
        <?php if ($rencontre['Phase']): ?>
        <div class="phase-line"><?= esc($rencontre['Phase']) ?></div>
        <div class="phase-line"></div>
        <?php endif; ?>
    </div>

    <!-- En-tête FFTT -->
    <table class="conv-header" width="100%">
        <tr>
            <td class="fftt-adresse">
                <strong>FÉDÉRATION FRANÇAISE<br/>DE TENNIS DE TABLE</strong><br/>
                3, rue Dieudonné Costes – BP 40348<br>
                75625 PARIS Cedex 13<br>
                Tél : 01.53.94.50.00
            </td>
            <td style="width:100px;text-align:right;vertical-align:middle;">
                <img src="<?= base_url('img/logo_FFTT.png') ?>" class="fftt-logo" alt="FFTT" onerror="this.style.display='none'">
            </td>
        </tr>
    </table>

    <!-- Titre -->
    <div class="conv-title">CONVOCATION</div>

    <!-- Identité JA -->
    <table class="tbl-ja">
        <tr>
            <td class="lbl">NOM DU JUGE-ARBITRE :</td>
            <td class="val"><?= esc(mb_strtoupper($ja['Nom']) . ' ' . $ja['Prenom']) ?></td>
        </tr>
        <tr>
            <td class="lbl">N° de licence :</td>
            <td class="val"><?= esc($idJa) ?></td>
        </tr>
        <tr>
            <td class="lbl">ASSOCIATION :</td>
            <td class="val"><?= esc($ja['Association'] ?? '–') ?></td>
        </tr>
        <tr>
            <td class="lbl">LIGUE :</td>
            <td class="val">NORMANDIE</td>
        </tr>
    </table>

    <!-- Texte intro -->
    <div class="conv-body-text">
        J'ai l'avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante du<br>
        <strong>CHAMPIONNAT DE FRANCE PAR ÉQUIPES</strong>
    </div>

    <!-- Tableau rencontre -->
    <table class="tbl-renc">
        <tr>
            <th>Journée n°</th>
            <th colspan="2">Division :</th>
            <th>Poule :</th>
        </tr>
        <tr>
            <td class="val-center"><?= esc($rencontre['Journee'] ?? '–') ?></td>
            <td class="val-center" colspan="2"><?= esc($rencontre['DivisionCode'] ?? '–') ?></td>
            <td class="val-center"><?= esc($rencontre['Poule'] ?? '–') ?></td>
        </tr>
        <tr>
            <th>Opposant</th>
            <td class="val-bold" style="width:38%"><?= esc($rencontre['NomDom'] ?? '–') ?></td>
            <td style="text-align:center;font-size:10px;width:4%">à</td>
            <td class="val-bold"><?= esc($rencontre['NomExt'] ?? '–') ?></td>
        </tr>
        <tr>
            <th>le</th>
            <td class="val-bold" colspan="2"><?= esc($dateFormatee) ?></td>
            <td class="val-center">à <?= esc($heure) ?></td>
        </tr>
    </table>

    <!-- Adresse salle -->
    <div class="salle-bloc">
        <span class="salle-lbl">ADRESSE DE LA SALLE :</span>
        <span class="salle-val">
            <?php
            $salleText = implode('  ', array_filter([
                $rencontre['NomSalle']     ?? null,
                $rencontre['AdresseSalle'] ?? null,
                $rencontre['CpSalle']      ?? null,
                $rencontre['VilleSalle']   ?? null,
            ]));
            echo esc($salleText ?: '–');
            ?>
        </span>
    </div>

    <!-- Correspondant -->
    <div class="correspondant-bloc">
        <span class="corr-lbl">NOM – PRÉNOM et ADRESSE du CORRESPONDANT du CLUB RECEVANT :</span><br>
        <span class="corr-val"><?= esc($correspondant['Nom'] ?? '') ?></span>
        <?php if ($correspondant && $correspondant['Telephone']): ?>
        &nbsp;&nbsp;&nbsp;
        <span style="font-size:11px;">Tél : <strong><?= esc($correspondant['Telephone']) ?></strong></span>
        <?php else: ?>
        &nbsp;&nbsp;&nbsp;<span style="font-size:11px; color:#999">Tél : –</span>
        <?php endif; ?>
    </div>

    <!--  Tableau indemnités / frais  -->
    <table class="tbl-indem" id="tbl-frais">
        <thead>
            <tr>
                <th>INDEMNITÉ FIXE</th>
                <th class="sep"></th>
                <th>PÉAGES</th>
                <th style="width:8mm"></th>
                <th colspan="2">DÉPLACEMENT</th>
                <th style="width:8mm"></th>
                <th>TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="val-money" id="td-indem"><?= number_format($indemniteForfait, 2, ',', ' ') ?> €</td>
                <td class="sep">+</td>
                <td>
                    <div class="input-group input-group-sm" style="width:80px">
                        <input type="number" id="inp-peages" class="form-control" min="0" step="0.01"
                               style="min-width:0;width:50px"
                               value="<?= esc(number_format($peages, 2, '.', '')) ?>"
                               placeholder="0">
                        <span class="input-group-text">€</span>
                    </div>
                </td>
                <td class="sep">+</td>
                <td>
                    <input type="number" id="inp-km" min="0" step="1"
                           value="<?= (int) $km ?>"
                           placeholder="0">
                </td>
                <td style="white-space:nowrap;padding-left:2px;">
                    km &nbsp;×&nbsp; <?= number_format($tauxKm, 2, ',', ' ') ?> €
                </td>
                <td class="sep">=</td>
                <td class="total-cell" id="td-total"><?= number_format($total, 2, ',', ' ') ?> €</td>
            </tr>
        </tbody>
    </table>

    <!--  Rapport JA  -->
    <table class="tbl-rapport">
        <tr>
            <th>Rapport de Juge-Arbitre</th>
            <td class="rapport-titre-cell">remplir si vous rencontrez des problèmes</td>
        </tr>
        <tr>
            <th>Accueil, ambiance</th>
            <td><textarea id="inp-rapport-accueil" rows="2"><?= esc($frais['RapportAccueil'] ?? '') ?></textarea></td>
        </tr>
        <tr>
            <th>Équipements, salle…</th>
            <td><textarea id="inp-rapport-eq" rows="2"><?= esc($frais['RapportEquipements'] ?? '') ?></textarea></td>
        </tr>
    </table>

    <!-- Formule de politesse -->
    <div class="conv-footer-text">Veuillez agréer mes meilleurs sentiments.</div>
    <div class="conv-signature">
        Pour le Président de la Commission<br>
        Régionale d'Arbitrage
    </div>

    <!-- Bande basse -->
    <div class="conv-bas">
        Vos indemnités de juge-arbitrage vous seront payées en fin de phase directement par la ligue.<br>
        Pensez à transmettre un RIB à la ligue.
    </div>

    <!-- Date émission -->
    <div class="conv-date-emission"><?= date('d/m/Y') ?></div>

</div><!-- /.page -->

<!--  Bouton sauvegarder (hors impression)  -->
<div style="text-align:center;margin-bottom:2rem">
    <button id="btn-save-frais" class="btn btn-success btn-lg">
        <i class="bi bi-floppy me-1"></i>Enregistrer les frais
    </button>
    <span id="save-status"><i class="bi bi-check-circle-fill me-1"></i>Enregistré !</span>
</div>

<?php endif; ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';
const BASE    = '<?= site_url('convocation-ja') ?>';
const INDEM   = <?= json_encode((float) $indemniteForfait) ?>;
const TAUX_KM = <?= json_encode((float) $tauxKm) ?>;
const ID_NOMINATION = <?= (int) $idNomination ?>;
const CNV_TOKEN = <?= json_encode($tokenCnv ?? '') ?>;

function recalcTotal() {
    const peages = parseFloat($('#inp-peages').val()) || 0;
    const km     = parseInt($('#inp-km').val())       || 0;
    const total  = INDEM + peages + km * TAUX_KM;
    $('#td-total').text(total.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €');
}

$('#inp-peages, #inp-km').on('input', recalcTotal);

$('#btn-save-frais').on('click', function () {
    const $btn = $(this).prop('disabled', true);
    $.post(`${BASE}/sauvegarder-frais`, {
        id_nomination:       ID_NOMINATION,
        cnv:                 CNV_TOKEN,
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
        alert('Erreur réseau.');
    });
});

// Recalc initial
recalcTotal();
</script>
</body>
</html>
