# ADR-003: CI/CD con GitHub Actions + rsync

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptado |
| **Fecha** | 2026-02 |
| **Autores** | Equipo Gastos Naia |

---

## Contexto

El despliegue manual por FTP era lento, propenso a errores y no garantizaba que el código en producción coincidiera con el repositorio. Se buscaba automatizar el proceso de despliegue con cada push a `main`.

## Decisión

Implementar **CI/CD con GitHub Actions** que:
1. Instala dependencias PHP con Composer en el runner de GitHub
2. Despliega al servidor Hostinger via **rsync sobre SSH**
3. Excluye archivos sensibles (`config.php`, `.env`, `credentials/`) que ya existen en el servidor

Las credenciales SSH se almacenan como **GitHub Secrets** (nunca en el código).

## Consecuencias

**Positivas:**
- Deploy automático con cada `git push main`
- Sin FTP: rsync es incremental y solo sube los archivos modificados
- `vendor/` se compila en el runner y se sube, sin depender de `composer` en el servidor
- Historial de deploys visible en GitHub Actions
- Rollback fácil mediante `git revert`

**Negativas:**
- El primer setup requiere configurar 4 secrets en GitHub
- `config.php` y `.env` deben mantenerse manualmente en el servidor
- rsync con `--delete` puede borrar archivos generados en el servidor si no se excluyen correctamente

## Alternativas consideradas

| Alternativa | Razón de descarte |
|-------------|-------------------|
| FTP manual | Error-prone, lento, no reproducible |
| Git pull en el servidor | Requiere Git instalado y acceso SSH más complejo |
| Docker + container registry | Sobreingeniería para hosting compartido |
| Plugin de despliegue de Hostinger | Menos flexible, acoplado al proveedor |
