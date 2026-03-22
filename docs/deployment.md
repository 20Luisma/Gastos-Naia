# Guía de Despliegue

## Entornos

| Entorno | URL | Rama | Trigger |
|---------|-----|------|---------|
| Producción | https://contenido.creawebes.com/GastosNaia/ | `main` | push automático |

---

## CI/CD — GitHub Actions

El despliegue es **completamente automático** mediante el workflow `.github/workflows/main.yml`.

### Pipeline

```
1. Checkout del código
2. Instalar PHP + Composer en el runner
3. composer install --no-dev --optimize-autoloader
4. rsync → servidor Hostinger via SSH
```

### Archivos excluidos del deploy

```
.git/
.github/
.env              ← ya existe en el servidor con credenciales reales
config.php        ← ya existe en el servidor configurado
credentials/      ← ya existe en el servidor con service-account.json
```

> ⚠️ **Importante:** `config.php`, `.env` y `credentials/` deben subirse manualmente la primera vez al servidor.

---

## Configuración de Secrets en GitHub

Ir a: **Repo → Settings → Secrets and variables → Actions**

| Secret | Ejemplo | Descripción |
|--------|---------|-------------|
| `DEPLOY_SSH_HOST` | `82.29.185.22` | IP del servidor |
| `DEPLOY_SSH_PORT` | `65002` | Puerto SSH (Hostinger: 65002) |
| `DEPLOY_SSH_USER` | `u968396048` | Usuario SSH |
| `DEPLOY_SSH_PASS` | `••••••••` | Contraseña SSH |

---

## Primer despliegue manual

Si es la primera vez que se despliega el servidor:

```bash
# 1. Subir config.php al servidor
sshpass -p 'PASSWORD' scp -P 65002 config.php USER@HOST:/home/USER/domains/contenido.creawebes.com/public_html/GastosNaia/

# 2. Subir .env al servidor
sshpass -p 'PASSWORD' scp -P 65002 .env USER@HOST:/home/USER/domains/contenido.creawebes.com/public_html/GastosNaia/

# 3. Subir credentials/service-account.json
sshpass -p 'PASSWORD' scp -P 65002 -r credentials/ USER@HOST:/home/USER/domains/contenido.creawebes.com/public_html/GastosNaia/
```

---

## Rollback

Para volver a una versión anterior:

```bash
# Ver commits recientes
git log --oneline -10

# Revertir al commit anterior
git revert HEAD
git push origin main

# O hacer un reset hard + push forzado (⚠️ destructivo)
git reset --hard <commit-hash>
git push --force origin main
```

---

## Verificar despliegue

```bash
# Comprobar que la web responde correctamente
curl -s -o /dev/null -w "%{http_code}" https://contenido.creawebes.com/GastosNaia/

# Conectarse al servidor por SSH
ssh -p 65002 USER@HOST

# Ver archivos desplegados
ls /home/USER/domains/contenido.creawebes.com/public_html/GastosNaia/
```

---

## 🔔 Cron Job de Recordatorios — Cron-job.org

Los recordatorios de eventos se gestionan mediante un cron job externo en **Cron-job.org**.

- **Web (Panel de Control)**: https://console.cron-job.org/jobs
- **Cuenta**: martinpallante@gmail.com
- **Nombre del job**: `Naia Reminders`
- **URL que llama cada minuto**:
```
https://contenido.creawebes.com/GastosNaia/send_reminders.php?secret=naia_secret_2026
```
- **Expresión Cron**: `* * * * *` (cada minuto)
- **Zona horaria**: Europa/Madrid

### ¿Cómo funciona?
1. Al crear un evento con alarma en la app → se guarda en `storage/reminders.json` en el servidor.
2. Cron-job.org llama a la URL cada minuto.
3. El script comprueba si algún evento tiene que dispararse ahora → manda aviso por Telegram.

### Si el cron deja de funcionar:
- Entrar en console.cron-job.org y verificar que el job está **Activado**.
- Comprobar el log de ejecuciones en Cron-job.org para ver si hay errores.

---

## 📬 Cron Job de Correos — Cron-job.org

La sincronización de correos se gestiona también en **Cron-job.org**.

- **Web (Panel de Control)**: https://console.cron-job.org/jobs
- **Cuenta**: martinpallante@gmail.com
- **Nombre del job**: `Naia Fetch Emails`
- **URL que llama cada 10 minutos**:
```
https://contenido.creawebes.com/GastosNaia/cron_fetch_emails.php
```
- **Expresión Cron**: `*/10 * * * *` (cada 10 minutos)
- **Zona horaria**: Europa/Madrid

### ¿Cómo funciona?
1. Cron-job.org llama a la URL cada 10 minutos.
2. El script conecta vía IMAP a Gmail y busca correos nuevos de `ireneriv_1976@hotmail.com`.
3. Descarga adjuntos y los guarda en `public/archivos_correos/`.
4. Guarda los datos del correo en Firebase bajo `/emails`.
5. Envía una notificación Telegram con el asunto y adjuntos del correo nuevo.

### Variables requeridas en `.env` del servidor:
```env
IMAP_HOST="imap.gmail.com"
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
IMAP_USER="martinpallante@gmail.com"
IMAP_PASS="unyd iepu ohyx yruy"
EMAIL_SENDER="ireneriv_1976@hotmail.com"
```

### Si el cron deja de funcionar:
- Verificar que el job está **Activado** en Cron-job.org.
- Comprobar que el `.env` del servidor tiene las variables IMAP.
- Acceder directamente a la URL para ver el log de ejecución.
