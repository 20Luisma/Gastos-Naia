<?php

namespace GastosNaia\Application;

class DeleteComunicadoUseCase
{
    private string $databaseUrl;
    private string $secret;
    private string $publicDir;

    public function __construct(string $publicDir)
    {
        $dbUrl = $_ENV['FIREBASE_DATABASE_URL'] ?? $_SERVER['FIREBASE_DATABASE_URL'] ?? getenv('FIREBASE_DATABASE_URL');
        $this->databaseUrl = rtrim(is_string($dbUrl) ? $dbUrl : '', '/');

        $sec = $_ENV['FIREBASE_SECRET'] ?? $_SERVER['FIREBASE_SECRET'] ?? getenv('FIREBASE_SECRET');
        $this->secret = is_string($sec) ? $sec : '';

        $this->publicDir = rtrim($publicDir, '/');
    }

    public function execute(string $id): bool
    {
        if (empty($this->databaseUrl) || empty($this->secret)) {
            throw new \Exception("Firebase credentials not configured.");
        }

        // 1. Obtener el comunicado antes de borrarlo para ver si tiene archivo
        $comunicado = $this->getComunicado($id);

        // 2. Borrar de Firebase
        $url = sprintf('%s/comunicados/%s.json?auth=%s', $this->databaseUrl, $id, $this->secret);
        $options = [
            'http' => [
                'method' => 'DELETE'
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \Exception("Error al borrar el comunicado de Firebase.");
        }

        // 3. Borrar archivo(s) local(es) si existen
        if ($comunicado) {
            if (!empty($comunicado['fileUrl'])) {
                $this->deleteLocalFile($comunicado['fileUrl']);
            }
            if (!empty($comunicado['attachments']) && is_array($comunicado['attachments'])) {
                foreach ($comunicado['attachments'] as $att) {
                    if (!empty($att['url'])) {
                        $this->deleteLocalFile($att['url']);
                    }
                }
            }
        }

        return true;
    }

    private function getComunicado(string $id): ?array
    {
        $url = sprintf('%s/comunicados/%s.json?auth=%s', $this->databaseUrl, $id, $this->secret);
        $result = @file_get_contents($url);
        if ($result) {
            return json_decode($result, true);
        }
        return null;
    }

    private function deleteLocalFile(string $fileUrl): void
    {
        // El fileUrl es algo como "uploads/comunicados/archivo.jpg"
        // Le quitamos la barra inicial si la tuviera por error
        $relativePath = ltrim($fileUrl, '/');
        $absolutePath = $this->publicDir . '/' . $relativePath;

        if (file_exists($absolutePath) && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
