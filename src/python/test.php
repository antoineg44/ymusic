<?php

echo '<pre>';

$commands = [
    '/usr/bin/python3 -m pip install --user --dry-run yt-dlp 2>&1',
    '/usr/bin/python3 -m pip install --user --dry-run "yt-dlp<2024.01.01" 2>&1',
];

foreach ($commands as $command) {
    echo "========================================\n";
    echo $command . "\n";
    echo "========================================\n\n";

    passthru($command, $return);

    echo "\n\nCode retour : $return\n\n";
}

echo '</pre>';