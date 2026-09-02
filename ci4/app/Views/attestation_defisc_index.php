<?php
$pf = function (string $k) use ($modeJa, $fiche) {
    return ($modeJa && $fiche && isset($fiche[$k])) ? esc($fiche[$k]) : '';
};
$cvSel  = ($modeJa && $fiche) ? $fiche['cv'] : null;
$enElec = ($modeJa && $fiche && $fiche['cv'] !== null && $fiche['electrique']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Attestation sur l'honneur (ED53)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        #page-header { background: #e65100; color: #fff; }

        #toolbar-user {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
        }
        #toolbar-user .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar-user .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }

        #toolbar {
            background: #fff;
            border-bottom: 1px solid #dde3ed;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
        }
        #toolbar .att-hint { font-size: .82rem; color: #6b7280; }

        .btn-imprimer, .btn-valider {
            border: none; border-radius: 6px;
            font-size: .85rem; font-weight: 600; padding: .4rem 1.1rem;
            display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
        }
        .btn-imprimer { background: #fff; color: #e65100; border: 1px solid #e65100; }
        .btn-imprimer:hover { background: #fff3e8; }
        .btn-valider { background: #1a7f4b; color: #fff; margin-left: auto; }
        .btn-valider:hover { opacity: .9; }
        .btn-valider:disabled { opacity: .5; cursor: not-allowed; }
        #toolbar .att-hint + .btn-imprimer { margin-left: auto; }
        #toolbar .btn-valider ~ .btn-imprimer { margin-left: 0; }

        .att-ok { color: #1a7f4b; font-weight: 700; display: inline-flex; align-items: center; gap: .4rem; margin-left: auto; }

        #main-content { flex: 1; padding: 1.5rem 1.25rem; overflow-y: auto; }

        .att-dev-note {
            max-width: 820px;
            margin: 0 auto 1rem;
            background: #fff8ef;
            border: 1px solid #f6d8c2;
            border-left: 4px solid #e8590c;
            border-radius: 8px;
            padding: .7rem 1rem;
            font-size: .85rem;
            color: #7c2d12;
            display: flex;
            gap: .6rem;
            align-items: flex-start;
        }
        .att-dev-note i { font-size: 1.1rem; margin-top: .1rem; flex-shrink: 0; }
        .att-dev-note code { background: #ffe9d1; padding: .05rem .3rem; border-radius: 4px; }

        #attestation {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e6ded7;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,.09);
            padding: 2.4rem 2.8rem 2.8rem;
            font-size: .95rem;
            line-height: 1.7;
            color: #1f2937;
        }
        #attestation.att-verrouille { opacity: .65; pointer-events: none; }
        .att-titre {
            text-align: center; font-size: 1.25rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: .04em;
            color: #9a3412; margin: 0 0 1.8rem;
        }
        #attestation p { margin: 0 0 1rem; }
        .att-liste { margin: 0 0 1rem; padding-left: 1.4rem; }
        .att-liste > li { margin-bottom: .5rem; }
        .att-liste ul { margin: .35rem 0; padding-left: 1.3rem; list-style: circle; }
        .att-lieu-date { margin-top: 1.8rem !important; }
        .att-sig-label { margin-top: 1rem !important; font-weight: 600; }

        .ph, .ph-sel {
            display: inline-block;
            min-width: 90px;
            border: 0;
            border-bottom: 1px dotted #c2410c;
            padding: 0 .25rem;
            outline: none;
            font: inherit;
            font-weight: 600;
            color: #7c2d12;
            background: transparent;
        }
        .ph { white-space: pre-wrap; }
        .ph:empty::before { content: attr(data-ph); color: #b9a08e; font-weight: 400; font-style: italic; }
        .ph:focus, .ph-sel:focus { background: #fff3e8; border-radius: 3px; }
        .ph-sel { min-width: 0; cursor: pointer; }

        .att-approuve { margin-top: 1.6rem !important; }
        .att-approuve label { display: inline-flex; align-items: center; gap: .55rem; cursor: pointer; font-size: 1rem; }
        .att-approuve input { width: 1.1rem; height: 1.1rem; cursor: pointer; flex-shrink: 0; }

        #sig-box {
            position: relative;
            width: 100%;
            max-width: 460px;
            height: 190px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: repeating-linear-gradient(#fff, #fff 30px, #f4f6fa 31px);
            touch-action: none;
        }
        #sig-canvas { position: absolute; inset: 0; width: 100%; height: 100%; cursor: crosshair; }
        #sig-hint {
            position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%);
            color: #9aa7b8; font-size: .85rem; font-style: italic; pointer-events: none;
        }
        .sig-tools { margin-top: .5rem; }
        .btn-sig-clear {
            background: #fff; color: #b45309; border: 1px solid #e4c9a8;
            border-radius: 6px; font-size: .8rem; font-weight: 600;
            padding: .28rem .8rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: .35rem;
        }
        .btn-sig-clear:hover { background: #fff3e8; }

        #page-footer {
            background: #e8eef7; border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem; font-size: .8rem;
            display: flex; justify-content: center; align-items: center; flex-shrink: 0;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }

        @media print {
            #page-header, #toolbar-user, #toolbar, #page-footer, .no-print { display: none !important; }
            body { background: #fff; display: block; }
            #main-content { padding: 0; overflow: visible; }
            #attestation {
                max-width: 100%; margin: 0; border: 0; border-radius: 0;
                box-shadow: none; padding: 0; font-size: 12pt;
            }
            .att-titre { color: #000; }
            .ph, .ph-sel {
                border-bottom: 1px solid #000; color: #000; font-weight: 600;
                -webkit-appearance: none; appearance: none;
            }
            .ph:empty::before { content: ''; }
            #sig-box { background: #fff; border: 1px solid #000; max-width: 460px; }
            .att-approuve input { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>

    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin-orange.css') ?>">
</head>
<body>

<?= view('partials/page_header', array_merge([
    'phIcon' => 'file-earmark-text', 'phTitle' => 'Attestation sur l\'honneur', 'phCode' => 'ED53',
    'phBackUrl' => $retourUrl, 'phCrumbColor' => '#ffe0c2', 'phBadgeColor' => '#ffe0c2',
], $modeJa ? [] : [
    'phCrumbLabel' => 'Défiscalisation JA', 'phCrumbUrl' => site_url('defiscalisation'),
])) ?>

<?php if (!$modeJa): ?>
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>
<?php require __DIR__ . '/_modal_mdp.php'; ?>
<?php endif; ?>

<div id="toolbar" class="no-print">
    <span class="att-hint">
        <i class="bi bi-pencil-square me-1"></i>
        <?= $modeJa
            ? 'Complétez les champs soulignés, choisissez la puissance et l\'énergie, cochez « Lu et approuvé », signez, puis validez.'
            : 'Complétez les champs soulignés, cochez « Lu et approuvé », signez puis imprimez.' ?>
    </span>
    <?php if ($modeJa): ?>
    <button class="btn-valider" id="btn-valider"><i class="bi bi-send-check"></i>Valider et transmettre</button>
    <?php endif; ?>
    <button class="btn-imprimer" id="btn-imprimer"><i class="bi bi-printer"></i>Imprimer / PDF</button>
</div>

<div id="main-content">
    <?php if (!$modeJa): ?>
    <div class="att-dev-note no-print">
        <i class="bi bi-info-circle-fill"></i>
        <span>
            <strong>Information défiscalisateur.</strong>
            Le texte de cette attestation (formule, libellés des champs) est codé en dur dans une vue
            (<code>attestation_defisc_index.php</code>). Toute modification du contenu doit être demandée
            au développeur.
        </span>
    </div>
    <?php endif; ?>

    <div id="attestation">
        <h1 class="att-titre">Attestation sur l'honneur</h1>

        <p>
            Je soussigné(e), M./Mme
            <span class="ph" contenteditable="true" data-ph="Nom Prénom" id="f-nomprenom"><?= $pf('nomPrenom') ?></span>,
            demeurant au
            <span class="ph" contenteditable="true" data-ph="adresse complète" id="f-adresse"><?= $pf('adresse') ?></span>,
            né(e) le
            <span class="ph" contenteditable="true" data-ph="date de naissance" id="f-naissance-date"></span>
            à
            <span class="ph" contenteditable="true" data-ph="lieu de naissance" id="f-naissance-lieu"></span>,
            certifie sur l'honneur que&nbsp;:
        </p>

        <ul class="att-liste">
            <li>
                Je suis propriétaire du véhicule suivant&nbsp;:
                <ul>
                    <li>Marque / Modèle&nbsp;: <span class="ph" contenteditable="true" data-ph="ex. Renault Zoé" id="f-marque"></span></li>
                    <li>Immatriculation&nbsp;: <span class="ph" contenteditable="true" data-ph="AB-123-CD" id="f-immat"></span></li>
                    <li>Puissance administrative&nbsp;:
                        <select class="ph-sel" id="f-puissance">
                            <option value="">—</option>
                            <?php foreach ($cvAutorises as $cv): ?>
                            <option value="<?= $cv ?>" <?= $cvSel === $cv ? 'selected' : '' ?>>
                                <?= $cv >= 7 ? '7 CV ou plus' : $cv . ' CV' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <li>Énergie&nbsp;:
                        <select class="ph-sel" id="f-energie">
                            <option value="" data-elec="0">—</option>
                            <optgroup label="Fossiles liquides (combustion interne)">
                                <option data-elec="0">Essence</option>
                                <option data-elec="0">Gazole (diesel)</option>
                            </optgroup>
                            <optgroup label="Gaz fossiles (combustion interne)">
                                <option data-elec="0">GPL (butane / propane)</option>
                                <option data-elec="0">Gaz naturel véhicule (GNV)</option>
                            </optgroup>
                            <optgroup label="Biocarburants (combustion interne)">
                                <option data-elec="0">Superéthanol E85</option>
                                <option data-elec="0">Bioéthanol</option>
                                <option data-elec="0">Biogaz</option>
                            </optgroup>
                            <optgroup label="Électricité (moteur électrique)">
                                <option data-elec="1" <?= $enElec ? 'selected' : '' ?>>Batteries lithium-ion (100 % électrique)</option>
                            </optgroup>
                            <optgroup label="Hybride (thermique + électrique)">
                                <option data-elec="0">Essence + électricité (hybride non rechargeable)</option>
                                <option data-elec="0">Essence + électricité (hybride rechargeable)</option>
                            </optgroup>
                            <optgroup label="Hydrogène (pile à combustible)">
                                <option data-elec="0">Pile à combustible (hydrogène H2)</option>
                            </optgroup>
                            <optgroup label="Carburants de synthèse">
                                <option data-elec="0">e-fuels (carburant de synthèse)</option>
                            </optgroup>
                        </select>
                    </li>
                </ul>
            </li>
            <li>
                Ce véhicule est utilisé exclusivement pour mes déplacements personnels et pour les besoins
                de mon activité bénévole au sein de l'association
                <span class="ph" contenteditable="true" data-ph="Nom de l'association" id="f-association"><?= esc($association) ?></span>,
                sans aucune contrepartie financière.
            </li>
            <li>
                Je certifie l'exactitude des informations ci-dessus et je m'engage à fournir la copie de la
                carte grise (certificat d'immatriculation) en justificatif.
            </li>
        </ul>

        <p class="att-lieu-date">
            Fait à <span class="ph" contenteditable="true" data-ph="ville" id="f-ville"><?= $pf('ville') ?></span>,
            le <span class="ph" contenteditable="true" data-ph="jj/mm/aaaa" id="f-date"><?= esc($dateJour) ?></span>
        </p>

        <p class="att-approuve">
            <label>
                <input type="checkbox" id="chk-approuve">
                <strong>Lu et approuvé</strong>
            </label>
        </p>

        <p class="att-sig-label">Signature&nbsp;:</p>

        <div id="sig-box">
            <canvas id="sig-canvas"></canvas>
            <span id="sig-hint">Signez ici — souris, écran tactile ou stylet</span>
        </div>
        <div class="sig-tools no-print">
            <button type="button" id="btn-sig-clear" class="btn-sig-clear"><i class="bi bi-eraser"></i>Effacer la signature</button>
        </div>
    </div>
</div>

<?= view('partials/page_footer') ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/jspdf.umd.min.js') ?>"></script>
<script>
const BASE    = '<?= site_url('attestation-defisc') ?>';
const TOKEN   = '<?= esc($tokenJa) ?>';
const MODE_JA = <?= $modeJa ? 'true' : 'false' ?>;

// ── Signature manuscrite (souris / tactile / stylet) ────────────────────────
const cv  = document.getElementById('sig-canvas');
const ctx = cv.getContext('2d');
let drawing = false, last = null, sigDirty = false;

function calibrer() {
    const r   = cv.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    cv.width  = Math.round(r.width * dpr);
    cv.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1a2330';
}
function point(e) {
    const r = cv.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
}
cv.addEventListener('pointerdown', function (e) {
    drawing = true; last = point(e); cv.setPointerCapture(e.pointerId);
    $('#sig-hint').hide();
});
cv.addEventListener('pointermove', function (e) {
    if (!drawing) return;
    const p = point(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p; sigDirty = true;
});
['pointerup', 'pointercancel'].forEach(ev => cv.addEventListener(ev, () => { drawing = false; }));
$('#btn-sig-clear').on('click', function () {
    ctx.clearRect(0, 0, cv.width, cv.height); sigDirty = false;
    $('#sig-hint').show();
});
calibrer();
$(window).on('load', calibrer);

// ── Valeurs des champs ─────────────────────────────────────────────────────
function champ(id) {
    const e = document.getElementById(id);
    if (e.tagName === 'SELECT') return e.value === '' ? '' : e.selectedOptions[0].textContent.trim();
    return e.textContent.trim();
}
function energieTexte() {
    return champ('f-energie');
}
function energieEstElectrique() {
    const opt = document.getElementById('f-energie').selectedOptions[0];
    return opt && opt.dataset.elec === '1';
}

// ── Génération PDF (jsPDF, mise en page manuelle) ──────────────────────────
function genererPdf() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const M = 20, W = 170, LH = 5.2;
    let y = 24;

    doc.setFont('helvetica', 'bold'); doc.setFontSize(14);
    doc.text("ATTESTATION SUR L'HONNEUR", 105, y, { align: 'center' });
    y += 13;
    doc.setFont('helvetica', 'normal'); doc.setFontSize(11);

    const bloc = (txt, x = M, w = W) => {
        const lines = doc.splitTextToSize(txt, w);
        doc.text(lines, x, y);
        y += lines.length * LH + 2.4;
    };
    const puce = (txt, indent = 0) => {
        const x = M + indent;
        const lines = doc.splitTextToSize(txt, W - indent - 4);
        doc.text('•', x, y);
        doc.text(lines, x + 4, y);
        y += lines.length * LH + 1.4;
    };

    bloc(`Je soussigné(e), M./Mme ${champ('f-nomprenom')}, demeurant au ${champ('f-adresse')}, `
       + `né(e) le ${champ('f-naissance-date')} à ${champ('f-naissance-lieu')}, certifie sur l'honneur que :`);
    y += 1;
    puce('Je suis propriétaire du véhicule suivant :');
    puce(`Marque / Modèle : ${champ('f-marque')}`, 6);
    puce(`Immatriculation : ${champ('f-immat')}`, 6);
    puce(`Puissance administrative : ${champ('f-puissance')}`, 6);
    puce(`Énergie : ${energieTexte()}`, 6);
    puce(`Ce véhicule est utilisé exclusivement pour mes déplacements personnels et pour les besoins `
       + `de mon activité bénévole au sein de l'association ${champ('f-association')}, sans aucune contrepartie financière.`);
    puce(`Je certifie l'exactitude des informations ci-dessus et je m'engage à fournir la copie de la `
       + `carte grise (certificat d'immatriculation) en justificatif.`);
    y += 4;
    bloc(`Fait à ${champ('f-ville')}, le ${champ('f-date')}`);
    y += 1;
    doc.text(`[${document.getElementById('chk-approuve').checked ? 'X' : ' '}]  Lu et approuvé`, M, y);
    y += 9;
    doc.text('Signature :', M, y);
    y += 3;
    doc.addImage(cv.toDataURL('image/png'), 'PNG', M, y, 85, 32);

    return doc.output('datauristring');
}

// ── Impression locale ─────────────────────────────────────────────────────
$('#btn-imprimer').on('click', function () {
    if (!document.getElementById('chk-approuve').checked) {
        toast('Cochez « Lu et approuvé » avant d\'imprimer.', false);
        document.getElementById('chk-approuve').focus();
        return;
    }
    window.print();
});

// ── Validation JA : écriture BDD + dépôt du PDF ───────────────────────────
if (MODE_JA) {
    $('#btn-valider').on('click', function () {
        const manque = [];
        [['f-nomprenom', 'Nom Prénom'], ['f-adresse', 'Adresse'],
         ['f-naissance-date', 'Date de naissance'], ['f-naissance-lieu', 'Lieu de naissance'],
         ['f-marque', 'Marque / Modèle'], ['f-immat', 'Immatriculation'],
         ['f-ville', 'Ville'], ['f-date', 'Date']].forEach(([id, lbl]) => {
            if (!champ(id)) manque.push(lbl);
        });
        if (!document.getElementById('f-puissance').value) manque.push('Puissance administrative');
        if (!document.getElementById('f-energie').value)   manque.push('Énergie');
        if (!document.getElementById('chk-approuve').checked) manque.push('« Lu et approuvé »');
        if (!sigDirty) manque.push('Signature');
        if (manque.length) { toast('À compléter : ' + manque.join(', '), false); return; }

        const $b = $(this).prop('disabled', true);
        let pdf;
        try { pdf = genererPdf(); }
        catch (err) { toast('Génération du PDF impossible : ' + err.message, false); $b.prop('disabled', false); return; }

        $.post(`${BASE}/valider`, {
            ja:         TOKEN,
            puissance:  document.getElementById('f-puissance').value,
            electrique: energieEstElectrique() ? 1 : 0,
            pdf:        pdf
        })
            .done(function (res) {
                if (!res.ok) { toast(res.msg || 'Échec.', false); $b.prop('disabled', false); return; }
                toast(res.msg || 'Attestation enregistrée.', true);
                $('#attestation').addClass('att-verrouille');
                $b.replaceWith('<span class="att-ok"><i class="bi bi-check-circle-fill"></i>Attestation transmise</span>');
            })
            .fail(function () { toast('Erreur réseau.', false); $b.prop('disabled', false); });
    });
}
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
