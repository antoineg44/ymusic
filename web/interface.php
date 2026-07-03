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

function find_music_by_id(string $id): ?array
{
    $pdo = get_database_pdo();
    ensure_music_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT Id, Titre, Artiste, Album, NombreVue, NombreVueInterne
         FROM Musiques
         WHERE Id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_downloaded_file_for_music_id(string $id): ?array
{
    $baseDir = __DIR__ . '/data';
    if (!is_dir($baseDir)) {
        return null;
    }

    $allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $filenameWithoutExt = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
        if ($filenameWithoutExt !== $id) {
            continue;
        }

        $relativePath = str_replace('\\\\', '/', substr($fileInfo->getPathname(), strlen(__DIR__) + 1));

        return [
            'file' => $fileInfo->getFilename(),
            'path' => $relativePath,
        ];
    }

    return null;
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
        $musicId = trim((string) $_GET['musicId']);
        if ($musicId === '') {
            throw new RuntimeException('musicId requis');
        }

        $existingMusic = find_music_by_id($musicId);

        if ($existingMusic !== null) {
            $existingFile = find_downloaded_file_for_music_id($musicId);

            if ($existingFile === null) {
                throw new RuntimeException('Musique deja presente en base, mais fichier audio introuvable');
            }

            echo json_encode([
                'success' => true,
                'download' => [
                    'success' => true,
                    'alreadyInDatabase' => true,
                    'file' => $existingFile['file'],
                    'path' => $existingFile['path'],
                ],
                'music' => $existingMusic,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = $yt->download($musicId);

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