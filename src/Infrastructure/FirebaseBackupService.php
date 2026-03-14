<?php

declare(strict_types=1);

namespace GastosNaia\Infrastructure;

class FirebaseBackupService
{
    private string $databaseUrl;
    private string $secret;

    public function __construct()
    {
        $dbUrl = $_ENV['FIREBASE_DATABASE_URL'] ?? $_SERVER['FIREBASE_DATABASE_URL'] ?? getenv('FIREBASE_DATABASE_URL');
        $this->databaseUrl = rtrim(is_string($dbUrl) ? $dbUrl : '', '/');

        $sec = $_ENV['FIREBASE_SECRET'] ?? $_SERVER['FIREBASE_SECRET'] ?? getenv('FIREBASE_SECRET');
        $this->secret = is_string($sec) ? $sec : '';
    }

    public function isConfigured(): bool
    {
        return $this->databaseUrl !== '' && $this->secret !== '';
    }

    public function backupExpenseAction(string $action, array $data): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $payload = [
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        $this->sendToFirebase('expenses_log', $payload);
    }

    public function backupFileAction(string $action, string $path): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $payload = [
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
            'path' => $path
        ];

        $this->sendToFirebase('files_log', $payload);
    }

    private function sendToFirebase(string $path, array $payload): void
    {
        // Fire and forget, no interfiere con la app principal si falla
        try {
            $url = sprintf('%s/backups/%s.json?auth=%s', $this->databaseUrl, $path, $this->secret);
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout' => 2 // Timeout corto para no bloquear la app
                ]
            ];
            $context = stream_context_create($options);
            @file_get_contents($url, false, $context);
        } catch (\Throwable $e) {
            // Silenciar errores para no afectar al usuario
        }
    }
}
