<?php

declare(strict_types=1);

namespace GastosNaia\Application;

class ProcessDiarioNoteUseCase
{
    private SaveComunicadoUseCase $saveComunicadoUseCase;

    public function __construct(SaveComunicadoUseCase $saveComunicadoUseCase)
    {
        $this->saveComunicadoUseCase = $saveComunicadoUseCase;
    }

    /**
     * @param string $rawText The transcribed or sent text
     * @return string The ID of the newly saved record
     */
    public function execute(string $rawText): string
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("Clave de API de OpenAI no configurada.");
        }

        $systemPrompt = "Eres un asistente que resume notas para un diario personal de una niña (Naia).
Extrae un título muy breve (2 a 5 palabras máximo) que capture la esencia de la nota enviada.
Devuelve el resultado como un JSON puro sin markdown (\`\`\`json):
{\"title\": \"El título corto generado\"}";

        $payload = [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Nota original:\n" . $rawText],
            ],
            'max_tokens' => 100,
            'response_format' => ['type' => 'json_object']
        ];

        $url = 'https://api.openai.com/v1/chat/completions';
        $options = [
            'http' => [
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
                'method' => 'POST',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        $title = "Nueva entrada de diario"; // Fallback title
        
        if ($result !== false) {
            $response = json_decode($result, true);
            if (!isset($response['error'])) {
                $jsonText = $response['choices'][0]['message']['content'] ?? '';
                $parsed = json_decode(trim($jsonText), true);
                if ($parsed && !empty($parsed['title'])) {
                    $title = $parsed['title'];
                }
            }
        }

        // Clean up text if needed, capitalize first letter
        $description = ucfirst(trim($rawText));
        
        $date = date('Y-m-d'); // Current day for the diario entry

        // $date, $title, $description, $fileUrl, $fileType, $fileName
        $newId = $this->saveComunicadoUseCase->execute($date, $title, $description, null, null, null);

        // Fetch back the created title to return it for the user message
        return $title;
    }
}
