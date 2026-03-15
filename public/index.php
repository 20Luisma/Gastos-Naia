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

// Cargar variables de entorno (.env) de forma segura y sin regex bugs (PHP 8.4 PCRE issue)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
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
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: ./');
    exit;
}

// ── Guard: si no está autenticado, mostrar login ──────────────────────────────
$isApiKeyValid = false;
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['secret'] ?? '';

// Si viene por JSON raw body en peticiones POST
if (empty($providedKey) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['secret'])) {
        $providedKey = $data['secret'];
    }
}

if (!empty($providedKey) && $providedKey === ($config['webhook_secret'] ?? '')) {
    $isApiKeyValid = true;
}

if (empty($_SESSION['authenticated']) && !$isApiKeyValid) {
    // Las peticiones AJAX/API devuelven 401 JSON (excepto telegram_webhook que no tiene auth en sesión)
    if ($action && $action !== 'login' && $action !== 'telegram_webhook') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado. Por favor inicia sesión o provee X-API-Key.']);
        exit;
    }
    if ($action !== 'telegram_webhook') {
        require __DIR__ . '/../templates/login.php';
        exit;
    }
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