<?php
// Router para el servidor de desarrollo PHP
// Sirve archivos estáticos desde la raíz del proyecto y delega el resto a public/index.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si el archivo existe físicamente (relativo a la raíz del proyecto), servirlo directamente
// Ej: /public/assets/styles.css  →  app_php/public/assets/styles.css
$file = __DIR__ . $uri;
$filePublic = __DIR__ . '/public' . $uri;
if ($uri !== '/' && ((file_exists($file) && is_file($file)) || (file_exists($filePublic) && is_file($filePublic)))) {
    return false; // PHP lo sirve directamente con el MIME correcto
}

// Todo lo demás → front controller
require __DIR__ . '/public/index.php';
