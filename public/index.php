<?php

/**
 * Gastos Naia — App Web Completa (Clean Architecture)
 * 
 * Controlador Frontal:
 * - Redirige a ApiController si "?action=" está presente
 * - Muestra la plantilla HTML principal en caso contrario
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GastosNaia\Presentation\ApiController;

// Cargar variables de entorno (.env)
if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Configuration file config.php not found.");
}
$config = require $configPath;

$action = $_GET['action'] ?? null;

if ($action) {
    // Si hay una acción, delegamos al controlador de la API.
    try {
        $apiController = new ApiController($config);
        $apiController->handle($action);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'Server Configuration Error: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Si no es una petición a la API, mostramos la vista HTML.
    $monthLabels = $config['month_labels'];
    $years = array_keys($config['spreadsheets']);
    sort($years);

    // Las variables anteriores serán usadas dentro de home.php
    require __DIR__ . '/../templates/home.php';
}
