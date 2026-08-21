<?php

echo '<pre>';

$commands = [
    'find /usr/bin /usr/local/bin /opt -maxdepth 3 -type f -name "python3*" 2>/dev/null',
    'find /usr/bin /usr/local/bin /opt -maxdepth 3 -type f -name "python3.[0-9]*" 2>/dev/null',
    'ls -la /usr/bin/python* 2>&1',
    'ls -la /usr/local/bin/python* 2>&1',
    'ls -la /opt 2>&1',
];

foreach ($commands as $cmd) {
    echo "========================================\n";
    echo "$cmd\n";
    echo "========================================\n";
    echo shell_exec($cmd);
    echo "\n";
}

echo '</pre>';