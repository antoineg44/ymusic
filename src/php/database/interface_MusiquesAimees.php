<?php

/**
 * Interface pour interagir avec la table MusiquesAimees de la base de donnees.
 * $options = [
 *     'select' => ['IdUtilisateur', 'IdMusique'], // Champs a retourner
 *     'count' => 1,                                // compter le nombre de resultats
 *     'groupBy' => 'IdUtilisateur',                // Champ de group
 *     'orderBy' => 'DateAjout',                    // Champ de tri simple
 *     'order' => 'DESC',                           // ASC ou DESC
 *     'limit' => 20,                               // Nombre maximum de resultats
 *     'page' => 1,                                 // Page a recuperer
 *     'equals' => [
 *         'IdUtilisateur' => 42,
 *         'IdMusique' => 'abc123'
 *     ],
 *     'withMusicDetails' => true // ajoute la jointure Musiques
 * ];
 */
function dMusiqueAimee_get(array $options)
{

    $pdo = get_database_pdo();
    ensure_liked_musics_table($pdo);

    $withMusicDetails = !empty($options['withMusicDetails']);

    $fromSql = 'MusiquesAimees ma';
    if ($withMusicDetails) {
        $fromSql .= ' INNER JOIN Musiques m ON m.Id = ma.IdMusique';
    }

    $selectMap = [
        'IdUtilisateur' => 'ma.IdUtilisateur',
        'IdMusique' => 'ma.IdMusique',
        'DateAjout' => 'ma.DateAjout',
    ];

    $filterMap = [
        'IdUtilisateur' => 'ma.IdUtilisateur',
        'IdMusique' => 'ma.IdMusique',
        'DateAjout' => 'ma.DateAjout',
    ];

    if ($withMusicDetails) {
        $selectMap = array_merge($selectMap, [
            'Id' => 'm.Id AS Id',
            'Titre' => 'm.Titre',
            'Artiste' => 'm.Artiste',
            'Utilisateur' => 'm.Utilisateur AS Utilisateur',
            'Album' => 'm.Album',
            'Duree' => 'm.Duree',
            'AnneeParution' => 'm.AnneeParution',
            'Genre' => 'm.Genre',
            'NombreVue' => 'm.NombreVue',
            'NombreVueInterne' => 'm.NombreVueInterne',
        ]);

        $filterMap = array_merge($filterMap, [
            'Id' => 'm.Id',
            'Titre' => 'm.Titre',
            'Artiste' => 'm.Artiste',
            'Utilisateur' => 'm.Utilisateur',
            'Album' => 'm.Album',
            'Duree' => 'm.Duree',
            'AnneeParution' => 'm.AnneeParution',
            'Genre' => 'm.Genre',
            'NombreVue' => 'm.NombreVue',
            'NombreVueInterne' => 'm.NombreVueInterne',
        ]);
    }

    $champsAutorises = array_keys($filterMap);

    // Champs a selectionner
    $select = '*';
    if (!empty($options['select'])) {
        $selectFields = array_intersect($options['select'], array_keys($selectMap));
        if (empty($selectFields)) {
            throw new InvalidArgumentException('Aucun champ valide a selectionner.');
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
        $sql .= ", COUNT(*) AS TotalMusiquesAimees";
    }

    $sql .= " FROM $fromSql";

    // Recherche par egalite
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
        $sql .= " LIMIT 50"; // Valeur par defaut
        $options['limit'] = 50;
        $options['page'] = 1;
    }

    $stmt = $pdo->prepare($sql);
    foreach ($queryParams as $paramName => $paramValue) {
        $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
    }
    $stmt->execute();
    $musiquesAimees = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        'musiquesAimees' => $musiquesAimees,
        'sortBy' => $options['orderBy'] ?? null,
        'sortDir' => $options['order'] ?? null,
        'page' => $options['page'] ?? 1,
        'perPage' => $options['limit'] ?? 50,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
        'query' => [
            'withMusicDetails' => $withMusicDetails,
        ],
    ];
}
