<?php
require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/credentials/service-account.json');
$client = new \Google\Client();
$client->useApplicationDefaultCredentials();
$client->addScope(\Google\Service\Drive::DRIVE);
$drive = new \Google\Service\Drive($client);

try {
    $results = $drive->files->listFiles([
        'q' => "mimeType = 'application/vnd.google-apps.folder' and name contains 'Renta' and trashed = false",
        'fields' => 'files(id, name)',
    ]);
    echo "Carpetas Renta visibles por el Service Account:\n";
    foreach ($results->getFiles() as $file) {
        echo sprintf("- %s (ID: %s)\n", $file->getName(), $file->getId());
    }

    $results2 = $drive->files->listFiles([
        'q' => "mimeType = 'application/vnd.google-apps.folder' and name contains '2026' and trashed = false",
        'fields' => 'files(id, name)',
    ]);
    echo "\nCarpetas que contienen 2026 visibles por el Service Account:\n";
    foreach ($results2->getFiles() as $file) {
        echo sprintf("- %s (ID: %s)\n", $file->getName(), $file->getId());
    }
} catch (\Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
