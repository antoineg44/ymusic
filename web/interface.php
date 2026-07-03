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

} elseif (!empty($_GET['artists'])) {

    try {
        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->query(
            "SELECT
                Artiste,
                COUNT(*) AS TotalMusiques,
                COALESCE(SUM(NombreVueInterne), 0) AS TotalVuesInternes
             FROM Musiques
             WHERE TRIM(Artiste) <> ''
             GROUP BY Artiste
             ORDER BY Artiste ASC"
        );

        $artists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'artists' => $artists,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['artistSongs'])) {

    try {
        $artist = trim((string) ($_GET['artist'] ?? ''));
        if ($artist === '') {
            throw new RuntimeException('Artiste requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT
                Id,
                Titre,
                Album,
                Duree,
                NombreVue,
                NombreVueInterne,
                DateAjout
             FROM Musiques
             WHERE Artiste = :artist
             ORDER BY DateAjout DESC, Titre ASC'
        );
        $stmt->execute([':artist' => $artist]);

        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'artist' => $artist,
            'songs' => $songs,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['musicDetails'])) {

    try {
        $id = trim((string) ($_GET['id'] ?? ''));
        $title = trim((string) ($_GET['title'] ?? ''));
        $artist = trim((string) ($_GET['artist'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Id requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT
                Id,
                Titre,
                Artiste,
                Utilisateur,
                Album,
                Duree,
                AnneeParution,
                Genre,
                NombreVue,
                NombreVueInterne,
                DateAjout
             FROM Musiques
             WHERE Id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $music = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($music === false && $title !== '') {
            if ($artist !== '') {
                $fallbackStmt = $pdo->prepare(
                    'SELECT
                        Id,
                        Titre,
                        Artiste,
                        Utilisateur,
                        Album,
                        Duree,
                        AnneeParution,
                        Genre,
                        NombreVue,
                        NombreVueInterne,
                        DateAjout
                     FROM Musiques
                     WHERE Titre = :title AND Artiste = :artist
                     ORDER BY DateAjout DESC
                     LIMIT 1'
                );
                $fallbackStmt->execute([
                    ':title' => $title,
                    ':artist' => $artist,
                ]);
            } else {
                $fallbackStmt = $pdo->prepare(
                    'SELECT
                        Id,
                        Titre,
                        Artiste,
                        Utilisateur,
                        Album,
                        Duree,
                        AnneeParution,
                        Genre,
                        NombreVue,
                        NombreVueInterne,
                        DateAjout
                     FROM Musiques
                     WHERE Titre = :title
                     ORDER BY DateAjout DESC
                     LIMIT 1'
                );
                $fallbackStmt->execute([
                    ':title' => $title,
                ]);
            }

            $music = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($music === false) {
            echo json_encode([
                'success' => true,
                'found' => false,
                'music' => [
                    'Id' => $id,
                    'Titre' => $title,
                    'Artiste' => $artist,
                    'Utilisateur' => null,
                    'Album' => null,
                    'Duree' => null,
                    'AnneeParution' => null,
                    'Genre' => null,
                    'NombreVue' => null,
                    'NombreVueInterne' => null,
                    'DateAjout' => null,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'found' => true,
            'music' => $music,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['albums'])) {

    try {
        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->query(
            "SELECT
                Album,
                COUNT(*) AS TotalMusiques,
                COALESCE(SUM(NombreVue), 0) AS TotalVues,
                COALESCE(SUM(NombreVueInterne), 0) AS TotalVuesInternes
             FROM Musiques
             WHERE TRIM(Album) <> ''
             GROUP BY Album
             ORDER BY Album ASC"
        );

        $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'albums' => $albums,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['musiques'])) {

    try {
        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->query(
            "SELECT
                Id,
                Titre,
                Artiste,
                Utilisateur,
                Album,
                Duree,
                AnneeParution,
                Genre,
                NombreVue,
                NombreVueInterne,
                DateAjout
             FROM Musiques
             ORDER BY DateAjout DESC"
        );

        $musiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'musiques' => $musiques,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['updateMusic']) || !empty($_POST['updateMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $id = trim((string) ($payload['Id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Id requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->prepare('SELECT Id FROM Musiques WHERE Id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Musique introuvable');
        }

        $titre = trim((string) ($payload['Titre'] ?? ''));
        $artiste = trim((string) ($payload['Artiste'] ?? ''));
        $utilisateur = trim((string) ($payload['Utilisateur'] ?? ''));
        $album = trim((string) ($payload['Album'] ?? ''));
        $genre = trim((string) ($payload['Genre'] ?? ''));
        $duree = ($payload['Duree'] ?? '') === '' ? null : (int) $payload['Duree'];
        $anneeParution = ($payload['AnneeParution'] ?? '') === '' ? null : (int) $payload['AnneeParution'];
        $nombreVue = max(0, (int) ($payload['NombreVue'] ?? 0));
        $nombreVueInterne = max(0, (int) ($payload['NombreVueInterne'] ?? 0));

        if ($titre === '') {
            throw new RuntimeException('Titre requis');
        }

        $update = $pdo->prepare(
            'UPDATE Musiques
             SET
                Titre = :titre,
                Artiste = :artiste,
                Utilisateur = :utilisateur,
                Album = :album,
                Duree = :duree,
                AnneeParution = :anneeParution,
                Genre = :genre,
                NombreVue = :nombreVue,
                NombreVueInterne = :nombreVueInterne
             WHERE Id = :id'
        );

        $update->execute([
            ':id' => $id,
            ':titre' => $titre,
            ':artiste' => $artiste,
            ':utilisateur' => $utilisateur,
            ':album' => $album,
            ':duree' => $duree,
            ':anneeParution' => $anneeParution,
            ':genre' => $genre,
            ':nombreVue' => $nombreVue,
            ':nombreVueInterne' => $nombreVueInterne,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Musique mise a jour',
            'music' => [
                'Id' => $id,
                'Titre' => $titre,
                'Artiste' => $artiste,
                'Utilisateur' => $utilisateur,
                'Album' => $album,
                'Duree' => $duree,
                'AnneeParution' => $anneeParution,
                'Genre' => $genre,
                'NombreVue' => $nombreVue,
                'NombreVueInterne' => $nombreVueInterne,
            ],
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