<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>Convocation JA (EN21)<?= $ja ? ' – ' . esc($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
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
    #save-status {
        display: none; position: fixed; bottom: 5.5rem; right: 1.5rem; left: 1.5rem;
        max-width: 480px; margin-left: auto; z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,.3);
    }

    /*  Accusé de réception  */
    #accuse-bloc {
        max-width: 210mm; margin: 1rem auto 0; padding: .6rem .8rem;
        display: flex; flex-wrap: wrap; align-items: center; gap: .6rem;
        background: #fff; border: 1px solid #d3dae6; border-radius: 6px; font-size: .95rem;
    }
    #accuse-bloc .accuse-ok {
        display: inline-block; background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7;
        border-radius: 999px; padding: .35rem .9rem; font-weight: 700;
    }

    /*  Justificatif de péage (nomination.FichierPeage)  */
    #justif-bloc {
        display: flex; flex-wrap: wrap; align-items: center; gap: .6rem;
        margin: 0 0 4mm; padding: .4rem .6rem; font-size: .92rem;
        background: #f3f6fb; border: 1px solid #d3dae6; border-radius: 6px;
    }
    #justif-bloc .justif-ok { color: #1b5e20; font-weight: 700; }
    #justif-bloc .justif-non { color: #8a6d3b; font-weight: 700; }

    /*  Adaptation smartphone (écran uniquement — l'impression garde le A4 ci-dessous)  */
    @media screen and (max-width: 820px) {
        body { font-size: 13px; }
        #action-bar { padding: .5rem .8rem; }
        #action-bar h1 { font-size: .92rem; }
        #accuse-bloc { margin: .6rem .6rem 0; }
        .page {
            width: auto;
            min-height: 0;
            margin: .6rem;
            padding: 4mm;
            overflow-x: auto;
        }
        .num-phase { position: static; text-align: left; margin-bottom: 3mm; }
        .conv-title { font-size: 15px; letter-spacing: 1px; }
        .salle-bloc { flex-wrap: wrap; }
        #btn-save-frais { bottom: 1rem; right: 1rem; }

        /* Le tableau d'indemnités déborde légèrement du viewport (8 colonnes) :
           resserrer padding/police pour que le TOTAL reste visible sans scroll. */
        table.tbl-indem th, table.tbl-indem td { padding: 4px 3px; font-size: 10px; }
        table.tbl-indem .total-cell { font-size: 12px; }
        table.tbl-indem .val-money, table.tbl-indem .val-bold { font-size: 11px; }
        table.tbl-indem .input-group { width: 62px !important; }
        table.tbl-indem .input-group input { width: 34px !important; }
        #inp-km { width: 32px; }
    }

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
        #btn-save-frais, #save-status, #accuse-bloc, #justif-bloc,
        .btn, .alert,
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
    <h1><i class="bi bi-file-earmark-text me-2"></i>CONVOCATION / NOTE DE FRAIS / RAPPORT du Juge-Arbitre <span style="font-size:.72rem;opacity:.7;font-weight:400">EN21</span></h1>
    <?php if ($ja && $rencontre): ?>
    <button class="btn btn-sm btn-light" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Imprimer / PDF
    </button>
    <?php endif; ?>
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

<?php if ($accuse && ($accuse['date'] || $accuse['accusable'] || $accuse['passee'])): ?>
<!--  Accusé de réception (nomination.AccuseReception)  -->
<div id="accuse-bloc">
    <span id="accuse-etat">
    <?php if ($accuse['date']): ?>
        <span class="accuse-ok"><i class="bi bi-check2-circle me-1"></i>Accusé de réception enregistré le <?= esc(str_replace(' ', ' à ', $accuse['date'])) ?></span>
    <?php elseif ($accuse['accusable']): ?>
        <!-- Même règle convocationAccusable() qu'accuser() ; identique avec ou sans session NIJAC (le lien fait foi) -->
        <button type="button" id="btn-accuser" class="btn btn-primary btn-lg">
            <i class="bi bi-check2-circle me-1"></i>J'accuse réception de cette convocation
        </button>
    <?php endif; ?>
    </span>
    <?php if ($accuse['passee']): ?>
        <span class="text-muted ms-2"><i class="bi bi-clock-history me-1"></i>Rencontre passée</span>
    <?php endif; ?>
</div>
<?php endif; ?>

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
        <span class="corr-lbl">NOM – PRÉNOM et COURRIEL du CORRESPONDANT du CLUB RECEVANT :</span><br>
        <span class="corr-val"><?= esc($correspondant['Nom'] ?? '') ?></span>
        <?php if ($correspondant && $correspondant['Telephone']): ?>
        &nbsp;&nbsp;&nbsp;
        <span style="font-size:11px;">Tél : <strong><?= esc($correspondant['Telephone']) ?></strong></span>
        <?php else: ?>
        &nbsp;&nbsp;&nbsp;<span style="font-size:11px; color:#999">Tél : –</span>
        <?php endif; ?>
        <?php if ($correspondant && $correspondant['Email']): ?>
        &nbsp;&nbsp;&nbsp;
        <span style="font-size:11px;">Courriel : <strong><?= esc($correspondant['Email']) ?></strong></span>
        <?php else: ?>
        &nbsp;&nbsp;&nbsp;<span style="font-size:11px; color:#999">Courriel : –</span>
        <?php endif; ?>
    </div>

    <!--  Tableau indemnités / frais  -->
    <?php if (!empty($arbitrageClub)): ?>
    <!-- Arbitrage Club : pas d'indemnité, de péage ni de km — champs conservés (valeurs 0) mais masqués -->
    <input type="hidden" id="inp-peages" value="0"><input type="hidden" id="inp-km" value="0">
    <input type="checkbox" id="chk-defisc" hidden>
    <span id="td-total" hidden></span><span id="defisc-montant" hidden></span>
    <?php else: ?>
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
                               placeholder="0"<?= $fraisBloques ? ' disabled' : '' ?>>
                        <span class="input-group-text">€</span>
                    </div>
                </td>
                <td class="sep">+</td>
                <td>
                    <input type="number" id="inp-km" min="0" step="1"
                           value="<?= (int) $km ?>"
                           placeholder="0"<?= $fraisBloques ? ' disabled' : '' ?>>
                </td>
                <td style="white-space:nowrap;padding-left:2px;">
                    km &nbsp;×&nbsp; <?= number_format($tauxKm, 2, ',', ' ') ?> €
                </td>
                <td class="sep">=</td>
                <td class="total-cell" id="td-total"><?= number_format($total, 2, ',', ' ') ?> €</td>
            </tr>
        </tbody>
    </table>

    <?php if ($justificatif !== null): ?>
    <!--  Justificatif de péage (hors impression) : visible seulement si la saisie des frais est ouverte et le péage saisi > 0 (le dépôt exige un péage enregistré > 0).
          Seuls le type et la date sont affichés ; « Consulter » ouvre GET convocation-ja/justificatif/voir (id_nomination + cnv
          seulement : le nom de fichier est lu en base côté serveur, jamais exposé).  -->
    <?php $urlVoirJustif = site_url('convocation-ja/justificatif/voir') . '?' . http_build_query(['id_nomination' => (int) $idNomination, 'cnv' => $tokenCnv]); ?>
    <div id="justif-bloc"<?= ($fraisBloques || (float) $peages <= 0) ? ' style="display:none"' : '' ?>>
        <span id="justif-etat">
        <?php if ($justificatif['libelle']): ?>
            <span class="justif-ok"><i class="bi bi-paperclip me-1"></i>Justificatif de péage joint (<?= esc($justificatif['libelle']) ?>)</span>
        <?php else: ?>
            <span class="justif-non"><i class="bi bi-exclamation-circle me-1"></i>Aucun justificatif de péage joint</span>
        <?php endif; ?>
        </span>
        <a id="btn-justif-voir" class="btn btn-sm btn-outline-success" href="<?= esc($urlVoirJustif, 'attr') ?>"
           target="_blank" rel="noopener noreferrer"<?= ($justificatif['libelle'] && $tokenCnv !== '') ? '' : ' style="display:none"' ?>>
            <i class="bi bi-eye me-1"></i>Consulter
        </a>
        <button type="button" id="btn-justif" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-upload me-1"></i>Joindre / remplacer le justificatif de péage
        </button>
    </div>
    <?php endif; ?>

    <!--  Défiscalisation  -->
    <div class="defisc-row" style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin:2mm 0 3mm;font-size:.92rem;">
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;user-select:none;font-weight:700;">
            <input type="checkbox" id="chk-defisc" style="width:1.1rem;height:1.1rem;cursor:pointer;accent-color:#2e7d32;"
                   <?= $defiscCoche ? 'checked' : '' ?><?= $fraisBloques ? ' disabled' : '' ?>>
            Défiscalisation des frais kilométriques
        </label>
        <span id="defisc-montant" style="font-weight:700;color:#2e7d32;"></span>
        <a href="<?= base_url('Documentation/Plaquette_Defiscalisation.pdf') ?>" target="_blank" rel="noopener"
           class="btn btn-sm" style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;font-weight:700;"
           title="Ouvrir la plaquette de défiscalisation (PDF)">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i>En savoir plus
        </a>
    </div>

    <?php endif; ?>

    <!--  Rapport JA  -->
    <table class="tbl-rapport">
        <tr>
            <th>Rapport de Juge-Arbitre</th>
            <td class="rapport-titre-cell">remplir si vous rencontrez des problèmes</td>
        </tr>
        <tr>
            <th>Accueil, ambiance</th>
            <td><textarea id="inp-rapport-accueil" rows="2"<?= $fraisBloques ? ' disabled' : '' ?>><?= esc($frais['RapportAccueil'] ?? '') ?></textarea></td>
        </tr>
        <tr>
            <th>Équipements, salle…</th>
            <td><textarea id="inp-rapport-eq" rows="2"<?= $fraisBloques ? ' disabled' : '' ?>><?= esc($frais['RapportEquipements'] ?? '') ?></textarea></td>
        </tr>
    </table>

    <!-- Formule de politesse -->
    <div class="conv-footer-text">Veuillez agréer mes meilleurs sentiments.</div>
    <div class="conv-signature">
        Pour le Président de la Commission<br>
        Régionale d'Arbitrage
    </div>

    <!-- Bande basse -->
    <?php if (empty($arbitrageClub)): ?>
    <div class="conv-bas">
        Vos indemnités de juge-arbitrage vous seront payées en fin de phase directement par la ligue.<br>
        Pensez à transmettre un RIB à la ligue.
    </div>
    <?php endif; ?>

    <!-- Date émission -->
    <div class="conv-date-emission"><?= date('d/m/Y') ?></div>

</div><!-- /.page -->

<!--  Bouton sauvegarder (hors impression) — identique avec ou sans session NIJAC (le lien fait foi)  -->
<div style="text-align:center;margin-bottom:2rem">
    <?php if ($fraisBloques): ?>
    <!-- Saisie des frais bloquée (fraisSaisissables()) : avant la date de la rencontre, ou le jour même sans accusé de réception -->
    <div id="frais-bloque-msg" class="alert alert-warning d-inline-block fw-bold mb-0">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= esc($etatFrais['err'] ?? 'La saisie des frais est indisponible.') ?>
    </div>
    <?php endif; ?>
    <button id="btn-save-frais" class="btn btn-success btn-lg"<?= $fraisBloques ? ' style="display:none"' : '' ?>>
        <i class="bi bi-floppy me-1"></i>Enregistrer les frais
    </button>
    <div id="save-status" class="alert alert-success mb-0" role="status">
        <i class="bi bi-check-circle-fill me-1"></i><strong>Vos informations ont bien été enregistrées.</strong><br>
        Merci pour votre participation et pour votre disponibilité !
    </div>
</div>

<?php if ($justificatif !== null): ?>
<!--  Popup justificatif de péage (POST convocation-ja/justificatif)  -->
<div class="modal fade" id="modal-justif" tabindex="-1" aria-labelledby="modal-justif-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white" style="background:var(--fftt-blue)">
                <h5 class="modal-title" id="modal-justif-titre" style="font-size:1rem;font-weight:700">
                    <i class="bi bi-receipt me-2"></i>Justificatif de péage
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" style="font-size:.95rem">
                <p class="mb-2">Vous avez déclaré des frais de péage : vous pouvez joindre une copie du justificatif (photo ou PDF, 5 Mo maximum).</p>
                <p id="justif-modal-etat" class="mb-2 fw-bold"<?= $justificatif['libelle'] ? '' : ' style="display:none"' ?>>
                    <i class="bi bi-paperclip me-1"></i>Un justificatif est déjà joint<span id="justif-modal-libelle"><?= $justificatif['libelle'] ? ' (' . esc($justificatif['libelle']) . ')' : '' ?></span> : un nouveau fichier le remplacera.
                    <?php if ($tokenCnv !== ''): ?>
                    <a class="ms-1 fw-normal" href="<?= esc(site_url('convocation-ja/justificatif/voir') . '?' . http_build_query(['id_nomination' => (int) $idNomination, 'cnv' => $tokenCnv]), 'attr') ?>"
                       target="_blank" rel="noopener noreferrer"><i class="bi bi-eye me-1"></i>Consulter</a>
                    <?php endif; ?>
                </p>
                <input type="file" id="inp-justif" class="form-control mb-3"
                       accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.jpg,.jpeg,.png,.gif,.webp,.pdf">
                <div class="alert alert-info mb-0 py-2" style="font-size:.88rem">
                    <i class="bi bi-info-circle me-1"></i>Si vous n'avez pas encore le justificatif, cette opération peut être réalisée ultérieurement en utilisant ce même lien.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Plus tard</button>
                <button type="button" id="btn-justif-joindre" class="btn btn-primary">
                    <i class="bi bi-upload me-1"></i>Joindre
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script>
'use strict';
const BASE    = '<?= site_url('convocation-ja') ?>';
const INDEM   = <?= json_encode((float) $indemniteForfait) ?>;
const TAUX_KM = <?= json_encode((float) $tauxKm) ?>;
const ID_NOMINATION = <?= (int) $idNomination ?>;
const CNV_TOKEN = <?= json_encode($tokenCnv ?? '') ?>;
const DEFISC_EUR_KM = <?= json_encode($defiscEuroParKm) ?>; // null si puissance fiscale non renseignée
// Vrai si seul l'accusé de réception bloque la saisie des frais (rencontre du jour) : l'accusé la débloque.
const FRAIS_ATTEND_ACCUSE = <?= json_encode((bool) ($etatFrais['attendAccuse'] ?? false)) ?>;

function recalcTotal() {
    const peages = parseFloat($('#inp-peages').val()) || 0;
    const km     = parseInt($('#inp-km').val())       || 0;
    const total  = INDEM + peages + km * TAUX_KM;
    $('#td-total').text(total.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €');
}

function recalcDefisc() {
    const km = parseInt($('#inp-km').val()) || 0;
    const $out = $('#defisc-montant');
    if (!$('#chk-defisc').is(':checked') || km === 0) { $out.text(''); return; }
    if (DEFISC_EUR_KM === null) {
        $out.text('Puissance fiscale non renseignée — contactez la ligue.').css('color', '#c00');
        return;
    }
    const m = km * DEFISC_EUR_KM;
    $out.css('color', '#2e7d32')
        .text('Montant défiscalisable : ' + m.toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €');
}

$('#inp-peages, #inp-km').on('input', recalcTotal);
$('#inp-km').on('input', recalcDefisc);
$('#chk-defisc').on('change', recalcDefisc);
recalcDefisc();

$('#btn-save-frais').on('click', function () {
    const $btn = $(this).prop('disabled', true);
    $.post(`${BASE}/sauvegarder-frais`, {
        id_nomination:       ID_NOMINATION,
        cnv:                 CNV_TOKEN,
        peages:              $('#inp-peages').val(),
        km:                  $('#inp-km').val(),
        rapport_accueil:     $('#inp-rapport-accueil').val(),
        rapport_equipements: $('#inp-rapport-eq').val(),
        defiscalisation:     $('#chk-defisc').is(':checked') ? 1 : 0,
    }, function (r) {
        $btn.prop('disabled', false);
        if (r.ok) {
            $('#save-status').stop(true, true).fadeIn().delay(8000).fadeOut();
            // Péage > 0 enregistré (et colonne FichierPeage présente) : proposer le justificatif.
            if (r.justificatif && $('#modal-justif').length) {
                ouvrirJustif();
            }
        } else {
            alert('Erreur : ' + (r.err || 'inconnue'));
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false);
        alert('Erreur réseau.');
    });
});

// ── Accusé de réception (POST convocation-ja/accuse) ─────────────────────────
$('#btn-accuser').on('click', function () {
    const $btn = $(this);
    $btn.prop('disabled', true);
    $.post(`${BASE}/accuse`, { id_nomination: ID_NOMINATION, cnv: CNV_TOKEN }, function (r) {
        if (!r.ok) {
            $btn.prop('disabled', false);
            nijacToast(r.err || 'Erreur inconnue.', 'danger', 0);
            return;
        }
        if (r.date) {
            $('#accuse-etat').empty().append($('<span class="accuse-ok">')
                .append('<i class="bi bi-check2-circle me-1"></i>')
                .append(document.createTextNode('Accusé de réception enregistré le ' + r.date.replace(' ', ' à '))));
        }
        // Rencontre du jour : saisie des frais débloquée sans rechargement. Rencontre à venir :
        // la saisie reste fermée jusqu'à la date de la rencontre (règle J >= D, contrôlée aussi côté serveur).
        if (FRAIS_ATTEND_ACCUSE) {
            $('#inp-peages, #inp-km, #chk-defisc, #inp-rapport-accueil, #inp-rapport-eq').prop('disabled', false);
            $('#frais-bloque-msg').remove();
            $('#btn-save-frais').show();
        }
        nijacToast('Accusé de réception enregistré. Merci !', 'success');
    }, 'json').fail(function (x) {
        $btn.prop('disabled', false);
        nijacToast(x.status === 403
            ? 'Session expirée : rechargez la page (F5) puis cliquez à nouveau.'
            : "Erreur réseau : l'accusé de réception n'a pas été enregistré, réessayez.", 'danger', 0);
    });
});

// ── Justificatif de péage (POST convocation-ja/justificatif, multipart) ──────
function ouvrirJustif() {
    const el = document.getElementById('modal-justif');
    if (!el || !window.bootstrap) return;
    $('#inp-justif').val('');
    bootstrap.Modal.getOrCreateInstance(el).show();
}
$('#btn-justif').on('click', ouvrirJustif);
// Bouton visible seulement si péage > 0 (le dépôt exige en plus un péage déjà enregistré, contrôlé côté serveur).
$('#inp-peages').on('input change', function () {
    if (!<?= json_encode(!$fraisBloques) ?>) return;
    $('#justif-bloc').toggle(parseFloat($(this).val()) > 0);
});
$('#btn-justif-joindre').on('click', function () {
    const f = $('#inp-justif')[0].files[0];
    if (!f) { nijacToast('Choisissez le fichier du justificatif (image ou PDF).', 'warning'); return; }
    if (f.size > 5 * 1024 * 1024) { nijacToast('Justificatif trop volumineux (5 Mo maximum).', 'warning'); return; }
    const fd = new FormData();
    fd.append('id_nomination', ID_NOMINATION);
    fd.append('cnv', CNV_TOKEN);
    fd.append('justificatif', f);
    const $btn = $(this).prop('disabled', true);
    $.ajax({ url: `${BASE}/justificatif`, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            $btn.prop('disabled', false);
            if (!r.ok) { nijacToast(r.msg || r.err || 'Erreur inconnue.', 'danger', 0); return; }
            const lib = r.libelle ? ' (' + r.libelle + ')' : '';
            $('#justif-etat').empty().append($('<span class="justif-ok">')
                .append('<i class="bi bi-paperclip me-1"></i>')
                .append(document.createTextNode('Justificatif de péage joint' + lib)));
            $('#justif-modal-libelle').text(lib);
            $('#justif-modal-etat').show();
            // Lien « Consulter » (URL fixe id_nomination + cnv, sans nom de fichier) : affiché dès le premier dépôt.
            if (CNV_TOKEN) $('#btn-justif-voir').show();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-justif')).hide();
            nijacToast(r.msg || 'Justificatif enregistré.', 'success');
        })
        .fail(function (x) {
            $btn.prop('disabled', false);
            nijacToast(x.status === 403
                ? 'Session expirée : rechargez la page (F5) puis réessayez.'
                : "Erreur réseau : le justificatif n'a pas été enregistré, réessayez.", 'danger', 0);
        });
});

// Recalc initial
recalcTotal();
</script>
</body>
</html>
