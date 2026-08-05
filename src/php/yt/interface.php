<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require 'YouTubeMusic.php';
require_once '../database_interface.php';
require_once '../tools/recherche.php';
require_once '../database/interface_Musiques.php';
require_once '../files/manager.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_GET['suggestions'])) {
    try {
        $query = trim((string) $_GET['suggestions']);
        if ($query === '') {
            throw new RuntimeException('Requete de suggestion vide');
        }

        $yt = new YouTubeMusic();

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
}

if (!empty($_GET['query'])) {
    try {
        $query = trim((string) $_GET['query']);
        if ($query === '') {
            throw new RuntimeException('Requete de recherche vide');
        }

        $yt = new YouTubeMusic();

        $res = $yt->search($query);

        // Vérifie si chaque musique de la recherche est déjà présente dans la base de données
        foreach ($res['results'] as &$element) {

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
}

if (!empty($_GET['search']) && !empty($_GET['Id'])) {
    try {
        echo json_encode(dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue'],
            'equals' => [
                'Id' => $_GET['Id'],
            ]
        ]), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

if(!empty($_GET['description'])) {
    try {
        $yt = new YouTubeMusic();
        $res = $yt->songDetails($_GET['description']);

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
        $db['musiques'][0] = $payload;

        echo json_encode($db, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (!empty($_GET['download'])) {
    try {
        $musicId = trim((string) $_GET['download']);
        if ($musicId === '') {
            throw new RuntimeException('musicId requis');
        }

        // Vérifie si la musique est déjà présente dans la base de données
        $db = dMusique_get([
            'select' => ['Id', 'Titre'],
            'equals' => [
                'Id' => $musicId,
            ],
            'limit' => 1
        ]);

        $yt = new YouTubeMusic();

        if (!empty($db['musiques'])) {
            $existingFile = find_downloaded_file_for_music_id($musicId);

            if ($existingFile === null) {
                $result = $yt->download($musicId);

                echo json_encode([
                    'success' => true,
                    'download' => $result,
                    'music' => $db,
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
                    'path' => "../../" . $existingFile['path'],
                ],
                'music' => $db,
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
}

if (!empty($_GET['playlistQuery'])) {
    try {
        $query = trim((string) $_GET['playlistQuery']);
        if ($query === '') {
            throw new RuntimeException('Requete de playlist vide');
        }

        $yt = new YouTubeMusic();

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
}

if (!empty($_GET['playlistItems'])) {
    try {
        $playlistId = trim((string) ($_GET['id'] ?? ''));
        if ($playlistId === '') {
            throw new RuntimeException('Id de playlist requis');
        }

        $yt = new YouTubeMusic();

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
}

if (!empty($_GET['musicMetadata'])) {
    try {
        $videoId = trim((string) ($_GET['id'] ?? $_GET['videoId'] ?? ''));
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        $yt = new YouTubeMusic();

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
}

if (!empty($_GET['musicId'])) {
    try {
        $musicId = trim((string) $_GET['musicId']);
        if ($musicId === '') {
            throw new RuntimeException('musicId requis');
        }

        $_GET['download'] = $musicId;

        $db = dMusique_get([
            'select' => ['Id', 'Titre'],
            'equals' => [
                'Id' => $musicId,
            ],
            'limit' => 1
        ]);

        $yt = new YouTubeMusic();

        if (!empty($db['musiques'])) {
            $existingFile = find_downloaded_file_for_music_id($musicId);

            if ($existingFile === null) {
                $result = $yt->download($musicId);

                echo json_encode([
                    'success' => true,
                    'download' => $result,
                    'music' => $db,
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
                'music' => $db,
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
}