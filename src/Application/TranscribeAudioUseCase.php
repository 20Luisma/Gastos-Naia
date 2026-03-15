<?php

declare(strict_types=1);

namespace GastosNaia\Application;

class TranscribeAudioUseCase
{
    /**
     * @param string $audioFilePath Path to the temporary audio file
     * @return string The transcribed text
     */
    public function execute(string $audioFilePath): string
    {
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("Clave de API de OpenAI no configurada.");
        }

        if (!file_exists($audioFilePath)) {
            throw new \Exception("El archivo de audio no existe en: {$audioFilePath}");
        }

        // OpenAI Whisper requires multipart/form-data
        $url = 'https://api.openai.com/v1/audio/transcriptions';
        
        $cfile = new \CURLFile($audioFilePath);

        $postFields = [
            'file' => $cfile,
            'model' => 'whisper-1',
            'language' => 'es' // Hint whisper to expect Spanish
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $responseStr = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($responseStr === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Error de conexión con OpenAI Whisper: " . $error);
        }
        curl_close($ch);

        $response = json_decode($responseStr, true);

        if ($httpCode !== 200) {
            $errorMsg = $response['error']['message'] ?? 'Desconocido';
            throw new \Exception("Error API OpenAI Whisper ({$httpCode}): {$errorMsg}");
        }

        if (empty($response['text'])) {
            throw new \Exception("La transcripción devolvió un texto vacío.");
        }

        return trim($response['text']);
    }
}
