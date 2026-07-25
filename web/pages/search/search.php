<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/yt/YouTubeMusic.php';
require_once '../../php/database_interface.php';
require_once '../../php/tools/recherche.php';
require_once '../../php/database/interface_Musiques.php';

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