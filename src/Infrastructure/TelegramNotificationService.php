<?php

namespace GastosNaia\Infrastructure;

class TelegramNotificationService
{
    private string $token;
    private string $chatId;

    public function __construct(string $token, string $chatId)
    {
        $this->token = $token;
        $this->chatId = $chatId;
    }

    public function sendMessage(string $text): bool
    {
        if (empty($this->token) || empty($this->chatId)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}
