import json
import re
import sys
from pathlib import Path

from pytube import YouTube

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


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "Usage: python download_audio.py <musicId>"}))
        return 1

    music_id = sys.argv[1].strip()
    base_dir = Path(__file__).resolve().parent
    output_dir = base_dir / 'data' / 'temp'
    output_dir.mkdir(parents=True, exist_ok=True)

    try:
        downloaded_file = download_audio(music_id, output_dir)
        print(json.dumps({"success": True, "file": downloaded_file.name}))
        return 0
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
