import sys
from pytube import YouTube

if len(sys.argv) < 2:
    print("Usage: python download_audio.py <musicId>")
    sys.exit(1)

musicId = sys.argv[1]
URL = f"https://www.youtube.com/watch?v={musicId}"

print(URL)
yt = YouTube(URL)
stream = yt.streams.get_highest_resolution()
stream.download()