<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/YouTubeMusic.php';
require_once '../../php/database_interface.php';
require_once '../../php/tools/recherche.php';
require_once '../../php/database/interface_Musiques.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_GET['latest_musiques'])) {
    try {
        dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue', 'DateAjout'],
            'orderBy' => 'DateAjout',
            'order' => 'DESC',
            'limit' => 5,
            'page' => 1
        ]);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (!empty($_GET['search']) && !empty($_GET['titleQuery'])) {
    try {
        dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Duree', 'NombreVue', 'DateAjout'],
            'orderBy' => 'Titre',
            'order' => 'ASC',
            'limit' => 20,
            'page' => 1,
            'search' => [
                'field' => 'Titre',
                'value' => $_GET['titleQuery']
            ]
        ]);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}