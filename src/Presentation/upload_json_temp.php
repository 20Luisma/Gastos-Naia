<?php
$json = file_get_contents('php://input');
file_put_contents(__DIR__ . '/../../credentials/firebase-service-account.json', $json);
echo "Credentials updated successfully.";
