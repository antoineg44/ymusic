<?php

/**
 * Interface pour interagir avec la table MyPlaylistMusiques de la base de données.
 * $options = [
    'select' => ['IdPlaylist', 'IdMusique'], // Champs à retourner
    'count' => 1,                             // compter le nombre de chaque résultat
    'groupBy' => 'IdPlaylist',                // Champ de group
    'orderBy' => 'PositionLecture',           // Champ de tri
    'order' => 'ASC',                         // ASC ou DESC
    'limit' => 20,                            // Nombre maximum de résultats
    'page' => 1,                              // Page à récupérer
    'search' => [                             // Facultatif
        'field' => 'IdMusique',
        'value' => 'abc123'
    ],
    'equals' => [
        'IdPlaylist' => 42,
        'IdMusique' => 'abc123'
    ],
    'withPlaylistDetails' => true // ajoute les jointures Playlist + Utilisateurs
];
 */
function dMyPlaylistMusiques_get(array $options)
{

    $pdo = get_database_pdo();
    ensure_playlists_tables($pdo);

    $withPlaylistDetails = !empty($options['withPlaylistDetails']);

    $fromSql = $withPlaylistDetails
        ? 'MyPlaylistMusiques pm INNER JOIN Playlist p ON p.idPlaylist = pm.IdPlaylist LEFT JOIN Utilisateurs u ON u.Id = p.Utilisateur'
        : 'MyPlaylistMusiques';

    $selectMap = $withPlaylistDetails
        ? [
            'IdPlaylist' => 'pm.IdPlaylist',
            'IdMusique' => 'pm.IdMusique',
            'PositionLecture' => 'pm.PositionLecture',
            'PlaylistId' => 'p.idPlaylist AS PlaylistId',
            'NomPlaylist' => 'p.NomPlaylist',
            'Description' => 'p.Description',
            'Utilisateur' => 'p.Utilisateur',
            'UtilisateurNom' => 'COALESCE(u.NomUtilisateur, "") AS UtilisateurNom',
        ]
        : [
            'IdPlaylist' => 'IdPlaylist',
            'IdMusique' => 'IdMusique',
            'PositionLecture' => 'PositionLecture',
        ];

    $filterMap = $withPlaylistDetails
        ? [
            'IdPlaylist' => 'pm.IdPlaylist',
            'IdMusique' => 'pm.IdMusique',
            'PositionLecture' => 'pm.PositionLecture',
            'PlaylistId' => 'p.idPlaylist',
            'NomPlaylist' => 'p.NomPlaylist',
            'Description' => 'p.Description',
            'Utilisateur' => 'p.Utilisateur',
            'UtilisateurNom' => 'u.NomUtilisateur',
        ]
        : [
            'IdPlaylist' => 'IdPlaylist',
            'IdMusique' => 'IdMusique',
            'PositionLecture' => 'PositionLecture',
        ];

    $champsAutorises = array_keys($filterMap);

    // Champs à sélectionner
    $select = '*';
    if (!empty($options['select'])) {
        $selectFields = array_intersect($options['select'], array_keys($selectMap));
        if (empty($selectFields)) {
            throw new InvalidArgumentException('Aucun champ valide à sélectionner.');
        }
        $selectParts = [];
        foreach ($selectFields as $field) {
            $selectParts[] = $selectMap[$field];
        }
        $select = implode(', ', $selectParts);
    }

    $sql = "SELECT $select";
    $queryParams = [];
    $whereParts = [];

    // Count
    if (!empty($options['count'])) {
        $sql .= ", COUNT(*) AS TotalMyPlaylistMusiques";
    }

    $sql .= " FROM $fromSql";

    // Recherche textuelle
    if (!empty($options['search'])) {
        $field = $options['search']['field'] ?? '';

        if (!in_array($field, $champsAutorises, true)) {
            throw new InvalidArgumentException('Champ de recherche invalide.');
        }

        $searchValue = (string) ($options['search']['value'] ?? '');
        $normalizedSearch = function_exists('mb_strtolower')
            ? mb_strtolower($searchValue, 'UTF-8')
            : strtolower($searchValue);
        $normalizedSearch = strtr($normalizedSearch, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        ]);
        $normalizedSearch = (string) preg_replace('/[[:punct:]\s]+/u', '', $normalizedSearch);

        if ($normalizedSearch === '') {
            $whereParts[] = '1 = 0';
        } else {
            $columnExpression = build_title_search_sql($filterMap[$field]);
            $whereParts[] = "$columnExpression LIKE :search";
            $queryParams[':search'] = '%' . $normalizedSearch . '%';
        }
    }

    // Recherche par égalité
    if (!empty($options['equals'])) {

        $conditions = [];

        foreach ($options['equals'] as $field => $value) {

            if (!in_array($field, $champsAutorises, true)) {
                throw new InvalidArgumentException("Champ '$field' invalide.");
            }

            $param = ':eq_' . $field;
            $conditions[] = "{$filterMap[$field]} = $param";
            $queryParams[$param] = $value;
        }

        if (!empty($conditions)) {
            $whereParts = array_merge($whereParts, $conditions);
        }
    }

    if (!empty($whereParts)) {
        $sql .= ' WHERE ' . implode(' AND ', $whereParts);
    }

    // Group
    if (!empty($options['groupBy'])) {
        $groupByField = (string) $options['groupBy'];
        if (!array_key_exists($groupByField, $filterMap)) {
            throw new InvalidArgumentException('Champ de tri invalide.');
        }

        $sql .= " GROUP BY {$filterMap[$groupByField]}";
    }

    // Tri
    if (!empty($options['orderBy'])) {
        $orderByField = (string) $options['orderBy'];
        if (!array_key_exists($orderByField, $filterMap)) {
            throw new InvalidArgumentException('Champ de tri invalide.');
        }

        $order = strtoupper($options['order'] ?? 'ASC');
        $order = ($order === 'DESC') ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$filterMap[$orderByField]} $order";
    }

    // Limite + offset
    $options['page'] = max(1, (int) ($options['page'] ?? 1));

    if (!empty($options['limit'])) {
        $sql .= " LIMIT " . (int)$options['limit'];

        $sql .= " OFFSET " . ((int)$options['page'] - 1) * (int)$options['limit'];
    } else {
        $sql .= " LIMIT 50"; // Valeur par défaut
        $options['limit'] = 50;
        $options['page'] = 1;
    }

    $stmt = $pdo->prepare($sql);
    foreach ($queryParams as $paramName => $paramValue) {
        $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
    }
    $stmt->execute();
    $myPlaylistMusiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*) AS Total FROM $fromSql";
    if (!empty($whereParts)) {
        $countSql .= " WHERE " . implode(' AND ', $whereParts);
    }

    $countStmt = $pdo->prepare($countSql);
    foreach ($queryParams as $paramName => $paramValue) {
        $countStmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRows = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
    $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $options['limit']) : 1;
    if ($options['page'] > $totalPages) {
        $options['page'] = $totalPages;
    }

    return[
        'success' => true,
        'myPlaylistMusiques' => $myPlaylistMusiques,
        'sortBy' => $options['orderBy'] ?? null,
        'sortDir' => $options['order'] ?? null,
        'page' => $options['page'] ?? 1,
        'perPage' => $options['limit'] ?? 50,
        'titleQuery' => $options['search']['value'] ?? null,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
        'query' => [
            'withPlaylistDetails' => $withPlaylistDetails,
        ],
    ];
}