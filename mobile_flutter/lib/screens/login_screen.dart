import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:fluttertoast/fluttertoast.dart';
import '../config/app_config.dart';
import '../services/api_client.dart';
import '../services/session_store.dart';
import 'shell_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.onSignedIn});

  final Future<void> Function() onSignedIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController(text: 'guard@infipre.local');
  final _passwordController = TextEditingController(text: 'Guard@123');
  final _sessionStore = SessionStore();
  ApiClient get _api => ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: _sessionStore);
  bool _busy = false;
  String _error = '';

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _showServerSettings() {
    final controller = TextEditingController(text: AppConfig.apiBaseUrl);
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        title: const Text('Backend API Server', style: TextStyle(color: Colors.white)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Specify your PC\'s local Wi-Fi IP address so your phone can reach the backend server.',
              style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 13),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: controller,
              style: const TextStyle(color: Colors.white),
              decoration: _fieldDecoration('Server Base URL'),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () {
                controller.text = AppConfig.defaultBaseUrl;
              },
              child: Text('Reset to Default (${AppConfig.defaultBaseUrl})', style: const TextStyle(fontSize: 12)),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () async {
              final newUrl = controller.text.trim();
              if (newUrl.isNotEmpty) {
                AppConfig.customBaseUrl = newUrl;
                await _sessionStore.saveServerUrl(newUrl);
                if (mounted) {
                  setState(() {});
                }
              }
              if (ctx.mounted) Navigator.pop(ctx);
              Fluttertoast.showToast(msg: 'Server URL updated');
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = '';
    });
    try {
      final response = await _api.postJson('/api/auth/login', {
        'email': _emailController.text.trim(),
        'password': _passwordController.text,
      });
      final data = (response['data'] as Map<String, dynamic>? ?? const {});
      final user = (data['user'] as Map<String, dynamic>? ?? const {});
      final token = data['token'].toString();
      await _sessionStore.saveSession(
        token: token,
        role: user['role'].toString(),
      );
      await _sessionStore.saveUser(user);
      await widget.onSignedIn();
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(
          builder: (_) => ShellScreen(
            sessionStore: _sessionStore,
            onSignedOut: widget.onSignedIn,
            onToggleTheme: () {},
          ),
        ),
        (_) => false,
      );
    } on ApiException catch (e) {
      setState(() => _error = e.message);
      Fluttertoast.showToast(msg: e.message);
    } catch (e) {
      setState(() => _error = e.toString().replaceFirst('Exception: ', ''));
      Fluttertoast.showToast(msg: _error);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          const _Backdrop(),
          Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(26),
                child: BackdropFilter(
                  filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                  child: Container(
                    width: 420,
                    decoration: BoxDecoration(
                      color: const Color(0xFF111827).withOpacity(0.94),
                      borderRadius: BorderRadius.circular(26),
                      border: Border.all(color: const Color(0xFF94A3B4).withOpacity(0.16)),
                    ),
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text('Secure360', style: TextStyle(fontSize: 34, fontWeight: FontWeight.w800, color: Colors.white)),
                            IconButton(
                              tooltip: 'Server Settings',
                              icon: const Icon(Icons.settings_ethernet, color: Color(0xFFF3C24F)),
                              onPressed: _showServerSettings,
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text('Guard & Admin operations', style: TextStyle(color: Colors.white.withOpacity(0.82))),
                        const SizedBox(height: 8),
                        InkWell(
                          onTap: _showServerSettings,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            child: Row(
                              children: [
                                const Icon(Icons.wifi, size: 14, color: Color(0xFF47D8A2)),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    AppConfig.apiBaseUrl,
                                    style: const TextStyle(fontSize: 11, color: Color(0xFF94A3B8)),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                                const Icon(Icons.edit, size: 12, color: Color(0xFF94A3B8)),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(height: 18),
                        TextField(
                          controller: _emailController,
                          decoration: _fieldDecoration('Email'),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: _passwordController,
                          obscureText: true,
                          decoration: _fieldDecoration('Password'),
                        ),
                        if (_error.isNotEmpty) ...[
                          const SizedBox(height: 14),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(
                              color: const Color(0xFFEF4444).withOpacity(0.14),
                              borderRadius: BorderRadius.circular(999),
                              border: Border.all(color: const Color(0xFFEF4444).withOpacity(0.22)),
                            ),
                            child: Text(_error, style: const TextStyle(color: Color(0xFFFEE2E2))),
                          ),
                        ],
                        const SizedBox(height: 20),
                        SizedBox(
                          height: 52,
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: const LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                              borderRadius: BorderRadius.circular(15),
                            ),
                            child: FilledButton(
                              style: FilledButton.styleFrom(
                                backgroundColor: Colors.transparent,
                                shadowColor: Colors.transparent,
                                foregroundColor: const Color(0xFF08101D),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
                              ),
                              onPressed: _busy ? null : _login,
                              child: _busy
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF08101D)),
                                    )
                                  : const Text('Sign In', style: TextStyle(fontWeight: FontWeight.w800)),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  InputDecoration _fieldDecoration(String label) {
    return InputDecoration(
      labelText: label,
      filled: true,
      fillColor: const Color(0xFF0F172A),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: Color(0xFF94A3B4))),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: Color(0xFF94A3B4))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: Color(0xFFF3C24F))),
      labelStyle: const TextStyle(color: Color(0xFFA3B2CA)),
    );
  }
}

class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF0A1320),
        gradient: RadialGradient(
          center: Alignment(-0.9, -0.8),
          radius: 1.3,
          colors: [Color(0x33F3C24F), Color(0x000A1320)],
        ),
      ),
      child: Stack(
        children: const [
          Positioned(
            top: -30,
            right: -10,
            child: _Blob(color: Color(0x3347D8A2), size: 220),
          ),
          Positioned(
            top: 40,
            left: -20,
            child: _Blob(color: Color(0x33F3C24F), size: 220),
          ),
        ],
      ),
    );
  }
}

class _Blob extends StatelessWidget {
  const _Blob({required this.color, required this.size});
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(shape: BoxShape.circle, color: color),
    );
  }
}
