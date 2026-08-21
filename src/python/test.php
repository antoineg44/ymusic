<?php

echo '<pre>';

echo shell_exec(
    '/usr/bin/python3 -m site --user-base 2>&1'
);

echo "\n";

echo shell_exec(
    'find /home/partith/.local -name "yt-dlp*" -type f 2>&1'
);

echo '</pre>';