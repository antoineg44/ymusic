<?php
header('Content-Type: application/json');

$baseDir = __DIR__ . '/data';
$allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];
$tracks = [];

if (is_dir($baseDir)) {
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

        $relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen(__DIR__) + 1));

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
