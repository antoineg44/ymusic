<?php

// Wrapper PHP vers scripts Python pour recherche, playlist et telechargement YouTube Music.

class YouTubeMusic
{
    private string $python;

    private string $script;

    private string $scriptDownload;

    public function __construct()
    {
        // Selectionne un binaire Python valide selon l'environnement (Windows/Linux, venv locale, fallback systeme).
        $this->python = '../python/.venv/bin/python';

        $this->script = '../python/ytapi.py';

        $this->scriptDownload = '../python/stream.py';
    }

    private function run(array $args): array
    {
        // Execute ytapi.py et convertit sa sortie JSON en tableau PHP.
        $command =
            escapeshellcmd($this->python)
            . ' '
            . escapeshellarg($this->script);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        exec($command . ' 2>&1', $output, $code);

        $json = implode("\n", $output);

        $data = json_decode($json, true);

        if (!$data) {
            throw new Exception($json);
        }

        return $data;
    }

    public function search(string $query): array
    {
        return $this->run([
            'search',
            $query
        ]);
    }

    public function getSuggestions(string $query): array
    {
        return $this->run([
            'suggest',
            $query
        ]);
    }

    public function searchPlaylists(string $query): array
    {
        return $this->run([
            'playlist_search',
            $query
        ]);
    }

    public function playlist(string $videoId): array
    {
        return $this->run([
            'playlist',
            $videoId
        ]);
    }

    public function playlistItems(string $playlistId): array
    {
        return $this->run([
            'playlist_items',
            $playlistId
        ]);
    }

    public function download(string $musicId): array
    {
        $command =
            escapeshellcmd($this->python)
            . ' '
            . escapeshellarg($this->scriptDownload);

        $command .= ' ' . escapeshellarg($musicId);

        exec($command . ' 2>&1', $output, $code);

        $json = implode("\n", $output);

        $data = json_decode($json, true);

        if (!$data) {
            throw new Exception($json);
        }

        return $data;
    }
}