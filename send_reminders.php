<?php
/**
 * send_reminders.php
 * 
 * Cron job script: ejecutar cada minuto.
 * Lee storage/reminders.json y manda un aviso por Telegram si
 * la hora de disparo (fireAt) está dentro del minuto actual.
 * 
 * Cron entry (Hostinger):
 *   * * * * * php /home/u968396048/domains/gastos.luismasabogal.es/public_html/send_reminders.php
 */

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

    // Margen: entre 0 y 59 segundos de retraso (sin reenviar si ya pasó más de 1 min)
    if ($diffSeconds >= 0 && $diffSeconds < 60) {
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
