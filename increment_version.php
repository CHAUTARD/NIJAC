<?php
/**
 * Incrémente les deux derniers chiffres de APP_VERSION dans config/db.php.
 * Usage : php increment_version.php
 */

$dbConfigPath = __DIR__ . '/config/db.php';
$content = file_get_contents($dbConfigPath);

if ($content === false) {
    fwrite(STDERR, "Impossible de lire $dbConfigPath\n");
    exit(1);
}

$pattern = "/define\\('APP_VERSION',\\s*'(\\d+)\\.(\\d+)\\.(\\d+)'\\)/";

if (!preg_match($pattern, $content, $matches)) {
    fwrite(STDERR, "APP_VERSION introuvable dans $dbConfigPath\n");
    exit(1);
}

[, $major, $minor, $patch] = $matches;
$newPatch = str_pad((string)((int)$patch + 1), strlen($patch), '0', STR_PAD_LEFT);
$newVersion = "$major.$minor.$newPatch";

$newContent = preg_replace(
    $pattern,
    "define('APP_VERSION', '$newVersion')",
    $content,
    1
);

if (file_put_contents($dbConfigPath, $newContent) === false) {
    fwrite(STDERR, "Impossible d'écrire dans $dbConfigPath\n");
    exit(1);
}

echo "APP_VERSION : {$major}.{$minor}.{$patch} → {$newVersion}\n";
