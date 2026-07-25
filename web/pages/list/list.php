<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/yt/YouTubeMusic.php';
require_once '../../php/database_interface.php';
require_once '../../php/database/interface_Musiques.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_GET['musiques'])) {
    try {
        echo json_encode(dMusique_get([
            'select' => ['Id', 'Titre', 'Artiste', 'Utilisateur', 'Album', 'Duree', 'AnneeParution', 'Genre', 'NombreVue', 'NombreVueInterne', 'DateAjout'],
            'orderBy' => $_GET['sortBy'] ?? 'Titre',
            'order' => $_GET['sortDir'] ?? 'ASC',
            'limit' => $_GET['perPage'] ?? 20,
            'page' => $_GET['page'] ?? 1,
        ]),JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}