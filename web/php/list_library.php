<?php
// Retourne la bibliotheque locale scannee depuis web/data pour l'utilisateur authentifie.
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

$webRoot = dirname(__DIR__);
$baseDir = $webRoot . '/data';
$allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];
$tracks = [];

if (is_dir($baseDir)) {
    // Parcours recursif des fichiers audio supportes.
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

        $relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($webRoot) + 1));

        $tracks[] = [
            'title' => pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME),
            'path' => $relativePath,
            'file' => $fileInfo->getFilename(),
            'folder' => str_replace('\\', '/', substr($fileInfo->getPath(), strlen($baseDir) + 1)),
        ];
    }
}

usort($tracks, function ($left, $right) {
    return strcmp($left['title'], $right['title']);
});

echo json_encode($tracks, JSON_UNESCAPED_UNICODE);
