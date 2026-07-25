<?php

function sanitize_folder_name(string $value): string
{
    // Nettoie un nom de dossier pour eviter caracteres invalides sur le systeme de fichiers.
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
        return [
            'error' => "Id null ou vide",
            'from' => null,
            'to' => null,
        ];
    }

    $artist = sanitize_folder_name((string) ($payload['Artiste'] ?? ''));
    $webRoot = dirname(__DIR__);
    $baseDir = "../../" . '/data';
    $source = $baseDir . '/temp/' . $id . '.webm';
    if (!is_file($source)) {
        return [
            'error' => "Fichier source webm introuvable pour l'Id: $id",
            'from' => 'data/temp/' . basename($source),
            'to' => null,
        ];
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
        'to' => str_replace('\\', '/', substr($destination, strlen($webRoot) + 1)),
    ];
}

function find_downloaded_file_for_music_id(string $id): ?array
{
    $webRoot = "../../";
    $baseDir = $webRoot . '/data';
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

        $relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($webRoot) + 1));

        return [
            'file' => $fileInfo->getFilename(),
            'path' => $relativePath,
        ];
    }

    return null;
}