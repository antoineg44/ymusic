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

if (!empty($_GET['add'])) {
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

        $yt = new YouTubeMusic();

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
}
