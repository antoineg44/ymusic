from ytmusicapi import YTMusic

ytmusic = YTMusic()

#song = ytmusic.get_search_suggestions(search_results[0]['videoId'])

search_results = ytmusic.search(
        query="Les démons de minuit",
        filter="songs",
        limit=5
    )

print(search_results)

#print(search_results[0]['videoId'])

#songs = ytmusic.get_watch_playlist(videoId=search_results[0]['videoId'])

#print(songs["tracks"])

# Première suggestion
#next_video = songs["tracks"][1]  # tracks[0] est souvent la vidéo en cours

#print(next_video["title"])
#print(next_video["videoId"])