<?php
require_once __DIR__ . '/vendor/autoload.php';
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/credentials/firebase-service-account.json');
$client = new \Google\Client();
$client->useApplicationDefaultCredentials();
$client->addScope(\Google\Service\Drive::DRIVE);
$drive = new \Google\Service\Drive($client);

try {
    $results = $drive->files->listFiles([
        'q' => "mimeType = 'application/vnd.google-apps.folder' and name contains '2026' and trashed = false",
        'fields' => 'files(id, name)'
    ]);
    echo "Carpetas visibles por el bot:\n";
    foreach ($results->getFiles() as $f) {
        echo "- Name: " . $f->getName() . " | ID: " . $f->getId() . "\n";
    }
} catch (\Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
