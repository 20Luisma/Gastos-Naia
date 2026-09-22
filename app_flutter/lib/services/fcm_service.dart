import 'dart:io';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Handler que corre en un Isolate separado cuando la app está completamente cerrada.
/// Debe ser una función de nivel superior (no un método de clase).
@pragma('vm:entry-point')
Future<void> _firebaseBackgroundHandler(RemoteMessage message) async {
  // FCM muestra la notificación del sistema automáticamente en background/terminated.
  // No necesitamos hacer nada aquí, pero la función debe existir y estar registrada.
}

/// Servicio de notificaciones push via FCM.
///
/// Uso:
///   await FcmService.init();
///
/// La suscripción al topic 'gastos_updates' se hace automáticamente.
/// Cuando llega una notificación y el usuario la pulsa, la app puede
/// detectarlo revisando [FcmService.pendingRefresh].
class FcmService {
  FcmService._();

  static final _messaging = FirebaseMessaging.instance;
  static final _localNotifications = FlutterLocalNotificationsPlugin();

  /// Canal de notificaciones Android
  static const _channel = AndroidNotificationChannel(
    'gastos_updates',
    'Actualizaciones de Gastos',
    description: 'Notificaciones cuando se añaden, editan o borran gastos.',
    importance: Importance.high,
    playSound: true,
  );

  /// Indica si hay datos nuevos que la pantalla debe recargar.
  static bool pendingRefresh = false;

  static Future<void> init() async {
    // 1. Handler para mensajes en background/terminated
    FirebaseMessaging.onBackgroundMessage(_firebaseBackgroundHandler);

    // 2. Pedir permisos (Android 13+ y iOS)
    await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    // 3. Inicializar flutter_local_notifications para primer plano
    await _initLocalNotifications();

    // 4. Suscribir al topic — todos los dispositivos con la app instalada recibirán notificaciones
    await _messaging.subscribeToTopic('gastos_updates');

    // 5. Mensaje recibido con la app en PRIMER PLANO → mostrar notificación local
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      _showLocalNotification(message);
    });

    // 6. Usuario pulsó la notificación estando la app en BACKGROUND
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      pendingRefresh = true;
    });

    // 7. App iniciada al pulsar una notificación (estaba TERMINADA)
    final initialMessage = await _messaging.getInitialMessage();
    if (initialMessage != null) {
      pendingRefresh = true;
    }
  }

  static Future<void> _initLocalNotifications() async {
    // Crear canal de notificaciones en Android
    if (Platform.isAndroid) {
      await _localNotifications
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(_channel);
    }

    const initSettings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    );

    await _localNotifications.initialize(
      initSettings,
      onDidReceiveNotificationResponse: (details) {
        // Usuario pulsó la notificación local (app en primer plano)
        pendingRefresh = true;
      },
    );
  }

  static Future<void> _showLocalNotification(RemoteMessage message) async {
    final notification = message.notification;
    if (notification == null) return;

    final title = notification.title ?? '📊 Gastos actualizados';
    final body = notification.body ?? '';

    await _localNotifications.show(
      notification.hashCode,
      title,
      body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _channel.id,
          _channel.name,
          channelDescription: _channel.description,
          importance: Importance.high,
          priority: Priority.high,
          icon: '@mipmap/ic_launcher',
        ),
      ),
    );
  }
}
