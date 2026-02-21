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
        if (is_array($decoded) && isset($decoded['error'])) {
            $err = $decoded['error'];
            if (is_array($err) && isset($err['message'])) {
                echo "  -> ERROR: " . $err['message'] . "\n";
            } else {
                echo "  -> ERROR: " . (is_string($err) ? $err : json_encode($err)) . "\n";
            }
        } else {
            echo "  -> ERROR: " . $msg . "\n";
        }
    }
}
