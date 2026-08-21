<?php

// Endpoint distant : exécute les scripts Python (ytapi.py / stream.py / main.py) et renvoie les résultats.
// À DÉPLOYER sur le serveur qui héberge le dossier python/ (même arborescence : php/yt/ à côté de python/).
// Aucune authentification pour l'instant (réseau privé) — à sécuriser ultérieurement.

// Sélectionne un binaire Python valide selon l'environnement (chemins absolus).
$pythonDir = realpath(__DIR__ . '/../../python');
if ($pythonDir === false) {
    $pythonDir = __DIR__ . '/../../python';
}

$python = $pythonDir . '/.venv/bin/python';
$apiScript = $pythonDir . '/ytapi.py';
$downloadScript = $pythonDir . '/stream.py';
$testScript = $pythonDir . '/main.py';

/**
 * Termine la requête avec une erreur JSON.
 */
function remote_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Décode la sortie Python en ignorant d'éventuels avertissements écrits sur stderr
 * (fusionnés via 2>&1) qui précèdent le JSON.
 */
function remote_decode_json(string $raw): ?array
{
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }

    // Retire le texte parasite avant le premier '{' ou '['.
    $start = strcspn($raw, '{[');
    if ($start < strlen($raw)) {
        $data = json_decode(substr($raw, $start), true);
        if (is_array($data)) {
            return $data;
        }
    }

    return null;
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$arg = (string) ($_POST['arg'] ?? $_GET['arg'] ?? $_POST['id'] ?? $_GET['id'] ?? '');

if ($action === '') {
    remote_fail('action requise');
}

$apiActions = ['search', 'suggest', 'playlist_search', 'playlist', 'playlist_items', 'song_details'];

if ($action === 'download') {
    // Télécharge l'audio via stream.py puis renvoie le fichier binaire au serveur principal.
    $musicId = trim($arg);
    if ($musicId === '') {
        remote_fail('musicId requis');
    }

    $command =
        escapeshellcmd($python)
        . ' ' . escapeshellarg($downloadScript)
        . ' ' . escapeshellarg($musicId);

    exec($command . ' 2>&1', $output, $code);
    $json = implode("\n", $output);
    $data = remote_decode_json($json);

    if (!is_array($data)) {
        remote_fail('Reponse Python invalide: ' . $json, 500);
    }

    if (empty($data['success'])) {
        // Erreur de téléchargement : renvoyée telle quelle en JSON.
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileName = basename((string) ($data['file'] ?? ''));
    $tempDir = realpath($pythonDir . '/../data/temp');
    if ($tempDir === false) {
        $tempDir = $pythonDir . '/../data/temp';
    }
    $filePath = $tempDir . '/' . $fileName;

    if ($fileName === '' || !is_file($filePath)) {
        remote_fail('Fichier telecharge introuvable sur le serveur distant', 500);
    }

    // Renvoie le fichier binaire + métadonnées via en-têtes.
    header('Content-Type: application/octet-stream');
    header('X-File-Name: ' . rawurlencode($fileName));
    header('X-Download-Result: ' . base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)));
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

if (in_array($action, $apiActions, true)) {
    // Exécute ytapi.py <action> <arg> et transmet la sortie JSON telle quelle.
    $command =
        escapeshellcmd($python)
        . ' ' . escapeshellarg($apiScript)
        . ' ' . escapeshellarg($action)
        . ' ' . escapeshellarg($arg);

    exec($command . ' 2>&1', $output, $code);
    $json = implode("\n", $output);
    $data = remote_decode_json($json);

    if (!is_array($data)) {
        remote_fail('Reponse Python invalide: ' . $json, 500);
    }

    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'test') {
    $command =
        escapeshellcmd($python)
        . ' ' . escapeshellarg($testScript);

    exec($command . ' 2>&1', $output, $code);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => $code === 0,
        'output' => $output,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

remote_fail('action inconnue: ' . $action);
