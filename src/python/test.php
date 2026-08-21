<?php

function run($cmd)
{
    echo "<h3>" . htmlspecialchars($cmd) . "</h3>";
    echo "<pre>";
    echo htmlspecialchars(shell_exec($cmd . " 2>&1"));
    echo "</pre>";
}

run('/usr/bin/python3 --version');
run('/usr/bin/python --version');
run('/usr/bin/pip3 --version');
run('/usr/bin/python3 -m pip --version');
