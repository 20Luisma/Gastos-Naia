import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:firebase_core/firebase_core.dart';
import 'providers/expense_provider.dart';
import 'screens/home_screen.dart';
import 'screens/annual_screen.dart';
import 'screens/ai_screen.dart';
import 'screens/calendar_screen.dart';
import 'screens/agenda_screen.dart';
import 'screens/diario_screen.dart';
import 'screens/correos_screen.dart';
import 'screens/splash_screen.dart';
import 'services/fcm_service.dart';
import 'providers/email_provider.dart';
import 'services/fcm_service.dart';
import 'screens/pin_screen.dart';

import 'package:flutter/services.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);
  await initializeDateFormatting('es_ES', null);

  try {
    // Inicializar Firebase (necesario para FCM)
    await Firebase.initializeApp();
    
    // Inicializar servicio de notificaciones push
    await FcmService.init();
  } catch (e) {
    debugPrint('Firebase Firebase/FCM init failed: $e');
  }

  runApp(const GastosNaiaApp());
}

class GastosNaiaApp extends StatelessWidget {
  const GastosNaiaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => ExpenseProvider()),
        ChangeNotifierProvider(create: (_) => EmailProvider()),
      ],
      child: MaterialApp(
        title: 'Universo Naia',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(
            seedColor: const Color(0xFF6C63FF),
            brightness: Brightness.dark,
          ),
          useMaterial3: true,
          textTheme: GoogleFonts.interTextTheme(ThemeData.dark().textTheme),
          scaffoldBackgroundColor: const Color(0xFF0F0F1A),
          cardTheme: CardThemeData(
            color: const Color(0xFF1A1A2E),
            elevation: 0,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(16),
              side: BorderSide(color: Colors.white.withOpacity(0.08)),
            ),
          ),
        ),
        initialRoute: '/pin',
        routes: {
          '/splash': (context) => const SplashScreen(),
          '/pin':    (context) => const PinScreen(),
          '/home':   (context) => const MainShell(),
        },
      ),
    );
  }
}

class MainShell extends StatefulWidget {
  const MainShell({super.key});
  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  int _currentIndex = 0;

  late final List<Widget> _screens;

  @override
  void initState() {
    super.initState();
    _screens = [
      const HomeScreen(),
      const CorreosScreen(),
      const AgendaScreen(),
      const DiarioScreen(),
      const AiScreen(),
    ];
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _currentIndex, children: _screens),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: const Color(0xFF1A1A2E),
          border: Border(top: BorderSide(color: Colors.white.withOpacity(0.07))),
        ),
        child: NavigationBar(
          backgroundColor: Colors.transparent,
          indicatorColor: const Color(0xFF6C63FF).withOpacity(0.2),
          selectedIndex: _currentIndex,
          onDestinationSelected: (i) {
            setState(() => _currentIndex = i);
            // Si entra a Correos y está vacío, cargarlo
            if (i == 1) {
              final ems = context.read<EmailProvider>();
              if (ems.emails.isEmpty) ems.loadEmails();
            }
          },
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.receipt_long_outlined, color: Colors.white54),
              selectedIcon: Icon(Icons.receipt_long_rounded, color: Color(0xFF6C63FF)),
              label: 'Gastos',
            ),
            NavigationDestination(
              icon: Icon(Icons.mail_outline_rounded, color: Colors.white54),
              selectedIcon: Icon(Icons.email_rounded, color: Color(0xFF6C63FF)),
              label: 'Correos',
            ),
            NavigationDestination(
              icon: Icon(Icons.calendar_month_outlined, color: Colors.white54),
              selectedIcon: Icon(Icons.calendar_month_rounded, color: Color(0xFF6C63FF)),
              label: 'Agenda',
            ),
            NavigationDestination(
              icon: Icon(Icons.mark_email_unread_outlined, color: Colors.white54),
              selectedIcon: Icon(Icons.mark_email_unread_rounded, color: Color(0xFF6C63FF)),
              label: 'Diario',
            ),
            NavigationDestination(
              icon: Icon(Icons.smart_toy_outlined, color: Colors.white54),
              selectedIcon: Icon(Icons.smart_toy_rounded, color: Color(0xFF6C63FF)),
              label: 'Alfred IA',
            ),
          ],
        ),
      ),
    );
  }
}
