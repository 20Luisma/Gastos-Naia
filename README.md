# 💰 Gastos Naia

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.0+-8892BF?style=for-the-badge&logo=php&logoColor=white)
![Google Sheets](https://img.shields.io/badge/Google%20Sheets-34A853?style=for-the-badge&logo=google-sheets&logoColor=white)
![GitHub Actions](https://img.shields.io/badge/GitHub%20Actions-2088FF?style=for-the-badge&logo=github-actions&logoColor=white)
![Hostinger](https://img.shields.io/badge/Hostinger-673DE6?style=for-the-badge&logo=hostinger&logoColor=white)

**Dashboard web para visualizar y gestionar gastos anuales sincronizados con Google Sheets.**

[🌐 Ver en producción](https://contenido.creawebes.com/GastosNaia/)

</div>

---

## ✨ Características

- 📊 **Visualización de gastos** por mes y año con gráficos interactivos (Chart.js)
- 🔄 **Sincronización automática** con Google Sheets vía Google Sheets API
- 🤖 **Asistente Contable IA** integrado con Gemini 2.5 Flash, capaz de razonar sobre todo tu histórico y generar resúmenes en formato Markdown.
- 💾 **Backups en Tiempo Real** mediante Firebase Realtime Database para tener siempre una copia de seguridad en la nube independiente de Google Sheets.
- 📁 **Gestión de documentos** integrada con Google Drive
- 📅 **Multi-año** — soporte desde 2020 hasta el año actual
- 🚀 **CI/CD automático** — deploy a Hostinger con cada push a `main`
- 🔒 **Arquitectura limpia** (Clean Architecture) con separación de capas

---

## 🏗️ Arquitectura

```
GastosNaia/
├── public/               # Entry point público (index.php + assets)
│   └── assets/           # CSS y JS del frontend (incluye parseador Markdown IA)
├── src/                  # Código fuente (PSR-4 autoload)
│   ├── Application/      # Casos de uso (AskAiUseCase, FirebaseBackupService)
│   ├── Domain/           # Entidades y repositorios
│   ├── Infrastructure/   # Adapters de Google Sheets, Firebase y Drive
│   └── Presentation/     # Controlador HTTP (ApiController)
├── backups/              # ⚡ Caché efímero para IA (ai_cache.json)
├── templates/            # Plantillas HTML
├── credentials/          # 🔒 Service Account JSON (no en git)
├── config.php            # 🔒 Configuración (no en git)
├── .env                  # 🔒 Variables de entorno (Firebase, Gemini)
└── .github/workflows/    # CI/CD pipeline
    └── main.yml
```

---

## 🚀 Despliegue

El proyecto se despliega automáticamente en **Hostinger** mediante GitHub Actions con cada push a la rama `main`.

### Secretos requeridos en GitHub

> **Settings → Secrets and variables → Actions**

| Secret | Descripción |
|--------|-------------|
| `DEPLOY_SSH_HOST` | IP o hostname del servidor |
| `DEPLOY_SSH_PORT` | Puerto SSH (Hostinger usa `65002`) |
| `DEPLOY_SSH_USER` | Usuario SSH |
| `DEPLOY_SSH_PASS` | Contraseña SSH |

### Pipeline CI/CD

```
push a main
    ↓
Checkout código
    ↓
Instalar dependencias (composer install)
    ↓
rsync → Hostinger (excluyendo .env, config.php, credentials)
```

---

## ⚙️ Instalación local

```bash
# 1. Clonar el repositorio
git clone https://github.com/20Luisma/Gastos-Naia.git
cd Gastos-Naia

# 2. Instalar dependencias PHP
composer install

# 3. Configurar variables de entorno
cp .env.example .env
# → Editar .env con tus credenciales de Gemini, Firebase y Google OAuth

# 4. Añadir el Service Account
# → Copiar el JSON en: credentials/service-account.json

# 5. Configurar los IDs de tus Spreadsheets
# → Editar config.php con los IDs de tus hojas de cálculo
```

---

## 🔑 Requisitos previos

- **PHP 8.0+**
- **Composer**
- **Google Cloud Project** con:
  - Google Sheets API y Google Drive API habilitadas
  - Service Account con acceso a las hojas
- **Firebase Project**:
  - Realtime Database URL y Secret Key habilitados.
- **Google AI Studio**:
  - API Key de Gemini habilitada para el asistente.
- **Cuenta Hostinger** (para producción)

---

## 📦 Dependencias

| Paquete | Versión | Uso |
|---------|---------|-----|
| `google/apiclient` | ^2.15 | Google Sheets & Drive API |
| `vlucas/phpdotenv` | ^5.6 | Variables de entorno (.env) |
| `guzzlehttp/guzzle` | ^7.0 | Peticiones HTTP para Gemini y Firebase |

---

## 🔒 Seguridad

Los siguientes archivos están **excluidos del repositorio** y del deploy automático:

- `.env` — credenciales (Gemini AI, Firebase Secret)
- `config.php` — IDs de Spreadsheets y configuración
- `credentials/` — Service Account JSON
- `vendor/` — dependencias de Composer
- `backups/` — caché en vivo generada por la IA

---

<div align="center">
Hecho con ❤️ para Naia · Sincronizado con Google Sheets, Firebase y Gemini AI
</div>
