<?php

namespace GastosNaia\Application;

class GetComunicadosUseCase
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

    public function execute(): array
    {
        if (empty($this->databaseUrl) || empty($this->secret)) {
            return [];
        }

        $url = sprintf('%s/comunicados.json?auth=%s', $this->databaseUrl, $this->secret);

        $options = [
            'http' => [
                'method' => 'GET',
                'timeout' => 5
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return [];
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            return [];
        }

        // Convertir de objeto asociativo a array plano inyectando el ID
        $comunicados = [];
        foreach ($data as $id => $item) {
            $item['id'] = $id;
            $comunicados[] = $item;
        }

        // Ordenar por fecha descendente
        usort($comunicados, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $comunicados;
    }
}
