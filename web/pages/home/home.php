<?php

// API principale: recherche, playlist, telechargement, metadonnees et routes artistes/albums.

require '../../php/YouTubeMusic.php';
require_once __DIR__ . '../../php/database_interface.php';

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
        if ($titleQueryInput !== '') {
            $normalizedTitleQueryInput = function_exists('mb_strtolower')
                ? mb_strtolower($titleQueryInput, 'UTF-8')
                : strtolower($titleQueryInput);
            $normalizedTitleQueryInput = strtr($normalizedTitleQueryInput, [
                'à' => 'a',
                'â' => 'a',
                'ä' => 'a',
                'á' => 'a',
                'ã' => 'a',
                'å' => 'a',
                'æ' => 'ae',
                'ç' => 'c',
                'è' => 'e',
                'é' => 'e',
                'ê' => 'e',
                'ë' => 'e',
                'ì' => 'i',
                'í' => 'i',
                'î' => 'i',
                'ï' => 'i',
                'ñ' => 'n',
                'ò' => 'o',
                'ó' => 'o',
                'ô' => 'o',
                'ö' => 'o',
                'õ' => 'o',
                'œ' => 'oe',
                'ù' => 'u',
                'ú' => 'u',
                'û' => 'u',
                'ü' => 'u',
                'ý' => 'y',
                'ÿ' => 'y',
            ]);
            $normalizedTitleQueryInput = (string) preg_replace('/[[:punct:]\s]+/u', '', $normalizedTitleQueryInput);

            // Normalise accents + ponctuation SQL pour recherche souple sur les titres.
            $normalizedTitleSql = 'LOWER(m.Titre)';
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'à', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'â', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ä', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'á', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ã', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'å', 'a')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'æ', 'ae')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ç', 'c')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'è', 'e')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'é', 'e')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ê', 'e')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ë', 'e')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ì', 'i')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'í', 'i')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'î', 'i')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ï', 'i')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ñ', 'n')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ò', 'o')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ó', 'o')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ô', 'o')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ö', 'o')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'õ', 'o')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'œ', 'oe')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ù', 'u')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ú', 'u')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'û', 'u')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ü', 'u')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ý', 'y')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, 'ÿ', 'y')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ' ', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '.', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ',', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ';', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ':', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '!', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '?', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '''', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '’', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, CHAR(34), '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '-', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '_', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '/', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '(', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ')', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '[', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, ']', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '{', '')";
            $normalizedTitleSql = "REPLACE({$normalizedTitleSql}, '}', '')";

            if ($normalizedTitleQueryInput === '') {
                $whereClause = 'WHERE 1 = 0';
            } else {
                $whereClause = "WHERE {$normalizedTitleSql} LIKE :titleQueryNormalized";
                $queryParams[':titleQueryNormalized'] = '%' . $normalizedTitleQueryInput . '%';
            }
        }

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