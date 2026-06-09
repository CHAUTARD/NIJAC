<?php
require __DIR__ . '/Obfuscator.php';
$o = new Obfuscator(167);

foreach ([1, 42, 127, 999, 1234, 5678] as $n) {
    $token = $o->obfuscate($n);
    $back  = $o->deobfuscate($token);
    $ok    = ($back === $n) ? 'OK' : 'ERREUR';
    echo "$ok  obfuscate($n) = '$token'  => deobfuscate = $back\n";
}
echo "token invalide : " . $o->deobfuscate('????????') . "\n";
echo "longueur incorrecte : " . $o->deobfuscate('abc') . "\n";
