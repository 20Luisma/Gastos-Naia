<?php
/**
 * send_reminders.php
 * 
 * Puede ejecutarse como:
 *   - Cron CLI:  php /ruta/send_reminders.php (en public/)
 * 
 * URL pública: https://contenido.creawebes.com/GastosNaia/send_reminders.php?secret=naia_secret_2026
 * EasyCron llama a esta URL cada minuto.
 * URL del panel de EasyCron: https://www.easycron.com/cron-jobs
 */

// Protección: si viene por HTTP, exigir el secret
if (PHP_SAPI !== 'cli') {
    $config = require __DIR__ . '/config.php';
    $expected = $config['webhook_secret'] ?? '';
    $provided = $_GET['secret'] ?? '';
    if ($expected === '' || $provided !== $expected) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$remindersFile = __DIR__ . '/storage/reminders.json';

if (!file_exists($remindersFile)) {
    exit(0);
}

$config = require __DIR__ . '/config.php';

$telegramToken  = $config['telegram_token']  ?? '';
$telegramChatId = $config['telegram_chat_id'] ?? '';

if (empty($telegramToken) || empty($telegramChatId)) {
    exit(0);
}

$reminders = json_decode(file_get_contents($remindersFile), true) ?? [];
$modified  = false;
$now       = new DateTime('now', new DateTimeZone('Europe/Madrid'));

foreach ($reminders as &$reminder) {
    // Saltar si ya fue enviado o si faltan datos
    if (!empty($reminder['sent'])) continue;
    if (empty($reminder['fireAt']))  continue;

    $fireAt = new DateTime($reminder['fireAt'], new DateTimeZone('Europe/Madrid'));

    // Disparar si la hora de aviso es "ahora" (dentro del minuto actual)
    $diffSeconds = $now->getTimestamp() - $fireAt->getTimestamp();

    // Margen: entre 0 y 119 segundos de retraso (2 minutos para cubrir retrasos de EasyCron)
    if ($diffSeconds >= 0 && $diffSeconds < 120) {
        $title           = $reminder['title'] ?? 'Evento';
        $reminderMinutes = $reminder['reminderMinutes'] ?? '';
        $startIso        = $reminder['startIso'] ?? '';

        $startFormatted = '';
        if ($startIso) {
            $startDt = new DateTime($startIso, new DateTimeZone('Europe/Madrid'));
            $startFormatted = $startDt->format('d/m/Y H:i');
        }

        if ($reminderMinutes === 1) {
            $timeLabel = '1 minuto';
        } elseif ($reminderMinutes < 60) {
            $timeLabel = "{$reminderMinutes} minutos";
        } elseif ($reminderMinutes === 60) {
            $timeLabel = '1 hora';
        } elseif ($reminderMinutes < 1440) {
            $hours = $reminderMinutes / 60;
            $timeLabel = "{$hours} horas";
        } else {
            $timeLabel = '1 día';
        }

        $msg = "⏰ <b>Recordatorio</b>\n\n";
        $msg .= "📅 <b>{$title}</b>\n";
        if ($startFormatted) {
            $msg .= "🕐 Empieza a las <b>{$startFormatted}</b>\n";
        }
        $msg .= "⚡ En <b>{$timeLabel}</b>";

        // Enviar por Telegram
        $url = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
        $payload = http_build_query([
            'chat_id'    => $telegramChatId,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ]
        ]);

        @file_get_contents($url, false, $ctx);

        $reminder['sent'] = true;
        $modified = true;
    }
}
unset($reminder);

if ($modified) {
    file_put_contents($remindersFile, json_encode($reminders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
