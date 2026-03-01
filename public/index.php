<?php

/**
 * Gastos Naia — App Web Completa (Clean Architecture)
 *
 * Controlador Frontal:
 * - Protege todas las rutas con autenticación por sesión
 * - Redirige a login si no hay sesión activa
 * - Delega a ApiController si "?action=" está presente
 * - Muestra la plantilla HTML principal en caso contrario
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GastosNaia\Presentation\ApiController;

// Cargar variables de entorno (.env)
if (class_exists('Dotenv\\Dotenv') && file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();
}

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Configuration file config.php not found.");
}
$config = require $configPath;

// ─── Sesión ───────────────────────────────────────────────────────────────────
session_start();

$action = $_GET['action'] ?? null;

// ── Acción: login (POST) ──────────────────────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = strtolower(trim($_POST['username'] ?? ''));
    $pass = $_POST['password'] ?? '';

    // Soporte multiusuario via APP_USERS JSON: {"luisma":"pass","irene":"pass"}
    $usersJson = $_ENV['APP_USERS'] ?? '';
    $users = json_decode($usersJson, true) ?? [];

    // Fallback a APP_USER / APP_PASSWORD si no hay APP_USERS
    if (empty($users)) {
        $envUser = strtolower($_ENV['APP_USER'] ?? '');
        $envPass = $_ENV['APP_PASSWORD'] ?? '';
        if ($envUser)
            $users[$envUser] = $envPass;
    }

    $valid = isset($users[$user]) && $users[$user] === $pass;

    if ($valid) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $user;
        header('Location: ./');
    } else {
        header('Location: ./?error=invalid');
    }
    exit;
}

// ── Acción: logout ────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    header('Location: ./');
    exit;
}

// ── Guard: si no está autenticado, mostrar login ──────────────────────────────
if (empty($_SESSION['authenticated'])) {
    // Las peticiones AJAX/API devuelven 401 JSON
    if ($action && $action !== 'login') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado. Por favor inicia sesión.']);
        exit;
    }
    require __DIR__ . '/../templates/login.php';
    exit;
}

// ─── Usuario autenticado ──────────────────────────────────────────────────────
if ($action) {
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
    $monthLabels = $config['month_labels'];
    $years = array_keys($config['spreadsheets']);
    sort($years);
    require __DIR__ . '/../templates/home.php';
}
