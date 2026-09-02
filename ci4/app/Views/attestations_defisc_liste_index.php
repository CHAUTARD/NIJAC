<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Attestations reçues (ED54)</title>

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

        #main-content { flex: 1; padding: 1.25rem; overflow-y: auto; }

        #wrap {
            max-width: 860px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #f1ece8;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,.09);
            padding: 1.25rem 1.5rem 1.5rem;
        }
        #wrap h1 { font-size: 1.05rem; font-weight: 700; color: #9a3412; margin: 0 0 .25rem; }
        #wrap .sub { font-size: .82rem; color: #6b7a90; margin-bottom: 1rem; }

        table.att { width: 100%; border-collapse: collapse; font-size: .86rem; }
        table.att thead th {
            background: #fff; color: #8a94a6; border: 0; border-bottom: 2px solid #f1ece8;
            font-size: .68rem; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
            padding: .6rem .55rem; text-align: left; white-space: nowrap;
        }
        table.att tbody tr:nth-child(odd)  { background: #fff1e6; }
        table.att tbody tr:hover           { background: #ffe4d1; }
        table.att td { padding: .5rem .55rem; border: 0; border-bottom: 1px solid #f2f0ee; vertical-align: middle; }
        table.att td.num { font-variant-numeric: tabular-nums; color: #8a94a6; }
        table.att td.nom { font-weight: 600; color: #1f2937; }
        .att-inconnu { color: #b45309; font-style: italic; font-weight: 500; }

        .btn-ouvrir {
            display: inline-flex; align-items: center; gap: .35rem;
            background: #fff; color: #c2410c; border: 1px solid #e8590c;
            border-radius: 999px; font-size: .78rem; font-weight: 600;
            padding: .22rem .7rem; text-decoration: none;
        }
        .btn-ouvrir:hover { background: #fff1e6; }

        #vide { text-align: center; color: #8a94a6; padding: 2.5rem 1rem; font-size: .9rem; }
        #vide i { font-size: 2rem; opacity: .3; display: block; margin-bottom: .5rem; }

        #page-footer {
            background: #e8eef7; border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem; font-size: .8rem;
            display: flex; justify-content: center; align-items: center; flex-shrink: 0;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
    </style>

    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin-orange.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'folder2-open', 'phTitle' => 'Attestations reçues', 'phCode' => 'ED54',
    'phCrumbLabel' => 'Menu Défiscalisateur', 'phCrumbUrl' => site_url('defiscalisateur-menu'),
    'phBackUrl' => site_url('defiscalisateur-menu'), 'phCrumbColor' => '#ffe0c2', 'phBadgeColor' => '#ffe0c2',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="main-content">
    <div id="wrap">
        <h1><i class="bi bi-folder2-open me-1"></i>Attestations sur l'honneur reçues</h1>
        <p class="sub">
            Fichiers PDF déposés par les JA depuis <a href="<?= site_url('attestation-defisc') ?>">ED53</a>,
            un par juge-arbitre (répertoire <code>_Defiscalisation/</code>) — <?= count($lignes) ?> attestation(s).
        </p>

        <?php if ($lignes === []): ?>
        <div id="vide">
            <i class="bi bi-inbox"></i>
            Aucune attestation reçue pour le moment.
        </div>
        <?php else: ?>
        <table class="att">
            <thead>
                <tr>
                    <th>N° JA</th>
                    <th>Nom</th>
                    <th>Prénom</th>
                    <th>Déposée le</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lignes as $l): ?>
                <tr>
                    <td class="num"><?= (int) $l['idJa'] ?></td>
                    <td class="nom"><?= $l['inconnu'] ? '<span class="att-inconnu">JA inconnu</span>' : esc($l['nom']) ?></td>
                    <td><?= esc($l['prenom']) ?></td>
                    <td><?= esc($l['depose']) ?></td>
                    <td>
                        <a class="btn-ouvrir" href="<?= site_url('attestations-defisc/telecharger/' . (int) $l['idJa']) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-file-earmark-pdf"></i>Ouvrir le PDF
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?= view('partials/page_footer') ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
