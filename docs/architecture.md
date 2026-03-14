# Arquitectura del Sistema — Gastos Naia

## Visión General

Gastos Naia es una aplicación web PHP que sigue los principios de **Clean Architecture**, separando claramente las responsabilidades en capas independientes. Google Sheets actúa como base de datos, eliminando la necesidad de una BBDD propia.

---

## Capas de la Arquitectura

```
┌─────────────────────────────────────────────┐
│             Presentation Layer              │
│         (ApiController, Templates)          │
├─────────────────────────────────────────────┤
│             Application Layer               │
│           (Use Cases / Services)            │
├─────────────────────────────────────────────┤
│               Domain Layer                  │
│         (Entities, Repositories)            │
├─────────────────────────────────────────────┤
│           Infrastructure Layer              │
│    (Google Sheets API, Google Drive API)    │
└─────────────────────────────────────────────┘
```

### Presentation Layer (`src/Presentation/`)
- **`ApiController.php`** — Punto de entrada HTTP. Procesa el parámetro `?action=` y delega en los casos de uso de Application.
- **`templates/`** — HTML de la interfaz de usuario.

### Application Layer (`src/Application/`)
- Contiene los **casos de uso** de la aplicación (leer gastos, crear año, listar documentos...).
- Orquesta Domain e Infrastructure sin depender de ellos directamente.

### Domain Layer (`src/Domain/`)
- **Entidades** puras: `Expense`, `Month`, `Year`.
- **Interfaces de repositorio**: definen el contrato sin acoplar a implementaciones concretas.

### Infrastructure Layer (`src/Infrastructure/`)
- Implementación concreta de los repositorios usando la **Google Sheets API** y **Google Drive API**.
- Adaptadores que satisfacen las interfaces definidas en Domain.

---

## Flujo de una petición

```
Browser → index.php (root)
         → .htaccess rewrite → public/index.php
                              → ApiController
                                → Application Use Case
                                  → Infrastructure (Google API)
                                    → Google Sheets
```

---

## Stack Tecnológico

| Componente | Tecnología |
|-----------|------------|
| Backend | PHP 8.0+ |
| Frontend | HTML + CSS Vanilla + Chart.js |
| Base de datos | Google Sheets (via API v4) |
| Almacenamiento | Google Drive (via API v3) |
| Autenticación | Service Account (Google Cloud) |
| Variables entorno | vlucas/phpdotenv |
| Autoload | PSR-4 via Composer |
| CI/CD | GitHub Actions |
| Hosting | Hostinger (SSH/rsync) |

---

## Decisiones de arquitectura

Ver carpeta [`adr/`](./adr/) para el registro de decisiones arquitectónicas.
