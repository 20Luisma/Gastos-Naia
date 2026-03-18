<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  cron_fetch_emails.php — Sincronizador de correos       ║
 * ║  Cron: * /10 * * * * php /path/to/app/cron_fetch_emails.php  ║
 * ║                                                          ║
 * ║  Lee correos NO LEÍDOS del remitente configurado,        ║
 * ║  sube adjuntos a Google Drive y guarda el resultado      ║
 * ║  en Firebase Realtime Database bajo /emails.             ║
 * ╚══════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

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
if (!extension_loaded('imap')) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: La extensión PHP 'ext-imap' no está instalada en este servidor.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronización de correos...\n";

// ── Conectar a IMAP ──────────────────────────────────────────────────────────
$mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $imapHost, $imapPort);
$imap = @imap_open($mailbox, $imapUser, $imapPass, 0, 1);
if (!$imap) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No se pudo conectar al servidor IMAP. " . imap_last_error() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Conectado. Buscando correos de: {$targetSender}\n";

// ── Buscar correos no leídos del remitente objetivo ──────────────────────────
$searchCriteria = sprintf('UNSEEN FROM "%s"', $targetSender);
$emailIds = imap_search($imap, $searchCriteria);

if ($emailIds === false || count($emailIds) === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] No hay correos nuevos.\n";
    imap_close($imap);
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Encontrados " . count($emailIds) . " correo(s) nuevo(s).\n";

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
foreach ($emailIds as $emailId) {
    $header  = imap_headerinfo($imap, $emailId);
    $subject = isset($header->subject) ? imap_utf8($header->subject) : '(Sin asunto)';
    $date    = isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : date('Y-m-d H:i:s');
    $from    = isset($header->from[0]->mailbox, $header->from[0]->host)
                ? $header->from[0]->mailbox . '@' . $header->from[0]->host
                : $targetSender;

    echo "  → Procesando: \"{$subject}\" ({$date})\n";

    // ── Extraer cuerpo del mensaje ────────────────────────────────────────────
    $body       = '';
    $attachments = [];
    $structure  = imap_fetchstructure($imap, $emailId);

    if (isset($structure->parts) && count($structure->parts) > 0) {
        // Multipart message — iterate parts
        foreach ($structure->parts as $partNum => $part) {
            $sectionNum = (string)($partNum + 1);

            // Texto plano del cuerpo
            if ($part->type === TYPETEXT && strtolower($part->subtype) === 'plain' && empty($part->disposition)) {
                $rawBody = imap_fetchbody($imap, $emailId, $sectionNum);
                $encoding = $part->encoding ?? ENCOTHER;
                $body = decodeImapPart($rawBody, $encoding, $part->parameters ?? []);
                continue;
            }

            // Adjunto
            if (!empty($part->disposition) && strtolower($part->disposition) === 'attachment') {
                $filename = getAttachmentFilename($part);
                if ($filename) {
                    $rawData = imap_fetchbody($imap, $emailId, $sectionNum);
                    $fileData = decodeImapPart($rawData, $part->encoding ?? ENCOTHER, []);
                    $localTmp = sys_get_temp_dir() . '/' . uniqid('email_attach_') . '_' . basename($filename);
                    file_put_contents($localTmp, $fileData);

                    // Subir a Google Drive si está configurado
                    $driveUrl = '';
                    if ($driveService && !empty($comunicadosDriveFolder)) {
                        try {
                            $driveUrl = uploadToDrive($driveService, $localTmp, $filename, $comunicadosDriveFolder);
                            echo "      ✓ Adjunto subido a Drive: {$filename}\n";
                        } catch (\Exception $e) {
                            echo "      ✗ Error subiendo adjunto {$filename}: " . $e->getMessage() . "\n";
                        }
                    }
                    @unlink($localTmp);

                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $attachments[] = [
                        'filename' => $filename,
                        'type'     => $ext ?: 'unknown',
                        'url'      => $driveUrl,
                    ];
                }
            }
        }
    } else {
        // Single-part message
        $rawBody = imap_body($imap, $emailId);
        $body    = decodeImapPart($rawBody, $structure->encoding ?? ENCOTHER, $structure->parameters ?? []);
    }

    // Truncar cuerpo en 4000 chars para Firebase (límite práctico)
    $body = mb_substr(trim($body), 0, 4000);

    // ── Guardar en Firebase ───────────────────────────────────────────────────
    $emailData = [
        'subject'     => $subject,
        'from'        => $from,
        'date'        => $date,
        'body'        => $body,
        'attachments' => $attachments,
        'synced_at'   => date('Y-m-d H:i:s'),
    ];

    $saved = saveToFirebase($firebaseUrl, $firebaseSecret, $emailData);
    if ($saved) {
        echo "      ✓ Guardado en Firebase.\n";
        // Marcar como leído en la bandeja
        imap_setflag_full($imap, (string)$emailId, '\\Seen');
    } else {
        echo "      ✗ Error al guardar en Firebase.\n";
    }
}

imap_close($imap);
echo "[" . date('Y-m-d H:i:s') . "] Sincronización completada.\n";

// ────────────────────────────────────────────────────────────────────────────
//  Funciones auxiliares
// ────────────────────────────────────────────────────────────────────────────

function decodeImapPart(string $raw, int $encoding, $params): string
{
    // Decodificar según el tipo de codificación del mensaje
    $decoded = match ($encoding) {
        ENCBASE64        => base64_decode($raw),
        ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
        default          => $raw,
    };

    // Detectar el charset para convertir a UTF-8
    $charset = 'UTF-8';
    if (is_array($params)) {
        foreach ($params as $param) {
            if (strtolower($param->attribute ?? '') === 'charset') {
                $charset = strtoupper($param->value);
                break;
            }
        }
    }

    if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $decoded;
}

function getAttachmentFilename($part): string
{
    // Buscar el nombre del adjunto en los parámetros del MIME
    $filename = '';
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $dp) {
            if (strtolower($dp->attribute) === 'filename') {
                $filename = imap_utf8($dp->value);
                break;
            }
        }
    }
    if (empty($filename) && !empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name') {
                $filename = imap_utf8($p->value);
                break;
            }
        }
    }
    return $filename;
}

function uploadToDrive(Google\Service\Drive $drive, string $localPath, string $filename, string $folderId): string
{
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name'    => $filename,
        'parents' => [$folderId],
    ]);
    $content  = file_get_contents($localPath);
    $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

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
    // Usa POST para que Firebase genere un ID push automático bajo /emails
    $url = "{$dbUrl}/emails.json?auth={$secret}";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => 10,
        ],
    ];
    $ctx    = stream_context_create($opts);
    $result = @file_get_contents($url, false, $ctx);
    return $result !== false;
}
