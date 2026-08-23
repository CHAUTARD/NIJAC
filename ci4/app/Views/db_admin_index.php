<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Administration base de données (E099)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            flex-shrink: 0;
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

        #main-area {
            flex: 1;
            display: flex;
            padding: 1rem 1.25rem;
            overflow: hidden;
            gap: 1rem;
        }

        #panel-tables {
            width: 240px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border: 1px solid #e0e8f0;
            border-radius: 6px;
            background: #fff;
            overflow: hidden;
        }
        #panel-tables .panel-titre {
            background: #1a3a6b;
            color: #fff;
            font-size: .8rem;
            font-weight: 700;
            padding: .5rem .75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        #liste-tables { flex: 1; overflow-y: auto; }
        #liste-tables .table-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            padding: .35rem .75rem;
            font-size: .82rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f4fa;
        }
        #liste-tables .table-item:hover { background: #e8eef7; }
        #liste-tables .table-item .t-nom { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #liste-tables .table-item .t-count {
            font-size: .72rem;
            color: #6b7280;
            background: #f0f4fa;
            border-radius: 10px;
            padding: .05rem .5rem;
            flex-shrink: 0;
        }

        #panel-query {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: .75rem;
            min-width: 0;
        }

        #sql-input {
            width: 100%;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: .9rem;
            padding: .6rem .75rem;
            border: 1px solid #c8d4e8;
            border-radius: 6px;
            resize: vertical;
            min-height: 120px;
        }
        #sql-input:focus { outline: none; border-color: #1a3a6b; box-shadow: 0 0 0 2px rgba(26,58,107,.15); }

        #action-bar { display: flex; align-items: center; gap: .75rem; flex-shrink: 0; }
        #result-meta { font-size: .82rem; color: #6b7280; }

        #result-wrap { flex: 1; overflow: auto; border: 1px solid #e0e8f0; border-radius: 6px; background: #fff; }

        #tbl-result { width: 100%; border-collapse: collapse; font-size: .82rem; }
        #tbl-result thead th {
            background: #1a3a6b;
            color: #fff;
            padding: .4rem .6rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            cursor: pointer;
            user-select: none;
        }
        #tbl-result thead th:hover { background: #24488c; }
        #tbl-result thead th .sort-icon { margin-left: .3rem; opacity: .5; font-size: .72rem; }
        #tbl-result thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        #tbl-result thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-result thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-result tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-result tbody tr:hover { background: #dce8f8; }
        #tbl-result td { padding: .3rem .6rem; border-bottom: 1px solid #e8edf5; white-space: nowrap; }
        #tbl-result td.is-null { color: #9ca3af; font-style: italic; }

        #empty-msg { text-align: center; padding: 2.5rem 1rem; color: #888; font-size: .9rem; }

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
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'database-fill-gear', 'phTitle' => 'Administration base de données', 'phCode' => 'E099',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => '']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<div id="main-area">
    <div id="panel-tables">
        <div class="panel-titre">
            <span><i class="bi bi-table me-1"></i>Tables</span>
            <span id="nb-tables" class="text-white-50"></span>
        </div>
        <div id="liste-tables">
            <div class="text-center text-muted py-3" style="font-size:.82rem;">Chargement…</div>
        </div>
    </div>

    <div id="panel-query">
        <textarea id="sql-input" placeholder="SELECT * FROM ja LIMIT 50;&#10;UPDATE ja SET ... WHERE ...;" spellcheck="false"></textarea>

        <div id="action-bar">
            <button class="btn btn-primary btn-sm" id="btn-executer">
                <i class="bi bi-play-fill me-1"></i>Exécuter (Ctrl+Entrée)
            </button>
            <span class="text-muted" style="font-size:.78rem;">Plusieurs requêtes séparées par « ; » sont exécutées à la suite.</span>
            <button class="btn btn-outline-secondary btn-sm" id="btn-effacer">
                <i class="bi bi-eraser me-1"></i>Effacer
            </button>
            <span id="result-meta"></span>
        </div>

        <div id="result-wrap">
            <div id="empty-msg"><i class="bi bi-terminal fs-2 d-block mb-2"></i>Saisissez une requête SQL et cliquez sur « Exécuter ».</div>
            <table id="tbl-result" style="display:none;">
                <thead><tr id="tbl-result-head"></tr></thead>
                <tbody id="tbl-result-body"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const DB_ADMIN_BASE = '<?= site_url('db-admin') ?>';

function spinner(show) { $('#spinner').toggleClass('show', show); }
function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Liste des tables ──────────────────────────────────────────────────────────
function chargerTables() {
    $.get(`${DB_ADMIN_BASE}/tables`, function (res) {
        const $liste = $('#liste-tables').empty();
        if (!res.ok) {
            $liste.html(`<div class="text-center text-danger py-3" style="font-size:.82rem;">${escHtml(res.msg)}</div>`);
            return;
        }
        $('#nb-tables').text(res.tables.length);
        if (!res.tables.length) {
            $liste.html('<div class="text-center text-muted py-3" style="font-size:.82rem;">Aucune table.</div>');
            return;
        }
        res.tables.forEach(t => {
            const $item = $('<div class="table-item">').attr('title', `${t.name} — ${t.rows.toLocaleString('fr-FR')} enregistrement(s)`);
            $(`<span class="t-nom">${escHtml(t.name)}</span>`)
                .attr('title', 'Voir la structure')
                .on('click', () => executerRequete(`DESCRIBE \`${t.name}\`;`))
                .appendTo($item);
            $(`<span class="t-count">${t.rows.toLocaleString('fr-FR')}</span>`)
                .attr('title', 'Voir les enregistrements')
                .on('click', () => executerRequete(`SELECT * FROM \`${t.name}\` LIMIT 100;`))
                .appendTo($item);
            $item.appendTo($liste);
        });
    }, 'json').fail(() => {
        $('#liste-tables').html('<div class="text-center text-danger py-3" style="font-size:.82rem;">Erreur réseau.</div>');
    });
}

// ── État de la dernière recherche SELECT (pour générer l'UPDATE au double-clic) ──
let currentTable  = null; // nom de table si la requête est un simple "SELECT ... FROM `table`", sinon null
let currentRows   = [];
let currentPkCols = []; // colonne(s) de la clé primaire de currentTable

function executerRequete(sqlOverride) {
    const sql = (sqlOverride !== undefined ? sqlOverride : $('#sql-input').val()).trim();
    if (!sql) { toast('Saisissez une requête SQL.', false); return; }
    if (sqlOverride !== undefined) $('#sql-input').val(sqlOverride);

    spinner(true);
    setStatus('Exécution en cours…');
    $.post(`${DB_ADMIN_BASE}/sql`, { sql }, function (res) {
        spinner(false);
        if (!res.ok) {
            const succes = (res.results || []).filter(r => r.ok);
            if (succes.length) {
                afficherResultatsMultiples(succes);
            } else {
                $('#tbl-result').hide();
                $('#empty-msg').show().html(`<i class="bi bi-x-circle-fill text-danger fs-2 d-block mb-2"></i><span class="text-danger">${escHtml(res.msg)}</span>`);
                $('#result-meta').text('');
            }
            setStatus('Erreur : ' + res.msg, false);
            return;
        }

        afficherResultatsMultiples(res.results);
        setStatus('Prêt.');
    }, 'json').fail(() => { spinner(false); setStatus('Erreur réseau.', false); });
}

// Affiche le résultat d'une ou plusieurs requêtes exécutées à la suite : le tableau
// central montre le dernier SELECT rencontré (pour permettre le double-clic → UPDATE),
// et la barre de résultat récapitule chaque requête (nombre de lignes / affectées).
function afficherResultatsMultiples(results) {
    currentTable = null;
    currentRows  = [];
    currentPkCols = [];

    let dernierSelect = null;
    for (let j = results.length - 1; j >= 0; j--) {
        if (results[j].type === 'select') { dernierSelect = results[j]; break; }
    }

    if (dernierSelect) {
        currentTable = detecterTable(dernierSelect.sql);
        currentRows  = dernierSelect.rows;
        if (currentTable) chargerClePrimaire(currentTable).then(cols => currentPkCols = cols);
        afficherResultats(dernierSelect.cols, dernierSelect.rows);
    } else {
        $('#tbl-result').hide();
        $('#empty-msg').show().html(`<i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>${results[results.length - 1].affected} ligne(s) affectée(s).`);
    }

    const totalMs = results.reduce((s, r) => s + r.ms, 0).toFixed(2);
    if (results.length === 1) {
        const r = results[0];
        $('#result-meta').text(r.type === 'select' ? `${r.rows.length} ligne(s) — ${r.ms} ms` : `${r.ms} ms`);
    } else {
        const recap = results.map((r, i) => r.type === 'select'
            ? `#${i + 1} SELECT : ${r.rows.length} ligne(s)`
            : `#${i + 1} : ${r.affected} ligne(s) affectée(s)`
        ).join(' · ');
        $('#result-meta').text(`${results.length} requête(s) — ${recap} — ${totalMs} ms total`);
    }
}

// Récupère la/les colonne(s) de la clé primaire d'une table (pour cibler l'UPDATE généré au double-clic).
function chargerClePrimaire(table) {
    return new Promise(resolve => {
        $.post(`${DB_ADMIN_BASE}/sql`, { sql: `SHOW KEYS FROM \`${table}\` WHERE Key_name = 'PRIMARY'` }, function (res) {
            const r = res.ok && res.results && res.results[0];
            resolve(r && r.type === 'select' ? r.rows.map(x => x.Column_name) : []);
        }, 'json').fail(() => resolve([]));
    });
}

// Ne reconnaît que "SELECT ... FROM `table` [WHERE|ORDER|LIMIT|;...]" (une seule table,
// pas de JOIN) — dans tous les autres cas (jointure, sous-requête...) le double-clic
// pour générer l'UPDATE reste désactivé (currentTable = null) car les colonnes affichées
// ne suffiraient pas à identifier une ligne d'une table précise.
function detecterTable(sql) {
    if (/\bjoin\b/i.test(sql)) return null;
    const m = sql.match(/^\s*select\s+.*?\sfrom\s+`?(\w+)`?\s*(where|order\s+by|group\s+by|limit|;|$)/is);
    return m ? m[1] : null;
}

// Représentation SQL d'une valeur JS (échappement des quotes, NULL géré à part).
function litteralSql(val) {
    if (val === null || val === undefined) return 'NULL';
    return `'${String(val).replace(/'/g, "''")}'`;
}

let resultSortState = { col: null, asc: true };

function afficherResultats(cols, rows) {
    const $head = $('#tbl-result-head').empty();
    const $body = $('#tbl-result-body').empty();

    if (!rows.length) {
        $('#tbl-result').hide();
        $('#empty-msg').show().html('<i class="bi bi-inbox fs-2 d-block mb-2"></i>Aucun résultat.');
        return;
    }

    resultSortState = { col: null, asc: true };
    cols.forEach((c, idx) => $head.append(`<th data-idx="${idx}">${escHtml(c)}<span class="sort-icon"></span></th>`));
    rows.forEach((row, rowIdx) => {
        const $tr = $('<tr>').attr('data-row-idx', rowIdx);
        cols.forEach(c => {
            const val = row[c];
            const $td = $('<td>').attr({ 'data-row-idx': rowIdx, 'data-col': c })
                                  .attr('title', 'Double-cliquez pour générer l\'UPDATE de ce champ');
            if (val === null) {
                $td.addClass('is-null').text('NULL');
            } else {
                $td.text(val);
            }
            $tr.append($td);
        });
        $body.append($tr);
    });

    $('#empty-msg').hide();
    $('#tbl-result').show();

    nijacSortableTable('#tbl-result thead th[data-idx]', 'idx', resultSortState, function () {
        nijacSortRows('#tbl-result-body', +resultSortState.col, resultSortState.asc);
    });
}

// ── Double-clic sur une cellule : génère l'UPDATE de ce champ pour cette ligne ──
$(document).on('dblclick', '#tbl-result td', function () {
    if (!currentTable) {
        toast('Impossible de déterminer la table (requête trop complexe, ou dernier résultat non tabulaire).', false);
        return;
    }
    if (!currentPkCols.length) {
        toast('Impossible de déterminer la clé primaire de cette table.', false);
        return;
    }

    const rowIdx = +$(this).data('row-idx');
    const col    = $(this).data('col');
    const row    = currentRows[rowIdx];
    if (!row) return;

    const whereClause = currentPkCols
        .map(c => (row[c] === null ? `\`${c}\` IS NULL` : `\`${c}\` = ${litteralSql(row[c])}`))
        .join(' AND ');

    const sql = `UPDATE \`${currentTable}\` SET \`${col}\` = ${litteralSql(row[col])} WHERE ${whereClause};`;
    $('#sql-input').val(sql);

    // Sélectionne la valeur à modifier dans le SET pour une édition immédiate.
    const debut = sql.indexOf(' = ') + 3;
    const fin   = sql.indexOf(' WHERE ');
    $('#sql-input').trigger('focus')[0].setSelectionRange(debut, fin);

    toast('Ordre UPDATE généré — modifiez la valeur puis cliquez sur Exécuter.');
});

$('#btn-executer').on('click', () => executerRequete());
$('#btn-effacer').on('click', function () {
    $('#sql-input').val('').trigger('focus');
    $('#tbl-result').hide();
    $('#empty-msg').show().html('<i class="bi bi-terminal fs-2 d-block mb-2"></i>Saisissez une requête SQL et cliquez sur « Exécuter ».');
    $('#result-meta').text('');
    setStatus('Prêt.');
});
$('#sql-input').on('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        executerRequete();
    }
});

$(function () { chargerTables(); });
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
