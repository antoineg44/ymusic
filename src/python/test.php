<?php

echo "<pre>";

echo "=== libz ===\n";
echo shell_exec("ldconfig -p 2>&1 | grep 'libz.so.1'");

echo "\n=== fichier système ===\n";
echo shell_exec("ls -l /lib/x86_64-linux-gnu/libz.so.1 2>&1");

echo "\n=== type ===\n";
echo shell_exec("file /lib/x86_64-linux-gnu/libz.so.1 2>&1");

echo "\n=== montage ===\n";
echo shell_exec("mount 2>&1");

echo "\n=== environnement ===\n";
echo shell_exec("uname -a 2>&1");

echo "</pre>";