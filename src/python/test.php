<?php

$dir = __DIR__ . '/bin';
$ytdlp = $dir . '/yt-dlp';

echo '<pre>';

if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
        die("Impossible de créer : $dir\n");
    }
}

/*
 * Téléchargement du binaire Linux x86_64 officiel
 */
$url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux';

echo "Téléchargement de yt-dlp...\n";

$data = file_get_contents($url);

if ($data === false) {
    die("ERREUR : téléchargement impossible.\n");
}

echo "Taille téléchargée : " . strlen($data) . " octets\n";

if (file_put_contents($ytdlp, $data) === false) {
    die("ERREUR : impossible d'écrire $ytdlp\n");
}

chmod($ytdlp, 0755);

echo "Binaire installé : $ytdlp\n\n";

/*
 * Test
 */
echo "Test de yt-dlp...\n\n";

$output = [];
$return = 0;

exec(
    escapeshellarg($ytdlp) . ' --version 2>&1',
    $output,
    $return
);

echo "Code retour : $return\n";
echo implode("\n", $output);

echo '</pre>';