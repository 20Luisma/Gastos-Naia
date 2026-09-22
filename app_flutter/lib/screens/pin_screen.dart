import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Pantalla de PIN de 4 dígitos.
/// - Primera vez: crea el PIN (pide confirmación)
/// - Siguientes veces: verifica el PIN guardado
class PinScreen extends StatefulWidget {
  const PinScreen({super.key});

  @override
  State<PinScreen> createState() => _PinScreenState();
}

class _PinScreenState extends State<PinScreen> with SingleTickerProviderStateMixin {
  static const _storage = FlutterSecureStorage();
  static const _pinKey = 'gastos_pin';

  // 'verify' | 'create' | 'confirm'
  String _mode = 'verify';
  String _pin = '';
  String _firstPin = '';
  String _errorMsg = '';

  late AnimationController _shakeController;
  late Animation<double> _shakeAnimation;

  @override
  void initState() {
    super.initState();
    _shakeController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 400),
    );
    _shakeAnimation = Tween<double>(begin: 0, end: 12).animate(
      CurvedAnimation(parent: _shakeController, curve: Curves.elasticIn),
    );
    _checkExistingPin();
  }

  @override
  void dispose() {
    _shakeController.dispose();
    super.dispose();
  }

  Future<void> _checkExistingPin() async {
    final saved = await _storage.read(key: _pinKey);
    setState(() => _mode = (saved == null || saved.isEmpty) ? 'create' : 'verify');
  }

  void _onKey(String digit) {
    if (_pin.length >= 4) return;
    setState(() {
      _pin += digit;
      _errorMsg = '';
    });
    if (_pin.length == 4) {
      Future.delayed(const Duration(milliseconds: 100), _processPin);
    }
  }

  void _onDelete() {
    if (_pin.isEmpty) return;
    setState(() => _pin = _pin.substring(0, _pin.length - 1));
  }

  Future<void> _processPin() async {
    if (_mode == 'verify') {
      final saved = await _storage.read(key: _pinKey);
      if (_pin == saved) {
        if (mounted) Navigator.pushReplacementNamed(context, '/home');
      } else {
        _shake('PIN incorrecto. Inténtalo de nuevo.');
      }
    } else if (_mode == 'create') {
      setState(() {
        _firstPin = _pin;
        _pin = '';
        _mode = 'confirm';
      });
    } else if (_mode == 'confirm') {
      if (_pin == _firstPin) {
        await _storage.write(key: _pinKey, value: _pin);
        if (mounted) Navigator.pushReplacementNamed(context, '/home');
      } else {
        setState(() => _mode = 'create');
        _shake('Los PINs no coinciden. Elige uno nuevo.');
      }
    }
  }

  void _shake(String msg) {
    setState(() {
      _pin = '';
      _errorMsg = msg;
    });
    _shakeController.forward(from: 0);
  }

  String get _title {
    switch (_mode) {
      case 'create':   return 'Crea tu PIN';
      case 'confirm':  return 'Confirma el PIN';
      default:         return 'Introduce tu PIN';
    }
  }

  String get _subtitle {
    switch (_mode) {
      case 'create':   return 'Elige 4 dígitos para proteger la app';
      case 'confirm':  return 'Repite el PIN que acabas de elegir';
      default:         return 'Acceso seguro a Universo Naia';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F0F1A),
      body: Stack(
        children: [
          // Orb fondo
          Positioned(
            top: -120, left: -80,
            child: Container(
              width: 350, height: 350,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: const Color(0xFF6C63FF).withOpacity(0.12),
              ),
            ),
          ),
          Positioned(
            bottom: -80, right: -60,
            child: Container(
              width: 280, height: 280,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: const Color(0xFF3B82F6).withOpacity(0.10),
              ),
            ),
          ),
          SafeArea(
            child: CustomScrollView(
              slivers: [
                SliverFillRemaining(
                  hasScrollBody: false,
                  child: Column(
                    children: [
                const SizedBox(height: 48),
                // Logo
                Container(
                  width: 110, height: 110,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(24),
                    boxShadow: [BoxShadow(color: const Color(0xFF6C63FF).withOpacity(0.4), blurRadius: 24, spreadRadius: 2)],
                  ),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(24),
                    child: Image.asset(
                      'assets/images/appnaia.jpeg',
                      fit: BoxFit.cover,
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                Text(_title, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.bold)),
                const SizedBox(height: 6),
                Text(_subtitle, style: TextStyle(color: Colors.white.withOpacity(0.45), fontSize: 14)),
                const SizedBox(height: 40),

                // Puntos PIN con shake
                AnimatedBuilder(
                  animation: _shakeAnimation,
                  builder: (_, child) => Transform.translate(
                    offset: Offset(_shakeController.isAnimating ? _shakeAnimation.value * ((_shakeController.value * 10).floor().isEven ? 1 : -1) : 0, 0),
                    child: child,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: List.generate(4, (i) {
                      final filled = i < _pin.length;
                      return AnimatedContainer(
                        duration: const Duration(milliseconds: 150),
                        margin: const EdgeInsets.symmetric(horizontal: 10),
                        width: 18, height: 18,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: filled ? const Color(0xFF6C63FF) : Colors.transparent,
                          border: Border.all(
                            color: filled ? const Color(0xFF6C63FF) : Colors.white.withOpacity(0.3),
                            width: 2,
                          ),
                          boxShadow: filled ? [BoxShadow(color: const Color(0xFF6C63FF).withOpacity(0.5), blurRadius: 8)] : null,
                        ),
                      );
                    }),
                  ),
                ),

                const SizedBox(height: 16),

                // Mensaje de error
                AnimatedOpacity(
                  opacity: _errorMsg.isEmpty ? 0 : 1,
                  duration: const Duration(milliseconds: 200),
                  child: Text(_errorMsg, style: const TextStyle(color: Color(0xFFf87171), fontSize: 13)),
                ),

                const Spacer(),

                // Teclado numérico
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 40),
                  child: Column(
                    children: [
                      for (final row in [['1','2','3'], ['4','5','6'], ['7','8','9'], ['','0','⌫']])
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: row.map((k) => _buildKey(k)).toList(),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 32),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildKey(String label) {
    if (label.isEmpty) return const SizedBox(width: 80, height: 72);

    final isDelete = label == '⌫';
    return GestureDetector(
      onTap: isDelete ? _onDelete : () => _onKey(label),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 80),
        width: 80, height: 72,
        decoration: BoxDecoration(
          color: isDelete ? Colors.transparent : const Color(0xFF1A1A2E),
          borderRadius: BorderRadius.circular(16),
          border: isDelete ? null : Border.all(color: Colors.white.withOpacity(0.07)),
        ),
        child: Center(
          child: Text(
            label,
            style: TextStyle(
              color: isDelete ? Colors.white.withOpacity(0.5) : Colors.white,
              fontSize: isDelete ? 22 : 26,
              fontWeight: FontWeight.w500,
            ),
          ),
        ),
      ),
    );
  }
}
