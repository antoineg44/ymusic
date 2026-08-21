<?php

$url = $_GET['url'] ?? '';

if (!$url) {
    ?>
    <form method="get">
        <input
            type="url"
            name="url"
            placeholder="https://www.youtube.com/watch?v=..."
            style="width:500px"
            required
        >
        <button type="submit">Tester</button>
    </form>
    <?php
    exit;
}

$ytdlp = '/home/partith/.local/bin/yt-dlp';

$command =
    escapeshellarg($ytdlp)
    . ' --no-playlist'
    . ' --dump-single-json'
    . ' --skip-download'
    . ' '
    . escapeshellarg($url)
    . ' 2>&1';

echo '<pre>';

$output = [];
$return = 0;

exec($command, $output, $return);

echo htmlspecialchars(implode("\n", $output));

echo "\n\nCode retour : " . $return;

echo '</pre>';