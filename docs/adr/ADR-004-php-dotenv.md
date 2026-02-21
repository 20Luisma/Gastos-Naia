# ADR-004: Gestión de Secretos con phpdotenv

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptado |
| **Fecha** | 2025-01 |
| **Autores** | Equipo Gastos Naia |

---

## Contexto

La aplicación requiere credenciales de Google (Client ID, Client Secret, Refresh Token) que no deben estar en el código fuente ni en el repositorio Git para evitar exposición accidental.

## Decisión

Usar **`vlucas/phpdotenv`** para cargar variables de entorno desde un archivo `.env` local que está incluido en `.gitignore`.

El archivo `.env` se mantiene manualmente en el servidor de producción y no se sube vía el pipeline de CI/CD.

## Consecuencias

**Positivas:**
- Las credenciales nunca están en Git
- Patrón estándar de la industria (12-Factor App)
- Fácil de cambiar credenciales sin tocar código
- `safeLoad()` no falla si el `.env` no existe (útil en CI)

**Negativas:**
- El archivo `.env` de producción debe mantenerse manualmente en el servidor
- Si el servidor se reinstala, hay que acordarse de recrear el `.env`

## Alternativas consideradas

| Alternativa | Razón de descarte |
|-------------|-------------------|
| Hardcodear en `config.php` | Inseguro, expone credenciales en Git |
| Variables de entorno del servidor (`.htaccess SetEnv`) | Menos portable y más complejo de gestionar |
| AWS Secrets Manager / Vault | Sobreingeniería para el tamaño del proyecto |
