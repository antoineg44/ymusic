<?php

echo '<pre>';

passthru(
    '/home/partith/.local/bin/yt-dlp --version 2>&1',
    $return
);

echo "\nCode retour : $return\n";

echo '</pre>';