<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../yt/YouTubeMusic.php';
require_once '../database_interface.php';
require_once '../tools/recherche.php';
require_once 'interface_Musiques.php';
require_once 'interface_Playlist.php';
require_once 'interface_MyPlaylistMusiques.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['requete'])) {
    try {
        $jsonStr = urldecode($_GET['requete']);
        $structure = json_decode($jsonStr, true);

        if($structure['table'] == "Musiques")
        {
            echo json_encode(dMusique_get($structure),JSON_UNESCAPED_UNICODE);
        }
        else if($structure['table'] == "Playlist")
        {
            echo json_encode(dPlaylist_get($structure),JSON_UNESCAPED_UNICODE);
        }
        else if($structure['table'] == "MyPlaylistMusiques")
        {
            echo json_encode(dMyPlaylistMusiques_get($structure),JSON_UNESCAPED_UNICODE);
        }
        else
        {
             echo json_encode([
                'success' => false,
                'error' => "Unknow table",
            ], JSON_UNESCAPED_UNICODE);
        }

        
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
