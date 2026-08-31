<?php
/**
 * NIJAC — Consultation publique des documents de travail (specs FFTT, calendriers,
 * plaquettes, annuaires…). Listing en PHP pur : fonctionne aussi bien en local
 * qu'en production, sans dépendre de « Options +Indexes » (souvent bloqué par
 * l'hébergeur). Accès public assumé, sans authentification.
 *
 * Servi tel quel par le .htaccess racine (fichier réel), et choisi comme
 * DirectoryIndex pour http://…/Documentation/.
 */

$ici     = __DIR__;
$exclus  = ['.', '..', 'index.php', '.htaccess'];
$sort    = $_GET['tri'] ?? 'nom';   // nom | date | taille

$fichiers = [];
foreach (scandir($ici) as $f) {
    if (in_array($f, $exclus, true) || $f[0] === '.' || is_dir($ici . '/' . $f)) {
        continue;
    }
    $chemin     = $ici . '/' . $f;
    $fichiers[] = [
        'nom'    => $f,
        'taille' => filesize($chemin),
        'date'   => filemtime($chemin),
        'ext'    => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
    ];
}

usort($fichiers, static function ($a, $b) use ($sort) {
    return match ($sort) {
        'date'   => $b['date']   <=> $a['date'],     // plus récent d'abord
        'taille' => $b['taille'] <=> $a['taille'],   // plus gros d'abord
        default  => strnatcasecmp($a['nom'], $b['nom']),
    };
});

function tailleLisible(int $o): string
{
    if ($o >= 1048576) return number_format($o / 1048576, 1, ',', ' ') . ' Mo';
    if ($o >= 1024)    return number_format($o / 1024, 0, ',', ' ') . ' Ko';
    return $o . ' o';
}

$icones = [
    'pdf'  => '📕', 'xls'  => '📗', 'xlsx' => '📗', 'csv' => '📗',
    'doc'  => '📘', 'docx' => '📘', 'html' => '🌐', 'txt' => '📄',
    'zip'  => '🗜️', 'png'  => '🖼️', 'jpg' => '🖼️', 'jpeg' => '🖼️',
];

$lien = static fn (string $col) => '?tri=' . $col;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Documents — NIJAC</title>
<style>
  :root { --bleu:#1a3a6b; --bord:#dde5f0; }
  * { box-sizing:border-box; }
  body { margin:0; background:#f0f4fa; color:#1b2a41;
         font-family:'Segoe UI',system-ui,-apple-system,sans-serif; }
  header { background:var(--bleu); color:#fff; padding:1rem 1.25rem; }
  header h1 { margin:0; font-size:1.15rem; }
  header p { margin:.25rem 0 0; font-size:.85rem; opacity:.85; }
  .wrap { max-width:900px; margin:1.5rem auto 3rem; padding:0 1rem; }
  table { width:100%; border-collapse:collapse; background:#fff;
          border:1px solid var(--bord); border-radius:8px; overflow:hidden;
          font-size:.9rem; }
  th, td { text-align:left; padding:.55rem .8rem; border-bottom:1px solid #eef1f6; }
  th { background:#f6f8fc; font-size:.8rem; text-transform:uppercase;
       letter-spacing:.03em; color:#5a6b85; }
  th a { color:inherit; text-decoration:none; }
  th a:hover { text-decoration:underline; }
  tr:last-child td { border-bottom:0; }
  tr:hover td { background:#f4f9f4; }
  td.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.date { white-space:nowrap; color:#5a6b85; }
  a.doc { color:var(--bleu); text-decoration:none; font-weight:600; }
  a.doc:hover { text-decoration:underline; }
  .ico { margin-right:.45rem; }
  .vide { padding:2rem; text-align:center; color:#8a97ab; }
  footer { max-width:900px; margin:0 auto; padding:0 1rem 2rem; font-size:.8rem; color:#8a97ab; }
</style>
</head>
<body>
<header>
  <h1>Documents de travail</h1>
  <p>Ligue Normandie de Tennis de Table — <?= count($fichiers) ?> document<?= count($fichiers) > 1 ? 's' : '' ?></p>
</header>

<div class="wrap">
<?php if (!$fichiers): ?>
  <p class="vide">Aucun document.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th><a href="<?= $lien('nom') ?>">Nom</a></th>
        <th><a href="<?= $lien('date') ?>">Modifié le</a></th>
        <th class="num"><a href="<?= $lien('taille') ?>">Taille</a></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($fichiers as $f): ?>
      <tr>
        <td>
          <span class="ico"><?= $icones[$f['ext']] ?? '📄' ?></span>
          <a class="doc" href="<?= rawurlencode($f['nom']) ?>"><?= htmlspecialchars($f['nom'], ENT_QUOTES) ?></a>
        </td>
        <td class="date"><?= date('d/m/Y H:i', $f['date']) ?></td>
        <td class="num"><?= tailleLisible((int) $f['taille']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<footer>Liste générée automatiquement à partir du dossier <code>/Documentation</code>.</footer>
</body>
</html>
