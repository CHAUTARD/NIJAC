<?php
/**
 * NIJAC – Encode une valeur en ROT47 pour le fichier .env.
 * ROT47 est son propre inverse : rot47(rot47($val)) === $val.
 *
 * Usage : php tools/rot47.php votre_valeur
 */
function rot47(string $s): string {
    $out = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $c = ord($s[$i]);
        if ($c >= 33 && $c <= 126) $c = (($c - 33 + 47) % 94) + 33;
        $out .= chr($c);
    }
    return $out;
}

if ($argc < 2) {
    fwrite(STDERR, "Usage : php tools/rot47.php votre_valeur\n");
    exit(1);
}

echo rot47($argv[1]) . PHP_EOL;
