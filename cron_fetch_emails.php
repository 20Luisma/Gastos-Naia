<?php
// ╔══════════════════════════════════════════════════════════╗
// ║  cron_fetch_emails.php — Sincronizador de correos        ║
// ║  Cron: */10 * * * * php /path/to/app/cron_fetch_emails.php ║
// ║                                                          ║
// ║  Lee correos NO LEÍDOS del remitente configurado,        ║
// ║  sube adjuntos a Google Drive y guarda el resultado      ║
// ║  en Firebase Realtime Database bajo /emails.             ║
// ╚══════════════════════════════════════════════════════════╝

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

// ── Cargar variables de entorno ──────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$config = require __DIR__ . '/config.php';

// ── Variables de configuración IMAP ─────────────────────────────────────────
$imapHost      = $_ENV['IMAP_HOST']          ?? 'imap.gmail.com';
$imapPort      = (int) ($_ENV['IMAP_PORT']   ?? 993);
$imapUser      = $_ENV['IMAP_USER']          ?? '';
$imapPass      = $_ENV['IMAP_PASS']          ?? '';
$targetSender  = $_ENV['EMAIL_SENDER']       ?? '';

// ── Variables Firebase ───────────────────────────────────────────────────────
$firebaseUrl   = rtrim($_ENV['FIREBASE_DATABASE_URL'] ?? '', '/');
$firebaseSecret = $_ENV['FIREBASE_SECRET'] ?? '';

// ── Validar configuración ────────────────────────────────────────────────────
if (empty($imapUser) || empty($imapPass)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Faltan IMAP_USER o IMAP_PASS en .env\n";
    exit(1);
}
if (empty($targetSender)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Falta EMAIL_SENDER en .env\n";
    exit(1);
}
if (empty($firebaseUrl) || empty($firebaseSecret)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Faltan FIREBASE_DATABASE_URL o FIREBASE_SECRET en .env\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronización de correos...\n";

// ── Conectar a IMAP usando Webklex PHP-IMAP ──────────────────────────────────
$cm = new ClientManager();
$client = $cm->make([
    'host'          => $imapHost,
    'port'          => $imapPort,
    'encryption'    => 'ssl',
    'validate_cert' => true,
    'username'      => $imapUser,
    'password'      => $imapPass,
    'protocol'      => 'imap'
]);

try {
    $client->connect();
} catch (ConnectionFailedException $ex) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No se pudo conectar al servidor IMAP. " . $ex->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Conectado. Buscando correos de: {$targetSender}\n";

// ── Obtener INBOX (Recibidos) ──────────────────────────────
$inboxFolder = $client->getFolder('INBOX');
$threeMonthsAgo = date('d.m.Y', strtotime('-3 months'));
$inboxQuery = $inboxFolder->query()->from($targetSender)->since($threeMonthsAgo);

$forceAll = in_array('--all', $argv ?? []);

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
        $sentQuery = clone $sentFolder->query()->to($targetSender)->since($threeMonthsAgo);
        $sentMsgs = $sentQuery->all()->setFetchOrderDesc()->limit(50)->get();
        foreach ($sentMsgs as $msg) {
            if ($msg) $allMessages[] = ['msg' => $msg, 'direction' => 'out'];
        }
    }
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Aviso: No se pudo leer la carpeta de Enviados. " . $e->getMessage() . "\n";
}

if (empty($allMessages)) {
    echo "[" . date('Y-m-d H:i:s') . "] No hay correos nuevos en INBOX ni enviados.\n";
    $client->disconnect();
    exit(0);
}

// Ordenar todos cronológicamente (más recientes primero)
usort($allMessages, function($a, $b) {
    $dateA = strtotime((string) $a['msg']->getDate() ?: 'now');
    $dateB = strtotime((string) $b['msg']->getDate() ?: 'now');
    return $dateB <=> $dateA;
});

echo "[" . date('Y-m-d H:i:s') . "] Procesando " . count($allMessages) . " correo(s) en total (Entrada + Salida).\n";

// ── Inicializar cliente Google Drive ─────────────────────────────────────────
$driveClient = null;
$driveService = null;
$credPath = $config['credentials_path'] ?? '';
if (file_exists($credPath)) {
    $driveClient = new Google\Client();
    $driveClient->setAuthConfig($credPath);
    $driveClient->setScopes([Google\Service\Drive::DRIVE]);
    $driveClient->setApplicationName('Gastos Naia Emails');
    $driveService = new Google\Service\Drive($driveClient);
}

// ── Folder de Drive para guardar adjuntos ────────────────────────────────────
$comunicadosDriveFolder = $config['comunicados_drive_folder_id'] ?? '';

// ── Procesar cada correo ──────────────────────────────────────────────────────
foreach ($allMessages as $item) {
    $message = $item['msg'];
    $direction = $item['direction'];

    $subject = mb_decode_mimeheader((string) $message->getSubject()) ?: '(Sin asunto)';
    $dateStr = (string) $message->getDate();
    $date    = $dateStr ? date('Y-m-d H:i:s', strtotime($dateStr)) : date('Y-m-d H:i:s');
    $from    = (string) $message->getFrom()[0]->mail ?? ($direction === 'out' ? $_ENV['IMAP_USER'] : $targetSender);

    echo "  → Procesando [{$direction}]: \"{$subject}\" ({$date})\n";

    // ── Extraer cuerpo del mensaje ────────────────────────────────────────────
    $body = '';
    if ($message->hasTextBody()) {
        $body = $message->getTextBody();
    } elseif ($message->hasHTMLBody()) {
        $body = strip_tags($message->getHTMLBody());
    }
    
    // Truncar cuerpo en 4000 chars para Firebase (límite práctico)
    $body = mb_substr(trim($body), 0, 4000);

    $attachments = [];
    
    // getAttachments() solo devuelve Content-Disposition: attachment.
    // Usamos getParts() para capturar también los adjuntos "inline" (como PDFs embebidos).
    $allParts = $message->getAttachments();
    
    // Si no hay adjuntos estándar, intentar a través de las partes raw
    if ($allParts->isEmpty() && method_exists($message, 'getPart')) {
        // Fallback: si hay partes de tipo application/* o image/* con nombre → tratarlas como adjuntos
        $allParts = collect($message->getParts())->filter(function($part) {
            $name = (string)($part->name ?? '');
            $type = strtolower((string)($part->type ?? ''));
            return !empty($name) && in_array($type, ['application', 'image']);
        });
    }
    
    foreach ($allParts as $attachment) {
        $filename = mb_decode_mimeheader((string) $attachment->name);
        if (empty($filename)) continue; // Saltar partes sin nombre
        
        $content = $attachment->getContent();
        if (empty($content)) continue;
        
        $localTmp = sys_get_temp_dir() . '/' . uniqid('email_attach_') . '_' . basename($filename);
        
        file_put_contents($localTmp, $content);
        
        // Extraer y transformar el original localTemporal al público permanente
        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
        $finalName = uniqid() . '_' . $safeName;
        $publicPath = __DIR__ . '/public/archivos_correos/' . $finalName;
        
        rename($localTmp, $publicPath);
        $driveUrl = 'archivos_correos/' . $finalName;
        echo "      ✓ Adjunto desempaquetado y subido localmente: {$safeName}\n";
        
        @unlink($localTmp);

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $attachments[] = [
            'filename' => $filename,
            'type'     => $ext ?: 'unknown',
            'url'      => $driveUrl,
        ];
    }

    // ── Guardar en Firebase ───────────────────────────────────────────────────
    $rawMessageId = (string) ($message->getMessageId() ?: uniqid('msg_'));
    $msgIdForHeader = strpos($rawMessageId, '<') === false ? "<{$rawMessageId}>" : $rawMessageId;
    
    $emailData = [
        'id'          => $msgIdForHeader, // Guardar el ID con formato corchetes para el In-Reply-To
        'subject'     => $subject,
        'from'        => $from,
        'to'          => ($direction === 'out' ? $targetSender : $_ENV['IMAP_USER']),
        'direction'   => $direction,
        'date'        => $date,
        'timestamp'   => strtotime($date),
        'body'        => $body,
        'attachments' => $attachments,
        'isRead'      => ($direction === 'out') ? true : false, // Los propios nacen leídos
    ];

    $saved = saveToFirebase($firebaseUrl, $firebaseSecret, $emailData);
    if ($saved) {
        echo "      ✓ Guardado en Firebase.\n";
        // Marcar como leído en IMAP
        $message->setFlag(['Seen']);

        // ── Notificación Telegram (solo correos entrantes de Irene) ──────────
        if ($direction === 'in') {
            $telegramToken  = $config['telegram_token']  ?? '';
            $telegramChatId = $config['telegram_chat_id'] ?? '';

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
                echo "      📱 Notificación Telegram enviada.\n";
            }
        }
    } else {
        echo "      ✗ Error al guardar en Firebase.\n";
    }

}

$client->disconnect();
echo "[" . date('Y-m-d H:i:s') . "] Sincronización completada.\n";

// ────────────────────────────────────────────────────────────────────────────
//  Funciones auxiliares
// ────────────────────────────────────────────────────────────────────────────

function uploadToDrive(Google\Service\Drive $drive, string $localPath, string $filename, string $folderId): string
{
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $filename,
        'parents' => [$folderId],
    ]);
    
    $mimeType = @mime_content_type($localPath);
    if (!$mimeType) {
        $mimeType = 'application/octet-stream';
    }
    
    $content  = file_get_contents($localPath);

    $file = $drive->files->create($fileMetadata, [
        'data'       => $content,
        'mimeType'   => $mimeType,
        'uploadType' => 'multipart',
        'fields'     => 'id, webViewLink',
        'supportsAllDrives' => true,
    ]);

    return $file->getWebViewLink() ?? '';
}

function saveToFirebase(string $dbUrl, string $secret, array $data): bool
{
    // Usar el Message-ID como clave determinista para evitar duplicados.
    // Firebase requiere que la clave no tenga caracteres especiales.
    $rawId = $data['id'] ?? uniqid('msg_');
    // Quitar <> y caracteres no válidos para clave Firebase (solo letras, números, -, _)
    $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($rawId, '<>'));
    $key = substr($key, 0, 128); // Limitar longitud

    // PUT hace upsert: si ya existe el correo con esta clave -> lo sobreescribe (no duplica)
    $url  = "{$dbUrl}/emails/{$key}.json?auth={$secret}";
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
