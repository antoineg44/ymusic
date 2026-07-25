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

if (!empty($_GET['next'])) {
    try {
        $videoId = trim((string) $_GET['next']);
        if ($videoId === '') {
            throw new RuntimeException('videoId requis');
        }

        $yt = new YouTubeMusic();
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
}
