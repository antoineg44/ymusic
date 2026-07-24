<?php

/**
 * Interface pour interagir avec la table Musiques de la base de données.
 * $options = [
    'select' => ['Titre', 'Artiste'],   // Champs à retourner
    'orderBy' => 'NombreVue',           // Champ de tri
    'order' => 'DESC',                  // ASC ou DESC
    'limit' => 20,                      // Nombre maximum de résultats
    'page' => 1,                        // Page à récupérer
    'search' => [                       // Facultatif
        'field' => 'Titre',
        'value' => 'love'
    ]
];
 */
function dMusique_get(array $options)
{

    $pdo = get_database_pdo();
    ensure_music_table($pdo);

    $champsAutorises = [
        'Id',
        'Titre',
        'Artiste',
        'Utilisateur',
        'Album',
        'Duree',
        'AnneeParution',
        'Genre',
        'NombreVue',
        'NombreVueInterne',
        'DateAjout'
    ];

    // Champs à sélectionner
    $select = '*';
    if (!empty($options['select'])) {
        $select = array_intersect($options['select'], $champsAutorises);
        if (empty($select)) {
            throw new InvalidArgumentException('Aucun champ valide à sélectionner.');
        }
        $select = implode(', ', $select);
    }

    $sql = "SELECT $select FROM Musiques"; //LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
    $queryParams = [];
    $searchWhereClause = '';

    // Recherche
    if (!empty($options['search'])) {
        $field = $options['search']['field'] ?? '';

        if (!in_array($field, $champsAutorises, true)) {
            throw new InvalidArgumentException('Champ de recherche invalide.');
        }

        $research_param = remove_accent_and_ponctuation($options['search']['value']);

        $searchWhereClause = $research_param["whereClause"];
        $sql .= ' ' . $searchWhereClause;
        $queryParams[':search'] = '%' . $research_param["queryParams"] . '%';
    }

    // Tri
    if (!empty($options['orderBy'])) {
        if (!in_array($options['orderBy'], $champsAutorises, true)) {
            throw new InvalidArgumentException('Champ de tri invalide.');
        }

        $order = strtoupper($options['order'] ?? 'ASC');
        $order = ($order === 'DESC') ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$options['orderBy']} $order";
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
    $musiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = 'SELECT COUNT(*) AS Total FROM Musiques';
    if ($searchWhereClause !== '') {
        $countSql .= ' ' . $searchWhereClause;
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

    echo json_encode([
        'success' => true,
        'musiques' => $musiques,
        'sortBy' => $options['orderBy'] ?? null,
        'sortDir' => $options['order'] ?? null,
        'page' => $options['page'] ?? 1,
        'perPage' => $options['limit'] ?? 50,
        'titleQuery' => $options['search']['value'] ?? null,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
    ], JSON_UNESCAPED_UNICODE);

    return true;
}