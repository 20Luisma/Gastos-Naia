# Gastos Naia - App Móvil (Flutter)

Esta carpeta contiene el código fuente de la aplicación móvil de **Gastos Naia**, desarrollada en el framework **Flutter** de Google.

## Arquitectura y Conexión

La aplicación móvil **no** tiene una base de datos propia tradicional ni un backend de uso exclusivo incrustado. Toda la lógica central y la base de datos real es tu **App Web (PHP)**, la cual a su vez actúa como intermediaria con tus hojas de cálculo en **Google Sheets**.

El flujo de conexión es el siguiente:

### 1. El entorno de producción (`secrets.dart`)
La app móvil sabe a dónde conectarse gracias al archivo de configuración ubicado en `lib/config/secrets.dart`. Allí tienes declarada la URL maestra de tu servidor:
```dart
static const backendUrl = 'https://contenido.creawebes.com/GastosNaia';
```
Esta es la ruta en vivo ("producción") alojada en tu hosting de Hostinger.

### 2. Operaciones de lectura y escritura a Google Sheets
La app utiliza el paquete oficial de automatización (`googleapis` y `googleapis_auth`) para identificarse como administrador directamente con Google usando tus credenciales privadas. 

- **Lectura**: Al pulsar sobre la vista de gráficos o abrir la tabla de gastos, la app de Flutter carga directamente las celdas desde Google Sheets (Spreadsheet ID).
- **Escritura**: Cuando creas, editas o borras un gasto desde la app, los botones mandan esas celdas actualizadas a tu Google Sheets usando el archivo `lib/services/google_sheets_service.dart`.

### 3. Sincronización en Tiempo Real (El Webhook PHP)
Dado que tu Servidor Web está en Hostinger con PHP, éste utiliza una Memoria Caché interna para cargar la web rapidísimo a los usuarios que entran por el navegador de Internet. Esto presenta un reto: si añades un gasto por la app móvil, el servidor PHP no se entera y sigue mostrando la web anticuada durante 5 minutos.

Para solucionarlo, la App Móvil cuenta con un mecanismo de **Webhook Invalidador**:
1. Tras acabar de añadir/editar/eliminar una celda en Google Sheets...
2. ...Flutter hace una petición silenciosa en segundo plano mediante un HTTP POST a `https://contenido.creawebes.com/GastosNaia/?action=clear_cache`.
3. Para asegurar que es la app legítima (y no un hacker malicioso borrando la memoria), viaja de forma oculta la contraseña que tienes en tu archivo `secrets.dart` (`webhookSecret = 'naia_secret_2026'`).
4. El servidor PHP comprueba que la contraseña recibida coincide con su `config.php`, y ejecuta el comando de borrado de carpetas de caché en un instante.
5. El resultado final: vas a la app web PHP, y automáticamente recarga todos los gastos nuevos.

### 4. Inteligencia Artificial y Backups
Por último, para el panel analítico web y el ChatBot Alfred, la sincronización se hace publicando historiales a **Firebase** tras modificar un elemento. La app web (PHP) leerá de ese Firebase más adelante para calcular predicciones superveloces.

## Compilación y Despliegue

Cada vez que quieras instalar una nueva versión en tu teléfono Android:
1. Abre tu terminal.
2. Navega hasta esta carpeta `mobile/`.
3. Ejecuta `flutter build apk --release`.
4. El fichero resultante aparecerá en `build/app/outputs/flutter-apk/app-release.apk`. Pásatelo por cable o correo para instalarlo.

---
_Este documento explica a nivel técnico cómo tu sistema frontend híbrido (App Móvil) se interconecta con tu sistema central monolítico (App PHP + Sheets)._
