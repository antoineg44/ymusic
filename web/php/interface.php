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

    function resolve_playlist_table_and_columns(PDO $pdo): ?array
    {
        $playlistTable = null;
        if (table_exists($pdo, 'Playlist')) {
            $playlistTable = 'Playlist';
        } elseif (table_exists($pdo, 'PlaylistsYoutube')) {
            $playlistTable = 'PlaylistsYoutube';
        }

        if ($playlistTable === null) {
            return null;
        }

        $idColumn = find_first_existing_column($pdo, $playlistTable, ['IdPlaylist', 'PlaylistId', 'Id', 'id']);
        $nameColumn = find_first_existing_column($pdo, $playlistTable, ['NomPlaylist', 'Titre', 'PlaylistName', 'Nom', 'Name']);
        $creatorColumn = find_first_existing_column($pdo, $playlistTable, ['UtilisateurCreateur', 'Utilisateur', 'Createur', 'Auteur', 'Author']);
        $dateColumn = find_first_existing_column($pdo, $playlistTable, ['DateLecture', 'DateAjout', 'DateCreation', 'CreatedAt']);

        if ($idColumn === null || $nameColumn === null) {
            throw new RuntimeException('Structure de table playlist invalide');
        }

        return [
            'table' => $playlistTable,
            'id' => $idColumn,
            'name' => $nameColumn,
            'creator' => $creatorColumn,
            'date' => $dateColumn,
        ];
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

        $playlistMeta = resolve_playlist_table_and_columns($pdo);
        if ($playlistMeta === null) {
            echo json_encode([
                'success' => true,
                'playlists' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $playlistTable = $playlistMeta['table'];
        $idColumn = $playlistMeta['id'];
        $nameColumn = $playlistMeta['name'];
        $creatorColumn = $playlistMeta['creator'];
        $dateColumn = $playlistMeta['date'];

        $creatorSql = $creatorColumn !== null ? "p.{$creatorColumn}" : "''";
        $dateSql = $dateColumn !== null ? "p.{$dateColumn}" : 'NULL';

        $stmt = $pdo->query(
            "SELECT
                p.{$idColumn} AS PlaylistId,
                p.{$nameColumn} AS NomPlaylist,
                {$creatorSql} AS UtilisateurCreateur,
                {$dateSql} AS DateLecture,
                COUNT(pm.IdMusique) AS TotalMusiques
             FROM {$playlistTable} p
             LEFT JOIN PlaylistMusiques pm ON pm.IdPlaylist = p.{$idColumn}
             GROUP BY p.{$idColumn}, p.{$nameColumn}, {$creatorSql}, {$dateSql}
             ORDER BY {$dateSql} DESC, p.{$nameColumn} ASC"
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
             FROM PlaylistMusiques pm
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
             ORDER BY {$sortBy} {$sortDir}, Titre ASC
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

} elseif (!empty($_GET['currentUser'])) {

    try {
        if (!empty($_SESSION['user'])) {
            echo json_encode([
                'success' => true,
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