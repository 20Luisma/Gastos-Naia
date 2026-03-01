<?php
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gastos Naia — Acceso</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #0F0F1A;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* Orbs de fondo */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            pointer-events: none;
        }

        body::before {
            width: 500px;
            height: 500px;
            background: #6C63FF;
            top: -150px;
            left: -100px;
        }

        body::after {
            width: 400px;
            height: 400px;
            background: #3B82F6;
            bottom: -100px;
            right: -100px;
        }

        .card {
            background: rgba(26, 26, 46, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 24px;
            padding: 48px 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.5);
        }

        .logo {
            width: 110px;
            height: 110px;
            border-radius: 24px;
            margin: 0 auto 20px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(108, 99, 255, 0.4);
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        h1 {
            text-align: center;
            color: #fff;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .subtitle {
            text-align: center;
            color: rgba(255, 255, 255, 0.4);
            font-size: 14px;
            margin-bottom: 36px;
        }

        .field {
            margin-bottom: 16px;
        }

        label {
            display: block;
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 14px 44px 14px 16px;
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }

        input:focus {
            border-color: rgba(108, 99, 255, 0.6);
            background: rgba(108, 99, 255, 0.07);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            padding: 12px 16px;
            color: #f87171;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toggle-eye {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.35);
            font-size: 18px;
            padding: 0;
            line-height: 1;
            transition: color 0.2s;
        }

        .toggle-eye:hover {
            color: rgba(255, 255, 255, 0.7);
        }

        .password-wrap {
            position: relative;
        }

        button[type="submit"] {
            width: 100%;
            background: linear-gradient(135deg, #6C63FF, #3B82F6);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: opacity 0.2s, transform 0.1s;
            box-shadow: 0 4px 20px rgba(108, 99, 255, 0.35);
        }

        button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        button:active {
            transform: translateY(0);
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="logo"><img src="public/assets/appnaia.jpeg" alt="Gastos Naia"></div>
        <h1>Gastos Naia</h1>
        <p class="subtitle">Introduce tus credenciales para acceder</p>

        <?php if ($error === 'invalid'): ?>
            <div class="error-msg">
                <span>⚠️</span> Usuario o contraseña incorrectos.
            </div>
        <?php endif; ?>

        <form method="POST" action="?action=login">
            <div class="field">
                <label>Usuario</label>
                <input type="text" name="username" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label>Contraseña</label>
                <div class="password-wrap">
                    <input type="password" name="password" id="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-eye" onclick="togglePassword()"
                        title="Mostrar/ocultar">👁</button>
                </div>
            </div>
            <button type="submit">Entrar →</button>
        </form>
        <script>
            function togglePassword() {
                const input = document.getElementById('password');
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        </script>
    </div>
</body>

</html>