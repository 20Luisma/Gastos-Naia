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
