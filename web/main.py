from ytmusicapi import YTMusic, OAuthCredentials

ytmusic = YTMusic()

#song = ytmusic.get_search_suggestions(search_results[0]['videoId'])

search_results = ytmusic.search("Les démons de minuit")

print(search_results)

print(search_results[0]['videoId'])

songs = ytmusic.get_watch_playlist(videoId=search_results[0]['videoId'])

print(songs["tracks"])

# Première suggestion
next_video = songs["tracks"][1]  # tracks[0] est souvent la vidéo en cours

print(next_video["title"])
print(next_video["videoId"])