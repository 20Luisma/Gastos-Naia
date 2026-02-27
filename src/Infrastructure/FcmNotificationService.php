<?php

declare(strict_types=1);

namespace GastosNaia\Infrastructure;

/**
 * Envía notificaciones push a dispositivos Android via FCM HTTP v1 API.
 *
 * Requiere en .env:
 *   FIREBASE_SERVICE_ACCOUNT_PATH=credentials/firebase-service-account.json
 *
 * Los dispositivos deben estar suscritos al topic "gastos_updates"
 * (la app Flutter lo hace automáticamente al iniciar).
 */
class FcmNotificationService
{
    private ?array $serviceAccount = null;
    private bool $configured = false;

    public function __construct()
    {
        $path = $_ENV['FIREBASE_SERVICE_ACCOUNT_PATH']
            ?? $_SERVER['FIREBASE_SERVICE_ACCOUNT_PATH']
            ?? getenv('FIREBASE_SERVICE_ACCOUNT_PATH')
            ?? '';

        // Resolve path relative to project root (two levels up from src/Infrastructure)
        if ($path && !str_starts_with($path, '/')) {
            $path = __DIR__ . '/../../../' . $path;
        }

        if ($path && file_exists($path)) {
            $json = file_get_contents($path);
            if ($json) {
                $this->serviceAccount = json_decode($json, true);
                $this->configured = ($this->serviceAccount !== null);
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Envía una notificación de actualización al topic gastos_updates.
     * Fire-and-forget: falla en silencio para no bloquear la respuesta al usuario.
     *
     * @param string $action  'add' | 'edit' | 'delete' | 'pension'
     * @param string $body    Texto descriptivo (ej. "Supermercado - 45,50 €")
     * @param int    $year    Año afectado
     * @param int    $month   Mes afectado (1-12)
     */
    public function notify(string $action, string $body, int $year, int $month): void
    {
        if (!$this->configured) {
            return;
        }

        try {
            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return;
            }

            $projectId = $this->serviceAccount['project_id'] ?? '';
            if (empty($projectId)) {
                return;
            }

            $titles = [
                'add' => '➕ Gasto añadido',
                'edit' => '✏️ Gasto modificado',
                'delete' => '🗑️ Gasto eliminado',
                'pension' => '💰 Pensión actualizada',
            ];

            $title = $titles[$action] ?? '📊 Gastos actualizados';

            $payload = [
                'message' => [
                    'topic' => 'gastos_updates',
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => [
                        'action' => $action,
                        'year' => (string) $year,
                        'month' => (string) $month,
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'gastos_updates',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ],
                    ],
                ],
            ];

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        "Authorization: Bearer {$accessToken}",
                        "Content-Type: application/json",
                    ]) . "\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'timeout' => 3, // fire-and-forget, timeout corto
                    'ignore_errors' => true,
                ],
            ];

            $context = stream_context_create($options);
            @file_get_contents($url, false, $context);

        } catch (\Throwable $e) {
            // Silenciar errores - las notificaciones no deben interrumpir la operación principal
            error_log('[FCM] Error enviando notificación: ' . $e->getMessage());
        }
    }

    /**
     * Genera un token OAuth 2.0 usando la Service Account y el scope de FCM.
     * Usa JWT firmado con RS256.
     */
    private function getAccessToken(): ?string
    {
        try {
            $now = time();
            $exp = $now + 3600;

            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = base64_encode(json_encode([
                'iss' => $this->serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $exp,
            ]));

            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = $this->base64UrlEncode(json_encode([
                'iss' => $this->serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $exp,
            ]));

            $signingInput = "{$header}.{$payload}";

            $privateKey = openssl_pkey_get_private($this->serviceAccount['private_key']);
            if (!$privateKey) {
                return null;
            }

            openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $jwt = $signingInput . '.' . $this->base64UrlEncode($signature);

            // Intercambiar JWT por access token
            $tokenOptions = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query([
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt,
                    ]),
                    'timeout' => 5,
                ],
            ];

            $ctx = stream_context_create($tokenOptions);
            $response = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);

            if (!$response) {
                return null;
            }

            $data = json_decode($response, true);
            return $data['access_token'] ?? null;

        } catch (\Throwable $e) {
            error_log('[FCM] Error obteniendo access token: ' . $e->getMessage());
            return null;
        }
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
