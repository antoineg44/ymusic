<?php

class YouTubeMusic
{
    private string $python;

    private string $script;

    private string $scriptDownload;

    private string $downloadLogFile;

    public function __construct()
    {
        $baseDir = __DIR__;
        $candidates = [
            $baseDir . '/.venv/bin/python',
            $baseDir . '/venv/bin/python',
            '/usr/bin/python3',
            '/usr/local/bin/python3',
        ];

        $this->python = '';

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $this->python = $candidate;
                break;
            }
        }

        if ($this->python === '') {
            $this->python = 'python3';
        }

        $this->script = $baseDir . '/ytapi.py';

        $this->scriptDownload = $baseDir . '/stream.py';

        $logsDir = $baseDir . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
        }
        $this->downloadLogFile = $logsDir . '/download.log';
    }

    private function logDownload(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] ' . $message;

        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' | ' . $json;
            }
        }

        @file_put_contents($this->downloadLogFile, $line . PHP_EOL, FILE_APPEND);
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

    public function download(string $musicId): array
    {
        $traceId = bin2hex(random_bytes(6));
        putenv('YMUSIC_DOWNLOAD_TRACE_ID=' . $traceId);

        $command =
            escapeshellcmd($this->python)
            . ' '
            . escapeshellarg($this->scriptDownload);

        $command .= ' ' . escapeshellarg($musicId);

        $this->logDownload('Download command prepared', [
            'traceId' => $traceId,
            'musicId' => $musicId,
            'python' => $this->python,
            'script' => $this->scriptDownload,
            'command' => $command,
        ]);

        exec($command . ' 2>&1', $output, $code);

        $this->logDownload('Download command finished', [
            'traceId' => $traceId,
            'exitCode' => $code,
            'outputLines' => count($output),
            'outputPreview' => array_slice($output, 0, 5),
        ]);

        $json = implode("\n", $output);

        $data = json_decode($json, true);

        if (!$data) {
            $this->logDownload('Download JSON decode failed', [
                'traceId' => $traceId,
                'rawOutput' => $json,
            ]);
            throw new Exception($json);
        }

        $this->logDownload('Download JSON parsed', [
            'traceId' => $traceId,
            'success' => $data['success'] ?? null,
            'error' => $data['error'] ?? null,
            'file' => $data['file'] ?? null,
        ]);

        return $data;
    }
}