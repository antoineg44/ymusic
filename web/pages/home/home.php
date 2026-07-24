<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/YouTubeMusic.php';
require_once '../../php/database_interface.php';
require_once '../../php/tools/recherche.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_GET['latest_musiques'])) {
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
        $titleQueryInput = trim((string) ($_GET['titleQuery'] ?? ''));

        $sortBy = $sortableColumns[$sortByInput] ?? 'DateAjout';
        $sortDir = $sortDirInput === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, $pageInput);
        $perPage = max(1, min(200, $perPageInput));

        $whereClause = '';
        $queryParams = [];

        $research_param = remove_accent_and_ponctuation($titleQueryInput);
        $whereClause = $research_param["whereClause"];
        $queryParams[':titleQueryNormalized'] = $research_param["queryParams"];


        $countQuery = "SELECT COUNT(*) AS Total FROM Musiques m {$whereClause}";
        $countStmt = $pdo->prepare($countQuery);
        foreach ($queryParams as $paramName => $paramValue) {
            $countStmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalRows = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $query = "SELECT
                     m.Id,
                     m.Titre,
                     m.Artiste,
                     m.Utilisateur,
                     u.Id AS UtilisateurId,
                     m.Album,
                     m.Duree,
                     m.AnneeParution,
                     m.Genre,
                     m.NombreVue,
                     m.NombreVueInterne,
                     m.DateAjout
                 FROM Musiques m
                 LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
                 {$whereClause}
                 ORDER BY m.{$sortBy} {$sortDir}, m.Titre ASC
             LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($query);
        foreach ($queryParams as $paramName => $paramValue) {
            $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
        }
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
            'titleQuery' => $titleQueryInput,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}