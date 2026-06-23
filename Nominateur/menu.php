<?php
/**
 * NIJAC – Menu nominateur (E020)
 *
 * Menu principal pour les nominateurs. Donne accès aux fonctions de nomination :
 * gestion des Juges-Arbitres, saisie des disponibilités par département,
 * interface de nomination et consultation des convocations.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-20
 */
session_start();

if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/csrf.php';

$u           = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$isAdmin     = !empty($u['is_admin']);

// ── Tableau de bord ──────────────────────────────────────────────────────────
$stats = [
    'ja_actifs'          => 0,
    'nominations_valider'=> 0,
    'convocations_envoyer'=> 0,
    'rencontres_sans_ja' => 0,
    'prochaine_date'     => null,
    'prochaine_journee'  => null,
    'prochaine_saison'   => null,
    'emails_ko'          => 0,
];
try {
    $pdo = getPDO();
    try { initTableConfiguration($pdo); } catch (\Throwable $ignored) {}

    $deptsAutorises = getDepartementsAutorises($u['id_departement'] ?? null);
    $deptPh = $deptsAutorises ? implode(',', array_fill(0, count($deptsAutorises), '?')) : '\'\'';

    // JA actifs du département
    if ($deptsAutorises) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM ja j
            LEFT JOIN Club    cl ON cl.Id_Club    = j.Id_Club
            LEFT JOIN Salle   s  ON s.Id_Club     = cl.Id_Club AND s.EstPrincipale = 1
            LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
            WHERE j.Actif = 1
              AND LEFT(lp.CodePostal, 2) IN ($deptPh)
        ");
        $stmt->execute($deptsAutorises);
        $stats['ja_actifs'] = (int)$stmt->fetchColumn();
    }

    $saison = getConfig('saison', '');

    // Prochaine journée du département
    if ($deptsAutorises) {
        $stmt = $pdo->prepare("
            SELECT r.Date AS d, r.Journee FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            WHERE r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 2, 2) IN ($deptPh)
            ORDER BY r.Date ASC LIMIT 1
        ");
        $stmt->execute($deptsAutorises);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $row = [];
    }
    $stats['prochaine_date']    = $row['d']       ?? null;
    $stats['prochaine_journee'] = $row['Journee'] ?? null;
    $stats['prochaine_saison']  = $saison;

    // Nominations à valider du département
    if ($deptsAutorises) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM nomination n
            JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
            JOIN equipe ed    ON ed.Id_Equipe   = r.Id_EquipeDom
            WHERE n.Valide = 0 AND r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 2, 2) IN ($deptPh)
        ");
        $stmt->execute($deptsAutorises);
        $stats['nominations_valider'] = (int)$stmt->fetchColumn();
    }

    // Convocations validées mais email non envoyé du département
    if ($deptsAutorises) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM nomination n
            JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
            JOIN equipe ed    ON ed.Id_Equipe   = r.Id_EquipeDom
            WHERE n.Valide = 1 AND n.EmailEnvoye = 0 AND r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 2, 2) IN ($deptPh)
        ");
        $stmt->execute($deptsAutorises);
        $stats['convocations_envoyer'] = (int)$stmt->fetchColumn();
    }

    // Rencontres à venir sans JA nominé du département
    if ($deptsAutorises) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            WHERE r.Date >= CURDATE()
              AND SUBSTRING(ed.Id_Club, 2, 2) IN ($deptPh)
              AND NOT EXISTS (
                  SELECT 1 FROM nomination n
                  WHERE n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
              )
        ");
        $stmt->execute($deptsAutorises);
        $stats['rencontres_sans_ja'] = (int)$stmt->fetchColumn();
    }

} catch (\Throwable $e) {
    // Tableau de bord non disponible — on continue sans bloquer
}

// Formater la prochaine date en français
$prochaineDateFr = '';
if ($stats['prochaine_date']) {
    $mois = ['','jan.','fév.','mar.','avr.','mai','juin','juil.','août','sep.','oct.','nov.','déc.'];
    $d = new DateTime($stats['prochaine_date']);
    $prochaineDateFr = (int)$d->format('j') . ' ' . $mois[(int)$d->format('n')] . ' ' . $d->format('Y');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Menu Nominateur (E020)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── En-tête ── */
        #page-header {
            background: #2e7d32;
            color: #fff;
            padding: .65rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
        }

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

        /* ── Grille de boutons ── */
        #menu-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
        .btn-messagerie    { background-color: #fff8e1; }
        .btn-envoi         { background-color: #e0f7fa; }

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
    </style>
</head>
<body>

    <?php $pageIcon = 'bi-grid-3x3-gap-fill'; $pageTitle = 'Menu nominateur'; $pageCode = 'E020'; $backUrl = null; require __DIR__ . '/../includes/page_header.php'; ?>

    <?php require __DIR__ . '/includes/toolbar.php'; ?>

    <!-- ── Tableau de bord ── -->
    <div id="dashboard">
        <div class="dash-title"><i class="bi bi-speedometer2 me-1"></i>Tableau de bord</div>
        <div class="dash-cards">

            <!-- Prochaine journée -->
            <div class="dash-card dc-blue">
                <div class="dc-icon"><i class="bi bi-calendar-event"></i></div>
                <div class="dc-label">Prochaine journée</div>
                <?php if ($stats['prochaine_date']): ?>
                    <div class="dc-value" style="font-size:1.2rem;margin-top:6px;"><?= htmlspecialchars($prochaineDateFr) ?></div>
                    <div class="dc-sub">Journée <?= htmlspecialchars($stats['prochaine_journee'] ?? '–') ?> · <?= htmlspecialchars($stats['prochaine_saison'] ?? '') ?></div>
                <?php else: ?>
                    <div class="dc-value" style="font-size:1.1rem;color:#aaa;">–</div>
                    <div class="dc-sub">Aucune rencontre à venir</div>
                <?php endif; ?>
            </div>

            <!-- JA actifs -->
            <div class="dash-card dc-green">
                <div class="dc-icon"><i class="bi bi-people-fill"></i></div>
                <div class="dc-label">JA actifs</div>
                <div class="dc-value"><?= $stats['ja_actifs'] ?></div>
                <div class="dc-sub">juges-arbitres en activité</div>
            </div>

            <!-- Nominations à valider -->
            <div class="dash-card dc-orange<?= $stats['nominations_valider'] > 0 ? ' dc-alert' : '' ?>">
                <div class="dc-icon"><i class="bi bi-hourglass-split"></i></div>
                <div class="dc-label">Nominations à valider</div>
                <div class="dc-value"><?= $stats['nominations_valider'] ?></div>
                <div class="dc-sub">en attente de validation</div>
            </div>

            <!-- Convocations à envoyer -->
            <div class="dash-card dc-purple<?= $stats['convocations_envoyer'] > 0 ? ' dc-alert' : '' ?>">
                <div class="dc-icon"><i class="bi bi-envelope-exclamation"></i></div>
                <div class="dc-label">Convocations à envoyer</div>
                <div class="dc-value"><?= $stats['convocations_envoyer'] ?></div>
                <div class="dc-sub">validées, email non envoyé</div>
            </div>

            <!-- Rencontres sans JA -->
            <div class="dash-card dc-red<?= $stats['rencontres_sans_ja'] > 0 ? ' dc-alert' : '' ?>">
                <div class="dc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="dc-label">Rencontres sans JA</div>
                <div class="dc-value"><?= $stats['rencontres_sans_ja'] ?></div>
                <div class="dc-sub">à venir, aucun JA nominé</div>
            </div>

        </div>
    </div>

    <!-- Grille -->
    <div id="menu-grid">

        <!-- Ligne 1 -->
        <div class="menu-btn-wrap">
            <a href="jugearbitre.php" class="menu-btn btn-ja">
                <div class="btn-icon"><img src="../img/Arbitre_filet.png" alt="Juge-Arbitre"></div>
                <span>Juge-Arbitre</span>
                <span class="btn-desc">Gérer la liste des juges-arbitres, grades et coordonnées</span>
            </a>
        </div>

        <div class="menu-btn-wrap">
            <a href="disponibilites.php" class="menu-btn btn-correspondant">
                <div class="btn-icon"><img src="../img/Dispo.png" alt="Disponibilités JA"></div>
                <span>Disponibilités JA</span>
                <span class="btn-desc">Saisir ou modifier les disponibilités d'un JA par département</span>
            </a>
        </div>

        <div class="menu-btn-wrap">
            <a href="nomination.php" class="menu-btn btn-nomination">
                <div class="btn-icon"><img src="../img/Nomination.png" alt="Nomination JA" style="max-width:220px;max-height:220px;width:220px;height:220px;"></div>
                <span>Nomination JA</span>
                <span class="btn-desc">Affecter les JA aux rencontres et valider les nominations</span>
            </a>
            <?php if ($stats['nominations_valider'] > 0): ?>
                <span class="btn-badge"><?= $stats['nominations_valider'] ?></span>
            <?php endif; ?>
        </div>

        <div class="menu-btn-wrap">
            <a href="messagerie.php" class="menu-btn btn-messagerie">
                <div class="btn-icon"><img src="../img/Correspondant.png" alt="Messagerie"></div>
                <span>Messagerie</span>
                <span class="btn-desc">Préparer les messages pour JA et correspondants de club</span>
            </a>
        </div>

        <!-- Ligne 2 -->

        <div class="menu-btn-wrap">
            <a href="centrenvoye.php" class="menu-btn btn-envoi">
                <div class="btn-icon"><img src="../img/Centrenvoye.png" alt="Centre d'envoi"></div>
                <span>Centre d'envoi</span>
                <span class="btn-desc">Envoyer les messages aux JA et correspondants</span>
            </a>
            <?php if ($stats['convocations_envoyer'] > 0): ?>
                <span class="btn-badge"><?= $stats['convocations_envoyer'] ?></span>
            <?php endif; ?>
        </div>

        <div class="menu-btn-wrap">
            <a href="compta.php" class="menu-btn btn-envoi">
                <div class="btn-icon"><img src="../img/Compta.png" alt="Comptabilite"></div>
                <span>Comptabilité</span>
                <span class="btn-desc">Génération des pièces pour la comptabilité</span>
            </a>
        </div>

        <div class="menu-btn-wrap">
            <a href="JA_R3R4.php" class="menu-btn btn-r34">
                <div class="btn-icon"><img src="img/R3_4.png" alt="Import Rencontres"></div>
                <span>R3 R4 ayant demandé un JA</span>
                <span class="btn-desc">Une R3 ou R4 demande un JA</span>
            </a>
        </div>

        <!-- Déconnexion -->
        <a href="../logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 4;">
            <div class="btn-icon"><i class="bi bi-box-arrow-right" style="color:#842029;"></i></div>
            <span style="color:#842029;">Se déconnecter</span>
            <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
        </a>

    </div>

    <?php require __DIR__ . '/../includes/footer.php'; ?>

    <script src="../asset/js/jquery-3.7.1.min.js"></script>
    <script src="../asset/js/nijac-csrf.js"></script>
    <script src="../asset/js/bootstrap.bundle.min.js"></script>
    <script>
    'use strict';
    $('a[href="../logout.php"]').on('click', function (e) {
        if (!confirm('Voulez-vous vous déconnecter ?')) {
            e.preventDefault();
        }
    });
    </script>


</body>
</html>
