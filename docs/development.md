# Guía de Desarrollo

## Requisitos

- PHP 8.0 o superior
- Composer
- Cuenta de Google Cloud con acceso a Sheets API y Drive API
- Service Account con permisos sobre las hojas de cálculo

---

## Setup local

### 1. Clonar el repositorio

```bash
git clone https://github.com/20Luisma/Gastos-Naia.git
cd Gastos-Naia
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Variables de entorno

```bash
cp .env.example .env
```

Editar `.env` con las credenciales de Google OAuth:

```dotenv
GOOGLE_CLIENT_ID="tu-client-id.apps.googleusercontent.com"
GOOGLE_CLIENT_SECRET="tu-client-secret"
GOOGLE_REFRESH_TOKEN="tu-refresh-token"
```

### 4. Service Account

Obtener el JSON del Service Account desde Google Cloud Console y colocarlo en:

```
credentials/service-account.json
```

### 5. Configurar Spreadsheets

Editar `config.php` y añadir los IDs de las hojas de cálculo de Google Sheets por año.

### 6. Lanzar servidor local

```bash
php -S localhost:8000
```

Abrir en el navegador: http://localhost:8000

---

## Estructura de ramas

| Rama | Propósito |
|------|-----------|
| `main` | Producción — deploys automáticos a Hostinger |

> Se recomienda trabajar en feature branches y hacer PR a `main`.

---

## Convenciones de código

- **PSR-4** para autoloading de clases
- **PSR-12** para estilo de código PHP
- Nombres de clases en `PascalCase`
- Métodos y variables en `camelCase`
- Comentarios en español

---

## Añadir un nuevo año

1. Crear la hoja de cálculo en Google Sheets (o usar la plantilla)
2. Compartir la hoja con el email del Service Account (permiso Editor)
3. Añadir el ID al array `spreadsheets` en `config.php`
4. Añadir la carpeta Drive correspondiente al array `drive_folders`
5. Hacer commit y push → el deploy es automático

---

## Google Cloud: obtener credenciales

### Service Account

1. Google Cloud Console → IAM → Service Accounts → Crear
2. Descargar el JSON de la key
3. Guardar como `credentials/service-account.json`
4. Compartir cada Spreadsheet con el email del Service Account

### OAuth (Refresh Token)

El Refresh Token se usa para operaciones que requieren permisos de usuario (crear años, copiar hojas). Se obtiene mediante flujo OAuth desde la app la primera vez.
