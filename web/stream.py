import json
import re
import sys
from typing import Any
from urllib.error import HTTPError
from pathlib import Path

from pytube import YouTube

try:
    from yt_dlp import YoutubeDL
except Exception:
    YoutubeDL = None

ALLOWED_EXTENSIONS = {'.m4a', '.mp3', '.webm', '.ogg', '.wav', '.aac', '.flac'}


def build_download_basename(music_id: str) -> str:
    cleaned_id = re.sub(r'[^A-Za-z0-9._-]+', '-', music_id.strip())
    cleaned_id = cleaned_id.strip('-.')
    return cleaned_id or 'download'


def find_downloaded_file(output_dir: Path, basename: str) -> Path | None:
    for path in sorted(output_dir.glob(f'{basename}.*')):
        if path.is_file() and path.suffix.lower() in ALLOWED_EXTENSIONS:
            return path
    return None


def download_audio(music_id: str, output_dir: Path) -> Path:
    url = f'https://www.youtube.com/watch?v={music_id}'
    video = YouTube(url)

    audio_stream = video.streams.get_audio_only()

    if audio_stream is None:
        raise RuntimeError('No audio stream available')

    basename = build_download_basename(music_id)
    downloaded_path = audio_stream.download(
        output_path=str(output_dir),
        filename=basename,
        skip_existing=False,
    )
    downloaded_file = Path(downloaded_path)

    if downloaded_file.exists() and downloaded_file.suffix.lower() in ALLOWED_EXTENSIONS:
        return downloaded_file

    candidate = find_downloaded_file(output_dir, basename)
    if candidate is not None:
        return candidate

    raise RuntimeError('No audio file downloaded')


def download_audio_with_yt_dlp(music_id: str, output_dir: Path) -> Path:
    if YoutubeDL is None:
        raise RuntimeError('yt-dlp is not installed')

    url = f'https://www.youtube.com/watch?v={music_id}'
    basename = build_download_basename(music_id)
    template = str(output_dir / f'{basename}.%(ext)s')

    options: dict[str, Any] = {
        'format': 'bestaudio/best',
        'outtmpl': template,
        'noplaylist': True,
        'quiet': True,
        'no_warnings': True,
        'noprogress': True,
        'nocheckcertificate': True,
        'http_headers': {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        },
    }

    cookies_path = Path(__file__).resolve().parent / 'cookies.txt'
    if cookies_path.is_file():
        try:
            with cookies_path.open('r', encoding='utf-8'):
                pass
            options['cookiefile'] = str(cookies_path)
        except OSError:
            pass

    try:
        with YoutubeDL(options) as ydl:
            info = ydl.extract_info(url, download=True)
    except OSError as exc:
        if 'Permission denied' in str(exc) and options.get('cookiefile'):
            options.pop('cookiefile', None)
            with YoutubeDL(options) as ydl:
                info = ydl.extract_info(url, download=True)
        else:
            raise

    if not info:
        raise RuntimeError('yt-dlp returned no metadata')

    candidate = find_downloaded_file(output_dir, basename)
    if candidate is not None:
        return candidate

    raise RuntimeError('yt-dlp did not produce an audio file')


def main() -> int:
    base_dir = Path(__file__).resolve().parent

    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "Usage: python download_audio.py <musicId>"}))
        return 1

    music_id = sys.argv[1].strip()
    output_dir = base_dir / 'data' / 'temp'
    output_dir.mkdir(parents=True, exist_ok=True)

    try:
        try:
            downloaded_file = download_audio(music_id, output_dir)
        except HTTPError:
            downloaded_file = download_audio_with_yt_dlp(music_id, output_dir)

        print(json.dumps({"success": True, "file": downloaded_file.name}))
        return 0
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
