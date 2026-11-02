<?php
// Hook PreToolUse (Read|Edit|Write|MultiEdit|NotebookEdit|Grep|Bash) :
// refuse la lecture ET la modification des fichiers d'identifiants
// (.env*, deploy.lftp, NIJAC_Login.txt, cj2.txt), quel que soit l'outil.
$d  = json_decode(stream_get_contents(STDIN), true) ?: [];
$in = $d['tool_input'] ?? [];

// Chemins (Read/Edit/Write/Grep) ou ligne de commande (Bash) à contrôler.
$cibles = [];
foreach (['file_path', 'notebook_path', 'path', 'glob', 'command'] as $cle) {
    if (isset($in[$cle]) && is_string($in[$cle]) && $in[$cle] !== '') {
        $cibles[] = $in[$cle];
    }
}

// Nom protégé précédé d'un début de chaîne / séparateur, suivi d'une fin / séparateur.
$motif = '~(?:^|[\\\\/\s"\'=:;|&<>(])'
       . '(\.env[^\s"\'\\\\/;|&<>)]*|deploy\.lftp|nijac_login\.txt|cj2\.txt)'
       . '(?=$|[\s"\';|&<>)])~i';

foreach ($cibles as $c) {
    if (preg_match($motif, $c, $m)) {
        fwrite(STDERR, "Fichier d'identifiants protégé ({$m[1]}) : lecture et modification réservées à Patrick.\n");
        exit(2);
    }
}
exit(0);
