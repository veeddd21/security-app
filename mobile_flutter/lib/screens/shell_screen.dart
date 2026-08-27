import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import '../config/app_config.dart';
import '../services/api_client.dart';
import '../services/session_store.dart';
import 'landing_screen.dart';
import 'login_screen.dart';
import 'tabs/account_sheet.dart';
import 'tabs/admin_home_tab.dart';
import 'tabs/guard_history_tab.dart';
import 'tabs/guard_home_tab.dart';
import 'tabs/guard_route_tab.dart';
import 'tabs/guard_shift_tab.dart';
import 'tabs/guard_selfie_tab.dart';
import 'tabs/super_admin_home_tab.dart';

class ShellScreen extends StatefulWidget {
  const ShellScreen({
    super.key,
    required this.sessionStore,
    required this.onSignedOut,
    required this.onToggleTheme,
  });

  final SessionStore sessionStore;
  final Future<void> Function() onSignedOut;
  final VoidCallback onToggleTheme;

  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> {
  int _index = 0;
  bool _accountOpen = false;
  bool _busySignOut = false;
  Map<String, dynamic>? _user;
  String? _role;
  Timer? _locationTimer;
  late final ApiClient _api = ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: widget.sessionStore);

  @override
  void initState() {
    super.initState();
    widget.sessionStore.user().then((value) {
      if (mounted) setState(() => _user = value);
    });
    widget.sessionStore.role().then((value) {
      if (!mounted) return;
      setState(() => _role = value);
      if (value == 'guard') {
        _locationTimer?.cancel();
        _locationTimer = Timer.periodic(const Duration(minutes: 2), (_) => _syncLocationIfActive());
      }
    });
  }

  @override
  void dispose() {
    _locationTimer?.cancel();
    super.dispose();
  }

  String _titleForRole(String role) => switch (role) {
        'super_admin' => 'Platform Control',
        'admin' => 'Operations Control',
        _ => 'Guard Command Hub',
      };

  String _initials(Map<String, dynamic>? user) {
    final name = (user?['full_name'] ?? 'IS').toString().trim();
    final parts = name.split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    final first = parts.isNotEmpty && parts.first.isNotEmpty ? parts.first.substring(0, 1) : 'I';
    final second = parts.length > 1 && parts[1].isNotEmpty ? parts[1].substring(0, 1) : 'S';
    return (first + second).toUpperCase();
  }

  Future<void> _signOut() async {
    if (_busySignOut) return;
    setState(() => _busySignOut = true);
    try {
      await _api.logout();
    } catch (_) {}
    await widget.sessionStore.clear();
    _locationTimer?.cancel();
    await widget.onSignedOut();
    if (mounted) {
      setState(() {
        _busySignOut = false;
        _accountOpen = false;
        _index = 0;
      });
      _goToLanding();
    }
  }

  Future<void> _syncLocationIfActive() async {
    if (_role != 'guard') return;
    try {
      final overview = await _api.getJson('/api/guard/overview');
      final data = overview['data'] as Map<String, dynamic>? ?? const {};
      final attendance = (data['attendance'] as List<dynamic>? ?? const []).whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      if (attendance.isEmpty) return;
      final active = attendance.firstWhere(
        (row) => row['check_out_at'] == null || row['check_out_at'].toString().isEmpty,
        orElse: () => const {},
      );
      if (active.isEmpty) return;
      final enabled = await Geolocator.isLocationServiceEnabled();
      if (!enabled) return;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever || permission == LocationPermission.denied) return;
      final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
      await _api.postJson('/api/guard/location', {
        'latitude': pos.latitude,
        'longitude': pos.longitude,
        'accuracy': pos.accuracy.round(),
        'address': '${pos.latitude.toStringAsFixed(5)}, ${pos.longitude.toStringAsFixed(5)}',
        'duty_label': (active['location_label'] ?? 'Field checkpoint').toString(),
      });
    } catch (_) {}
  }

  void _goToLanding() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(
        builder: (_) => LandingScreen(
          onSignIn: () {
            Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => LoginScreen(onSignedIn: widget.onSignedOut)),
            );
          },
        ),
      ),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<String?>(
      future: widget.sessionStore.role(),
      builder: (context, snapshot) {
        final role = _role ?? snapshot.data ?? 'guard';
        final user = _user;

        final pages = switch (role) {
          'super_admin' => const [
              SuperAdminHomeTab(),
              Placeholder(),
              Placeholder(),
              Placeholder(),
            ],
          'admin' => const [
              AdminHomeTab(),
              Placeholder(),
              Placeholder(),
              Placeholder(),
            ],
          _ => [
              GuardHomeTab(
                sessionStore: widget.sessionStore,
                onOpenShift: () => setState(() => _index = 1),
                onOpenRoute: () => setState(() => _index = 3),
                onOpenHistory: () => setState(() => _index = 4),
                onSessionExpired: _signOut,
              ),
              GuardShiftTab(
                sessionStore: widget.sessionStore,
                onOpenSelfie: () => setState(() => _index = 2),
                onSessionExpired: _signOut,
              ),
              GuardSelfieTab(
                sessionStore: widget.sessionStore,
                onGoToShift: () => setState(() => _index = 1),
                onSessionExpired: _signOut,
              ),
              GuardRouteTab(sessionStore: widget.sessionStore, onSessionExpired: _signOut),
              GuardHistoryTab(sessionStore: widget.sessionStore, onSessionExpired: _signOut),
            ],
        };

        final items = switch (role) {
          'super_admin' => const [
              BottomNavigationBarItem(icon: Icon(Icons.home_outlined), label: 'Home'),
              BottomNavigationBarItem(icon: Icon(Icons.apartment_outlined), label: 'Orgs'),
              BottomNavigationBarItem(icon: Icon(Icons.add_circle_outline), label: 'Add'),
              BottomNavigationBarItem(icon: Icon(Icons.admin_panel_settings_outlined), label: 'Admins'),
            ],
          'admin' => const [
              BottomNavigationBarItem(icon: Icon(Icons.home_outlined), label: 'Home'),
              BottomNavigationBarItem(icon: Icon(Icons.dashboard_outlined), label: 'Master'),
              BottomNavigationBarItem(icon: Icon(Icons.map_outlined), label: 'Map'),
              BottomNavigationBarItem(icon: Icon(Icons.badge_outlined), label: 'Guards'),
            ],
          _ => const [
              BottomNavigationBarItem(icon: Icon(Icons.home_outlined), label: 'Home'),
              BottomNavigationBarItem(icon: Icon(Icons.access_time_outlined), label: 'Shift'),
              BottomNavigationBarItem(icon: Icon(Icons.camera_alt_outlined), label: 'Selfie'),
              BottomNavigationBarItem(icon: Icon(Icons.route_outlined), label: 'Route'),
              BottomNavigationBarItem(icon: Icon(Icons.history_outlined), label: 'History'),
            ],
        };

        return Scaffold(
          appBar: AppBar(
            title: Text(_titleForRole(role)),
            actions: [
              IconButton(
                tooltip: 'Toggle theme',
                icon: Icon(
                  Theme.of(context).brightness == Brightness.dark
                      ? Icons.light_mode_outlined
                      : Icons.dark_mode_outlined,
                ),
                onPressed: widget.onToggleTheme,
              ),
              Padding(
                padding: const EdgeInsets.only(right: 12),
                child: Center(
                  child: InkWell(
                    onTap: () => setState(() => _accountOpen = true),
                    borderRadius: BorderRadius.circular(999),
                    child: Container(
                      width: 38,
                      height: 38,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                      ),
                      alignment: Alignment.center,
                      child: Text(_initials(user), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                    ),
                  ),
                ),
              ),
            ],
          ),
          body: IndexedStack(index: _index, children: pages),
          bottomNavigationBar: BottomNavigationBar(
            currentIndex: _index,
            type: BottomNavigationBarType.fixed,
            onTap: (value) => setState(() => _index = value),
            items: items,
          ),
          drawer: Drawer(
            child: ListView(
              padding: EdgeInsets.zero,
              children: [
                DrawerHeader(
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                  ),
                  child: Text(role, style: const TextStyle(fontSize: 20, color: Color(0xFF08101D))),
                ),
                ListTile(
                  leading: const Icon(Icons.logout),
                  title: const Text('Sign out'),
                  onTap: () async {
                    Navigator.of(context).pop();
                    final confirm = await showDialog<bool>(
                      context: context,
                      builder: (context) => AlertDialog(
                        title: const Text('Sign out?'),
                        content: const Text('Do you want to sign out of Secure360?'),
                        actions: [
                          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
                          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sign out')),
                        ],
                      ),
                    );
                    if (confirm == true) await _signOut();
                  },
                ),
              ],
            ),
          ),
          persistentFooterButtons: _accountOpen
              ? [
                  SizedBox(
                    width: MediaQuery.of(context).size.width - 32,
                    child: AccountSheet(
                      sessionStore: widget.sessionStore,
                      onClose: () => setState(() => _accountOpen = false),
                      onSignOut: _signOut,
                    ),
                  ),
                ]
              : null,
        );
      },
    );
  }
}
