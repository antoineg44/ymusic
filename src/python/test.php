<?php

echo '<pre>';

$cmd = '/usr/bin/python3 -m pip install --user --no-deps "yt-dlp==2023.11.16" 2>&1';

passthru($cmd, $return);

echo "\nCode retour : $return\n";

echo '</pre>';