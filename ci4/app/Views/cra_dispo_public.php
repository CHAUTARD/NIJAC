<?php
/**
 * EC74 – page PUBLIQUE dispo-cra?ja=TOKEN (vue autonome, sans header de menu, comme EN22).
 * Variables : $erreur (lien invalide) | $ja, $token, $lignes, [$valeurs], [$msgErreur], [$merci], [$choixEtendus].
 * $choixEtendus = false (réglage EC74) : seuls Disponible / Indisponible sont proposés, plus la valeur déjà enregistrée du JA si masquée.
 */
$choixEtendus = $choixEtendus ?? true;
$merci     = $merci ?? false;
$msgErreur = $msgErreur ?? null;
$valeurs   = $valeurs ?? [];
// Statut CRA_Dispo.Disponible → [classe de pastille, icône du bouton, classe du bouton] (couleurs de la matrice de suivi).
$statuts = [
    'Disponible'                => ['st-dispo', 'check-lg', 'btn-outline-success'],
    'Disponible sous condition' => ['st-cond', 'check2-square', 'btn-outline-primary'],
    'À confirmer'               => ['st-conf', 'hourglass-split', 'btn-outline-warning'],
    'Indisponible'              => ['st-indispo', 'x-lg', 'btn-outline-danger'],
];
$pastille = fn ($s) => isset($statuts[$s])
    ? '<span class="st ' . $statuts[$s][0] . '">' . esc($s) . '</span>'
    : '<em>pas de réponse</em>';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>NIJAC – Disponibilités CRA (EC74)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; }
        #page-header {
            background: #00695c; color: #fff; padding: .5rem 1.25rem;
            display: flex; align-items: center; flex-wrap: wrap; gap: .55rem;
        }
        #page-header h1 { font-size: 1rem; font-weight: 700; margin: 0; }
        #page-header .ja-nom { font-weight: 700; }
        main { max-width: 860px; margin: 0 auto; padding: 1rem; }
        .compet {
            background: #fff; border: 1px solid #d0d8e8; border-radius: 8px;
            padding: .8rem 1rem; margin-bottom: .8rem;
        }
        .compet.passee { background: #f5f5f5; color: #6b7280; }
        .compet .titre { font-weight: 700; color: #00695c; }
        .compet .meta { font-size: .85rem; color: #555; }
        .choix { display: flex; flex-wrap: wrap; gap: .5rem; margin: .6rem 0 .4rem; }
        .choix .btn { min-width: 9rem; }
        .compet.invalide { border-color: #c62828; box-shadow: 0 0 0 2px rgba(198,40,40,.15); }
        #recap li { margin-bottom: .2rem; }
        .st { display: inline-block; padding: .05rem .5rem; border-radius: 999px; font-weight: 600; font-size: .85em; }
        .st-dispo   { background: #c6efce; color: #006100; }
        .st-indispo { background: #ffc7ce; color: #9c0006; }
        .st-conf    { background: #fde2c4; color: #9a4b00; }
        .st-cond    { background: #cfe2f3; color: #1e4e79; }
        @media (max-width: 576px) {
            .choix .btn { flex: 1 1 100%; }
            #actions .btn { width: 100%; margin-bottom: .4rem; }
        }
    </style>
</head>
<body>

<header id="page-header">
    <i class="bi bi-calendar-check"></i>
    <h1>Disponibilités CRA</h1>
    <?php if (!empty($ja)): ?>
        <span class="opacity-50">|</span>
        <span class="ja-nom"><?= esc(trim(mb_strtoupper($ja['Nom']) . ' ' . $ja['Prenom'])) ?></span>
    <?php endif; ?>
</header>

<main>
<?php if (!empty($erreur)): ?>
    <div class="alert alert-danger mt-3"><i class="bi bi-exclamation-triangle me-1"></i><?= esc($erreur) ?></div>

<?php elseif ($merci): ?>
    <div class="alert alert-success mt-3"><i class="bi bi-check-circle me-1"></i>Merci, vos disponibilités ont bien été enregistrées.</div>
    <h2 class="h6 mt-3">Récapitulatif</h2>
    <ul id="recap">
        <?php foreach ($lignes as $l): ?>
            <li>
                n°<?= esc($l['Numero']) ?> — <?= esc($l['Libelle']) ?> (<?= esc($l['Dates']) ?>, <?= esc($l['Lieu']) ?>) :
                <?= $pastille($l['Disponible']) ?>
                <?php if (!empty($l['Commentaire'])): ?><br><small class="text-muted">« <?= esc($l['Commentaire']) ?> »</small><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="small text-muted">Vous pouvez modifier vos réponses avec le même lien tant que la compétition n'a pas eu lieu.</p>
    <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('dispo-cra') . '?ja=' . rawurlencode($token) ?>">Modifier mes réponses</a>

<?php elseif (!$lignes): ?>
    <div class="alert alert-info mt-3">Aucune demande de disponibilité ne vous a été envoyée pour le moment.</div>

<?php else: ?>
    <p class="mt-2">Pour chaque compétition, indiquez votre <b>disponibilité</b> pour être juge-arbitre. Un commentaire est possible (facultatif)<?php if ($choixEtendus): ?>,
        il est <b>obligatoire</b> pour « Disponible sous condition » afin de préciser la condition<?php endif; ?>.</p>
    <?php if ($msgErreur): ?>
        <div class="alert alert-danger" role="alert"><?= esc($msgErreur) ?></div>
    <?php endif; ?>
    <div id="err-client" class="alert alert-danger d-none" role="alert"></div>

    <form method="post" action="<?= site_url('dispo-cra') ?>" id="form-dispo" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="ja" value="<?= esc($token) ?>">
        <?php $nbModifiables = 0; foreach ($lignes as $l):
            $idC = (int) $l['Id_CRA_Competition'];
            $v   = $valeurs[$idC] ?? ['dispo' => $l['Disponible'], 'com' => $l['Commentaire']];
            $d   = (string) $v['dispo'];   // « Non renseigné » : aucun bouton coché
            $nbModifiables += $l['Modifiable'] ? 1 : 0;
        ?>
            <div class="compet<?= $l['Modifiable'] ? '' : ' passee' ?>" data-id="<?= $idC ?>"
                 data-libelle="<?= esc('n°' . $l['Numero'] . ' — ' . $l['Libelle'] . ' (' . $l['Dates'] . ')') ?>">
                <div class="titre">n°<?= esc($l['Numero']) ?> — <?= esc($l['Libelle']) ?></div>
                <div class="meta"><i class="bi bi-calendar3 me-1"></i><?= esc($l['Dates']) ?>
                    · <i class="bi bi-geo-alt me-1"></i><?= esc($l['Lieu']) ?> · Niveau <?= esc($l['NiveauJA']) ?></div>
                <?php if ($l['Modifiable']): ?>
                    <div class="choix" role="radiogroup" aria-label="Disponibilité n°<?= esc($l['Numero']) ?>">
                        <?php $i = 0; foreach ($statuts as $s => [$stCls, $ico, $btnCls]):
                            // Choix masqués par le réglage EC74 : seule la valeur déjà enregistrée de ce JA reste proposée (conservable).
                            $masque = !$choixEtendus && in_array($s, ['À confirmer', 'Disponible sous condition'], true);
                            if ($masque && $s !== $l['Disponible']) continue;
                            $rid = 'd' . $i++ . '-' . $idC; ?>
                            <input type="radio" class="btn-check" name="dispo[<?= $idC ?>]" id="<?= $rid ?>" value="<?= esc($s) ?>"
                                   data-cls="<?= $stCls ?>"<?= $i === 1 ? ' required' : '' ?> <?= $d === $s ? 'checked' : '' ?>>
                            <label class="btn <?= $btnCls ?>" for="<?= $rid ?>"><i class="bi bi-<?= $ico ?> me-1"></i><?= esc($s) ?><?= $masque ? ' <small>(valeur actuelle)</small>' : '' ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="text" class="form-control form-control-sm" name="commentaire[<?= $idC ?>]" maxlength="255"
                           placeholder="<?= $choixEtendus ? 'Commentaire (facultatif ; obligatoire pour « Disponible sous condition » : précisez la condition)' : 'Commentaire (facultatif)' ?>"
                           aria-label="Commentaire n°<?= esc($l['Numero']) ?>" value="<?= esc((string) $v['com']) ?>">
                <?php else: ?>
                    <div class="mt-2 small">Compétition passée (lecture seule) :
                        <?= $pastille($l['Disponible']) ?>
                        <?php if (!empty($l['Commentaire'])): ?> — « <?= esc($l['Commentaire']) ?> »<?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($nbModifiables): ?>
            <div id="confirmation" class="alert alert-info d-none">
                <b>Vérifiez vos réponses :</b>
                <ul id="recap" class="mb-0 mt-1"></ul>
            </div>
            <div id="actions" class="text-end">
                <button type="button" class="btn btn-primary" id="btn-verifier"><i class="bi bi-eye me-1"></i>Vérifier mes réponses</button>
                <button type="button" class="btn btn-outline-secondary d-none" id="btn-modifier">Modifier</button>
                <button type="submit" class="btn btn-success d-none" id="btn-valider"><i class="bi bi-check2-circle me-1"></i>Valider mes disponibilités</button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
</main>

<script>
'use strict';
(function () {
    const form = document.getElementById('form-dispo');
    if (!form || !document.getElementById('btn-verifier')) return;
    const blocs = [...form.querySelectorAll('.compet:not(.passee)')];
    const $ = id => document.getElementById(id);
    const etape = confirmer => {
        $('confirmation').classList.toggle('d-none', !confirmer);
        $('btn-verifier').classList.toggle('d-none', confirmer);
        $('btn-modifier').classList.toggle('d-none', !confirmer);
        $('btn-valider').classList.toggle('d-none', !confirmer);
    };
    const REPONSES = <?= json_encode(array_keys($statuts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const COND = 'Disponible sous condition';
    const COND_OBLIGATOIRE = <?= $choixEtendus ? 'true' : 'false' ?>;   // condition obligatoire seulement si les choix étendus sont proposés
    /** Erreur du bloc (choix absent / hors liste, condition manquante), ou ''. */
    const erreurBloc = b => {
        const r = b.querySelector('input[type=radio]:checked');
        if (!r || !REPONSES.includes(r.value)) return 'Merci d\'indiquer votre disponibilité pour chaque compétition.';
        if (COND_OBLIGATOIRE && r.value === COND && !b.querySelector('input[type=text]').value.trim()) {
            return 'Merci de préciser la condition en commentaire pour « Disponible sous condition ».';
        }
        return '';
    };
    // Étape 1 : tous les choix renseignés (et la condition précisée), puis récapitulatif avant validation.
    $('btn-verifier').addEventListener('click', function () {
        const erreurs = new Set();
        const recap = $('recap');
        recap.textContent = '';
        blocs.forEach(b => {
            const err = erreurBloc(b);
            b.classList.toggle('invalide', !!err);
            if (err) { erreurs.add(err); return; }
            const r = b.querySelector('input[type=radio]:checked');
            const com = b.querySelector('input[type=text]').value.trim();
            const li = document.createElement('li');
            const s = document.createElement('span');
            s.className = 'st ' + r.dataset.cls;
            s.textContent = r.value;
            li.append(b.dataset.libelle + ' : ', s, com ? ` — « ${com} »` : '');
            recap.append(li);
        });
        $('err-client').textContent = [...erreurs].join(' ');
        $('err-client').classList.toggle('d-none', !erreurs.size);
        if (erreurs.size) { form.querySelector('.compet.invalide').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
        etape(true);
        $('confirmation').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    $('btn-modifier').addEventListener('click', () => etape(false));
    // Toute modification après le récapitulatif oblige à le revoir.
    form.addEventListener('change', () => etape(false));
    form.addEventListener('input', () => etape(false));
    form.addEventListener('submit', function (e) {
        if (blocs.some(erreurBloc)) { e.preventDefault(); etape(false); return; }
        $('btn-valider').disabled = true;
    });
})();
</script>
</body>
</html>
