<?php

namespace GastosNaia\Application;

use GastosNaia\Infrastructure\FirebaseWriteRepository;

class SaveComunicadoUseCase
{
    private string $databaseUrl;
    private string $secret;
    private ?\GastosNaia\Infrastructure\TelegramNotificationService $telegramService = null;

    public function __construct(?\GastosNaia\Infrastructure\TelegramNotificationService $telegramService = null)
    {
        $dbUrl = $_ENV['FIREBASE_DATABASE_URL'] ?? $_SERVER['FIREBASE_DATABASE_URL'] ?? getenv('FIREBASE_DATABASE_URL');
        $this->databaseUrl = rtrim(is_string($dbUrl) ? $dbUrl : '', '/');

        $sec = $_ENV['FIREBASE_SECRET'] ?? $_SERVER['FIREBASE_SECRET'] ?? getenv('FIREBASE_SECRET');
        $this->secret = is_string($sec) ? $sec : '';
        $this->telegramService = $telegramService;
    }

    public function execute(string $date, string $title, string $description, ?string $fileUrl, ?string $fileType, ?string $fileName, ?string $id = null): string
    {
        if (empty($this->databaseUrl) || empty($this->secret)) {
            throw new \Exception("Firebase credentials not configured.");
        }

        $isNew = ($id === null);
        if ($isNew) {
            $id = uniqid('com_');
        }

        $timestamp = date('c');

        $data = [
            'id' => $id,
            'date' => $date,
            'title' => $title,
            'description' => $description,
            'fileUrl' => $fileUrl,
            'fileType' => $fileType,
            'fileName' => $fileName,
            'updated_at' => $timestamp
        ];

        if ($isNew) {
            $data['created_at'] = $timestamp;
        }

        // Se guardará en /comunicados/{id}
        $url = sprintf('%s/comunicados/%s.json?auth=%s', $this->databaseUrl, $id, $this->secret);

        // Usamos PATCH si es una edición para no borrar campos que no estemos mandando (aunque aquí mandamos todos)
        // O PUT para sobrescribir completamente. Usaremos PUT para asegurar consistencia.
        $options = [
            'http' => [
                'method' => 'PUT',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE)
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \Exception("Error al guardar el comunicado en Firebase.");
        }

        // Enviar notificación por Telegram si es nuevo
        if ($isNew && $this->telegramService) {
            $msg = "<b>📝 Diario de Naia</b>\n\n";
            $msg .= "<b>Fecha:</b> " . date('d/m/Y', strtotime($date)) . "\n";
            $msg .= "<b>Título:</b> {$title}\n\n";
            if (!empty($description)) {
                $msg .= "<i>" . mb_substr($description, 0, 200) . (mb_strlen($description) > 200 ? '...' : '') . "</i>\n\n";
            }
            if ($fileUrl) {
                $msg .= "🔗 <a href='https://contenido.creawebes.com/GastosNaia/{$fileUrl}'>Ver adjunto</a>";
            }
            $this->telegramService->sendMessage($msg);
        }

        return $id;
    }
}
