<?php

$baseDir = __DIR__ ;

$venvDir = $baseDir . '/venv';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
}

$commands = [
    "python3 -m venv " . escapeshellarg($venvDir),
    $venvDir . "/bin/pip install --upgrade pip",
    $venvDir . "/bin/pip install ytmusicapi"
];

foreach ($commands as $command) {

    echo "\n>>> $command\n";

    exec($command . " 2>&1", $output, $code);

    echo implode("\n", $output) . "\n";

    if ($code !== 0) {
        die("Erreur installation\n");
    }

    $output = [];
}

echo "\nInstallation terminée\n";