<?php

// Wrapper PHP vers scripts Python pour recherche, playlist et telechargement YouTube Music.

class YouTubeMusic
{
    private string $python;
    private string $script;
    private string $scriptTest;
    private string $scriptDownload;

    public function __construct()
    {
        // Selectionne un binaire Python valide selon l'environnement (utilise chemins absolus)
        $pythonDir = realpath(__DIR__ . '/../../python');
        if ($pythonDir === false) {
            // Fallback si realpath échoue (conserve comportement relatif)
            $pythonDir = __DIR__ . '/../../python';
        }

        $this->python = $pythonDir . '/.venv/bin/python';

        $this->script = $pythonDir . '/ytapi.py';
        $this->scriptTest = $pythonDir . '/main.py';

        $this->scriptDownload = $pythonDir . '/stream.py';
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

        // Log command and output to help debugging when run by the webserver.
        try {
            $log = [];
            $log[] = "=== " . date('c') . " ===";
            $log[] = "command: " . $command . ' ' . implode(' ', array_map('escapeshellarg', $args));
            $log[] = "exit_code: " . intval($code);
            $log[] = "user: " . get_current_user();
            $log[] = "env_PATH: " . getenv('PATH');
            $log[] = "env_HOME: " . getenv('HOME');
            $log[] = "output:";
            $log[] = $json;
            $log[] = "\n";

            @file_put_contents(__DIR__ . '/debug_ytapi.log', implode("\n", $log), FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // ne pas interrompre l'exécution pour le logging
        }

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

    public function songDetails(string $videoId): array
    {
        return $this->run([
            'song_details',
            $videoId
        ]);
    }

    public function test()
    {
        // Execute ytapi.py et convertit sa sortie JSON en tableau PHP.
        $command =
            escapeshellcmd($this->python)
            . ' '
            . escapeshellarg($this->scriptTest);

        exec($command . ' 2>&1', $output, $code);

        print_r($output);
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