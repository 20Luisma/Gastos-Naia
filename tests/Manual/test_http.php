<?php

$baseUrl = 'http://127.0.0.1:8000';

function req($url, $cookie = '', $post = null)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, /*http_build_query*/($post));
    }
    $res = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($res, 0, $header_size);
    $body = substr($res, $header_size);
    curl_close($ch);

    // Extract PHPSESSID
    $newCookie = '';
    if (preg_match('/Set-Cookie: (PHPSESSID=[^;]+)/', $header, $matches)) {
        $newCookie = $matches[1];
    }
    return [$body, $newCookie, $header];
}

echo "1. Haciendo login...\n";
list($body, $cookie, $header) = req($baseUrl . '/?action=login', '', [
    'username' => 'irene',
    'password' => 'GastosNaia2026!'
]);

if (!$cookie && preg_match('/PHPSESSID=([^;]+)/', $header, $matches)) {
    $cookie = "PHPSESSID=" . $matches[1];
}

echo "Cookie obtenida: $cookie\n";

echo "\n2. Pidiendo gastos de marzo sin mes explicitado(?action=expenses)...\n";
list($res2) = req($baseUrl . '/?action=expenses&year=2026&month=', $cookie);
echo substr($res2, 0, 500) . "\n\n";

echo "3. Pidiendo calendario (?action=calendar_events)...\n";
list($res3) = req($baseUrl . '/?action=calendar_events&year=2026&month=3', $cookie);
echo substr($res3, 0, 500) . "\n\n";

echo "4. Pidiendo AI (?action=ai_ask)...\n";
list($res4) = req($baseUrl . '/?action=ai_ask', $cookie, '{"question":"hola"}');
echo substr($res4, 0, 500) . "\n\n";

