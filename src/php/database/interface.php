<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../yt/YouTubeMusic.php';
require_once '../database_interface.php';
require_once '../tools/recherche.php';
require_once 'interface_Musiques.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['requete'])) {
    try {
        $jsonStr = urldecode($_GET['requete']);
        $structure = json_decode($jsonStr, true);
        print_r($structure);

        echo json_encode(dMusique_get($structure),JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
