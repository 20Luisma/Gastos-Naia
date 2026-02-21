<?php
require 'vendor/autoload.php';
$config = require 'config.php';

$client = new \Google_Client();
$client->setAuthConfig('credentials/service-account.json');
$client->addScope(\Google_Service_Sheets::SPREADSHEETS_READONLY);
$service = new \Google_Service_Sheets($client);

foreach ($config['spreadsheets'] as $year => $id) {
    echo "Testing $year ($id)...\n";
    try {
        $spreadsheet = $service->spreadsheets->get($id);
        echo "  -> SUCCESS: " . $spreadsheet->getProperties()->getTitle() . "\n";
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        $decoded = json_decode($msg, true);
        if ($decoded && isset($decoded['error'])) {
            echo "  -> ERROR: " . $decoded['error']['message'] . " (" . $decoded['error']['status'] . ")\n";
        } else {
            echo "  -> ERROR: " . $msg . "\n";
        }
    }
}
