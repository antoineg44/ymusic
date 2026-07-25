<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/yt/YouTubeMusic.php';
require_once '../../php/database_interface.php';
require_once '../../php/database/interface_Musiques.php';
require_once '../../php/files/manager.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_GET['Id'])) {
    try {
        $db = dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Utilisateur', 'Album', 'Duree', 'AnneeParution', 'Genre', 'NombreVue', 'NombreVueInterne', 'DateAjout'],
            'equals' => [
                'Id' => $_GET['Id'],
            ]
        ]);

        if (empty($db['musiques'])) {
            $yt = new YouTubeMusic();
            $res = $yt->songDetails($_GET['Id']);

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
        }

        echo json_encode($db, JSON_UNESCAPED_UNICODE);

    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (!empty($_GET['Download'])) {
    try {
        $musicId = trim((string) $_GET['Download']);
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