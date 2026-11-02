<?php
/**
 * Éditeur HTML visuel des modèles d'email (EN15, EA93) : TinyMCE auto-hébergé dans asset/tinymce/
 * (paquet « TinyMCE Community » dézippé tel quel, licence GPL) + asset/js/nijac-editeur.js.
 * À inclure APRÈS jQuery et AVANT le script de la vue :  <?= view('partials/editeur_html') ?>
 * puis, dans le script de la vue :  window.nijacEditeur && nijacEditeur.attacher('txt-message', {source: …});
 * TinyMCE absent → rien n'est chargé, la vue garde son textarea. Langue française si
 * asset/tinymce/langs/fr_FR.js existe (pack de langue téléchargé à part), sinon anglais.
 */
$nijacRacine = rtrim(ROOTPATH, '/\\') . '/../asset/tinymce/';
if (!is_file($nijacRacine . 'tinymce.min.js')) {
    return;
}
$nijacConf = [
    'base'   => base_url('asset/tinymce'),
    'langue' => is_file($nijacRacine . 'langs/fr_FR.js') ? 'fr_FR' : null,
];
?>
<script src="<?= base_url('asset/tinymce/tinymce.min.js') ?>"></script>
<script>window.NIJAC_EDITEUR = <?= json_encode($nijacConf, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= base_url('asset/js/nijac-editeur.js') ?>"></script>
