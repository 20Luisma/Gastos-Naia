<?php
// src/Application/FetchEmailsUseCase.php

namespace GastosNaia\Application;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;

class FetchEmailsUseCase
{
    private string $imapHost;
    private int $imapPort;
    private string $imapUser;
    private string $imapPass;
    private string $targetSender;
    private string $firebaseUrl;
    private string $firebaseSecret;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->imapHost      = $_ENV['IMAP_HOST']          ?? 'imap.gmail.com';
        $this->imapPort      = (int) ($_ENV['IMAP_PORT']   ?? 993);
        $this->imapUser      = $_ENV['IMAP_USER']          ?? '';
        $this->imapPass      = $_ENV['IMAP_PASS']          ?? '';
        $this->targetSender  = $_ENV['EMAIL_SENDER']       ?? '';
        $this->firebaseUrl   = rtrim($_ENV['FIREBASE_DATABASE_URL'] ?? '', '/');
        $this->firebaseSecret = $_ENV['FIREBASE_SECRET'] ?? '';
    }

    public function execute(bool $forceAll = false, bool $isCli = false): array
    {
        if (empty($this->imapUser) || empty($this->imapPass)) {
            return ['success' => false, 'message' => 'Faltan IMAP_USER o IMAP_PASS en .env'];
        }
        if (empty($this->targetSender)) {
            return ['success' => false, 'message' => 'Falta EMAIL_SENDER en .env'];
        }
        if (empty($this->firebaseUrl) || empty($this->firebaseSecret)) {
            return ['success' => false, 'message' => 'Faltan credenciales de Firebase en .env'];
        }

        $this->log("Iniciando sincronización de correos...", $isCli);

        $cm = new ClientManager();
        $client = $cm->make([
            'host'          => $this->imapHost,
            'port'          => $this->imapPort,
            'encryption'    => 'ssl',
            'validate_cert' => true,
            'username'      => $this->imapUser,
            'password'      => $this->imapPass,
            'protocol'      => 'imap'
        ]);

        try {
            $client->connect();
        } catch (ConnectionFailedException $ex) {
            return ['success' => false, 'message' => 'No se pudo conectar al servidor IMAP: ' . $ex->getMessage()];
        }

        $this->log("Conectado. Buscando correos de: {$this->targetSender}", $isCli);

        // ── Obtener INBOX (Recibidos) ──────────────────────────────
        $inboxFolder = $client->getFolder('INBOX');
        $threeMonthsAgo = date('d.m.Y', strtotime('-3 months'));
        $inboxQuery = $inboxFolder->query()->from($this->targetSender)->since($threeMonthsAgo);

        // 1. Inbox (Recibidos de Irene)
        $inboxMsgs = $forceAll 
            ? clone $inboxQuery->all()->setFetchOrderDesc()->limit(50)->get()
            : clone $inboxQuery->unseen()->setFetchOrderDesc()->get();

        $allMessages = [];
        foreach ($inboxMsgs as $msg) {
            if ($msg) $allMessages[] = ['msg' => $msg, 'direction' => 'in'];
        }

        // 2. Enviados (Propios hacia Irene)
        try {
            $sentFolder = $client->getFolder('[Gmail]/Enviados');
            if ($sentFolder) {
                $sentQuery = clone $sentFolder->query()->to($this->targetSender)->since($threeMonthsAgo);
                $sentMsgs = $sentQuery->all()->setFetchOrderDesc()->limit(50)->get();
                foreach ($sentMsgs as $msg) {
                    if ($msg) $allMessages[] = ['msg' => $msg, 'direction' => 'out'];
                }
            }
        } catch (\Exception $e) {
            $this->log("Aviso: No se pudo leer la carpeta de Enviados. " . $e->getMessage(), $isCli);
        }

        if (empty($allMessages)) {
            $this->log("No hay correos nuevos en INBOX ni enviados.", $isCli);
            $client->disconnect();
            return ['success' => true, 'message' => 'No hay correos nuevos en INBOX ni enviados.', 'count' => 0];
        }

        usort($allMessages, function($a, $b) {
            $dateA = strtotime((string) $a['msg']->getDate() ?: 'now');
            $dateB = strtotime((string) $b['msg']->getDate() ?: 'now');
            return $dateB <=> $dateA;
        });

        $this->log("Procesando " . count($allMessages) . " correo(s) en total (Entrada + Salida).", $isCli);

        $comunicadosDriveFolder = $this->config['comunicados_drive_folder_id'] ?? '';
        $baseDir = dirname(__DIR__, 2);

        $savedCount = 0;

        foreach ($allMessages as $item) {
            $message = $item['msg'];
            $direction = $item['direction'];

            $subject = mb_decode_mimeheader((string) $message->getSubject()) ?: '(Sin asunto)';
            $dateStr = (string) $message->getDate();
            $date    = $dateStr ? date('Y-m-d H:i:s', strtotime($dateStr)) : date('Y-m-d H:i:s');
            $from    = (string) $message->getFrom()[0]->mail ?? ($direction === 'out' ? $_ENV['IMAP_USER'] : $this->targetSender);

            $this->log("  → Procesando [{$direction}]: \"{$subject}\" ({$date})", $isCli);

            $body = '';
            if ($message->hasTextBody()) {
                $body = $message->getTextBody();
            } elseif ($message->hasHTMLBody()) {
                $body = strip_tags($message->getHTMLBody());
            }
            $body = mb_substr(trim($body), 0, 4000);

            $attachments = [];
            $allParts = $message->getAttachments();
            
            if ($allParts->isEmpty() && method_exists($message, 'getPart')) {
                $allParts = collect($message->getParts())->filter(function($part) {
                    $name = (string)($part->name ?? '');
                    $type = strtolower((string)($part->type ?? ''));
                    return !empty($name) && in_array($type, ['application', 'image']);
                });
            }
            
            foreach ($allParts as $attachment) {
                $filename = mb_decode_mimeheader((string) $attachment->name);
                if (empty($filename)) continue;
                
                $content = $attachment->getContent();
                if (empty($content)) continue;
                
                $localTmp = sys_get_temp_dir() . '/' . uniqid('email_attach_') . '_' . basename($filename);
                file_put_contents($localTmp, $content);
                
                $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
                $finalName = uniqid() . '_' . $safeName;
                $publicPath = $baseDir . '/public/archivos_correos/' . $finalName;
                
                rename($localTmp, $publicPath);
                $driveUrl = 'archivos_correos/' . $finalName;
                $this->log("      ✓ Adjunto desempaquetado y subido localmente: {$safeName}", $isCli);
                
                @unlink($localTmp);

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $attachments[] = [
                    'filename' => $filename,
                    'type'     => $ext ?: 'unknown',
                    'url'      => $driveUrl,
                ];
            }

            $rawMessageId = (string) ($message->getMessageId() ?: uniqid('msg_'));
            $msgIdForHeader = strpos($rawMessageId, '<') === false ? "<{$rawMessageId}>" : $rawMessageId;
            
            $emailData = [
                'id'          => $msgIdForHeader,
                'subject'     => $subject,
                'from'        => $from,
                'to'          => ($direction === 'out' ? $this->targetSender : $_ENV['IMAP_USER']),
                'direction'   => $direction,
                'date'        => $date,
                'timestamp'   => strtotime($date),
                'body'        => $body,
                'attachments' => $attachments,
                'isRead'      => ($direction === 'out') ? true : false,
            ];

            $saved = $this->saveToFirebase($emailData);
            if ($saved) {
                $savedCount++;
                $this->log("      ✓ Guardado en Firebase.", $isCli);
                $message->setFlag(['Seen']);

                if ($direction === 'in') {
                    $telegramToken  = $this->config['telegram_token']  ?? '';
                    $telegramChatId = $this->config['telegram_chat_id'] ?? '';

                    if (!empty($telegramToken) && !empty($telegramChatId)) {
                        $attNames = array_map(fn($a) => $a['filename'], $attachments);
                        $attText  = !empty($attNames) ? "\n📎 *Adjuntos:* " . implode(', ', $attNames) : '';
                        $msgText  = "📬 *Nuevo correo de Irene*\n"
                                  . "📌 *Asunto:* " . $subject . "\n"
                                  . "🗓 *Fecha:* {$date}"
                                  . $attText;

                        $tgUrl  = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
                        $tgData = json_encode([
                            'chat_id'    => $telegramChatId,
                            'text'       => $msgText,
                            'parse_mode' => 'Markdown',
                        ]);
                        $tgOpts = ['http' => [
                            'method'  => 'POST',
                            'header'  => "Content-Type: application/json\r\n",
                            'content' => $tgData,
                            'timeout' => 5,
                        ]];
                        @file_get_contents($tgUrl, false, stream_context_create($tgOpts));
                        $this->log("      📱 Notificación Telegram enviada.", $isCli);
                    }
                }
            } else {
                $this->log("      ✗ Error al guardar en Firebase.", $isCli);
            }
        }

        $client->disconnect();
        $this->log("Sincronización completada. Total procesados/guardados: {$savedCount}.", $isCli);

        return ['success' => true, 'message' => "Procesados {$savedCount} correos nuevos.", 'count' => $savedCount];
    }

    private function log(string $msg, bool $isCli): void
    {
        if ($isCli) {
            echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
        }
    }

    private function saveToFirebase(array $data): bool
    {
        $rawId = $data['id'] ?? uniqid('msg_');
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($rawId, '<>'));
        $key = substr($key, 0, 128);

        $url  = "{$this->firebaseUrl}/emails/{$key}.json?auth={$this->firebaseSecret}";
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $opts = [
            'http' => [
                'method'  => 'PUT',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 10,
            ],
        ];
        $ctx    = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);
        return $result !== false;
    }
}
