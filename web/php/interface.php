<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require 'YouTubeMusic.php';
require_once __DIR__ . '/database_interface.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
        return null;
    }

    $artist = sanitize_folder_name((string) ($payload['Artiste'] ?? ''));
    $webRoot = dirname(__DIR__);
    $baseDir = $webRoot . '/data';
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
        'to' => str_replace('\\', '/', substr($destination, strlen($webRoot) + 1)),
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
    $webRoot = dirname(__DIR__);
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

        $stmt = $pdo->prepare(
            'SELECT
                p.idPlaylist AS PlaylistId,
                p.NomPlaylist,
                p.Description,
                p.Utilisateur,
                COALESCE(u.NomUtilisateur, "") AS UtilisateurNom,
                pm.PositionLecture
             FROM MyPlaylistMusiques pm
             INNER JOIN Playlist p ON p.idPlaylist = pm.IdPlaylist
             LEFT JOIN Utilisateurs u ON u.Id = p.Utilisateur
             WHERE pm.IdMusique = :musicId
             ORDER BY p.NomPlaylist ASC'
        );
        $stmt->execute([':musicId' => $musicId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS Total
             FROM MyPlaylistMusiques
             WHERE IdMusique = :musicId'
        );
        $stmt->execute([':musicId' => $musicId]);

        return (int) (($stmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0));
    }

if (empty($_SESSION['user'])) {
    // Toutes les routes de cette API necessitent une session utilisateur active.
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentification requise',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$yt = new YouTubeMusic();

if (!empty($_GET['deleteFile'])) {

    try {
        $filePath = trim((string) $_GET['deleteFile']);
        if ($filePath === '') {
            throw new RuntimeException('Chemin du fichier vide');
        }

        // Vérifier que le fichier est dans le dossier temp pour éviter les suppressions non autorisées
        $realPath = realpath($filePath);
        $tempDir = realpath(__DIR__ . '/../data/temp');

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
} elseif (!empty($_GET['dbPlaylists'])) {

    try {
        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $stmt = $pdo->query(
            "SELECT
                p.idPlaylist AS PlaylistId,
                p.NomPlaylist,
                p.Description,
                p.DateDerniereModification,
                p.NombreVue,
                p.Utilisateur,
                u.NomUtilisateur AS UtilisateurNom,
                COUNT(pm.IdMusique) AS TotalMusiques
             FROM Playlist p
             LEFT JOIN Utilisateurs u ON u.Id = p.Utilisateur
                 LEFT JOIN MyPlaylistMusiques pm ON pm.IdPlaylist = p.idPlaylist
             GROUP BY p.idPlaylist, p.NomPlaylist, p.Description, p.DateDerniereModification, p.NombreVue, p.Utilisateur, u.NomUtilisateur
             ORDER BY p.DateDerniereModification DESC, p.NomPlaylist ASC"
        );

        $playlists = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

} elseif (!empty($_GET['myPlaylists'])) {

    try {
        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);
        $currentUserId = resolve_current_user_id();

        $stmt = $pdo->prepare(
            "SELECT
                p.idPlaylist AS PlaylistId,
                p.NomPlaylist,
                p.Description,
                p.DateDerniereModification,
                p.NombreVue,
                p.Utilisateur,
                u.NomUtilisateur AS UtilisateurNom,
                COUNT(pm.IdMusique) AS TotalMusiques
             FROM Playlist p
             LEFT JOIN Utilisateurs u ON u.Id = p.Utilisateur
             LEFT JOIN MyPlaylistMusiques pm ON pm.IdPlaylist = p.idPlaylist
             WHERE p.Utilisateur = :userId
             GROUP BY p.idPlaylist, p.NomPlaylist, p.Description, p.DateDerniereModification, p.NombreVue, p.Utilisateur, u.NomUtilisateur
             ORDER BY p.DateDerniereModification DESC, p.NomPlaylist ASC"
        );
        $stmt->execute([':userId' => $currentUserId]);

        echo json_encode([
            'success' => true,
            'playlists' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

} elseif (!empty($_GET['playlistSongs'])) {

    try {
        $playlistId = trim((string) ($_GET['id'] ?? ''));
        if ($playlistId === '') {
            throw new RuntimeException('Id de playlist requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);
        ensure_playlists_tables($pdo);

        $stmt = $pdo->prepare(
            'SELECT
                m.Id,
                m.Titre,
                m.Artiste,
                m.Utilisateur,
                m.Album,
                m.Duree,
                m.AnneeParution,
                m.Genre,
                m.NombreVue,
                m.NombreVueInterne,
                m.DateAjout,
                pm.PositionLecture
                 FROM MyPlaylistMusiques pm
             INNER JOIN Musiques m ON m.Id = pm.IdMusique
             WHERE pm.IdPlaylist = :playlistId
             ORDER BY pm.PositionLecture ASC, m.Titre ASC'
        );
        $stmt->execute([':playlistId' => $playlistId]);
        $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'playlistId' => $playlistId,
            'songs' => $songs,
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

        $pdo = get_database_pdo();
        ensure_music_table($pdo);
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();

        $playlistStmt = $pdo->prepare(
            'SELECT
                p.idPlaylist AS PlaylistId,
                p.NomPlaylist,
                p.Description,
                p.DateDerniereModification,
                p.NombreVue,
                p.Utilisateur,
                u.NomUtilisateur AS UtilisateurNom
             FROM Playlist p
             LEFT JOIN Utilisateurs u ON u.Id = p.Utilisateur
             WHERE p.idPlaylist = :playlistId
             LIMIT 1'
        );
        $playlistStmt->execute([':playlistId' => $playlistId]);
        $playlist = $playlistStmt->fetch(PDO::FETCH_ASSOC);

        if ($playlist === false) {
            throw new RuntimeException('Playlist introuvable');
        }

        if ((int) $playlist['Utilisateur'] !== $currentUserId) {
            throw new RuntimeException('Edition non autorisee pour cette playlist');
        }

        $songsStmt = $pdo->prepare(
            'SELECT
                m.Id,
                m.Titre,
                m.Artiste,
                m.Album,
                m.Duree,
                m.NombreVue,
                m.NombreVueInterne,
                pm.PositionLecture
             FROM MyPlaylistMusiques pm
             INNER JOIN Musiques m ON m.Id = pm.IdMusique
             WHERE pm.IdPlaylist = :playlistId
             ORDER BY pm.PositionLecture ASC, m.Titre ASC'
        );
        $songsStmt->execute([':playlistId' => $playlistId]);

        echo json_encode([
            'success' => true,
            'playlist' => $playlist,
            'songs' => $songsStmt->fetchAll(PDO::FETCH_ASSOC),
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

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();
        $ownerStmt = $pdo->prepare(
            'SELECT idPlaylist
             FROM Playlist
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId
             LIMIT 1'
        );
        $ownerStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        if ($ownerStmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

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

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();
        $ownerStmt = $pdo->prepare(
            'SELECT idPlaylist
             FROM Playlist
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId
             LIMIT 1'
        );
        $ownerStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        if ($ownerStmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

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
        $positionsStmt = $pdo->prepare(
            'SELECT IdMusique, PositionLecture
             FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId
             ORDER BY PositionLecture ASC, IdMusique ASC'
        );
        $positionsStmt->execute([':playlistId' => $playlistId]);
        $rows = $positionsStmt->fetchAll(PDO::FETCH_ASSOC);

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

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();
        $ownerStmt = $pdo->prepare(
            'SELECT idPlaylist
             FROM Playlist
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId
             LIMIT 1'
        );
        $ownerStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        if ($ownerStmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $existingStmt = $pdo->prepare(
            'SELECT IdMusique
             FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId'
        );
        $existingStmt->execute([':playlistId' => $playlistId]);
        $existingIds = array_map(static function ($value) {
            return trim((string) $value);
        }, $existingStmt->fetchAll(PDO::FETCH_COLUMN));

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

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();
        $ownerStmt = $pdo->prepare(
            'SELECT idPlaylist
             FROM Playlist
             WHERE idPlaylist = :playlistId
               AND Utilisateur = :userId
             LIMIT 1'
        );
        $ownerStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        if ($ownerStmt->fetch(PDO::FETCH_ASSOC) === false) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

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

        $pdo = get_database_pdo();
        ensure_playlists_tables($pdo);

        $currentUserId = resolve_current_user_id();
        $ownerStmt = $pdo->prepare(
            'SELECT idPlaylist, NomPlaylist
             FROM Playlist
             WHERE idPlaylist = :playlistId AND Utilisateur = :userId
             LIMIT 1'
        );
        $ownerStmt->execute([
            ':playlistId' => $playlistId,
            ':userId' => $currentUserId,
        ]);

        $playlist = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        if ($playlist === false) {
            throw new RuntimeException('Playlist introuvable ou non autorisee');
        }

        $existingStmt = $pdo->prepare(
            'SELECT PositionLecture
             FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId AND IdMusique = :musicId
             LIMIT 1'
        );
        $existingStmt->execute([
            ':playlistId' => $playlistId,
            ':musicId' => $musicId,
        ]);

        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
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

        $positionStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(PositionLecture), 0) + 1 AS NextPosition
             FROM MyPlaylistMusiques
             WHERE IdPlaylist = :playlistId'
        );
        $positionStmt->execute([':playlistId' => $playlistId]);
        $nextPosition = (int) ($positionStmt->fetch(PDO::FETCH_ASSOC)['NextPosition'] ?? 1);

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

} elseif (!empty($_GET['videoId'])) {

    try {
        $videoId = trim((string) $_GET['videoId']);
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        echo json_encode(
            $yt->playlist($videoId),
            JSON_UNESCAPED_UNICODE
        );
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

} elseif (!empty($_GET['artists'])) {

    try {
        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $stmt = $pdo->query(
            "SELECT
                Artiste,
                COUNT(*) AS TotalMusiques
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
                m.Id,
                m.Titre,
                m.Artiste,
                m.Utilisateur,
                u.Id AS UtilisateurId,
                m.Album,
                m.Duree,
                m.AnneeParution,
                m.Genre,
                m.NombreVue,
                m.NombreVueInterne,
                m.DateAjout
             FROM Musiques m
             LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
             WHERE m.Id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $music = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($music === false && $title !== '') {
            if ($artist !== '') {
                $fallbackStmt = $pdo->prepare(
                    'SELECT
                        m.Id,
                        m.Titre,
                        m.Artiste,
                        m.Utilisateur,
                        u.Id AS UtilisateurId,
                        m.Album,
                        m.Duree,
                        m.AnneeParution,
                        m.Genre,
                        m.NombreVue,
                        m.NombreVueInterne,
                        m.DateAjout
                     FROM Musiques m
                     LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
                     WHERE m.Titre = :title AND m.Artiste = :artist
                     ORDER BY m.DateAjout DESC
                     LIMIT 1'
                );
                $fallbackStmt->execute([
                    ':title' => $title,
                    ':artist' => $artist,
                ]);
            } else {
                $fallbackStmt = $pdo->prepare(
                    'SELECT
                        m.Id,
                        m.Titre,
                        m.Artiste,
                        m.Utilisateur,
                        u.Id AS UtilisateurId,
                        m.Album,
                        m.Duree,
                        m.AnneeParution,
                        m.Genre,
                        m.NombreVue,
                        m.NombreVueInterne,
                        m.DateAjout
                     FROM Musiques m
                     LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
                     WHERE m.Titre = :title
                     ORDER BY m.DateAjout DESC
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
                    'UtilisateurId' => null,
                    'Album' => null,
                    'Duree' => null,
                    'AnneeParution' => null,
                    'Genre' => null,
                    'NombreVue' => null,
                    'NombreVueInterne' => null,
                    'DateAjout' => null,
                ],
                'playlists' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $musicPlaylists = get_music_playlists($pdo, (string) ($music['Id'] ?? ''));

        echo json_encode([
            'success' => true,
            'found' => true,
            'music' => $music,
            'playlists' => $musicPlaylists,
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
                COALESCE(SUM(NombreVue), 0) AS TotalVues
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

        $sortableColumns = [
            'Id' => 'Id',
            'Titre' => 'Titre',
            'Artiste' => 'Artiste',
            'Utilisateur' => 'Utilisateur',
            'Album' => 'Album',
            'Duree' => 'Duree',
            'AnneeParution' => 'AnneeParution',
            'Genre' => 'Genre',
            'NombreVue' => 'NombreVue',
            'NombreVueInterne' => 'NombreVueInterne',
            'DateAjout' => 'DateAjout',
        ];

        $sortByInput = trim((string) ($_GET['sortBy'] ?? 'DateAjout'));
        $sortDirInput = strtolower(trim((string) ($_GET['sortDir'] ?? 'desc')));
        $pageInput = (int) ($_GET['page'] ?? 1);
        $perPageInput = (int) ($_GET['perPage'] ?? 50);

        $sortBy = $sortableColumns[$sortByInput] ?? 'DateAjout';
        $sortDir = $sortDirInput === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, $pageInput);
        $perPage = max(1, min(200, $perPageInput));

        $countStmt = $pdo->query('SELECT COUNT(*) AS Total FROM Musiques');
        $totalRows = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $query = "SELECT
                     m.Id,
                     m.Titre,
                     m.Artiste,
                     m.Utilisateur,
                     u.Id AS UtilisateurId,
                     m.Album,
                     m.Duree,
                     m.AnneeParution,
                     m.Genre,
                     m.NombreVue,
                     m.NombreVueInterne,
                     m.DateAjout
                 FROM Musiques m
                 LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
                 ORDER BY m.{$sortBy} {$sortDir}, m.Titre ASC
             LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $musiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'musiques' => $musiques,
            'sortBy' => $sortBy,
            'sortDir' => strtolower($sortDir),
            'page' => $page,
            'perPage' => $perPage,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,
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

} elseif (!empty($_GET['deleteMusic']) || !empty($_POST['deleteMusic'])) {

    try {
        $payload = array_merge($_GET, $_POST);
        $id = trim((string) ($payload['Id'] ?? $payload['id'] ?? ''));

        if ($id === '') {
            throw new RuntimeException('Id requis');
        }

        $pdo = get_database_pdo();
        ensure_music_table($pdo);

        $existsStmt = $pdo->prepare('SELECT Id FROM Musiques WHERE Id = :id LIMIT 1');
        $existsStmt->execute([':id' => $id]);
        if ($existsStmt->fetch(PDO::FETCH_ASSOC) === false) {
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

        echo json_encode([
            'success' => true,
            'message' => 'Musique supprimee',
            'Id' => $id,
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

} else {

    echo json_encode([
        'success' => false,
        'error' => 'query ou videoId requis'
    ]);
}