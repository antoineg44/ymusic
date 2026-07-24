<?php

function get()
{
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
}