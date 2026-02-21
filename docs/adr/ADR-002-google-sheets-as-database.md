# ADR-002: Usar Google Sheets como Base de Datos

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptado |
| **Fecha** | 2025-01 |
| **Autores** | Equipo Gastos Naia |

---

## Contexto

Los gastos de la familia ya se registraban manualmente en Google Sheets antes de crear esta aplicación. El objetivo del dashboard es **visualizar esos datos existentes**, no migrarlos a un nuevo sistema.

## Decisión

Usar **Google Sheets como fuente de datos primaria** a través de la Google Sheets API v4, manteniendo los datos donde ya existen sin forzar una migración.

## Consecuencias

**Positivas:**
- Cero coste de infraestructura de base de datos
- Los datos se pueden seguir editando directamente en Google Sheets
- Sin necesidad de backup: Google Drive lo gestiona
- El hosting simple (Hostinger) es suficiente, sin necesidad de MySQL

**Negativas:**
- Latencia mayor que una base de datos local (llamadas a API externa)
- Rate limits de la API de Google (100 requests/100 segundos por usuario)
- Sin transacciones ni integridad referencial
- Menos flexible para queries complejas

## Alternativas consideradas

| Alternativa | Razón de descarte |
|-------------|-------------------|
| MySQL/MariaDB | Requiere migrar datos existentes, coste adicional en hosting |
| SQLite | Complicaría la edición manual de datos por parte de la familia |
| Firebase Firestore | Sobreingeniería para el volumen de datos |
