<?php
/**
 * Herramienta de recuperación y autoguardado de Google Refresh Token
 * Gastos Naia
 */

require __DIR__ . '/vendor/autoload.php';

// Limpiar sesión temporal si se pide reiniciar
session_start();
if (isset($_GET['reset'])) {
    session_destroy();
    header("Location: auth.php");
    exit;
}

// 1. Cargar .env remotamente (igual que en index.php)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
        }
    }
}

$clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

if (empty($clientId) || empty($clientSecret)) {
    die("<h1>Error:</h1> No se encontraron GOOGLE_CLIENT_ID o GOOGLE_CLIENT_SECRET en tu archivo .env.");
}

// 2. Configurar Google Client
$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
// El redirect_uri DEBE estar autorizado en la consola de Google Cloud para este Client ID.
// Si no lo está, Google tirará un error `redirect_uri_mismatch`.
// Usualmente, los clientes configurados permiten HTTPS plano a la ruta. Probemos con la URL real.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$redirectUri = $protocol . $_SERVER['HTTP_HOST'] . explode('?', $_SERVER['REQUEST_URI'], 2)[0];

$client->setRedirectUri($redirectUri);
$client->setAccessType('offline');
$client->setPrompt('consent'); // Forzar a que nos dé un refresh_token nuevo siempre
$client->addScope(\Google\Service\Drive::DRIVE);
$client->addScope(\Google\Service\Sheets::SPREADSHEETS);

echo "<!DOCTYPE html><html style='font-family: sans-serif; padding: 40px; background: #1a1a2e; color: #fff;'><body>";
echo "<h1>🔑 Generador de Token Gastos Naia</h1>";

if (!isset($_GET['code'])) {
    $auth_url = $client->createAuthUrl();
    echo "<p>Hacé clic en el botón de abajo para autorizar la aplicación de Naia con tu cuenta de Google.</p>";
    echo "<a href='$auth_url' style='display:inline-block; padding: 15px 30px; background: #6c5ce7; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Autorizar Google Drive</a>";
} else {
    try {
        $tokenParams = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        
        if (isset($tokenParams['error'])) {
            throw new Exception($tokenParams['error_description'] ?? $tokenParams['error']);
        }

        $refreshToken = $client->getRefreshToken();

        if (empty($refreshToken)) {
            echo "<h2 style='color: #ff7675;'>❌ Google no devolvió un Refresh Token.</h2>";
            echo "<p>Esto suele pasar si ya habías autorizado antes y Google no quiso darte otro token. <a href='auth.php?reset=1' style='color:#74b9ff'>Probá reiniciar el proceso</a>.</p>";
        } else {
            // Guardar automáticamente en el .env
            $envContent = file_get_contents($envFile);
            $envContent = preg_replace('/^GOOGLE_REFRESH_TOKEN=.*$/m', 'GOOGLE_REFRESH_TOKEN="' . $refreshToken . '"', $envContent);
            
            // Si por alguna razón no estaba la línea, la agregamos al final
            if (strpos($envContent, 'GOOGLE_REFRESH_TOKEN') === false) {
                $envContent .= "\nGOOGLE_REFRESH_TOKEN=\"" . $refreshToken . "\"\n";
            }

            file_put_contents($envFile, $envContent);

            echo "<h2 style='color: #00b894;'>✅ ¡ÉXITO TOTAL!</h2>";
            echo "<p>El nuevo token se generó y se guardó <strong>automáticamente</strong> en el servidor de producción.</p>";
            echo "<div style='background: #2d3436; padding: 15px; border-radius: 8px; word-break: break-all;'>$refreshToken</div>";
            echo "<br><a href='./' style='display:inline-block; padding: 15px 30px; background: #00b894; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Volver a Gastos Naia</a>";
        }
    } catch (Exception $e) {
        echo "<h2 style='color: #ff7675;'>❌ Error al generar el Token:</h2>";
        echo "<pre style='background: #2d3436; padding: 15px; border-radius: 8px;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
        echo "<br><a href='auth.php?reset=1' style='color:#74b9ff'>Volver a intentar</a>";
    }
}

echo "</body></html>";
