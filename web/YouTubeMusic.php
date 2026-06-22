<?php

class YouTubeMusic
{
    private string $python;

    private string $script;

    public function __construct()
    {
        $baseDir = __DIR__;

        $this->python = $baseDir . '/.venv/bin/python';

        $this->script = $baseDir . '/ytapi.py';
    }

    private function run(array $args): array
    {
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

    public function playlist(string $videoId): array
    {
        return $this->run([
            'playlist',
            $videoId
        ]);
    }
}