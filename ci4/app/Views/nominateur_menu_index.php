<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Menu Nominateur (E003)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #e8f5e9;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── En-tête (vert, propre à E003/Nominateur) ── */
        #page-header {
            background: #2e7d32;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
        }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
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

        /* ── Toolbar ── */
        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            gap: .75rem;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        #toolbar .ts-pwd-warning:hover { color: #900; }
        #btn-switch-admin {
            display: <?= $isAdmin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: var(--nijac-blue);
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        #btn-switch-admin:hover { background: #0f2550; color: #fff; }

        /* ── Tableau de bord ── */
        #dashboard {
            padding: 16px 24px 0;
        }

        .dash-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #5a6a82;
            margin-bottom: 10px;
        }

        .dash-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 6px;
        }

        .dash-card {
            background: #fff;
            border-radius: 10px;
            padding: 14px 16px 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,.10);
            border-left: 4px solid #ccc;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .dash-card .dc-label {
            font-size: .70rem;
            color: #6b7a90;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            line-height: 1.2;
        }
        .dash-card .dc-value {
            font-size: 1.9rem;
            font-weight: 800;
            line-height: 1;
            margin: 4px 0 2px;
        }
        .dash-card .dc-sub {
            font-size: .68rem;
            color: #888;
        }
        .dash-card .dc-icon {
            font-size: 1.1rem;
            margin-bottom: 2px;
        }

        /* Couleurs par carte */
        .dc-blue   { border-color: #1a3a6b; }
        .dc-blue   .dc-value { color: #1a3a6b; }
        .dc-blue   .dc-icon  { color: #1a3a6b; }

        .dc-orange { border-color: #e65100; }
        .dc-orange .dc-value { color: #e65100; }
        .dc-orange .dc-icon  { color: #e65100; }

        .dc-green  { border-color: #2e7d32; }
        .dc-green  .dc-value { color: #2e7d32; }
        .dc-green  .dc-icon  { color: #2e7d32; }

        .dc-red    { border-color: #c62828; }
        .dc-red    .dc-value { color: #c62828; }
        .dc-red    .dc-icon  { color: #c62828; }

        .dc-purple { border-color: #6a1b9a; }
        .dc-purple .dc-value { color: #6a1b9a; }
        .dc-purple .dc-icon  { color: #6a1b9a; }

        /* Alerte si valeur > 0 */
        .dc-alert  { background: #fff8f0; }

        /* Carte cliquable (détail au clic) */
        .dc-clickable { cursor: pointer; transition: box-shadow .12s, transform .12s; }
        .dc-clickable:hover { box-shadow: 0 3px 10px rgba(0,0,0,.16); transform: translateY(-1px); }

        #modal-convocations .conv-row,
        #modal-rencontres-sans-ja .conv-row {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .4rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .85rem;
        }
        #modal-convocations .conv-date,
        #modal-rencontres-sans-ja .conv-date { font-weight: 700; color: #1a3a6b; min-width: 90px; }
        #modal-convocations .conv-ja   { font-weight: 600; flex: 1; }
        #modal-convocations .conv-renc,
        #modal-rencontres-sans-ja .conv-renc { color: #666; font-size: .78rem; }
        #modal-rencontres-sans-ja .conv-equipes { font-weight: 600; flex: 1; }

        /* ── Grille de boutons ── */
        #menu-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            padding: 16px 24px 24px;
            flex: 1;
        }

        .menu-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 20px 12px 18px;
            border: 2px solid rgba(0,0,0,.12);
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: #222;
            transition: filter .15s, transform .1s, box-shadow .15s;
            box-shadow: 2px 2px 6px rgba(0,0,0,.15);
            text-align: center;
            min-height: 190px;
        }

        .menu-btn .btn-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 150px;
            height: 150px;
            flex-shrink: 0;
        }

        .menu-btn img {
            max-width: 150px;
            max-height: 150px;
            width: 150px;
            height: 150px;
            object-fit: contain;
        }

        .menu-btn .btn-icon i { font-size: 6rem; line-height: 1; }
        .menu-btn span { line-height: 1.25; margin-top: 10px; }
        .menu-btn .btn-desc {
            font-size: .72rem;
            font-weight: 400;
            color: #555;
            margin-top: 4px;
            line-height: 1.3;
        }
        .menu-btn:hover .btn-desc { color: #333; }
        .menu-btn:hover {
            filter: brightness(1.08);
            transform: translateY(-2px);
            box-shadow: 4px 6px 14px rgba(0,0,0,.22);
            color: #000;
        }
        .menu-btn:active { transform: translateY(0); box-shadow: 1px 2px 4px rgba(0,0,0,.15); }

        /* Couleurs boutons */
        .btn-nomination    { background-color: #e8f5e9; }
        .btn-ja            { background-color: #e3f2fd; }
        .btn-envoi         { background-color: #e0f7fa; }
        .btn-r34           { background-color: #fbe9e7; }
        .btn-correspondant { background-color: #fff8e1; }

        /* Code écran en haut à droite de chaque bouton */
        .menu-btn { position: relative; }
        .btn-code {
            position: absolute;
            top: 6px;
            right: 8px;
            font-size: .62rem;
            font-weight: 600;
            color: rgba(0,0,0,.32);
            letter-spacing: .03em;
            pointer-events: none;
        }

        /* Badge sur bouton */
        .btn-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #c62828;
            color: #fff;
            font-size: .65rem;
            font-weight: 800;
            border-radius: 50px;
            padding: 2px 7px;
            min-width: 22px;
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,.25);
        }
        .menu-btn-wrap { position: relative; }

        #zone-deconnexion {
            padding: 0 24px 24px;
            display: flex;
            justify-content: center;
        }
        #zone-deconnexion .menu-btn {
            max-width: 260px;
            width: 100%;
        }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php (pas de bouton Retour, page racine) -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu nominateur
        <small class="opacity-75 ms-2">(E003)</small>
    </div>
</div>

<!-- Toolbar : recopié de Nominateur/includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbSwitchTo' => 'admin']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Tableau de bord ── -->
<div id="dashboard">
    <div class="dash-title"></div>
    <div class="dash-cards">

        <!-- Prochaine journée -->
        <div class="dash-card dc-blue">
            <div class="dc-icon"><i class="bi bi-calendar-event"></i></div>
            <div class="dc-label">Prochaine journée</div>
            <?php if ($stats['prochaine_date']): ?>
                <div class="dc-value" style="font-size:1.2rem;margin-top:6px;"><?= esc($prochaineDateFr) ?></div>
                <div class="dc-sub">Journée <?= esc($stats['prochaine_journee'] ?? '–') ?> · <?= esc($stats['prochaine_saison'] ?? '') ?></div>
            <?php else: ?>
                <div class="dc-value" style="font-size:1.1rem;color:#aaa;">–</div>
                <div class="dc-sub">Aucune rencontre à venir</div>
            <?php endif; ?>
        </div>

        <!-- JA actifs -->
        <div class="dash-card dc-green">
            <div class="dc-icon"><i class="bi bi-people-fill"></i></div>
            <div class="dc-label">JA actifs</div>
            <div class="dc-value"><?= (int) $stats['ja_actifs'] ?></div>
            <div class="dc-sub">juges-arbitres en activité</div>
        </div>

        <!-- Nominations à valider -->
        <div class="dash-card dc-orange<?= $stats['nominations_valider'] > 0 ? ' dc-alert' : '' ?>">
            <div class="dc-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="dc-label">Nominations à valider</div>
            <div class="dc-value"><?= (int) $stats['nominations_valider'] ?></div>
            <div class="dc-sub">en attente de validation</div>
        </div>

        <!-- Convocations à envoyer -->
        <div class="dash-card dc-purple dc-clickable<?= $stats['convocations_envoyer'] > 0 ? ' dc-alert' : '' ?>" id="card-convocations-envoyer">
            <div class="dc-icon"><i class="bi bi-envelope-exclamation"></i></div>
            <div class="dc-label">Convocations à envoyer</div>
            <div class="dc-value"><?= (int) $stats['convocations_envoyer'] ?></div>
            <div class="dc-sub">validées, email non envoyé</div>
        </div>

        <!-- Rencontres sans JA -->
        <div class="dash-card dc-red dc-clickable<?= $stats['rencontres_sans_ja'] > 0 ? ' dc-alert' : '' ?>" id="card-rencontres-sans-ja">
            <div class="dc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="dc-label">Rencontres sans JA</div>
            <div class="dc-value"><?= (int) $stats['rencontres_sans_ja'] ?></div>
            <div class="dc-sub">à venir, aucun JA nominé</div>
        </div>

    </div>
</div>

<!-- Grille -->
<div id="menu-grid">

    <!-- Ligne 1 -->
    <div class="menu-btn-wrap">
        <a href="<?= site_url('jugearbitre') ?>" class="menu-btn btn-ja">
            <span class="btn-code">EN11</span>
            <div class="btn-icon"><img src="<?= base_url('img/Arbitre_filet.png') ?>" alt="Juge-Arbitre"></div>
            <span>Juge-Arbitre</span>
            <span class="btn-desc">Gérer la liste des juges-arbitres, grades et coordonnées</span>
        </a>
    </div>

    <div class="menu-btn-wrap">
        <a href="<?= site_url('disponibilites') ?>" class="menu-btn btn-correspondant">
            <span class="btn-code">EN13</span>
            <div class="btn-icon"><img src="<?= base_url('img/Dispo.png') ?>" alt="Disponibilités JA"></div>
            <span>Disponibilités JA</span>
            <span class="btn-desc">Saisir ou modifier les disponibilités d'un JA par département</span>
        </a>
    </div>

    <div class="menu-btn-wrap">
        <a href="<?= site_url('nomination') ?>" class="menu-btn btn-nomination">
            <span class="btn-code">EN14</span>
            <div class="btn-icon"><img src="<?= base_url('img/Nomination.png') ?>" alt="Nomination JA" style="max-width:220px;max-height:220px;width:220px;height:220px;"></div>
            <span>Nomination JA</span>
            <span class="btn-desc">Affecter les JA aux rencontres et valider les nominations</span>
        </a>
        <?php if ($stats['nominations_valider'] > 0): ?>
            <span class="btn-badge"><?= (int) $stats['nominations_valider'] ?></span>
        <?php endif; ?>
    </div>

    <div class="menu-btn-wrap">
        <a href="<?= site_url('centrenvoye') ?>" class="menu-btn btn-envoi">
            <span class="btn-code">EN15</span>
            <div class="btn-icon"><img src="<?= base_url('img/Centrenvoye.png') ?>" alt="Centre d'envoi"></div>
            <span>Centre d'envoi</span>
            <span class="btn-desc">Envoyer les messages aux JA et correspondants</span>
        </a>
    </div>

    <!-- Ligne 2 -->

    <div class="menu-btn-wrap">
        <a href="<?= site_url('compta') ?>" class="menu-btn btn-envoi">
            <span class="btn-code">EN16</span>
            <div class="btn-icon"><img src="<?= base_url('img/Compta.png') ?>" alt="Comptabilite"></div>
            <span>Comptabilité</span>
            <span class="btn-desc">Génération des pièces pour la comptabilité</span>
        </a>
    </div>

    <div class="menu-btn-wrap">
        <a href="<?= site_url('stats-ja') ?>" class="menu-btn btn-ja">
            <span class="btn-code">EN17</span>
            <div class="btn-icon"><img src="<?= base_url('img/Stat_JA.png') ?>" alt="Statistiques JA"></div>
            <span>Statistiques JA</span>
            <span class="btn-desc">Arbitrages, kilomètres et frais par JA sur une période</span>
        </a>
    </div>


</div>

<!-- Déconnexion : ligne à part, centrée -->
<div id="zone-deconnexion">
    <a href="<?= site_url('logout') ?>" id="lnk-logout" class="menu-btn" style="background:#f8d7da;">
        <div class="btn-icon"><img src="<?= base_url('img/Quitter.png') ?>" alt="Se déconnecter"></div>
        <span style="color:#842029;">Se déconnecter</span>
        <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
    </a>
</div>

<!-- Modale détail « Convocations à envoyer » -->
<div class="modal fade" id="modal-convocations" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#6a1b9a;color:#fff;">
                <h6 class="modal-title mb-0"><i class="bi bi-envelope-exclamation me-2"></i>Convocations à envoyer</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modal-convocations-body">
                <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <a href="<?= site_url('nomination') ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-check-fill me-1"></i>Aller à la Nomination (EN14)
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modale détail « Rencontres sans JA » -->
<div class="modal fade" id="modal-rencontres-sans-ja" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#c62828;color:#fff;">
                <h6 class="modal-title mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Rencontres sans JA</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modal-rencontres-sans-ja-body">
                <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
            <div class="modal-footer py-2">
                <a href="<?= site_url('nomination') ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-check-fill me-1"></i>Aller à la Nomination (EN14)
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script>
'use strict';

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDateFr(s) {
    if (!s) return '';
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

$('#card-convocations-envoyer').on('click', function () {
    const $body = $('#modal-convocations-body').html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>');
    new bootstrap.Modal('#modal-convocations').show();

    $.getJSON('<?= site_url('nominateur-menu/convocations-a-envoyer') ?>', function (r) {
        if (!r.ok || !r.data.length) {
            $body.html('<div class="text-center text-muted py-4"><i class="bi bi-check-circle fs-2 d-block mb-2"></i>Aucune convocation en attente d\'envoi.</div>');
            return;
        }
        $body.html(r.data.map(c => `
            <div class="conv-row">
                <span class="conv-date"><i class="bi bi-calendar3 me-1"></i>${escHtml(formatDateFr(c.Date))}</span>
                <span class="conv-ja"><i class="bi bi-person-fill me-1"></i>${escHtml(c.Prenom)} ${escHtml(c.Nom)}</span>
                <span class="conv-renc">J${escHtml(c.Journee)} — ${escHtml(c.Division)} — ${escHtml(c.NomDom)} vs ${escHtml(c.NomExt || '?')}</span>
            </div>
        `).join(''));
    }).fail(function () {
        $body.html('<div class="text-center text-danger py-4">Erreur de communication.</div>');
    });
});

$('#card-rencontres-sans-ja').on('click', function () {
    const $body = $('#modal-rencontres-sans-ja-body').html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>');
    new bootstrap.Modal('#modal-rencontres-sans-ja').show();

    $.getJSON('<?= site_url('nominateur-menu/rencontres-sans-ja') ?>', function (r) {
        if (!r.ok || !r.data.length) {
            $body.html('<div class="text-center text-muted py-4"><i class="bi bi-check-circle fs-2 d-block mb-2"></i>Toutes les rencontres à venir ont un JA nominé.</div>');
            return;
        }
        $body.html(r.data.map(c => `
            <div class="conv-row">
                <span class="conv-date"><i class="bi bi-calendar3 me-1"></i>${escHtml(formatDateFr(c.Date))}</span>
                <span class="conv-equipes">${escHtml(c.NomDom)} vs ${escHtml(c.NomExt || '?')}</span>
                <span class="conv-renc">J${escHtml(c.Journee)} — ${escHtml(c.Division)}</span>
            </div>
        `).join(''));
    }).fail(function () {
        $body.html('<div class="text-center text-danger py-4">Erreur de communication.</div>');
    });
});
</script>
</body>
</html>
