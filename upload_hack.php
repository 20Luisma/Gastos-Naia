<?php
$json = file_get_contents('php://input');
if (empty($json)) die("Empty payload");
file_put_contents(__DIR__ . '/credentials/firebase-service-account.json', $json);
file_put_contents(__DIR__ . '/credentials/service-account.json', $json);
echo "Written OK";
