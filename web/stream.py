
from yt_dlp import YoutubeDL

URLS = ['https://www.youtube.com/watch?v=L9gmrWXjHeE']

ydl_opts = {
    'format': 'bestaudio[ext=webm]/bestaudio/best',
    'outtmpl': '%(title)s.%(ext)s',
}

with YoutubeDL(ydl_opts) as ydl:
    ydl.download(URLS)