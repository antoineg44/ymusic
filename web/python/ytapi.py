#!/usr/bin/env python3

import sys
import json
from ytmusicapi import YTMusic

ytmusic = YTMusic()


def search(query, limit=10):
    results = ytmusic.search(
        query=query,
        filter="songs",
        limit=limit
    )

    data = []

    for item in results:
        data.append({
            "title": item.get("title"),
            "videoId": item.get("videoId"),
            "duration": item.get("duration"),
            "artists": [
                artist.get("name")
                for artist in item.get("artists", [])
            ],
            "album": {
                "name": item.get("album", {}).get("name"),
                "id": item.get("album", {}).get("id"),
            },
            "views": item.get("views")
        })

    return data


def search_playlists(query, limit=10):
    results = ytmusic.search(
        query=query,
        filter="playlists",
        limit=limit
    )

    data = []

    for item in results:
        author_name = item.get("author")

        data.append({
            "title": item.get("title"),
            "playlistId": item.get("browseId"),
            "author": author_name,
            "itemCount": item.get("itemCount"),
        })

    return data


def get_suggestions(query, limit=8):
    try:
        raw_items = ytmusic.get_search_suggestions(query)
    except Exception:
        return []

    suggestions = []

    if isinstance(raw_items, dict):
        raw_items = raw_items.get("suggestions") or raw_items.get("results") or []

    for item in raw_items[:limit]:
        if isinstance(item, str):
            value = item.strip()
            if value:
                suggestions.append(value)
        elif isinstance(item, dict):
            for key in ("suggestion", "title", "text", "query", "display"):
                value = item.get(key)
                if isinstance(value, str) and value.strip():
                    suggestions.append(value.strip())
                    break

    return list(dict.fromkeys(suggestions))[:limit]


def playlist(video_id):
    watch = ytmusic.get_watch_playlist(videoId=video_id)

    tracks = []

    for track in watch.get("tracks", []):
        tracks.append({
            "title": track.get("title"),
            "videoId": track.get("videoId"),
            "duration": track.get("duration"),
            "artists": [
                artist.get("name")
                for artist in track.get("artists", [])
            ]
        })

    return tracks


def playlist_items(playlist_id, limit=200):
    payload = ytmusic.get_playlist(playlist_id, limit=limit)

    tracks = []
    for track in payload.get("tracks", []) or []:
        if not isinstance(track, dict):
            continue

        tracks.append({
            "title": track.get("title"),
            "videoId": track.get("videoId"),
            "duration": track.get("duration"),
            "artists": [
                artist.get("name")
                for artist in track.get("artists", [])
                if isinstance(artist, dict)
            ]
        })

    return {
        "playlistId": playlist_id,
        "title": payload.get("title"),
        "author": (payload.get("author") or {}).get("name") if isinstance(payload.get("author"), dict) else payload.get("author"),
        "tracks": tracks,
    }


def get_song_details(video_id, title=None, artist=None):
    """Récupère les détails d'une chanson (durée, nombre de vues) par son videoId ou titre/artiste"""
    try:
        # Essayer d'abord avec l'ID vidéo
        song = ytmusic.get_song(video_id)
        
        # Extraire les informations pertinentes
        video_details = song.get("videoDetails", {})
        result = {
            "videoId": video_id,
            "title": video_details.get("title"),
            "duration": video_details.get("lengthSeconds"),
            "views": video_details.get("viewCount"),
        }
        
        return result
    except Exception:
        # Si get_song échoue, essayer de chercher par titre/artiste
        if title:
            try:
                # Construire une requête de recherche
                if artist:
                    query = f"{title} {artist}"
                else:
                    query = title
                
                results = search(query, limit=5)
                
                # Chercher la correspondance exacte ou la meilleure correspondance
                for result in results:
                    if result.get("videoId") == video_id:
                        return result
                
                # Si pas de correspondance exacte par ID, retourner la première
                if results:
                    return results[0]
            except Exception:
                pass
        
        raise Exception(f"Impossible de récupérer les détails de la chanson avec l'ID {video_id}")


try:

    action = sys.argv[1]

    if action == "search":

        query = sys.argv[2]

        print(json.dumps({
            "success": True,
            "results": search(query),
            "suggestions": get_suggestions(query)
        }))

    elif action == "playlist_search":

        query = sys.argv[2]

        print(json.dumps({
            "success": True,
            "results": search_playlists(query),
            "suggestions": get_suggestions(query)
        }))

    elif action == "playlist":

        video_id = sys.argv[2]

        print(json.dumps({
            "success": True,
            "playlist": playlist(video_id)
        }))

    elif action == "playlist_items":

        playlist_id = sys.argv[2]

        print(json.dumps({
            "success": True,
            "playlist": playlist_items(playlist_id)
        }))

    elif action == "get_song_details":

        video_id = sys.argv[2]
        title = sys.argv[3] if len(sys.argv) > 3 else None
        artist = sys.argv[4] if len(sys.argv) > 4 else None

        print(json.dumps({
            "success": True,
            "song": get_song_details(video_id, title, artist)
        }))

    else:

        print(json.dumps({
            "success": False,
            "error": "Action inconnue"
        }))

except Exception as e:

    print(json.dumps({
        "success": False,
        "error": str(e)
    }))