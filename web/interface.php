<?php

require 'YouTubeMusic.php';
require_once __DIR__ . '/database_interface.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sanitize_folder_name(string $value): string
{
    $cleaned = trim($value);
    $cleaned = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $cleaned);
    $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
    $cleaned = trim((string) $cleaned, " .-");

    return $cleaned !== '' ? $cleaned : 'Artiste inconnu';
}

function build_unique_destination_path(string $directory, string $filename): string
{
    $target = $directory . '/' . $filename;
    if (!file_exists($target)) {
        return $target;
    }

    $name = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $suffix = $ext !== '' ? '.' . $ext : '';

    for ($index = 1; $index <= 1000; $index += 1) {
        $candidate = $directory . '/' . $name . '-' . $index . $suffix;
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }

    return $directory . '/' . $name . '-' . time() . $suffix;
}

function move_downloaded_webm_for_music(array $payload): ?array
{
    $id = trim((string) ($payload['Id'] ?? ''));
    if ($id === '') {
        return null;
    }

    $artist = sanitize_folder_name((string) ($payload['Artiste'] ?? ''));

    $baseDir = __DIR__ . '/data';
    $source = $baseDir . '/temp/' . $id . '.webm';
    if (!is_file($source)) {
        return null;
    }

    $destinationDir = $baseDir . '/' . $artist;
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Impossible de creer le dossier artiste');
    }

    $destination = build_unique_destination_path($destinationDir, basename($source));
    if (!rename($source, $destination)) {
        throw new RuntimeException('Impossible de deplacer le fichier webm vers le dossier artiste');
    }

    return [
        'from' => 'data/temp/' . basename($source),
        'to' => str_replace('\\\\', '/', substr($destination, strlen(__DIR__) + 1)),
    ];
}

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise',
    ], JSON_UNESCAPED_UNICODE);
    exit;
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

    try {
        $result = $yt->download($_GET['musicId']);

        echo json_encode([
            'success' => true,
            'download' => $result,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {

        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['addMusic']) || !empty($_POST['addMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $added = add_music_to_database($payload);
        $moved = move_downloaded_webm_for_music($payload);

        echo json_encode([
            'success' => true,
            'message' => 'Musique ajoutee a la base',
            'music' => $added,
            'movedFile' => $moved,
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