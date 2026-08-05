<?php

/**
 * Interface pour interagir avec la table MyPlaylistMusiques de la base de données.
 * $options = [
    
];
 */
function dMyPlaylistMusiques_get(array $options)
{

    $pdo = get_database_pdo();
    ensure_playlists_tables($pdo);

    
}