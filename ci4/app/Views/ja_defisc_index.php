<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Ma défiscalisation (EJA3)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        body { background: #fff8e1; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; display: flex; flex-direction: column; }
        #page-header { background: #8d6e00; color: #fff; padding: .5rem 1.25rem; font-size: .9rem; font-weight: 600; }
        #toolbar { background: #f8fafc; border-bottom: 1px solid #dde5f0; padding: .3rem 1rem; display: flex; align-items: center; justify-content: space-between; font-size: .85rem; gap: .75rem; }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; align-items: center; gap: .35rem; color: #c00; font-weight: 700; cursor: pointer; text-decoration: underline dotted; }
        #contenu { flex: 1; padding: 1rem; max-width: 960px; width: 100%; margin: 0 auto; }
        .carte { background: #fff; border: 1px solid #dde5f0; border-radius: .5rem; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .carte h2 { font-size: 1.05rem; font-weight: 700; color: #1a3a6b; margin-bottom: .75rem; }
        .carte h2 .num { display: inline-block; width: 1.6rem; height: 1.6rem; line-height: 1.6rem; text-align: center; border-radius: 50%; background: #8d6e00; color: #fff; font-size: .85rem; margin-right: .4rem; }
        .carte-aide > summary { font-size: 1.05rem; font-weight: 700; color: #1a3a6b; cursor: pointer; list-style: none; }
        .carte-aide > summary::-webkit-details-marker { display: none; }
        .carte-aide > summary::after { content: "\f282"; font-family: "bootstrap-icons"; float: right; transition: transform .15s; }
        .carte-aide[open] > summary::after { transform: rotate(180deg); }
        .sim-box { border: 1px solid #dde5f0; border-radius: .4rem; padding: .75rem 1rem; background: #f8fafc; height: 100%; }
        .carte-etape.etape-off { opacity: .55; }
        .carte-etape.etape-off .etape-msg { color: #b26a00; font-weight: 600; opacity: 1; }
        .etat-ok { color: #2e7d32; font-weight: 600; }
        .etat-ko { color: #b26a00; font-weight: 600; }
        #page-footer { background: #e8eef7; border-top: 1px solid #c8d4e8; padding: .25rem 1rem; font-size: .8rem; display: flex; justify-content: center; align-items: center; flex-shrink: 0; }
        #page-footer.pf-status-left { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'cash-coin', 'phTitle' => 'Ma défiscalisation', 'phCode' => 'EJA3',
    'phCrumbLabel' => 'Menu JA', 'phCrumbUrl' => site_url('ja-menu'), 'phBackUrl' => site_url('ja-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="contenu">

    <details class="carte carte-aide">
        <summary><i class="bi bi-info-circle me-1"></i>La défiscalisation, comment ça marche&nbsp;?</summary>
        <p class="mb-2 mt-3">
            Un juge-arbitre bénévole peut <strong>renoncer au remboursement de ses frais kilométriques</strong>
            (abandon de frais). La Ligue lui délivre alors un <strong>reçu fiscal</strong> qui ouvre droit à une
            réduction d'impôt sur le revenu, au lieu de lui rembourser ces kilomètres. La défiscalisation porte sur l'année
            civile <strong><?= (int) $annee ?></strong> (1<sup>er</sup> janvier – 31 décembre) et sur les kilomètres de vos
            missions de juge-arbitre CRA (barème kilométrique fiscal selon la puissance fiscale du véhicule, majoré pour un
            véhicule électrique&nbsp;; les péages n'y entrent pas).
        </p>
        <ol class="mb-2">
            <li>Cochez «&nbsp;Je souhaite la défiscalisation&nbsp;» et indiquez les caractéristiques de votre véhicule (étape 1).</li>
            <li>Déposez une copie de votre <strong>carte grise</strong> (étape 2).</li>
            <li>Remplissez et <strong>signez l'attestation sur l'honneur</strong> à l'écran (étape 3).</li>
            <li>Après chaque rencontre, saisissez vos kilomètres dans
                <a href="<?= site_url('ja/frais') ?>">Mes nominations et frais</a> (EJA4)&nbsp;; en fin d'année, le
                défiscalisateur établit votre reçu à partir de ces éléments.</li>
        </ol>
        <p class="small text-muted mb-0">
            Le choix peut aussi être fait mission par mission dans EJA4 (colonne «&nbsp;Défisc.&nbsp;»).
            <a href="<?= base_url('Documentation/Plaquette_Defiscalisation.pdf') ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf-fill"></i> Plaquette de défiscalisation (PDF)</a>
        </p>
    </details>

    <div class="carte">
        <h2><i class="bi bi-calculator me-1"></i>Simulation <?= (int) $annee ?></h2>
<?php if ($simulation === null): ?>
        <p class="mb-0 text-muted">Aucune mission enregistrée pour le moment.</p>
<?php else:
    $eur = static fn (float $v): string => number_format($v, 2, ',', ' ') . ' €'; ?>
        <p class="mb-2">
            <strong><?= esc(number_format($simulation['km'], 0, ',', ' ')) ?> km</strong> parcourus sur
            <?= (int) $simulation['missions'] ?> mission<?= $simulation['missions'] > 1 ? 's' : '' ?> CRA passée<?= $simulation['missions'] > 1 ? 's' : '' ?>
            en <?= (int) $annee ?>.
        </p>
<?php if (!$simulation['cvRenseigne']): ?>
        <p class="small etat-ko mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Puissance non renseignée : calcul avec <?= (int) $simulation['cv'] ?> CV.</p>
<?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="sim-box">
                    <div class="fw-semibold mb-1">Sans défiscalisation</div>
                    <div>Vous touchez <strong class="fs-5"><?= esc($eur($simulation['remboursement'])) ?></strong></div>
                    <div class="small text-muted">remboursement de la Ligue (<?= esc(number_format((float) $tauxKm, 2, ',', '')) ?> €/km)</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="sim-box">
                    <div class="fw-semibold mb-1">Avec défiscalisation</div>
<?php if ($simulation['recu'] === null): ?>
                    <div class="text-muted">Barème fiscal indisponible pour cette puissance.</div>
<?php else: ?>
                    <div>Reçu fiscal <strong class="fs-5"><?= esc($eur($simulation['recu'])) ?></strong></div>
                    <div>soit une réduction d'impôt de <strong class="fs-5 etat-ok"><?= esc($eur($simulation['reduction'])) ?></strong></div>
                    <div class="small text-muted">barème fiscal <?= (int) $simulation['cv'] ?> CV<?= $simulation['electrique'] ? ' électrique (majoré)' : '' ?>, réduction de <?= (int) $simulation['tauxReduction'] ?>&nbsp;%</div>
<?php endif; ?>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">
            Estimation indicative, sans valeur contractuelle. La réduction d'impôt est plafonnée à 20&nbsp;% du revenu imposable et ne
            concerne que les foyers imposables.
        </p>
<?php endif; ?>
    </div>

    <div class="carte">
        <h2><span class="num">1</span>Mes informations</h2>
        <form id="frm-infos" class="row g-3 align-items-end" onsubmit="return false;">
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="f-defisc" <?= $ja['defisc'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="f-defisc">Je souhaite la défiscalisation de mes frais kilométriques</label>
                </div>
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="f-cv">Puissance fiscale (CV)</label>
                <select id="f-cv" class="form-select">
                    <option value="">— non renseignée —</option>
                    <?php foreach ($cvAutorises as $cv): ?>
                        <option value="<?= (int) $cv ?>" <?= $ja['cv'] === $cv ? 'selected' : '' ?>><?= (int) $cv ?> CV<?= $cv === 7 ? ' et plus' : '' ?><?= isset($tauxCv[$cv]) ? ' — ' . esc(number_format($tauxCv[$cv], 3, ',', '')) . ' €/km' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-5">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="f-elec" <?= $ja['electrique'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="f-elec">Véhicule 100% électrique (+<?= esc($majoration) ?>%)</label>
                </div>
            </div>
            <div class="col-sm-3 text-sm-end">
                <button type="button" class="btn btn-primary" id="btn-infos"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            </div>
        </form>
        <p class="small text-muted mt-2 mb-0">La puissance fiscale figure sur la carte grise, rubrique P.6.</p>
    </div>

    <div class="carte carte-etape <?= $ja['etape1'] ? '' : 'etape-off' ?>">
        <h2><span class="num">2</span>Ma carte grise <span class="small text-muted fw-normal">(facultative, mais recommandée)</span></h2>
        <p class="small etape-msg <?= $ja['etape1'] ? 'd-none' : '' ?>"><i class="bi bi-lock-fill me-1"></i>Enregistrez d'abord l'étape&nbsp;1 (défiscalisation souhaitée et puissance fiscale renseignée).</p>
        <p class="mb-2">
            État&nbsp;:
            <span id="cg-etat" class="<?= $carteGrise ? 'etat-ok' : 'etat-ko' ?>">
                <?= $carteGrise ? 'déposée (' . strtoupper(esc($carteGrise['ext'])) . ', ' . date('d/m/Y H:i', $carteGrise['date']) . ')' : 'non déposée' ?>
            </span>
            <a id="cg-voir" class="ms-2 <?= $carteGrise ? '' : 'd-none' ?>" href="<?= site_url('ja/defiscalisation/carte-grise/voir') ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-eye"></i> Consulter</a>
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="file" id="f-cg" class="form-control form-control-sm" style="max-width:340px;" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <button type="button" class="btn btn-sm btn-primary" id="btn-cg"><i class="bi bi-upload me-1"></i>Envoyer</button>
        </div>
        <p class="small text-muted mt-2 mb-0">Formats PDF, JPEG ou PNG, 5&nbsp;Mo maximum. Un nouvel envoi remplace le précédent. Seul le défiscalisateur de la Ligue y a accès.</p>
    </div>

    <div class="carte carte-etape <?= $ja['etape1'] ? '' : 'etape-off' ?>">
        <h2><span class="num">3</span>Mon attestation sur l'honneur</h2>
        <p class="small etape-msg <?= $ja['etape1'] ? 'd-none' : '' ?>"><i class="bi bi-lock-fill me-1"></i>Enregistrez d'abord l'étape&nbsp;1 (défiscalisation souhaitée et puissance fiscale renseignée).</p>
        <p class="mb-2">
            État&nbsp;:
            <span class="<?= $attestation ? 'etat-ok' : 'etat-ko' ?>">
                <?= $attestation ? 'signée et déposée le ' . date('d/m/Y H:i', $attestation) : 'non déposée' ?>
            </span>
        </p>
        <a class="btn btn-sm btn-warning" id="lnk-attestation" href="<?= esc($urlAttestation, 'attr') ?>">
            <i class="bi bi-pen me-1"></i><?= $attestation ? 'Refaire mon attestation' : 'Remplir et signer mon attestation' ?>
        </a>
        <p class="small text-muted mt-2 mb-0">L'attestation se remplit et se signe à l'écran&nbsp;; elle est transmise automatiquement au défiscalisateur. Pensez à enregistrer l'étape&nbsp;1 d'abord.</p>
    </div>

</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script>
'use strict';
const BASE = <?= json_encode(site_url('ja/defiscalisation')) ?>;

// Étapes 2 et 3 : actives seulement quand l'étape 1 est ENREGISTRÉE (défiscalisation cochée + puissance fiscale).
function majEtapes(ok) {
    $('.carte-etape').toggleClass('etape-off', !ok);
    $('.etape-msg').toggleClass('d-none', ok);
    $('#f-cg, #btn-cg').prop('disabled', !ok);
    $('#lnk-attestation').toggleClass('disabled', !ok).attr('aria-disabled', ok ? null : 'true').attr('tabindex', ok ? null : '-1');
}
majEtapes(<?= $ja['etape1'] ? 'true' : 'false' ?>);

$('#btn-infos').on('click', function () {
    const $b = $(this).prop('disabled', true);
    $.post(BASE + '/sauvegarder', {
        defiscalisation: $('#f-defisc').is(':checked') ? 1 : 0,
        puissance: $('#f-cv').val(),
        electrique: $('#f-elec').is(':checked') ? 1 : 0,
    }, null, 'json').done(function (r) {
        nijacToast(r.msg || (r.ok ? 'Enregistré.' : 'Erreur.'), r.ok ? 'success' : 'danger');
        if (r.ok) { majEtapes(!!r.etape1); }
    }).fail(function () {
        nijacToast('Erreur réseau, merci de réessayer.', 'danger');
    }).always(function () { $b.prop('disabled', false); });
});

$('#btn-cg').on('click', function () {
    const f = $('#f-cg')[0].files[0];
    if (!f) { nijacToast('Choisissez d\'abord un fichier.', 'warning'); return; }
    if (f.size > 5 * 1024 * 1024) { nijacToast('Fichier trop volumineux (5 Mo maximum).', 'warning'); return; }
    const fd = new FormData();
    fd.append('carte_grise', f);
    const $b = $(this).prop('disabled', true);
    $.ajax({ url: BASE + '/carte-grise', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            nijacToast(r.msg || (r.ok ? 'Enregistrée.' : 'Erreur.'), r.ok ? 'success' : 'danger');
            if (r.ok) {
                $('#cg-etat').text('déposée (' + r.ext + ', ' + r.date + ')').removeClass('etat-ko').addClass('etat-ok');
                $('#cg-voir').removeClass('d-none');
                $('#f-cg').val('');
            }
        })
        .fail(function () { nijacToast('Erreur réseau, merci de réessayer.', 'danger'); })
        .always(function () { $b.prop('disabled', false); });
});
</script>
</body>
</html>
