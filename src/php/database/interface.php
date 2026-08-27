<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed = [
    'https://localhost',
    'https://musiques.partitions.ovh'
];

if (in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../yt/YouTubeMusic.php';
require_once __DIR__ . '/database_interface.php';
require_once __DIR__ . '/../files/manager.php';
require_once __DIR__ . '/../tools/recherche.php';
require_once __DIR__ . '/interface_Musiques.php';
require_once __DIR__ . '/interface_Playlist.php';
require_once __DIR__ . '/interface_MyPlaylistMusiques.php';
require_once __DIR__ . '/interface_MusiquesAimees.php';


header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['requete'])) {
    try {
        $jsonStr = urldecode((string) $_GET['requete']);
        $structure = json_decode($jsonStr, true);

        if (!is_array($structure) || empty($structure['table'])) {
            throw new RuntimeException('Requete invalide');
        }

        if ($structure['table'] === 'Musiques') {
            echo json_encode(dMusique_get($structure), JSON_UNESCAPED_UNICODE);
        } elseif ($structure['table'] === 'Playlist') {
            echo json_encode(dPlaylist_get($structure), JSON_UNESCAPED_UNICODE);
        } elseif ($structure['table'] === 'MyPlaylistMusiques') {
            echo json_encode(dMyPlaylistMusiques_get($structure), JSON_UNESCAPED_UNICODE);
        } elseif ($structure['table'] === 'MusiquesAimees') {
            echo json_encode(dMusiqueAimee_get($structure), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Unknow table',
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function find_music_by_id(string $id): ?array
{
    $musicPayload = dMusique_get([
        'select' => ['Id', 'Titre', 'Artiste', 'Album', 'NombreVue', 'NombreVueInterne'],
        'equals' => ['Id' => $id],
        'limit' => 1,
        'page' => 1,
    ]);

    $music = (array) (($musicPayload['musiques'] ?? [])[0] ?? []);
    return !empty($music) ? $music : null;
}

function find_downloaded_files_for_music_id(string $id): array
{
    $webRoot = dirname(dirname(__DIR__));
    $baseDir = $webRoot . '/data';
    if (!is_dir($baseDir)) {
        return [];
    }

    $allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];
    $matches = [];

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

        $absolutePath = $fileInfo->getPathname();
        $matches[] = [
            'file' => $fileInfo->getFilename(),
            'path' => str_replace('\\', '/', substr($absolutePath, strlen($webRoot) + 1)),
            'absolutePath' => $absolutePath,
        ];
    }

    return $matches;
}

function delete_downloaded_files_for_music_id(string $id): array
{
    if ($id === '') {
        return [];
    }

    $matches = find_downloaded_files_for_music_id($id);
    if (empty($matches)) {
        return [];
    }

    $deletedPaths = [];

    foreach ($matches as $match) {
        $absolutePath = (string) ($match['absolutePath'] ?? '');
        if ($absolutePath === '') {
            continue;
        }

        if (!is_file($absolutePath)) {
            continue;
        }

        if (!unlink($absolutePath)) {
            throw new RuntimeException('Suppression du fichier audio impossible: ' . (string) ($match['path'] ?? $absolutePath));
        }

        $deletedPaths[] = (string) ($match['path'] ?? '');
    }

    return $deletedPaths;
}

function build_audio_files_index(): array
{
    $webRoot = dirname(dirname(__DIR__));
    $baseDir = $webRoot . '/data';

    if (!is_dir($baseDir)) {
        return [
            'filesById' => [],
            'filesList' => [],
        ];
    }

    $allowedExtensions = ['mp3', 'm4a', 'aac', 'ogg', 'wav', 'flac', 'webm'];
    $filesById = [];
    $filesList = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $absolutePath = $fileInfo->getPathname();
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($webRoot) + 1));
        if (strpos($relativePath, 'data/temp/') === 0) {
            continue;
        }

        $extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $id = trim((string) pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME));
        if ($id === '') {
            continue;
        }

        if (!isset($filesById[$id])) {
            $filesById[$id] = [];
        }

        $fileEntry = [
            'id' => $id,
            'file' => $fileInfo->getFilename(),
            'path' => $relativePath,
        ];

        $filesById[$id][] = $fileEntry;
        $filesList[] = $fileEntry;
    }

    return [
        'filesById' => $filesById,
        'filesList' => $filesList,
    ];
}

function build_music_files_integrity_report(PDO $pdo): array
{
    ensure_music_table($pdo);

    $rows = [];
    $page = 1;
    $totalPages = 1;
    do {
        $payload = dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Album'],
            'orderBy' => 'DateAjout',
            'order' => 'DESC',
            'limit' => 500,
            'page' => $page,
        ]);

        $rows = array_merge($rows, (array) ($payload['musiques'] ?? []));
        $totalPages = max(1, (int) ($payload['totalPages'] ?? 1));
        $page += 1;
    } while ($page <= $totalPages);

    $dbMusicById = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['Id'] ?? ''));
        if ($id === '') {
            continue;
        }

        $dbMusicById[$id] = [
            'Id' => $id,
            'Titre' => (string) ($row['Titre'] ?? ''),
            'Artiste' => (string) ($row['Artiste'] ?? ''),
            'Album' => (string) ($row['Album'] ?? ''),
        ];
    }

    $audioIndex = build_audio_files_index();
    $filesById = $audioIndex['filesById'];
    $filesList = $audioIndex['filesList'];

    $missingFiles = [];
    foreach ($dbMusicById as $id => $music) {
        if (empty($filesById[$id])) {
            $missingFiles[] = $music;
        }
    }

    $orphanFiles = [];
    foreach ($filesList as $fileEntry) {
        $id = (string) ($fileEntry['id'] ?? '');
        if ($id === '' || isset($dbMusicById[$id])) {
            continue;
        }

        $orphanFiles[] = $fileEntry;
    }

    $multipleFilesPerMusic = [];
    foreach ($filesById as $id => $entries) {
        if (!isset($dbMusicById[$id])) {
            continue;
        }

        if (count($entries) <= 1) {
            continue;
        }

        $multipleFilesPerMusic[] = [
            'music' => $dbMusicById[$id],
            'files' => $entries,
        ];
    }

    return [
        'summary' => [
            'dbMusicCount' => count($dbMusicById),
            'audioFilesCount' => count($filesList),
            'missingFilesCount' => count($missingFiles),
            'orphanFilesCount' => count($orphanFiles),
            'multipleFilesPerMusicCount' => count($multipleFilesPerMusic),
        ],
        'missingFiles' => $missingFiles,
        'orphanFiles' => $orphanFiles,
        'multipleFilesPerMusic' => $multipleFilesPerMusic,
    ];
}

function is_valid_youtube_video_id(string $value): bool
{
    return (bool) preg_match('/^[0-9A-Za-z_-]{11}$/', trim($value));
}

function resolve_audio_file_by_id_and_path(string $id, string $path = ''): ?array
{
    $audioIndex = build_audio_files_index();
    $entries = $audioIndex['filesById'][$id] ?? [];
    if (!is_array($entries) || empty($entries)) {
        return null;
    }

    $targetPath = trim($path);
    if ($targetPath !== '') {
        foreach ($entries as $entry) {
            if (trim((string) ($entry['path'] ?? '')) === $targetPath) {
                return $entry;
            }
        }
    }

    return $entries[0];
}

function delete_audio_file_by_relative_path(string $relativePath): string
{
    $relative = trim($relativePath);
    if ($relative === '') {
        throw new RuntimeException('Chemin fichier requis');
    }

    $webRoot = realpath(dirname(dirname(__DIR__)));
    $baseDir = realpath(dirname(dirname(__DIR__)) . '/data');
    if ($webRoot === false || $baseDir === false) {
        throw new RuntimeException('Dossier data introuvable');
    }

    $candidateAbsolutePath = $webRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $realPath = realpath($candidateAbsolutePath);
    if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
        throw new RuntimeException('Fichier non autorise');
    }

    if (!is_file($realPath)) {
        throw new RuntimeException('Fichier introuvable');
    }

    if (!unlink($realPath)) {
        throw new RuntimeException('Suppression du fichier impossible');
    }

    return str_replace('\\', '/', substr($realPath, strlen($webRoot) + 1));
}

function delete_all_temp_files(): array
{
    $webRoot = realpath(dirname(dirname(__DIR__)));
    $tempDir = realpath(dirname(dirname(__DIR__)) . '/data/temp');
    if ($webRoot === false || $tempDir === false) {
        throw new RuntimeException('Dossier temp introuvable');
    }

    $deleted = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $absolutePath = $entry->getPathname();
        $realPath = realpath($absolutePath);
        if ($realPath === false || strpos($realPath, $tempDir) !== 0) {
            continue;
        }

        if (!unlink($realPath)) {
            throw new RuntimeException('Impossible de supprimer le fichier temporaire: ' . $entry->getFilename());
        }

        $deleted[] = str_replace('\\', '/', substr($realPath, strlen($webRoot) + 1));
    }

    return [
        'deletedCount' => count($deleted),
        'deletedFiles' => $deleted,
    ];
}

function count_temp_files(): int
{
    $tempDir = realpath(dirname(dirname(__DIR__)) . '/data/temp');
    if ($tempDir === false || !is_dir($tempDir)) {
        return 0;
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $count += 1;
        }
    }

    return $count;
}

function delete_music_entry_only(PDO $pdo, string $musicId): array
{
    ensure_music_table($pdo);
    ensure_playlists_tables($pdo);

    $id = trim($musicId);
    if ($id === '') {
        throw new RuntimeException('Id musique requis');
    }

    $existingMusic = dMusique_get([
        'select' => ['Id'],
        'equals' => ['Id' => $id],
        'limit' => 1,
        'page' => 1,
    ]);
    if (empty($existingMusic['musiques'])) {
        throw new RuntimeException('Musique introuvable');
    }

    $playlistLinksCount = count_music_playlist_links($pdo, $id);
    if ($playlistLinksCount > 0) {
        throw new RuntimeException('Suppression impossible: retirez d\'abord la musique de toutes les playlists');
    }

    $deleteStmt = $pdo->prepare('DELETE FROM Musiques WHERE Id = :id');
    $deleteStmt->execute([':id' => $id]);
    if ($deleteStmt->rowCount() <= 0) {
        throw new RuntimeException('Suppression de la musique impossible');
    }

    return [
        'musicId' => $id,
        'deleted' => true,
    ];
}

function add_music_file_for_existing_entry(YouTubeMusic $yt, string $musicId): array
{
    $id = trim($musicId);
    if ($id === '') {
        throw new RuntimeException('Id musique requis');
    }

    $music = find_music_by_id($id);
    if ($music === null) {
        throw new RuntimeException('Musique introuvable');
    }

    $download = $yt->download($id);
    if (!is_array($download) || empty($download['success'])) {
        $error = is_array($download) ? ($download['error'] ?? 'Telechargement impossible') : 'Telechargement impossible';
        throw new RuntimeException((string) $error);
    }

    $moved = move_downloaded_webm_for_music([
        'Id' => $id,
        'Artiste' => (string) ($music['Artiste'] ?? ''),
        'DownloadedFile' => (string) ($download['file'] ?? ''),
    ]);

    if ($moved === null) {
        throw new RuntimeException('Fichier telecharge introuvable dans data/temp');
    }

    return [
        'musicId' => $id,
        'movedFile' => $moved,
        'download' => $download,
    ];
}

function add_music_entry_for_orphan_file(PDO $pdo, YouTubeMusic $yt, string $musicId, string $relativePath = ''): array
{
    ensure_music_table($pdo);

    $id = trim($musicId);
    if ($id === '') {
        throw new RuntimeException('Id musique requis');
    }

    $existingMusic = dMusique_get([
        'select' => ['Id'],
        'equals' => ['Id' => $id],
        'limit' => 1,
        'page' => 1,
    ]);
    if (!empty($existingMusic['musiques'])) {
        return [
            'musicId' => $id,
            'alreadyExists' => true,
        ];
    }

    $fileEntry = resolve_audio_file_by_id_and_path($id, $relativePath);
    if ($fileEntry === null) {
        throw new RuntimeException('Fichier correspondant introuvable');
    }

    $filePath = str_replace('\\', '/', (string) ($fileEntry['path'] ?? ''));
    $pathParts = array_values(array_filter(explode('/', $filePath), static function ($part) {
        return trim((string) $part) !== '';
    }));

    $album = '';
    if (count($pathParts) >= 3) {
        $album = (string) $pathParts[count($pathParts) - 2];
    }

    $metadata = [];
    if (is_valid_youtube_video_id($id)) {
        $details = $yt->songDetails($id);
        if (is_array($details) && !empty($details['success']) && is_array($details['metadata'] ?? null)) {
            $metadata = $details['metadata'];
        }
    }

    $title = trim((string) ($metadata['title'] ?? ''));
    if ($title === '') {
        $title = $id;
    }

    $artist = trim((string) ($metadata['artist'] ?? ''));
    if ($artist === '' && !empty($metadata['artists']) && is_array($metadata['artists'])) {
        $artist = trim((string) ($metadata['artists'][0] ?? ''));
    }

    $albumFromMetadata = trim((string) ($metadata['album'] ?? ''));
    if ($albumFromMetadata !== '') {
        $album = $albumFromMetadata;
    }

    $payload = [
        'Id' => $id,
        'Titre' => $title,
        'Artiste' => $artist,
        'Album' => $album,
        'Duree' => $metadata['durationSeconds'] ?? null,
        'NombreVue' => $metadata['views'] ?? 0,
    ];

    $music = add_music_to_database($payload, $pdo);

    return [
        'musicId' => $id,
        'music' => $music,
        'sourceFile' => $filePath,
    ];
}

    function table_exists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :name');
        $stmt->execute([':name' => $tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    }

    function find_first_existing_column(PDO $pdo, string $tableName, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$tableName} LIKE :name");
            $stmt->execute([':name' => $candidate]);
            if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    function resolve_current_user_id(): int
    {
        if (!empty($_SESSION['user']['id'])) {
            return (int) $_SESSION['user']['id'];
        }

        throw new RuntimeException('Utilisateur courant introuvable');
    }

    function playlist_row_to_response(array $row): array
    {
        return [
            'PlaylistId' => (int) $row['idPlaylist'],
            'NomPlaylist' => (string) $row['NomPlaylist'],
            'Description' => (string) ($row['Description'] ?? ''),
            'DateDerniereModification' => (string) $row['DateDerniereModification'],
            'NombreVue' => (int) $row['NombreVue'],
            'Utilisateur' => (int) $row['Utilisateur'],
            'UtilisateurNom' => (string) ($row['UtilisateurNom'] ?? ''),
        ];
    }

    function get_music_playlists(PDO $pdo, string $musicId): array
    {
        if ($musicId === '') {
            return [];
        }

        ensure_playlists_tables($pdo);

        $payload = dMyPlaylistMusiques_get([
            'select' => ['PlaylistId', 'NomPlaylist', 'Description', 'Utilisateur', 'UtilisateurNom', 'PositionLecture'],
            'equals' => ['IdMusique' => $musicId],
            'orderBy' => 'NomPlaylist',
            'order' => 'ASC',
            'withPlaylistDetails' => true,
            'limit' => 2000,
            'page' => 1,
        ]);

        $rows = (array) ($payload['myPlaylistMusiques'] ?? []);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                return [
                    'PlaylistId' => (int) ($row['PlaylistId'] ?? 0),
                    'NomPlaylist' => (string) ($row['NomPlaylist'] ?? ''),
                    'Description' => (string) ($row['Description'] ?? ''),
                    'Utilisateur' => (int) ($row['Utilisateur'] ?? 0),
                    'UtilisateurNom' => (string) ($row['UtilisateurNom'] ?? ''),
                    'PositionLecture' => (int) ($row['PositionLecture'] ?? 0),
                ];
            },
            $rows
        );
    }

    function count_music_playlist_links(PDO $pdo, string $musicId): int
    {
        if ($musicId === '') {
            return 0;
        }

        ensure_playlists_tables($pdo);

        $payload = dMyPlaylistMusiques_get([
            'select' => ['IdMusique'],
            'equals' => ['IdMusique' => $musicId],
            'limit' => 1,
            'page' => 1,
        ]);

        return (int) ($payload['totalRows'] ?? 0);
    }
/*
if (empty($_SESSION['user'])) {
    // Toutes les routes de cette API necessitent une session utilisateur active.
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}*/

$yt = new YouTubeMusic();

if (!empty($_GET['deleteFile'])) {

    try {
        $filePath = trim((string) $_GET['deleteFile']);
        if ($filePath === '') {
            throw new RuntimeException('Chemin du fichier vide');
        }

        // Vérifier que le fichier est dans le dossier temp pour éviter les suppressions non autorisées
        $realPath = realpath($filePath);
        $tempDir = realpath(__DIR__ . '/../../data/temp');

        if ($realPath === false || $tempDir === false || strpos($realPath, $tempDir) !== 0) {
            throw new RuntimeException('Fichier non autorisé pour suppression');
        }

        if (!file_exists($realPath)) {
            echo json_encode([
                'success' => false,
                'error' => 'Fichier non trouvé',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (unlink($realPath)) {
            echo json_encode([
                'success' => true,
                'message' => 'Fichier supprimé avec succès',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new RuntimeException('Impossible de supprimer le fichier');
        }
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['suggestions'])) {

    try {
        $query = trim((string) $_GET['suggestions']);
        if ($query === '') {
            throw new RuntimeException('Requete de suggestion vide');
        }

        echo json_encode(
            $yt->getSuggestions($query),
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['query'])) {

    try {
        $query = trim((string) $_GET['query']);
        if ($query === '') {
            throw new RuntimeException('Requete de recherche vide');
        }

        echo json_encode(
            $yt->search($query),
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['playlistQuery'])) {

    try {
        $query = trim((string) $_GET['playlistQuery']);
        if ($query === '') {
            throw new RuntimeException('Requete de playlist vide');
        }

        echo json_encode(
            $yt->searchPlaylists($query),
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['playlistItems'])) {

    try {
        $playlistId = trim((string) ($_GET['id'] ?? ''));
        if ($playlistId === '') {
            throw new RuntimeException('Id de playlist requis');
        }

        echo json_encode(
            $yt->playlistItems($playlistId),
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['musicMetadata'])) {

    try {
        $videoId = trim((string) ($_GET['id'] ?? $_GET['videoId'] ?? ''));
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        echo json_encode(
            $yt->songDetails($videoId),
            JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['savePlayedPlaylist']) || !empty($_POST['savePlayedPlaylist'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $saved = save_played_playlist($payload);

        echo json_encode([
            'success' => true,
            'message' => 'Playlist enregistree',
            'playlist' => $saved,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['myPlaylists'])) {

    try {
        $currentUserId = resolve_current_user_id();

        $playlistsRaw = [];
        $page = 1;
        $totalPages = 1;
        do {
            $playlistsPayload = dPlaylist_get([
                'select' => [
                    'idPlaylist',
                    'NomPlaylist',
                    'Description',
                    'Partage',
                    'DateDerniereModification',
                    'NombreVue',
                    'Utilisateur',
                ],
                'equals' => [
                    'Utilisateur' => $currentUserId,
                ],
                'orderBy' => 'DateDerniereModification',
                'order' => 'DESC',
                'limit' => 200,
                'page' => $page,
            ]);

            $playlistsRaw = array_merge($playlistsRaw, (array) ($playlistsPayload['playlists'] ?? []));
            $totalPages = max(1, (int) ($playlistsPayload['totalPages'] ?? 1));
            $page += 1;
        } while ($page <= $totalPages);

        $playlists = [];
        foreach ($playlistsRaw as $playlist) {
            $playlistId = (int) ($playlist['idPlaylist'] ?? 0);
            $linksPayload = dMyPlaylistMusiques_get([
                'select' => ['IdMusique'],
                'equals' => ['IdPlaylist' => $playlistId],
                'limit' => 1,
                'page' => 1,
            ]);

            $playlists[] = [
                'PlaylistId' => $playlistId,
                'NomPlaylist' => (string) ($playlist['NomPlaylist'] ?? ''),
                'Description' => (string) ($playlist['Description'] ?? ''),
                'Partage' => (int) ($playlist['Partage'] ?? 0),
                'DateDerniereModification' => (string) ($playlist['DateDerniereModification'] ?? ''),
                'NombreVue' => (int) ($playlist['NombreVue'] ?? 0),
                'Utilisateur' => (int) ($playlist['Utilisateur'] ?? 0),
                'UtilisateurNom' => '',
                'TotalMusiques' => (int) ($linksPayload['totalRows'] ?? 0),
            ];
        }

        echo json_encode([
            'success' => true,
            'playlists' => $playlists,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['incrementPlaylistView']) || !empty($_POST['incrementPlaylistView'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['id'] ?? $payload['playlistId'] ?? 0);

        if ($playlistId <= 0) {
            throw new RuntimeException('Id de playlist requis');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $touch = $pdo->prepare(
            'UPDATE Playlist
             SET NombreVue = NombreVue + 1
             WHERE idPlaylist = :playlistId'
        );
        $touch->execute([':playlistId' => $playlistId]);

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['playlistEdition']) || !empty($_POST['playlistEdition'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['id'] ?? $payload['playlistId'] ?? 0);
        if ($playlistId <= 0) {
            throw new RuntimeException('Id de playlist requis');
        }

        $currentUserId = resolve_current_user_id();

        $playlistPayload = dPlaylist_get([
            'select' => ['idPlaylist', 'NomPlaylist', 'Description', 'Partage', 'DateDerniereModification', 'NombreVue', 'Utilisateur'],
            'equals' => ['idPlaylist' => $playlistId],
            'limit' => 1,
            'page' => 1,
        ]);
        $playlistRow = (array) (($playlistPayload['playlists'] ?? [])[0] ?? []);

        if (empty($playlistRow)) {
            throw new RuntimeException('Playlist introuvable');
        }

        if ((int) ($playlistRow['Utilisateur'] ?? 0) !== $currentUserId) {
            throw new RuntimeException('Edition non autorisee pour cette playlist');
        }

        $playlist = [
            'PlaylistId' => (int) ($playlistRow['idPlaylist'] ?? 0),
            'NomPlaylist' => (string) ($playlistRow['NomPlaylist'] ?? ''),
            'Description' => (string) ($playlistRow['Description'] ?? ''),
            'Partage' => (int) ($playlistRow['Partage'] ?? 0),
            'DateDerniereModification' => (string) ($playlistRow['DateDerniereModification'] ?? ''),
            'NombreVue' => (int) ($playlistRow['NombreVue'] ?? 0),
            'Utilisateur' => (int) ($playlistRow['Utilisateur'] ?? 0),
            'UtilisateurNom' => (string) ($playlistRow['UtilisateurNom'] ?? ''),
        ];

        $linksPayload = dMyPlaylistMusiques_get([
            'select' => ['IdMusique', 'PositionLecture'],
            'equals' => ['IdPlaylist' => $playlistId],
            'orderBy' => 'PositionLecture',
            'order' => 'ASC',
            'limit' => 2000,
            'page' => 1,
        ]);

        $songs = [];
        foreach ((array) ($linksPayload['myPlaylistMusiques'] ?? []) as $link) {
            $linkMusicId = trim((string) ($link['IdMusique'] ?? ''));
            if ($linkMusicId === '') {
                continue;
            }

            $musicPayload = dMusique_get([
                'equals' => ['Id' => $linkMusicId],
                'limit' => 1,
                'page' => 1,
            ]);

            $music = (array) (($musicPayload['musiques'] ?? [])[0] ?? []);
            if (empty($music)) {
                continue;
            }
            $music['PositionLecture'] = (int) ($link['PositionLecture'] ?? 0);
            $songs[] = $music;
        }

        echo json_encode([
            'success' => true,
            'playlist' => $playlist,
            'songs' => $songs,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['updatePlaylist']) || !empty($_POST['updatePlaylist'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['id'] ?? $payload['playlistId'] ?? 0);
        $name = trim((string) ($payload['NomPlaylist'] ?? $payload['name'] ?? ''));
        $description = trim((string) ($payload['Description'] ?? $payload['description'] ?? ''));

        if ($playlistId <= 0) {
            throw new RuntimeException('Id de playlist requis');
        }
        if ($name === '') {
            throw new RuntimeException('Nom de playlist requis');
        }

        $currentUserId = resolve_current_user_id();
        $ownerPayload = dPlaylist_get([
            'select' => ['idPlaylist'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        if (empty($ownerPayload['playlists'])) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $updateStmt = $pdo->prepare(
            'UPDATE Playlist
             SET NomPlaylist = :name,
                 Description = :description,
                 DateDerniereModification = NOW()
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId'
        );
        $updateStmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'NomPlaylist' => $name,
            'Description' => $description,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['togglePlaylistShare']) || !empty($_POST['togglePlaylistShare'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['id'] ?? $payload['playlistId'] ?? 0);

        if ($playlistId <= 0) {
            throw new RuntimeException('Id de playlist requis');
        }

        $currentUserId = resolve_current_user_id();
        $playlistPayload = dPlaylist_get([
            'select' => ['idPlaylist', 'Partage', 'Utilisateur'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        $playlist = (array) (($playlistPayload['playlists'] ?? [])[0] ?? []);
        if (empty($playlist)) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $targetPartage = null;
        if (isset($payload['Partage'])) {
            $rawPartage = filter_var($payload['Partage'], FILTER_VALIDATE_INT);
            if ($rawPartage === false || ($rawPartage !== 0 && $rawPartage !== 1)) {
                throw new RuntimeException('Valeur Partage invalide');
            }
            $targetPartage = (int) $rawPartage;
        }

        if ($targetPartage === null) {
            $targetPartage = ((int) ($playlist['Partage'] ?? 0)) === 1 ? 0 : 1;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE Playlist
             SET Partage = :partage,
                 DateDerniereModification = NOW()
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId'
        );
        $updateStmt->execute([
            ':partage' => $targetPartage,
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'Partage' => $targetPartage,
            'message' => $targetPartage === 1 ? 'Partage active' : 'Partage desactive',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['removePlaylistMusic']) || !empty($_POST['removePlaylistMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['IdPlaylist'] ?? $payload['playlistId'] ?? 0);
        $musicId = trim((string) ($payload['IdMusique'] ?? $payload['musicId'] ?? ''));

        if ($playlistId <= 0) {
            throw new RuntimeException('IdPlaylist requis');
        }
        if ($musicId === '') {
            throw new RuntimeException('IdMusique requis');
        }

        $currentUserId = resolve_current_user_id();
        $ownerPayload = dPlaylist_get([
            'select' => ['idPlaylist'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        if (empty($ownerPayload['playlists'])) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $deleteStmt = $pdo->prepare(
            'DELETE FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId
               AND IdMusique = :musicId'
        );
        $deleteStmt->execute([
            ':playlistId' => $playlistId,
            ':musicId' => $musicId,
        ]);

        // Recalcul de position simple pour garder un ordre compact.
        $positionsPayload = dMyPlaylistMusiques_get([
            'select' => ['IdMusique', 'PositionLecture'],
            'equals' => ['IdPlaylist' => $playlistId],
            'orderBy' => 'PositionLecture',
            'order' => 'ASC',
            'limit' => 2000,
            'page' => 1,
        ]);
        $rows = (array) ($positionsPayload['myPlaylistMusiques'] ?? []);

        $updatePositionStmt = $pdo->prepare(
            'UPDATE MyPlaylistMusiques
             SET PositionLecture = :position
             WHERE IdPlaylist = :playlistId
               AND IdMusique = :musicId'
        );

        $position = 1;
        foreach ($rows as $row) {
            $updatePositionStmt->execute([
                ':position' => $position,
                ':playlistId' => $playlistId,
                ':musicId' => (string) $row['IdMusique'],
            ]);
            $position += 1;
        }

        $touchStmt = $pdo->prepare(
            'UPDATE Playlist
             SET DateDerniereModification = NOW()
             WHERE idPlaylist = :playlistId'
        );
        $touchStmt->execute([':playlistId' => $playlistId]);

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'musicId' => $musicId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['reorderPlaylistSongs']) || !empty($_POST['reorderPlaylistSongs'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['IdPlaylist'] ?? $payload['playlistId'] ?? 0);
        $orderedMusicIdsRaw = (string) ($payload['orderedMusicIds'] ?? '[]');
        $orderedMusicIds = json_decode($orderedMusicIdsRaw, true);

        if ($playlistId <= 0) {
            throw new RuntimeException('IdPlaylist requis');
        }

        if (!is_array($orderedMusicIds) || count($orderedMusicIds) === 0) {
            throw new RuntimeException('orderedMusicIds invalide');
        }

        $orderedMusicIds = array_values(array_filter(array_map(static function ($value) {
            return trim((string) $value);
        }, $orderedMusicIds), static function ($value) {
            return $value !== '';
        }));

        if (count($orderedMusicIds) === 0) {
            throw new RuntimeException('Aucun identifiant musique valide');
        }

        if (count(array_unique($orderedMusicIds)) !== count($orderedMusicIds)) {
            throw new RuntimeException('orderedMusicIds contient des doublons');
        }

        $currentUserId = resolve_current_user_id();
        $ownerPayload = dPlaylist_get([
            'select' => ['idPlaylist'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        if (empty($ownerPayload['playlists'])) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $existingPayload = dMyPlaylistMusiques_get([
            'select' => ['IdMusique'],
            'equals' => ['IdPlaylist' => $playlistId],
            'limit' => 2000,
            'page' => 1,
        ]);
        $existingIds = array_map(static function ($value) {
            return trim((string) $value);
        }, array_map(static function ($row) {
            return (string) ($row['IdMusique'] ?? '');
        }, (array) ($existingPayload['myPlaylistMusiques'] ?? [])));

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        sort($existingIds);
        $sortedPayloadIds = $orderedMusicIds;
        sort($sortedPayloadIds);

        if ($existingIds !== $sortedPayloadIds) {
            throw new RuntimeException('La liste des musiques a reordonner ne correspond pas a la playlist');
        }

        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare(
            'UPDATE MyPlaylistMusiques
             SET PositionLecture = :position
             WHERE IdPlaylist = :playlistId
               AND IdMusique = :musicId'
        );

        $position = 1;
        foreach ($orderedMusicIds as $musicId) {
            $updateStmt->execute([
                ':position' => $position,
                ':playlistId' => $playlistId,
                ':musicId' => $musicId,
            ]);
            $position += 1;
        }

        $touchStmt = $pdo->prepare(
            'UPDATE Playlist
             SET DateDerniereModification = NOW()
             WHERE idPlaylist = :playlistId'
        );
        $touchStmt->execute([':playlistId' => $playlistId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'count' => count($orderedMusicIds),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['deletePlaylist']) || !empty($_POST['deletePlaylist'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['playlistId'] ?? $payload['id'] ?? 0);

        if ($playlistId <= 0) {
            throw new RuntimeException('IdPlaylist requis');
        }

        $currentUserId = resolve_current_user_id();
        $ownerPayload = dPlaylist_get([
            'select' => ['idPlaylist'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        if (empty($ownerPayload['playlists'])) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $pdo->beginTransaction();

        $deleteLinksStmt = $pdo->prepare(
            'DELETE FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId'
        );
        $deleteLinksStmt->execute([':playlistId' => $playlistId]);
        $deletedLinks = (int) $deleteLinksStmt->rowCount();

        $deletePlaylistStmt = $pdo->prepare(
            'DELETE FROM Playlist
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId'
        );
        $deletePlaylistStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        if ($deletePlaylistStmt->rowCount() <= 0) {
            throw new RuntimeException('Suppression de la playlist impossible');
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'deletedLinks' => $deletedLinks,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['addPlaylistMusic']) || !empty($_POST['addPlaylistMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $playlistId = (int) ($payload['IdPlaylist'] ?? $payload['PlaylistId'] ?? 0);
        $musicId = trim((string) ($payload['IdMusique'] ?? $payload['MusicId'] ?? ''));

        if ($playlistId <= 0) {
            throw new RuntimeException('IdPlaylist requis');
        }

        if ($musicId === '') {
            throw new RuntimeException('IdMusique requis');
        }

        $currentUserId = resolve_current_user_id();
        $playlistPayload = dPlaylist_get([
            'select' => ['idPlaylist', 'NomPlaylist', 'Utilisateur'],
            'equals' => [
                'idPlaylist' => $playlistId,
                'Utilisateur' => $currentUserId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        $playlist = (array) (($playlistPayload['playlists'] ?? [])[0] ?? []);
        if (empty($playlist)) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $existingPayload = dMyPlaylistMusiques_get([
            'select' => ['PositionLecture'],
            'equals' => [
                'IdPlaylist' => $playlistId,
                'IdMusique' => $musicId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        $existing = (array) (($existingPayload['myPlaylistMusiques'] ?? [])[0] ?? []);
        if (!empty($existing)) {
            echo json_encode([
                'success' => true,
                'message' => 'Musique deja presente dans la playlist',
                'playlist' => [
                    'PlaylistId' => $playlistId,
                    'NomPlaylist' => (string) $playlist['NomPlaylist'],
                ],
                'positionLecture' => (int) $existing['PositionLecture'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $allLinksPayload = dMyPlaylistMusiques_get([
            'select' => ['PositionLecture'],
            'equals' => ['IdPlaylist' => $playlistId],
            'limit' => 2000,
            'page' => 1,
        ]);
        $nextPosition = 1;
        foreach ((array) ($allLinksPayload['myPlaylistMusiques'] ?? []) as $link) {
            $position = (int) ($link['PositionLecture'] ?? 0);
            if ($position >= $nextPosition) {
                $nextPosition = $position + 1;
            }
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $insert = $pdo->prepare(
            'INSERT INTO MyPlaylistMusiques (IdPlaylist, IdMusique, PositionLecture)
             VALUES (:playlistId, :musicId, :positionLecture)'
        );
        $insert->execute([
            ':playlistId' => $playlistId,
            ':musicId' => $musicId,
            ':positionLecture' => $nextPosition,
        ]);

        $updatePlaylist = $pdo->prepare(
            'UPDATE Playlist
             SET DateDerniereModification = NOW()
             WHERE idPlaylist = :playlistId'
        );
        $updatePlaylist->execute([':playlistId' => $playlistId]);

        echo json_encode([
            'success' => true,
            'message' => 'Musique ajoutee a la playlist',
            'playlist' => [
                'PlaylistId' => $playlistId,
                'NomPlaylist' => (string) $playlist['NomPlaylist'],
            ],
            'positionLecture' => $nextPosition,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['createPlaylist']) || !empty($_POST['createPlaylist'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $name = trim((string) ($payload['NomPlaylist'] ?? ''));
        $description = trim((string) ($payload['Description'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Nom de playlist requis');
        }

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $userId = resolve_current_user_id();
        $stmt = $pdo->prepare(
            'INSERT INTO Playlist (NomPlaylist, Description, DateDerniereModification, NombreVue, Utilisateur)
             VALUES (:name, :description, NOW(), 0, :utilisateur)'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':utilisateur' => $userId,
        ]);

        $playlistId = (int) $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Playlist creee',
            'playlist' => [
                'PlaylistId' => $playlistId,
                'NomPlaylist' => $name,
                'Description' => $description,
                'DateDerniereModification' => date('Y-m-d H:i:s'),
                'NombreVue' => 0,
                'Utilisateur' => $userId,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

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
                $result = $yt->download($musicId);

                echo json_encode([
                    'success' => true,
                    'download' => $result,
                    'music' => $existingMusic,
                    'recoveredMissingAudio' => true,
                ], JSON_UNESCAPED_UNICODE);
                exit;
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

} elseif (!empty($_GET['updateMusic']) || !empty($_POST['updateMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $id = trim((string) ($payload['Id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Id requis');
        }

        $existsPayload = dMusique_get([
            'select' => ['Id'],
            'equals' => ['Id' => $id],
            'limit' => 1,
            'page' => 1,
        ]);
        if (empty($existsPayload['musiques'])) {
            throw new RuntimeException('Musique introuvable');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $existingMusic = dMusique_get([
            'select' => ['Titre', 'Artiste', 'Utilisateur', 'Album', 'Duree', 'AnneeParution', 'Genre', 'NombreVue', 'NombreVueInterne'],
            'equals' => ['Id' => $id],
            'limit' => 1,
            'page' => 1,
        ]);

        $currentMusic = $existingMusic['musiques'][0] ?? [];

        $titre = trim((string) (array_key_exists('Titre', $payload) ? ($payload['Titre'] ?? '') : ($currentMusic['Titre'] ?? '')));
        $artiste = trim((string) (array_key_exists('Artiste', $payload) ? ($payload['Artiste'] ?? '') : ($currentMusic['Artiste'] ?? '')));
        $utilisateur = trim((string) (array_key_exists('Utilisateur', $payload) ? ($payload['Utilisateur'] ?? '') : ($currentMusic['Utilisateur'] ?? '')));
        $album = trim((string) (array_key_exists('Album', $payload) ? ($payload['Album'] ?? '') : ($currentMusic['Album'] ?? '')));
        $genre = trim((string) (array_key_exists('Genre', $payload) ? ($payload['Genre'] ?? '') : ($currentMusic['Genre'] ?? '')));
        $duree = array_key_exists('Duree', $payload)
            ? (($payload['Duree'] ?? '') === '' ? null : (int) $payload['Duree'])
            : (isset($currentMusic['Duree']) ? (int) $currentMusic['Duree'] : null);
        $anneeParution = array_key_exists('AnneeParution', $payload)
            ? (($payload['AnneeParution'] ?? '') === '' ? null : (int) $payload['AnneeParution'])
            : (isset($currentMusic['AnneeParution']) ? (int) $currentMusic['AnneeParution'] : null);
        $nombreVue = array_key_exists('NombreVue', $payload)
            ? max(0, (int) ($payload['NombreVue'] ?? 0))
            : max(0, (int) ($currentMusic['NombreVue'] ?? 0));
        $nombreVueInterne = array_key_exists('NombreVueInterne', $payload)
            ? max(0, (int) ($payload['NombreVueInterne'] ?? 0))
            : max(0, (int) ($currentMusic['NombreVueInterne'] ?? 0));

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

} elseif (!empty($_GET['deleteMusic']) || !empty($_POST['deleteMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $id = trim((string) ($payload['Id'] ?? $payload['id'] ?? ''));

        if ($id === '') {
            throw new RuntimeException('Id requis');
        }

        $existsPayload = dMusique_get([
            'select' => ['Id'],
            'equals' => ['Id' => $id],
            'limit' => 1,
            'page' => 1,
        ]);
        if (empty($existsPayload['musiques'])) {
            throw new RuntimeException('Musique introuvable');
        }

        $playlistLinksCount = count_music_playlist_links($pdo, $id);
        if ($playlistLinksCount > 0) {
            throw new RuntimeException('Suppression impossible: retirez d\'abord la musique de toutes les playlists');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $pdo->beginTransaction();

        $deleteStmt = $pdo->prepare('DELETE FROM Musiques WHERE Id = :id');
        $deleteStmt->execute([':id' => $id]);

        if ($deleteStmt->rowCount() <= 0) {
            throw new RuntimeException('Suppression de la musique impossible');
        }

        $deletedFiles = delete_downloaded_files_for_music_id($id);
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Musique supprimee',
            'Id' => $id,
            'deletedFiles' => $deletedFiles,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['musicFilesIntegrity'])) {

    try {
        $pdo = get_database_pdo();
        $report = build_music_files_integrity_report($pdo);

        echo json_encode([
            'success' => true,
            'report' => $report,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['musicFilesIntegrityAction']) || !empty($_POST['musicFilesIntegrityAction'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $action = trim((string) ($payload['action'] ?? ''));
        if ($action === '') {
            throw new RuntimeException('Action requise');
        }

        $pdo = get_database_pdo();
        $result = [];

        if ($action === 'delete_music_entry') {
            $musicId = trim((string) ($payload['musicId'] ?? ''));
            $result = delete_music_entry_only($pdo, $musicId);
        } elseif ($action === 'add_music_file') {
            $musicId = trim((string) ($payload['musicId'] ?? ''));
            $result = add_music_file_for_existing_entry($yt, $musicId);
        } elseif ($action === 'delete_file') {
            $filePath = trim((string) ($payload['filePath'] ?? ''));
            $deletedPath = delete_audio_file_by_relative_path($filePath);
            $result = [
                'filePath' => $deletedPath,
                'deleted' => true,
            ];
        } elseif ($action === 'add_music_entry') {
            $musicId = trim((string) ($payload['musicId'] ?? ''));
            $filePath = trim((string) ($payload['filePath'] ?? ''));
            $result = add_music_entry_for_orphan_file($pdo, $yt, $musicId, $filePath);
        } else {
            throw new RuntimeException('Action inconnue');
        }

        echo json_encode([
            'success' => true,
            'action' => $action,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['clearTempFiles']) || !empty($_POST['clearTempFiles'])) {

    try {
        $result = delete_all_temp_files();

        echo json_encode([
            'success' => true,
            'message' => 'Fichiers temporaires supprimes',
            'result' => $result,
            'tempFilesCount' => count_temp_files(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['tempFilesCount']) || !empty($_POST['tempFilesCount'])) {

    try {
        echo json_encode([
            'success' => true,
            'tempFilesCount' => count_temp_files(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['currentUser'])) {

    try {
        if (!empty($_SESSION['user'])) {
            echo json_encode([
                'success' => true,
                'id' => (int) ($_SESSION['user']['id'] ?? 0),
                'username' => $_SESSION['user']['username'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Non authentifie'
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['playedHistory'])) {

    try {
        $userId = resolve_current_user_id();
        $history = get_played_history($userId);

        echo json_encode([
            'success' => true,
            'musiques' => $history,
            'totalRows' => count($history),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['likedMusics'])) {

    try {
        $userId = resolve_current_user_id();
        $payload = dMusiqueAimee_get([
            'select' => ['IdMusique', 'DateAjout', 'Id', 'Titre', 'Artiste', 'Utilisateur', 'Album', 'Duree', 'AnneeParution', 'Genre', 'NombreVue', 'NombreVueInterne'],
            'equals' => ['IdUtilisateur' => $userId],
            'withMusicDetails' => true,
            'orderBy' => 'DateAjout',
            'order' => 'DESC',
            'limit' => 5000,
            'page' => 1,
        ]);

        echo json_encode([
            'success' => true,
            'musiques' => $payload['musiquesAimees'] ?? [],
            'totalRows' => $payload['totalRows'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['favoriteState'])) {

    try {
        $userId = resolve_current_user_id();
        $musicId = trim((string) ($_GET['id'] ?? ''));
        if ($musicId === '') {
            throw new RuntimeException('IdMusique requis');
        }

        $payload = dMusiqueAimee_get([
            'select' => ['IdMusique'],
            'equals' => [
                'IdUtilisateur' => $userId,
                'IdMusique' => $musicId,
            ],
            'limit' => 1,
            'page' => 1,
        ]);

        echo json_encode([
            'success' => true,
            'isFavorite' => !empty($payload['musiquesAimees']),
            'IdMusique' => $musicId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['addFavoriteMusic']) || !empty($_POST['addFavoriteMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $userId = resolve_current_user_id();
        $musicId = trim((string) ($payload['IdMusique'] ?? $payload['MusicId'] ?? ''));
        if ($musicId === '') {
            throw new RuntimeException('IdMusique requis');
        }

        add_liked_music($userId, $musicId);

        echo json_encode([
            'success' => true,
            'favorite' => true,
            'IdMusique' => $musicId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['removeFavoriteMusic']) || !empty($_POST['removeFavoriteMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $userId = resolve_current_user_id();
        $musicId = trim((string) ($payload['IdMusique'] ?? $payload['MusicId'] ?? ''));
        if ($musicId === '') {
            throw new RuntimeException('IdMusique requis');
        }

        remove_liked_music($userId, $musicId);

        echo json_encode([
            'success' => true,
            'favorite' => false,
            'IdMusique' => $musicId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['play']) || !empty($_POST['play'])) {
    try {
        $videoId = trim((string) ($_GET['play'] ?? $_POST['play'] ?? ''));
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $existingMusic = dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Album', 'NombreVue', 'NombreVueInterne'],
            'equals' => ['Id' => $videoId],
            'limit' => 1,
            'page' => 1,
        ]);

        if (empty($existingMusic['musiques'])) {
            $details = $yt->songDetails($videoId);
            if (!is_array($details) || empty($details['success']) || !is_array($details['metadata'] ?? null)) {
                throw new RuntimeException('Impossible de récupérer les métadonnées de la musique');
            }

            $metadata = $details['metadata'];
            $payload = [
                'Id' => (string) ($metadata['videoId'] ?? $videoId),
                'Titre' => (string) ($metadata['title'] ?? $videoId),
                'Artiste' => (string) ($metadata['artist'] ?? ''),
                'Album' => (string) ($metadata['album'] ?? ''),
                'Duree' => (int) ($metadata['durationSeconds'] ?? 0),
                'AnneeParution' => (int) ($metadata['year'] ?? 0),
                'Genre' => (string) ($metadata['genre'] ?? ''),
                'NombreVue' => (int) ($metadata['views'] ?? 0),
                'NombreVueInterne' => 1,
                'DateAjout' => date('Y-m-d H:i:s'),
            ];

            $added = add_music_to_database($payload, $pdo);
            $moved = move_downloaded_webm_for_music($payload);

            $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
            if ($currentUserId > 0) {
                try {
                    record_played_music($currentUserId, (string) ($added['Id'] ?? $videoId), $pdo);
                } catch (Throwable $historyError) {
                    error_log('record_played_music: ' . $historyError->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'action' => 'created',
                'message' => 'Musique ajoutee a la base et NombreVueInterne initialise',
                'music' => $added,
                'movedFile' => $moved,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $incrementStmt = $pdo->prepare(
            'UPDATE Musiques
             SET NombreVueInterne = NombreVueInterne + 1
             WHERE Id = :id'
        );
        $incrementStmt->execute([':id' => $videoId]);

        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
        if ($currentUserId > 0) {
            try {
                record_played_music($currentUserId, $videoId, $pdo);
            } catch (Throwable $historyError) {
                error_log('record_played_music: ' . $historyError->getMessage());
            }
        }

        $updatedMusic = dMusique_get([
            'select' => ['Id', 'NombreVueInterne'],
            'equals' => ['Id' => $videoId],
            'limit' => 1,
            'page' => 1,
        ]);

        echo json_encode([
            'success' => true,
            'action' => 'incremented',
            'message' => 'NombreVueInterne incrémenté',
            'music' => (array) (($updatedMusic['musiques'][0] ?? []) ?: []),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
} elseif (!empty($_GET['add'])) {
    try {

        $videoId = trim((string) $_GET['add']);
        if ($videoId === '') {
            throw new RuntimeException('Requete de recherche vide');
        }

        // Vérifie si la musique est déjà présente dans la base de données
        $db = dMusique_get([
            'select' => ['Id'],
            'equals' => [
                'Id' => $videoId,
            ],
            'limit' => 1
        ]);
        if (!empty($db['musiques'])) {
            echo json_encode([
                'success' => false,
                'error' => "La musique est déjà présente dans la base de données",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = $yt->songDetails($videoId);

        $payload = [
            'Id' => $res['metadata']['videoId'],
            'Titre' => $res['metadata']['title'],
            'Artiste' => $res['metadata']['artist'] ?? '',
            'Album' => $res['metadata']['album'] ?? '',
            'Duree' => $res['metadata']['durationSeconds'] ?? 0,
            'AnneeParution' => $res['metadata']['year'] ?? 0,
            'Genre' => $res['metadata']['genre'] ?? '',
            'NombreVue' => $res['metadata']['views'] ?? 0,
            'NombreVueInterne' => 1,
            'DateAjout' => date('Y-m-d H:i:s'),
        ];

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
} elseif (!empty($_GET['next'])) {
    try {
        $videoId = trim((string) $_GET['next']);
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        $res = $yt->playlist($videoId);

        // Vérifie si chaque musique de la recherche est déjà présente dans la base de données
        foreach ($res['playlist'] as &$element) {

            $db = dMusique_get([
                'select' => ['Id'],
                'equals' => [
                    'Id' => $element['videoId'],
                ],
                'limit' => 1
            ]);
            $element['inDatabase'] = !empty($db['musiques']);
        }
        unset($element);

        echo json_encode($res, JSON_UNESCAPED_UNICODE);

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
