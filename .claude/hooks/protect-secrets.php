<?php
// Hook PreToolUse (Edit|Write) : refuse la modification des fichiers d'identifiants.
$d = json_decode(stream_get_contents(STDIN), true);
$f = $d['tool_input']['file_path'] ?? '';
$nom = basename(str_replace('\\', '/', $f));
if (preg_match('/^\.env/i', $nom) || in_array(strtolower($nom), ['deploy.lftp', 'nijac_login.txt', 'cj2.txt'], true)) {
    fwrite(STDERR, "Fichier d'identifiants protégé ($nom) : à modifier par Patrick uniquement.\n");
    exit(2);
}
exit(0);
