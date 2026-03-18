<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Webklex\PHPIMAP\ClientManager;

$cm = new ClientManager();
$client = $cm->make([
    'host'          => 'imap.gmail.com',
    'port'          => 993,
    'encryption'    => 'ssl',
    'validate_cert' => true,
    'username'      => $_ENV['IMAP_USER'],
    'password'      => $_ENV['IMAP_PASS'],
    'protocol'      => 'imap'
]);

try {
    $client->connect();
    $folders = $client->getFolders();
    foreach ($folders as $folder) {
        echo $folder->path . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
