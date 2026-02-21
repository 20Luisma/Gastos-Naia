<?php
/**
 * Script interactivo para configurar la autorización OAuth 2.0 y
 * obtener el "Refresh Token" (Llave Maestra) necesario para subir recibos.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Cargar .env si existe
if (class_exists('Dotenv\Dotenv') && file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$clientSecret = "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";

echo "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Setup Google Drive OAuth</title></head>";
echo "<body style='font-family: system-ui, -apple-system, sans-serif; background-color: #f9f9f9; color: #333; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh;'>";
echo "<div style='background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); max-width: 600px; width: 100%; text-align: center;'>";

// Validar que el .env esté relleno
if (empty($clientId) || empty($clientSecret)) {
    echo "<h2 style='color: #ef4444;'>❌ Faltan las credenciales</h2>";
    echo "<p>No se ha encontrado <b>GOOGLE_CLIENT_ID</b> o <b>GOOGLE_CLIENT_SECRET</b> en el archivo <code>.env</code>.</p>";
    echo "<p>Por favor, obtén estas claves de Google Cloud Console, pégalas en el archivo <b>.env</b> y recarga esta página.</p>";
    echo "</div></body></html>";
    exit;
}

$client = new \Google\Client();
$client->setAuthConfig([
    'web' => [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uris' => ['http://localhost:8080/setup-drive.php']
    ]
]);
$client->setAccessType('offline');
$client->setPrompt('consent'); // Forzar pantalla para asegurar Refresh Token
$client->addScope(\Google\Service\Drive::DRIVE);

if (empty($_GET['code'])) {
    $authUrl = $client->createAuthUrl();
    echo "<h2 style='color: #10b981; margin-top: 0;'>Paso 1: Vincular Cuenta de Google 🎉</h2>";
    echo "<p style='color: #555; text-align: left;'>Para que Gastos Naia pueda subir recibos a tu cuenta personal de Google Drive necesitas autorizar la aplicación una única vez.</p>";
    echo "<p style='color: #555; text-align: left;'>Haz clic en el botón de abajo y selecciona tu cuenta de Google. Cuando Google te pregunte si confías en esta aplicación que has creado, haz clic en Continuar.</p>";
    echo "<a href='$authUrl' style='display: inline-block; margin-top: 20px; padding: 12px 24px; background: #4285F4; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;'>🔑 Iniciar Sesión con Google</a>";
} else {
    // Intercambiar código por token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        echo "<h2 style='color: #ef4444;'>Error en la vinculación ⚠️</h2>";
        echo "<p>Detalle: " . htmlspecialchars($token['error']) . "</p>";
        echo "<a href='/setup-drive.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 6px;'>Volver a intentar</a>";
        echo "</div></body></html>";
        exit;
    }

    if (isset($token['refresh_token'])) {
        echo "<h2 style='color: #10b981; margin-top: 0;'>¡Paso 2 Superado! 🚀</h2>";
        echo "<p style='color: #555;'>Google te ha dado tu Llave Maestra (Refresh Token). Cópiala entera con cuidado y pégala en tu archivo <b>.env</b> al lado de <code>GOOGLE_REFRESH_TOKEN=</code></p>";
        echo "<textarea style='width: 100%; height: 60px; padding: 10px; margin-top: 15px; border: 1px solid #ccc; border-radius: 6px; font-family: monospace; font-size: 14px; resize: none;' readonly onclick='this.select()'>" . $token['refresh_token'] . "</textarea>";
        echo "<p style='margin-top: 20px; font-size: 0.9em; color: #666;'>Una vez guardes el archivo .env, <b>¡ya estará todo listo!</b> Podrás cerrar esta pestaña y usar el Dashboard de Gastos Naia normalmente para subir recibos.</p>";
    } else {
        echo "<h2 style='color: #f59e0b;'>Falta la Llave Maestra 🔑</h2>";
        echo "<p style='color: #555;'>Parece que Google ha iniciado sesión correctamente pero no nos ha dado la llave de larga duración (Refresh Token).</p>";
        echo "<p style='color: #555; font-size: 0.9em;'>Esto suele pasar si ya le habías dado permisos a esta app antes. Para arreglarlo, tienes que ir a <b>Gestión de tu Cuenta de Google -> Seguridad -> Conexiones de terceros</b>, quitarle el acceso a tu app 'Gastos Naia' y volver a intentarlo aquí.</p>";
        echo "<a href='/setup-drive.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #e5e7eb; color: #333; text-decoration: none; border-radius: 6px;'>Reintentar Vinculación</a>";
    }
}

echo "</div></body></html>";
