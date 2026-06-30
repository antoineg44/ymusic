import sys
from yt_dlp import YoutubeDL

if len(sys.argv) < 2:
    print("Usage: python download_audio.py <musicId>")
    sys.exit(1)

musicId = sys.argv[1]
URL = f"https://www.youtube.com/watch?v={musicId}"

ydl_opts = {
    'format': 'bestaudio[ext=webm]/bestaudio/best',
    'outtmpl': '%(title)s.%(ext)s',
    "cookiefile": "/home/partith/music/web/cookies.txt",
}

with YoutubeDL(ydl_opts) as ydl:
    ydl.download([URL])