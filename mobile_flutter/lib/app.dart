import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'config/app_config.dart';
import 'screens/landing_screen.dart';
import 'screens/login_screen.dart';
import 'screens/shell_screen.dart';
import 'services/session_store.dart';
import 'theme/app_theme.dart';

final _navigatorKey = GlobalKey<NavigatorState>();

class Secure360App extends StatefulWidget {
  const Secure360App({super.key});

  @override
  State<Secure360App> createState() => _Secure360AppState();
}

class _Secure360AppState extends State<Secure360App> {
  final SessionStore _sessionStore = SessionStore();
  late Future<bool> _hasSession;
  ThemeMode _themeMode = ThemeMode.dark;

  @override
  void initState() {
    super.initState();
    _hasSession = _sessionStore.hasSession();
    _sessionStore.serverUrl().then((savedUrl) {
      if (savedUrl != null && savedUrl.isNotEmpty) {
        AppConfig.customBaseUrl = savedUrl;
      }
    });
    SharedPreferences.getInstance().then((prefs) {
      final saved = prefs.getString('theme_mode') ?? 'dark';
      if (mounted) setState(() => _themeMode = saved == 'light' ? ThemeMode.light : ThemeMode.dark);
    });
  }

  Future<void> _refreshSession() async {
    setState(() {
      _hasSession = _sessionStore.hasSession();
    });
  }

  void _toggleTheme() {
    setState(() => _themeMode = _themeMode == ThemeMode.dark ? ThemeMode.light : ThemeMode.dark);
    SharedPreferences.getInstance().then(
      (prefs) => prefs.setString('theme_mode', _themeMode == ThemeMode.light ? 'light' : 'dark'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      navigatorKey: _navigatorKey,
      debugShowCheckedModeBanner: false,
      title: 'Secure360',
      theme: buildLightTheme(),
      darkTheme: buildDarkTheme(),
      themeMode: _themeMode,
      home: FutureBuilder<bool>(
        future: _hasSession,
        builder: (context, snapshot) {
          if (!snapshot.hasData) return const Scaffold(body: Center(child: CircularProgressIndicator()));
          final signedIn = snapshot.data ?? false;
          if (!signedIn) {
            return LandingScreen(
              onSignIn: () {
                _navigatorKey.currentState?.push(
                  MaterialPageRoute(builder: (_) => LoginScreen(onSignedIn: _refreshSession)),
                );
              },
            );
          }
          return ShellScreen(
            sessionStore: _sessionStore,
            onSignedOut: _refreshSession,
            onToggleTheme: _toggleTheme,
          );
        },
      ),
    );
  }
}
