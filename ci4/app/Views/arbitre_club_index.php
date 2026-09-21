<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Juge-arbitre du club (EN25)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <style>
        body { background: #eef2f8; font-family: 'Segoe UI', system-ui, sans-serif; padding: 1.5rem .75rem; }
        .card-pub { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 6px 24px rgba(0,0,0,.12); overflow: hidden; }
        .card-pub .hd { background: #1a3a6b; color: #fff; padding: 1rem 1.25rem; }
        .card-pub .hd h1 { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .card-pub .hd .ec { font-size: .72rem; opacity: .7; }
        .card-pub .bd { padding: 1.25rem; }
        .bandeau-ligue { display: block; max-width: 100%; height: auto; margin: 0 auto 1rem; }
        .renc-box { background: #f4f7fb; border: 1px solid #d8e2f0; border-radius: 8px; padding: .9rem 1rem; font-size: .9rem; }
        .renc-box .vs { font-size: 1.05rem; font-weight: 700; color: #1a3a6b; }
        .renc-box dl { margin: .6rem 0 0; }
        .renc-box dt { font-weight: 600; color: #4b5b74; font-size: .78rem; }
        .renc-box dd { margin: 0 0 .4rem; }
        label.form-label { font-weight: 600; }
        .alert-retard { background: #fff3cd; border: 1px solid #f0c36d; color: #7a5b00; border-radius: 8px; padding: .7rem .9rem; font-size: .85rem; }
        #msg-ok { display: none; text-align: center; padding: 1.5rem .5rem; }
        #msg-ok i { font-size: 2.5rem; color: #2e7d32; display: block; margin-bottom: .5rem; }
    </style>
</head>
<body>
<div class="card-pub">
    <div class="hd">
        <h1><i class="bi bi-person-badge me-2"></i>Juge-arbitre du club <span class="ec">EN25</span></h1>
    </div>
    <div class="bd">

    <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="Ligue de Normandie de Tennis de Table" class="bandeau-ligue">

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($erreur) ?></div>
    <?php else: ?>
        <?php $c = $ctx; ?>
        <div class="renc-box mb-3">
            <div class="vs"><?= esc($c['NomDom']) ?> <span class="text-muted">reçoit</span> <?= esc($c['NomExt'] ?: '?') ?></div>
            <dl>
                <dt>Division</dt><dd><?= esc(($c['Division'] ?? '') . ($c['DivisionNom'] ? ' — ' . $c['DivisionNom'] : '')) ?><?= $c['Journee'] ? ' · Journée ' . esc($c['Journee']) : '' ?><?= $c['Poule'] ? ' · Poule ' . esc($c['Poule']) : '' ?></dd>
                <dt>Date et heure</dt><dd><?= esc(date('d/m/Y', strtotime($c['Date']))) ?> à <?= esc(substr($c['Heure'], 0, 5)) ?></dd>
                <dt>Lieu</dt><dd><?= esc(trim(($c['SalleNom'] ?? '') . ' ' . ($c['SalleAdresse'] ?? '') . ' ' . ($c['SalleCp'] ?? '') . ' ' . ($c['SalleVille'] ?? ''))) ?: '—' ?></dd>
            </dl>
        </div>

        <?php if ($enRetard): ?>
            <div class="alert-retard mb-3">
                <i class="bi bi-clock-history me-1"></i>Le délai de <strong>5 jours</strong> après la rencontre est dépassé. Vous pouvez encore répondre, mais merci de prévenir la CRA.
            </div>
        <?php endif; ?>

        <?php if ($dejaFait): ?>
            <div class="alert alert-info py-2 mb-3 small">
                <i class="bi bi-info-circle me-1"></i>Réponse déjà enregistrée : <strong><?= esc($dejaFait['NomJa']) ?></strong>.
                <?= (int) $dejaFait['EmailEnvoye'] === 1 ? 'La convocation a été envoyée — contactez la CRA pour tout changement.' : 'Vous pouvez la corriger ci-dessous.' ?>
            </div>
        <?php endif; ?>

        <?php if (!$jas): ?>
        <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Aucun juge-arbitre n'est rattaché à votre club dans l'application. Merci de contacter la CRA.</div>
        <?php else: ?>
        <form id="form-ac" <?= (int) ($dejaFait['EmailEnvoye'] ?? 0) === 1 ? 'style="display:none"' : '' ?>>
            <input type="hidden" name="renc" value="<?= esc($token) ?>">
            <div class="mb-3">
                <label class="form-label" for="sel-ja">Juge-arbitre qui a dirigé la rencontre</label>
                <select id="sel-ja" name="id_ja" class="form-select" required>
                    <option value="">— Choisir un juge-arbitre —</option>
                    <?php foreach ($jas as $j): ?>
                    <option value="<?= (int) $j['Id_JA'] ?>"><?= esc(strtoupper((string) $j['Nom']) . ' ' . $j['Prenom']) ?> (n°<?= (int) $j['Id_JA'] ?>)</option>
                    <?php endforeach; ?>
                    <option value="hors-club">— Juge-arbitre hors club —</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-1"></i>Envoyer</button>
            <div id="form-err" class="text-danger small mt-2"></div>
        </form>
        <?php endif; ?>

        <div id="msg-ok">
            <i class="bi bi-check-circle-fill"></i>
            <div class="fw-bold">Merci, votre réponse a bien été enregistrée.</div>
            <div class="text-muted small mt-1" id="msg-ok-ja"></div>
        </div>
    <?php endif; ?>

    </div>
</div>

<div class="modal fade" id="modal-hors-club" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="bi bi-search me-1"></i>Juge-arbitre hors club</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="txt-nom-hors-club">Nom du juge-arbitre</label>
                <input type="text" id="txt-nom-hors-club" class="form-control" placeholder="Nom, prénom…" autocomplete="off">
                <div id="hors-club-resultats" class="mt-2"></div>
                <div id="hors-club-err" class="text-danger small mt-2"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-primary" id="btn-hors-club-chercher"><i class="bi bi-search me-1"></i>Rechercher</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';
const $selJa = $('#sel-ja');
let modalHorsClub;
$(function () {
    const elModal = document.getElementById('modal-hors-club');
    if (elModal) modalHorsClub = new bootstrap.Modal(elModal);
});

$selJa.on('change', function () {
    if ($(this).val() !== 'hors-club') return;
    $('#txt-nom-hors-club').val('');
    $('#hors-club-resultats').empty();
    $('#hors-club-err').text('');
    modalHorsClub.show();
    setTimeout(() => $('#txt-nom-hors-club').trigger('focus'), 300);
});

/** Ajoute (si besoin) l'option du JA trouvé dans #sel-ja et la sélectionne. */
function retenirJaHorsClub(j) {
    const label = `${String(j.Nom).toUpperCase()} ${j.Prenom} (n°${j.Id_JA}) — hors club`;
    let $opt = $selJa.find(`option[value="${j.Id_JA}"]`);
    if (!$opt.length) {
        $opt = $('<option>').val(j.Id_JA).text(label);
        $selJa.find('option[value="hors-club"]').before($opt);
    }
    $selJa.val(String(j.Id_JA));
    modalHorsClub.hide();
}

function chercherJaHorsClub() {
    const nom = $('#txt-nom-hors-club').val().trim();
    $('#hors-club-err').text('');
    const $resultats = $('#hors-club-resultats').empty().removeClass('list-group');
    if (!nom) { $('#hors-club-err').text('Saisissez un nom.'); return; }

    $.get('<?= site_url('arbitre-club/rechercher-ja') ?>', { nom, renc: $('input[name=renc]').val() }, function (res) {
        if (!res.ok) { $('#hors-club-err').text(res.msg || 'Erreur.'); return; }
        if (!res.jas.length) { $('#hors-club-err').text('Aucun juge-arbitre actif trouvé avec ce nom.'); return; }
        if (res.jas.length === 1) { retenirJaHorsClub(res.jas[0]); return; }

        // Plusieurs correspondances : laisser choisir.
        $resultats.addClass('list-group');
        res.jas.forEach(j => {
            $('<button type="button" class="list-group-item list-group-item-action">')
                .text(`${String(j.Nom).toUpperCase()} ${j.Prenom} (n°${j.Id_JA})`)
                .on('click', () => retenirJaHorsClub(j))
                .appendTo($resultats);
        });
    }, 'json').fail(() => $('#hors-club-err').text('Erreur réseau.'));
}
$('#btn-hors-club-chercher').on('click', chercherJaHorsClub);
$('#txt-nom-hors-club').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); chercherJaHorsClub(); }
});

// Fermeture sans résultat retenu (Annuler, Échap, clic hors modale) : revenir au placeholder.
$('#modal-hors-club').on('hidden.bs.modal', function () {
    if ($selJa.val() === 'hors-club') $selJa.val('');
});

$('#form-ac').on('submit', function (e) {
    e.preventDefault();
    $('#form-err').text('');
    const $btn = $(this).find('button[type=submit]').prop('disabled', true);
    $.post('<?= site_url('arbitre-club/enregistrer') ?>', $(this).serialize(), function (res) {
        if (res.ok) {
            $('#form-ac').hide();
            $('#msg-ok-ja').text(res.ja ? 'Juge-arbitre : ' + res.ja : '');
            $('#msg-ok').show();
        } else {
            $('#form-err').text(res.msg || 'Erreur.');
            $btn.prop('disabled', false);
        }
    }, 'json').fail(function () {
        $('#form-err').text('Erreur réseau, réessayez.');
        $btn.prop('disabled', false);
    });
});
</script>
</body>
</html>
