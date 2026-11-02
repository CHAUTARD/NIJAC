<?php
// Hook PostToolUse (Edit|Write) : vérifie la syntaxe d'un fichier .php modifié.
$d = json_decode(stream_get_contents(STDIN), true);
$f = $d['tool_input']['file_path'] ?? '';
if (!preg_match('/\.php$/i', $f) || !is_file($f)) {
    exit(0);
}
exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(2);
}
exit(0);
