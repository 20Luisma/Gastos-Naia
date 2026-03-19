<?php
// ╔══════════════════════════════════════════════════════════╗
// ║  cron_fetch_emails.php — Sincronizador de correos CLI    ║
// ║  Cron: */10 * * * * php /path/to/app/cron_fetch_emails.php ║
// ╚══════════════════════════════════════════════════════════╝

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
if (empty($_ENV['IMAP_USER'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$config = require __DIR__ . '/config.php';

$fetcher = new \GastosNaia\Application\FetchEmailsUseCase($config);
$forceAll = in_array('--all', $argv ?? []);

$result = $fetcher->execute($forceAll, true);

exit($result['success'] ? 0 : 1);
