<?php

declare(strict_types=1);

namespace GastosNaia\Application;

use GastosNaia\Infrastructure\TelegramNotificationService;

class HandleTelegramWebhookUseCase
{
    private TranscribeAudioUseCase $transcribeUseCase;
    private ProcessDiarioNoteUseCase $processNoteUseCase;
    private TelegramNotificationService $telegramService;
    private string $botToken;

    public function __construct(
        TranscribeAudioUseCase $transcribeUseCase,
        ProcessDiarioNoteUseCase $processNoteUseCase,
        TelegramNotificationService $telegramService
    ) {
        $this->transcribeUseCase = $transcribeUseCase;
        $this->processNoteUseCase = $processNoteUseCase;
        $this->telegramService = $telegramService;

        $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? $_SERVER['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN');
        $this->botToken = is_string($token) ? $token : '';
    }

    public function execute(array $payload): bool
    {
        if (empty($this->botToken)) {
            error_log("TELEGRAM WEBHOOK ERROR: Bot token no configurado.");
            return false;
        }

        // Validación básica
        if (!isset($payload['message'])) {
            return true; // Ignorar updates que no sean mensajes
        }

        $message = $payload['message'];
        $chatId = $message['chat']['id'] ?? '';
        
        // TODO: En producción, idealmente validaríamos que el $chatId coincida con el $_ENV['TELEGRAM_CHAT_ID'] para que nadie más pueda meter gastos.
        $configuredChatId = $_ENV['TELEGRAM_CHAT_ID'] ?? $_SERVER['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID');
        if ((string)$chatId !== (string)$configuredChatId) {
            error_log("TELEGRAM WEBHOOK: Intento de uso desde un chat no autorizado ({$chatId})");
            return true; // Ignorar silenciosamente
        }

        // 1. Es una nota de VOZ (Audio de voz de Telegram)
        if (isset($message['voice'])) {
            $fileId = $message['voice']['file_id'];
            $this->telegramService->sendMessage("<i>🎤 Procesando nota de voz, dame un segundito...</i>");

            try {
                // Paso 1: Obtener la ruta temporal del archivo en los servidores de Telegram
                $filePath = $this->getTelegramFilePath($fileId);
                if (!$filePath) {
                    throw new \Exception("No se pudo obtener la ruta del archivo de audio de Telegram.");
                }

                // Paso 2: Descargar el archivo localmente a /tmp
                $localAudioPath = $this->downloadTelegramFile($filePath, 'voice_' . uniqid() . '.oga');
                
                // Paso 3: Transcribir el audio usando Whisper (OpenAI)
                $transcribedText = $this->transcribeUseCase->execute($localAudioPath);
                
                // Paso 4: Generar título, formatear y guardar en Firebase (Diario)
                $title = $this->processNoteUseCase->execute($transcribedText);

                // Paso 5: Responder al usuario
                $this->telegramService->sendMessage("✅ <b>Guardado en el Diario</b>\n\n<b>Tíulo:</b> {$title}\n<b>Texto:</b> <i>\"{$transcribedText}\"</i>");
                
                @unlink($localAudioPath); // Limpieza

            } catch (\Exception $e) {
                error_log("TELEGRAM WEBHOOK ERROR (Voice): " . $e->getMessage());
                $this->telegramService->sendMessage("❌ Error al procesar el audio: " . $e->getMessage());
            }

            return true;
        }

        // 2. Es un TEXTO normal
        if (isset($message['text'])) {
            $text = trim($message['text']);
            
            // Ignorar comandos básicos como /start
            if (strpos($text, '/') === 0) {
                if ($text === '/start') {
                    $this->telegramService->sendMessage("¡Hola Luisma! Soy tu asistente para Gastos Naia. Envíame una nota de voz contándome algo sobre ella, y lo guardaré directamente en su Diario.");
                }
                return true;
            }

            $this->telegramService->sendMessage("<i>📝 Procesando texto...</i>");

            try {
                // Guardar directamente (se salta la transcripción whisper)
                $title = $this->processNoteUseCase->execute($text);
                $this->telegramService->sendMessage("✅ <b>Guardado en el Diario por texto</b>\n\n<b>Tíulo:</b> {$title}");
            } catch (\Exception $e) {
                error_log("TELEGRAM WEBHOOK ERROR (Text): " . $e->getMessage());
                $this->telegramService->sendMessage("❌ Error al guardar la nota: " . $e->getMessage());
            }

            return true;
        }

        return true;
    }

    /**
     * Hace una petición a Telegram para obtener el 'file_path' dado un 'file_id'
     */
    private function getTelegramFilePath(string $fileId): ?string
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getFile?file_id={$fileId}";
        $response = @file_get_contents($url);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['ok']) && $data['ok'] === true && isset($data['result']['file_path'])) {
                return $data['result']['file_path'];
            }
        }
        return null;
    }

    /**
     * Descarga el archivo físico usando el token y la ruta
     */
    private function downloadTelegramFile(string $telegramFilePath, string $localFilename): string
    {
        $url = "https://api.telegram.org/file/bot{$this->botToken}/{$telegramFilePath}";
        
        $localDir = sys_get_temp_dir() . '/gastosnaia_audio';
        if (!is_dir($localDir)) {
            mkdir($localDir, 0777, true);
        }
        
        $localPath = $localDir . '/' . $localFilename;
        
        $content = @file_get_contents($url);
        if ($content === false) {
            throw new \Exception("Fallo al descargar el archivo físico de Telegram ({$url}).");
        }
        
        file_put_contents($localPath, $content);
        return $localPath;
    }
}
