<?php

// Wrapper PHP appelant un serveur distant (via HTTP) qui exécute les scripts Python
// pour la recherche, les playlists et le téléchargement YouTube Music.

class YouTubeMusic
{
    private string $remoteEndpoint;
    private string $tempDir;

    public function __construct()
    {
        $config = require __DIR__ . '/config.php';

        $this->remoteEndpoint =
            rtrim((string) $config['remote_base_url'], '/')
            . '/' . ltrim((string) $config['remote_endpoint'], '/');

        // Dossier local où sont écrits les fichiers audio récupérés du serveur distant.
        $tempDir = realpath(__DIR__ . '/../../data/temp');
        if ($tempDir === false) {
            $tempDir = __DIR__ . '/../../data/temp';
        }
        $this->tempDir = $tempDir;
    }

    /**
     * Envoie une requête POST au serveur distant et retourne le statut, les en-têtes et le corps.
     */
    private function request(array $fields): array
    {
        $ch = curl_init($this->remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            // Désactive "Expect: 100-continue" pour éviter les blocs d'en-têtes intermédiaires.
            CURLOPT_HTTPHEADER => ['Expect:'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Connexion au serveur distant impossible: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $this->parseHeaders(substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        ];
    }

    /**
     * Transforme un bloc d'en-têtes HTTP bruts en tableau associatif (clés en minuscules).
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n/', $rawHeaders) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return $headers;
    }

    /**
     * Exécute une action ytapi.py sur le serveur distant et retourne la réponse JSON décodée.
     */
    private function run(array $args): array
    {
        $response = $this->request([
            'action' => $args[0] ?? '',
            'arg' => $args[1] ?? '',
        ]);

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            throw new Exception($response['body'] !== '' ? $response['body'] : 'Reponse distante vide');
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
        $response = $this->request(['action' => 'test']);
        print_r($response['body']);
    }

    /**
     * Demande le téléchargement au serveur distant puis écrit le fichier audio localement
     * dans data/temp (contrat identique à l'ancien comportement basé sur stream.py).
     */
    public function download(string $musicId): array
    {
        $response = $this->request([
            'action' => 'download',
            'arg' => $musicId,
        ]);

        $contentType = $response['headers']['content-type'] ?? '';

        // Réponse JSON = erreur (ou message) renvoyé par le serveur distant.
        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                throw new Exception($response['body'] !== '' ? $response['body'] : 'Reponse distante vide');
            }
            if (empty($data['success'])) {
                throw new Exception($data['error'] ?? 'Echec du telechargement distant');
            }
            return $data;
        }

        // Réponse binaire = fichier audio : récupère le nom et écrit le fichier localement.
        $fileName = isset($response['headers']['x-file-name'])
            ? rawurldecode($response['headers']['x-file-name'])
            : '';
        $fileName = basename($fileName);

        if ($fileName === '') {
            throw new Exception('Nom de fichier manquant dans la reponse distante');
        }

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new Exception('Impossible de creer le dossier temporaire local');
        }

        $destination = $this->tempDir . '/' . $fileName;
        if (file_put_contents($destination, $response['body']) === false) {
            throw new Exception('Impossible d\'ecrire le fichier telecharge localement');
        }

        $resultHeader = $response['headers']['x-download-result'] ?? '';
        $result = $resultHeader !== ''
            ? json_decode(base64_decode($resultHeader), true)
            : null;

        if (!is_array($result)) {
            $result = ['success' => true];
        }
        $result['file'] = $fileName;

        return $result;
    }
}