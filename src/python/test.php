<?php

echo '<pre>';

$commands = [
    '/usr/bin/python3 -m pip index versions yt-dlp 2>&1',
    '/usr/bin/python3 -m pip install --user --no-deps "yt-dlp==2023.12.30" 2>&1',
];

foreach ($commands as $command) {

    echo "========================================\n";
    echo $command . "\n";
    echo "========================================\n\n";

    passthru($command, $return);

    echo "\nCode retour : $return\n\n";
}

echo '</pre>';