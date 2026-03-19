<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Webklex\PHPIMAP\ClientManager;

$cm = new ClientManager();
$client = $cm->make([
    'host'          => $_ENV['IMAP_HOST'],
    'port'          => $_ENV['IMAP_PORT'],
    'encryption'    => $_ENV['IMAP_ENCRYPTION'] ?? 'ssl',
    'validate_cert' => true,
    'username'      => $_ENV['IMAP_USER'],
    'password'      => $_ENV['IMAP_PASS'],
    'protocol'      => 'imap'
]);
$client->connect();
$targetSender = $_ENV['EMAIL_SENDER'] ?? 'ireneriv_1976@hotmail.com';
echo "Conectado. Buscando correos a: $targetSender\n";

$sentFolder = $client->getFolder('[Gmail]/Enviados');
if (!$sentFolder) {
    echo "NO EXISTE CARPETA [Gmail]/Enviados\n";
    die();
}
echo "Carpeta Enviados encontrada.\n";

$threeMonthsAgo = date('d-M-Y', strtotime('-3 months'));

// Sin filtro "TO":
$allSentMsgs = $sentFolder->query()->since($threeMonthsAgo)->all()->setFetchOrderDesc()->limit(5)->get();
echo "Ultimos 5 mensajes ENVIADOS (sin filtro TO):\n";
foreach($allSentMsgs as $m) {
    echo " - " . $m->getSubject() . " | A: " . json_encode($m->getTo()) . "\n";
}

// Con filtro "TO":
$filteredMsgs = $sentFolder->query()->to($targetSender)->since($threeMonthsAgo)->all()->setFetchOrderDesc()->limit(5)->get();
echo "\nUltimos 5 mensajes con filtro TO($targetSender):\n";
foreach($filteredMsgs as $m) {
    echo " - " . $m->getSubject() . "\n";
}
