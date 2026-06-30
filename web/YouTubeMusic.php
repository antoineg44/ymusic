<?php

use YouTube\YouTubeDownloader;
use YouTube\Exception\YouTubeException;

class YouTubeMusic
{
    private string $python;

    private string $script;

    private string $scriptDownload;

    public function __construct()
    {
        $baseDir = __DIR__;

        $this->python = $baseDir . '/venv/bin/python';

        $this->script = $baseDir . '/ytapi.py';

        $this->scriptDownload = $baseDir . '/stream.py';
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

    public function download(string $musicId)
    {
        $youtube = new YouTubeDownloader();

        try {
            $downloadOptions = $youtube->getDownloadLinks("https://www.youtube.com/watch?v=-SuIZNhQffs");

            if ($downloadOptions->getAllFormats()) {
                echo $downloadOptions->getFirstCombinedFormat()->url;
            } else {
                echo 'No links found';
            }

        } catch (YouTubeException $e) {
            echo 'Something went wrong: ' . $e->getMessage();
        }
    }
}