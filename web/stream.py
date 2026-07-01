import json
import logging
import os
import re
import sys
import traceback
from urllib.error import HTTPError
from pathlib import Path

from pytube import YouTube

try:
    from yt_dlp import YoutubeDL
except Exception:
    YoutubeDL = None

ALLOWED_EXTENSIONS = {'.m4a', '.mp3', '.webm', '.ogg', '.wav', '.aac', '.flac'}


def setup_logger(base_dir: Path) -> logging.Logger:
    logs_dir = base_dir / 'logs'
    logs_dir.mkdir(parents=True, exist_ok=True)
    log_file = logs_dir / 'download.log'

    logger = logging.getLogger('ymusic.download')
    logger.setLevel(logging.INFO)

    if not logger.handlers:
        handler = logging.FileHandler(log_file, encoding='utf-8')
        formatter = logging.Formatter('%(asctime)s %(levelname)s %(message)s')
        handler.setFormatter(formatter)
        logger.addHandler(handler)

    return logger


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
    trace_id = os.environ.get('YMUSIC_DOWNLOAD_TRACE_ID', '-')
    logger = logging.getLogger('ymusic.download')

    logger.info('[trace:%s] Starting download | music_id=%s | url=%s', trace_id, music_id, url)
    video = YouTube(url)
    logger.info('[trace:%s] Video metadata fetched | title=%s | length=%s', trace_id, video.title, video.length)

    audio_stream = video.streams.get_audio_only()

    if audio_stream is None:
        logger.error('[trace:%s] No audio stream available', trace_id)
        raise RuntimeError('No audio stream available')

    logger.info(
        '[trace:%s] Audio stream selected | itag=%s | mime_type=%s | abr=%s',
        trace_id,
        str(getattr(audio_stream, 'itag', '')),
        str(getattr(audio_stream, 'mime_type', '')),
        str(getattr(audio_stream, 'abr', '')),
    )

    basename = build_download_basename(music_id)
    downloaded_path = audio_stream.download(
        output_path=str(output_dir),
        filename=basename,
        skip_existing=False,
    )
    downloaded_file = Path(downloaded_path)
    logger.info(
        '[trace:%s] Raw download result | path=%s | exists=%s | suffix=%s',
        trace_id,
        str(downloaded_file),
        downloaded_file.exists(),
        downloaded_file.suffix.lower(),
    )

    if downloaded_file.exists() and downloaded_file.suffix.lower() in ALLOWED_EXTENSIONS:
        logger.info('[trace:%s] Download resolved directly | file=%s', trace_id, downloaded_file.name)
        return downloaded_file

    candidate = find_downloaded_file(output_dir, basename)
    if candidate is not None:
        logger.info('[trace:%s] Download resolved via candidate scan | file=%s', trace_id, candidate.name)
        return candidate

    logger.error(
        '[trace:%s] No audio file downloaded | basename=%s | output_dir=%s | files=%s',
        trace_id,
        basename,
        str(output_dir),
        ','.join(sorted(path.name for path in output_dir.glob(f'{basename}.*'))),
    )

    raise RuntimeError('No audio file downloaded')


def download_audio_with_yt_dlp(music_id: str, output_dir: Path) -> Path:
    if YoutubeDL is None:
        raise RuntimeError('yt-dlp is not installed')

    url = f'https://www.youtube.com/watch?v={music_id}'
    trace_id = os.environ.get('YMUSIC_DOWNLOAD_TRACE_ID', '-')
    logger = logging.getLogger('ymusic.download')
    basename = build_download_basename(music_id)
    template = str(output_dir / f'{basename}.%(ext)s')

    options = {
        'format': 'bestaudio/best',
        'outtmpl': template,
        'noplaylist': True,
        'quiet': True,
        'no_warnings': True,
        'nocheckcertificate': True,
        'http_headers': {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        },
    }

    cookies_path = Path(__file__).resolve().parent / 'cookies.txt'
    if cookies_path.is_file():
        options['cookiefile'] = str(cookies_path)

    logger.info('[trace:%s] yt-dlp fallback start | music_id=%s | url=%s | template=%s', trace_id, music_id, url, template)

    with YoutubeDL(options) as ydl:
        info = ydl.extract_info(url, download=True)

    if not info:
        raise RuntimeError('yt-dlp returned no metadata')

    candidate = find_downloaded_file(output_dir, basename)
    if candidate is not None:
        logger.info('[trace:%s] yt-dlp fallback success | file=%s', trace_id, candidate.name)
        return candidate

    logger.error('[trace:%s] yt-dlp fallback found no file | basename=%s', trace_id, basename)
    raise RuntimeError('yt-dlp did not produce an audio file')


def main() -> int:
    base_dir = Path(__file__).resolve().parent
    setup_logger(base_dir)
    trace_id = os.environ.get('YMUSIC_DOWNLOAD_TRACE_ID', '-')
    logger = logging.getLogger('ymusic.download')

    logger.info('[trace:%s] stream.py invoked | argv=%s', trace_id, sys.argv)

    if len(sys.argv) < 2:
        logger.error('[trace:%s] Missing required argument music_id', trace_id)
        print(json.dumps({"success": False, "error": "Usage: python download_audio.py <musicId>"}))
        return 1

    music_id = sys.argv[1].strip()
    output_dir = base_dir / 'data' / 'temp'
    output_dir.mkdir(parents=True, exist_ok=True)
    logger.info('[trace:%s] Normalized input | music_id=%s | output_dir=%s', trace_id, music_id, str(output_dir))

    try:
        try:
            downloaded_file = download_audio(music_id, output_dir)
        except HTTPError as pytube_error:
            logger.warning('[trace:%s] pytube failed with HTTP error, trying yt-dlp fallback | error=%s', trace_id, str(pytube_error))
            downloaded_file = download_audio_with_yt_dlp(music_id, output_dir)

        logger.info('[trace:%s] Download completed | file=%s', trace_id, downloaded_file.name)
        print(json.dumps({"success": True, "file": downloaded_file.name}))
        return 0
    except Exception as exc:
        logger.error('[trace:%s] Download failed | error=%s', trace_id, str(exc))
        logger.error('[trace:%s] Traceback:\n%s', trace_id, traceback.format_exc())
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
