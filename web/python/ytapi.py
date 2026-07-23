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


def parse_duration_to_seconds(value):
    text = str(value or '').strip()
    if not text:
        return None

    parts = text.split(':')
    if not parts or any(not part.isdigit() for part in parts):
        return None

    seconds = 0
    for part in parts:
        seconds = (seconds * 60) + int(part)

    return seconds if seconds > 0 else None


def to_positive_int(value):
    text = str(value or '').strip()
    if not text:
        return None

    digits = ''.join(ch for ch in text if ch.isdigit())
    if not digits:
        return None

    number = int(digits)
    return number if number > 0 else None


def song_details(video_id):
    song = ytmusic.get_song(video_id)
    watch = ytmusic.get_watch_playlist(videoId=video_id)

    video_details = song.get('videoDetails', {}) if isinstance(song, dict) else {}
    microformat = (song.get('microformat', {}) or {}).get('microformatDataRenderer', {}) if isinstance(song, dict) else {}

    matched_track = None
    for track in (watch.get('tracks', []) if isinstance(watch, dict) else []):
        if not isinstance(track, dict):
            continue
        if str(track.get('videoId') or '').strip() == str(video_id).strip():
            matched_track = track
            break

    track_artists = []
    if isinstance(matched_track, dict):
        track_artists = [
            artist.get('name')
            for artist in (matched_track.get('artists', []) or [])
            if isinstance(artist, dict) and str(artist.get('name') or '').strip()
        ]

    title = str(video_details.get('title') or '').strip()
    if not title and isinstance(matched_track, dict):
        title = str(matched_track.get('title') or '').strip()

    artist = str(video_details.get('author') or '').strip()
    if not artist and track_artists:
        artist = str(track_artists[0] or '').strip()

    album = ''
    if isinstance(matched_track, dict):
        album_value = matched_track.get('album')
        if isinstance(album_value, dict):
            album = str(album_value.get('name') or '').strip()
        elif isinstance(album_value, str):
            album = album_value.strip()

    duration_seconds = to_positive_int(video_details.get('lengthSeconds'))
    if duration_seconds is None and isinstance(matched_track, dict):
        duration_seconds = parse_duration_to_seconds(matched_track.get('duration'))

    views = to_positive_int(video_details.get('viewCount'))

    return {
        'videoId': str(video_id or '').strip(),
        'title': title,
        'artist': artist,
        'artists': track_artists,
        'album': album,
        'durationSeconds': duration_seconds,
        'views': views,
        'description': str(microformat.get('description') or '').strip(),
    }


try:

    action = sys.argv[1]

    if action == "suggest":

        query = sys.argv[2]

        print(json.dumps({
            "success": True,
            "suggestions": get_suggestions(query)
        }))

    elif action == "search":

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

    elif action == "song_details":

        video_id = sys.argv[2]

        print(json.dumps({
            "success": True,
            "metadata": song_details(video_id)
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