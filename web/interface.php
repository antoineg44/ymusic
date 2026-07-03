<?php

require 'YouTubeMusic.php';
require_once __DIR__ . '/database_interface.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function log_download_request(string $message, array $context = []): void
{
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0777, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $line .= ' | ' . $json;
        }
    }

    @file_put_contents($logsDir . '/download.log', $line . PHP_EOL, FILE_APPEND);
}

$yt = new YouTubeMusic();

if (!empty($_GET['query'])) {

    echo json_encode(
        $yt->search($_GET['query']),
        JSON_UNESCAPED_UNICODE
    );

} elseif (!empty($_GET['videoId'])) {

    echo json_encode(
        $yt->playlist($_GET['videoId']),
        JSON_UNESCAPED_UNICODE
    );

} elseif (!empty($_GET['musicId'])) {

    log_download_request('HTTP download request received', [
        'musicId' => $_GET['musicId'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    try {
        $result = $yt->download($_GET['musicId']);

        log_download_request('HTTP download request succeeded', [
            'musicId' => $_GET['musicId'],
            'resultSuccess' => $result['success'] ?? null,
            'file' => $result['file'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        echo json_encode([
            'success' => true,
            'download' => $result,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        log_download_request('HTTP download request failed', [
            'musicId' => $_GET['musicId'],
            'exception' => $exception->getMessage(),
        ]);

        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['addMusic']) || !empty($_POST['addMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $added = add_music_to_database($payload);

        echo json_encode([
            'success' => true,
            'message' => 'Musique ajoutee a la base',
            'music' => $added,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} else {

    echo json_encode([
        'success' => false,
        'error' => 'query ou videoId requis'
    ]);
}