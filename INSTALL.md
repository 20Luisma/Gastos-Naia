# 📦 Instalación — Gastos Naia Dashboard

Guía paso a paso para instalar la app de gastos en tu hosting.

---

## 1️⃣ Crear proyecto en Google Cloud y activar la API

1. Ve a [Google Cloud Console](https://console.cloud.google.com/)
2. Crea un proyecto nuevo (o usa uno existente)
3. Ve a **APIs & Services → Library** → busca **Google Sheets API** → **Habilitar**

---

## 2️⃣ Crear Service Account

1. Ve a **APIs & Services → Credentials** → **Create credentials → Service Account**
2. Nombre: ej. `gastos-naia-editor`
3. Haz clic en **Done**

### Descargar el JSON

1. Clic en el Service Account → pestaña **Keys**
2. **Add Key → Create new key → JSON** → se descarga el archivo

> ⚠️ **Nunca subas este archivo a un repositorio público.**

---

## 3️⃣ Compartir cada Spreadsheet con el Service Account

Para cada Google Sheet ("Gastos Naia 2020" a "Gastos Naia 2026"):

1. Abre el spreadsheet → **Compartir**
2. Pega el email del Service Account: `nombre@proyecto.iam.gserviceaccount.com`
3. **⚡ IMPORTANTE: Permiso de Editor** (no solo Lector — necesario para añadir gastos)
4. Desmarca "Notificar" y comparte

---

## 4️⃣ Obtener los Spreadsheet IDs

El ID está en la URL de cada spreadsheet:
```
https://docs.google.com/spreadsheets/d/AQUI_ESTA_EL_ID/edit
```

---

## 5️⃣ Subir archivos al hosting

```
public_html/gastos-naia/
├── composer.json
├── config.php
├── index.php
├── lib/
│   ├── SheetsService.php
│   └── FileUploader.php
├── assets/
│   ├── styles.css
│   └── app.js
├── uploads/               ← recibos subidos
│   └── .htaccess
├── credentials/
│   ├── .htaccess           ← Deny from all
│   └── service-account.json
└── vendor/                 ← composer install
```

---

## 6️⃣ Instalar dependencias

```bash
cd public_html/gastos-naia
composer install --no-dev
```

Si tu hosting no tiene Composer, haz `composer install --no-dev` localmente y sube la carpeta `vendor/`.

---

## 7️⃣ Configurar Spreadsheet IDs

Edita `config.php`:

```php
'spreadsheets' => [
    2020 => 'tu_ID_real_2020',
    2021 => 'tu_ID_real_2021',
    2022 => 'tu_ID_real_2022',
    2023 => 'tu_ID_real_2023',
    2024 => 'tu_ID_real_2024',
    2025 => 'tu_ID_real_2025',
    2026 => 'tu_ID_real_2026',
],
```

### Añadir año nuevo
Simplemente agrega: `2027 => 'NUEVO_ID',`

---

## 8️⃣ Permisos de escritura

Asegúrate de que la carpeta `uploads/` tenga permisos de escritura:
```bash
chmod 755 uploads/
```

---

## 9️⃣ Verificar

1. Abre `https://tu-dominio.com/gastos-naia/`
2. Pestaña **Resumen Anual** → tabla y gráfico por año
3. Pestaña **Vista Mensual** → gráfico y cards por mes
4. Pestaña **Gastos** → selecciona año/mes → añade gastos → sube recibos

---

## 🔧 Solución de problemas

| Problema | Solución |
|---|---|
| Error "archivo de credenciales no encontrado" | Verifica `credentials/service-account.json` |
| Error 403 de Google API | Comparte los spreadsheets con permiso **Editor** |
| Error "Google Sheets API has not been enabled" | Activa la API en Google Cloud Console |
| No se pueden subir archivos | `chmod 755 uploads/` |
| Tabla vacía | Verifica que la hoja se llama "Gastos Anual" y tiene "Total Final:" |

---

## 🛡️ Seguridad

- Credenciales protegidas por `credentials/.htaccess`
- Para **Nginx**: `location ~ /credentials/ { deny all; }`
- El endpoint API nunca expone credenciales
- Los uploads se sirven vía PHP (`?action=download`), no directamente

---

## 💻 Desarrollo local

```bash
cd "Gastos Naia"
composer install
php -S localhost:8080
```
Abre http://localhost:8080
