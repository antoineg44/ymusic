<?php

/**
 * Interface pour interagir avec la table Musiques de la base de données.
 * $options = [
    'select' => ['Titre', 'Artiste'],   // Champs à retourner
    'count' => 1,                       // compter le nombre de chaque résultat
    'groupBy' => 'Artiste',             // Champ de group
    'orderBy' => 'NombreVue',           // Champ de tri
    'order' => 'DESC',                  // ASC ou DESC
    'limit' => 20,                      // Nombre maximum de résultats
    'page' => 1,                        // Page à récupérer
    'search' => [                       // Facultatif
        'field' => 'Titre',
        'value' => 'love'
    ],
    'equals' => [
        'Id' => 42,
        'Utilisateur' => 'john',
        'Genre' => 'Rock'
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

    $sql = "SELECT $select"; //LEFT JOIN Utilisateurs u ON u.NomUtilisateur = m.Utilisateur
    $queryParams = [];
    $searchWhereClause = '';

    // Count
    if (!empty($options['count'])) {
        $sql .= ", COUNT(*) AS TotalMusiques";
    }

    $sql .= " FROM Musiques";

    // Recherche textuelle
    if (!empty($options['search'])) {
        $field = $options['search']['field'] ?? '';

        if (!in_array($field, $champsAutorises, true)) {
            throw new InvalidArgumentException('Champ de recherche invalide.');
        }

        $research_param = remove_accent_and_ponctuation(
            (string) ($options['search']['value'] ?? ''),
            (string) ($field)
        );

        $searchWhereClause = $research_param["whereClause"];
        $sql .= ' ' . $searchWhereClause;
        $queryParams[':search'] = '%' . $research_param["queryParams"] . '%';
    }

    // Recherche par égalité
    if (!empty($options['equals'])) {

        $conditions = [];

        foreach ($options['equals'] as $field => $value) {

            if (!in_array($field, $champsAutorises, true)) {
                throw new InvalidArgumentException("Champ '$field' invalide.");
            }

            $param = ':eq_' . $field;
            $conditions[] = "$field = $param";
            $queryParams[$param] = $value;
        }

        if (!empty($conditions)) {
            $sql .= ($searchWhereClause === '' ? ' WHERE ' : ' AND ');
            $sql .= implode(' AND ', $conditions);
        }
    }

    // Group
    if (!empty($options['groupBy'])) {
        if (!in_array($options['groupBy'], $champsAutorises, true)) {
            throw new InvalidArgumentException('Champ de tri invalide.');
        }

        $sql .= " GROUP BY {$options['groupBy']}";
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

    $countSql = "SELECT COUNT(*) AS Total FROM Musiques";

    $where = [];

    if (!empty($options['search'])) {
        $where[] = substr($searchWhereClause, strlen('WHERE '));
    }

    if (!empty($options['equals'])) {
        foreach ($options['equals'] as $field => $value) {
            $where[] = "$field = :eq_$field";
        }
    }

    if (!empty($where)) {
        $countSql .= " WHERE " . implode(' AND ', $where);
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
        'musiques' => $musiques,
        'sortBy' => $options['orderBy'] ?? null,
        'sortDir' => $options['order'] ?? null,
        'page' => $options['page'] ?? 1,
        'perPage' => $options['limit'] ?? 50,
        'titleQuery' => $options['search']['value'] ?? null,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
    ];
}