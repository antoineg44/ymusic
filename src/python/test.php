<?php

header('Content-Type: text/html; charset=UTF-8');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function result($name, $value, $ok = null)
{
    if ($ok === true) {
        $status = '<span class="ok">OK</span>';
    } elseif ($ok === false) {
        $status = '<span class="ko">NON</span>';
    } else {
        $status = '<span class="info">INFO</span>';
    }

    echo '<tr>';
    echo '<td>' . h($name) . '</td>';
    echo '<td>' . $status . '</td>';
    echo '<td><pre>' . h($value) . '</pre></td>';
    echo '</tr>';
}

echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Diagnostic Page Perso Free</title>
<style>
body {
    font-family: Arial, sans-serif;
    margin: 30px;
    background: #f5f5f5;
    color: #222;
}
.container {
    max-width: 1100px;
    margin: auto;
    background: white;
    padding: 25px;
    border-radius: 8px;
}
h1 {
    margin-top: 0;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    padding: 10px;
    border: 1px solid #ddd;
    vertical-align: top;
}
th {
    background: #eee;
    text-align: left;
}
.ok {
    color: green;
    font-weight: bold;
}
.ko {
    color: red;
    font-weight: bold;
}
.info {
    color: #777;
    font-weight: bold;
}
pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}
.warning {
    padding: 15px;
    background: #fff3cd;
    border: 1px solid #ffeeba;
    margin-bottom: 20px;
}
</style>
</head>
<body>
<div class="container">
<h1>Diagnostic Page Perso Free</h1>

<div class="warning">
<strong>Attention :</strong>
ce script ne télécharge ni n'installe yt-dlp.
Il vérifie uniquement si l'environnement permet potentiellement de l'utiliser.
</div>

<table>
<tr>
<th>Test</th>
<th>Résultat</th>
<th>Détail</th>
</tr>
HTML;

/*
 * PHP
 */
result(
    'Version PHP',
    PHP_VERSION,
    true
);

result(
    'Système',
    PHP_OS . ' / ' . php_uname('m'),
    null
);


/*
 * Fonctions PHP permettant d'exécuter des commandes
 */
$functions = [
    'shell_exec',
    'exec',
    'system',
    'passthru',
    'proc_open'
];

foreach ($functions as $function) {

    $exists = function_exists($function);

    $disabled = false;

    $disabledFunctions = ini_get('disable_functions');

    if ($disabledFunctions) {
        $disabledList = array_map(
            'trim',
            explode(',', $disabledFunctions)
        );

        $disabled = in_array($function, $disabledList, true);
    }

    $available = $exists && !$disabled;

    result(
        'Fonction PHP : ' . $function,
        $available
            ? 'Disponible'
            : ($disabled ? 'Désactivée par disable_functions' : 'Indisponible'),
        $available
    );
}


/*
 * Configuration PHP
 */
result(
    'disable_functions',
    ini_get('disable_functions') ?: '(aucune)',
    null
);

result(
    'open_basedir',
    ini_get('open_basedir') ?: '(aucune)',
    null
);

result(
    'allow_url_fopen',
    ini_get('allow_url_fopen'),
    ini_get('allow_url_fopen') === '1'
);

result(
    'memory_limit',
    ini_get('memory_limit'),
    null
);

result(
    'max_execution_time',
    ini_get('max_execution_time') . ' secondes',
    null
);


/*
 * Extensions
 */
$extensions = [
    'curl',
    'openssl',
    'zip',
    'json',
    'mbstring'
];

foreach ($extensions as $extension) {

    $loaded = extension_loaded($extension);

    result(
        'Extension PHP : ' . $extension,
        $loaded ? 'Chargée' : 'Non chargée',
        $loaded
    );
}


/*
 * Recherche des exécutables
 */
function commandExists($command)
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = ini_get('disable_functions');

    if ($disabled) {
        $list = array_map('trim', explode(',', $disabled));

        if (in_array('shell_exec', $list, true)) {
            return false;
        }
    }

    $output = @shell_exec(
        'command -v ' . escapeshellarg($command) . ' 2>/dev/null'
    );

    return trim((string)$output);
}

$commands = [
    'python',
    'python3',
    'pip',
    'pip3',
    'yt-dlp',
    'ffmpeg',
    'curl',
    'wget'
];

foreach ($commands as $command) {

    $path = commandExists($command);

    result(
        'Commande : ' . $command,
        $path ?: 'Introuvable',
        $path !== false
    );
}


/*
 * Test d'exécution d'une commande très simple
 */
if (function_exists('shell_exec')) {

    $disabled = ini_get('disable_functions');

    $disabledList = $disabled
        ? array_map('trim', explode(',', $disabled))
        : [];

    if (!in_array('shell_exec', $disabledList, true)) {

        $output = @shell_exec('echo FREE_TEST 2>&1');

        result(
            'Exécution shell',
            trim((string)$output),
            trim((string)$output) === 'FREE_TEST'
        );
    }
}


/*
 * Test d'écriture
 */
$testFile = __DIR__ . '/free_diagnostic_test.tmp';

$writeOk = @file_put_contents(
    $testFile,
    'FREE_DIAGNOSTIC_TEST'
);

if ($writeOk !== false) {

    @unlink($testFile);

    result(
        'Écriture dans le répertoire du site',
        'Possible',
        true
    );

} else {

    result(
        'Écriture dans le répertoire du site',
        'Impossible',
        false
    );
}


/*
 * Test téléchargement HTTPS avec cURL
 */
if (function_exists('curl_init')) {

    $ch = curl_init('https://www.example.com/');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $data = curl_exec($ch);

    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($data !== false) {

        result(
            'Téléchargement HTTPS avec cURL',
            'HTTP ' . $code . ' — ' . strlen($data) . ' octets',
            true
        );

    } else {

        result(
            'Téléchargement HTTPS avec cURL',
            'Erreur : ' . $error,
            false
        );
    }

} else {

    result(
        'Téléchargement HTTPS avec cURL',
        'Extension cURL absente',
        false
    );
}


echo '</table>';

echo '<h2>Conclusion</h2>';

$canExecute =
    function_exists('shell_exec') &&
    !in_array(
        'shell_exec',
        array_map('trim', explode(',', ini_get('disable_functions'))),
        true
    );

$python =
    commandExists('python') ||
    commandExists('python3');

$ytdlp =
    commandExists('yt-dlp');

$ffmpeg =
    commandExists('ffmpeg');

if (!$canExecute) {

    echo '<p class="ko">
    PHP ne semble pas autoriser l’exécution de commandes système.
    yt-dlp ne pourra donc probablement pas être lancé directement depuis PHP.
    </p>';

} elseif (!$python && !$ytdlp) {

    echo '<p class="ko">
    Python et yt-dlp ne sont pas accessibles comme commandes système.
    </p>';

} else {

    echo '<p class="ok">
    L’environnement semble permettre l’exécution de programmes externes.
    </p>';
}

if ($ytdlp) {

    echo '<p class="ok">
    yt-dlp est directement accessible.
    </p>';

} else {

    echo '<p class="ko">
    yt-dlp n’est pas installé ou n’est pas présent dans le PATH.
    </p>';
}

if ($ffmpeg) {

    echo '<p class="ok">
    FFmpeg est accessible.
    </p>';

} else {

    echo '<p class="ko">
    FFmpeg n’est pas accessible.
    Certaines opérations yt-dlp nécessitant une fusion ou conversion ne fonctionneront donc pas.
    </p>';
}

echo <<<HTML
<hr>
<p>
<strong>Supprime ce fichier après le diagnostic</strong>,
car il révèle des informations sur la configuration du serveur.
</p>

</div>
</body>
</html>
HTML;