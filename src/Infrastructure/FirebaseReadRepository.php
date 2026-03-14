<?php

declare(strict_types=1);

namespace GastosNaia\Infrastructure;

class FirebaseReadRepository
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

    /**
     * Descarga el árbol completo JSON de la IA compilado desde Firebase
     */
    public function getFullContext(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $url = sprintf('%s/ai_context.json?auth=%s', $this->databaseUrl, $this->secret);
            $options = [
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5 // Debe ser rápido
                ]
            ];
            $context = stream_context_create($options);
            $json = @file_get_contents($url, false, $context);

            if ($json === false) {
                return null;
            }

            return json_decode($json, true);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
