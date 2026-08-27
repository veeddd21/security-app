import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../config/app_config.dart';
import '../../services/api_client.dart';
import '../../services/session_store.dart';

class GuardHomeTab extends StatefulWidget {
  const GuardHomeTab({
    super.key,
    required this.sessionStore,
    required this.onOpenShift,
    required this.onOpenRoute,
    required this.onOpenHistory,
    required this.onSessionExpired,
  });

  final SessionStore sessionStore;
  final VoidCallback onOpenShift;
  final VoidCallback onOpenRoute;
  final VoidCallback onOpenHistory;
  final Future<void> Function() onSessionExpired;

  @override
  State<GuardHomeTab> createState() => _GuardHomeTabState();
}

class _GuardHomeTabState extends State<GuardHomeTab> {
  late final ApiClient _api = ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: widget.sessionStore);
  Map<String, dynamic>? _overview;
  bool _loading = true;
  String _error = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final res = await _api.getJson('/api/guard/overview');
      if (!mounted) return;
      setState(() => _overview = res['data'] as Map<String, dynamic>? ?? const {});
    } on ApiException catch (e) {
      if (e.statusCode == 401) {
        await _handleUnauthorized();
        return;
      }
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _handleUnauthorized() async {
    await widget.sessionStore.clear();
    await widget.onSessionExpired();
  }

  Map<String, dynamic>? get _user => (_overview?['user'] as Map<String, dynamic>?);

  List<Map<String, dynamic>> get _attendance {
    final raw = (_overview?['attendance'] as List<dynamic>? ?? const []);
    return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
  }

  int get _hoursToday {
    var total = 0;
    final now = DateTime.now();
    for (final row in _attendance) {
      final checkIn = DateTime.tryParse((row['check_in_at'] ?? '').toString());
      if (checkIn == null) continue;
      final checkOut = DateTime.tryParse((row['check_out_at'] ?? '').toString()) ?? now;
      total += checkOut.difference(checkIn).inMinutes;
    }
    return total;
  }

  @override
  Widget build(BuildContext context) {
    final name = (_user?['full_name'] ?? 'Guard').toString();
    final initials = name
        .split(RegExp(r'\s+'))
        .where((part) => part.isNotEmpty)
        .map((part) => part.substring(0, 1))
        .take(2)
        .join()
        .toUpperCase();
    final today = DateFormat('dd MMM, hh:mm a');

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error.isNotEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final last = _attendance.isNotEmpty ? _attendance.first : null;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: const BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                    ),
                    alignment: Alignment.center,
                    child: Text(initials, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Guard Profile', style: TextStyle(letterSpacing: 3)),
                        const SizedBox(height: 6),
                        Text(name, style: Theme.of(context).textTheme.headlineSmall),
                        Text((_user?['employee_code'] ?? '').toString(), style: const TextStyle(color: Color(0xFFA3B2CA))),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      _statusBadge((_attendance.isNotEmpty && (last?['check_out_at'] == null || (last?['check_out_at'] ?? '').toString().isEmpty)) ? 'On Duty' : 'Standby'),
                      const SizedBox(height: 8),
                      _statusBadge((_user?['status'] ?? 'active').toString() == 'active' ? 'Selfie Ready' : 'Need Review'),
                    ],
                  )
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              _metricCard('Shift State', (_attendance.isNotEmpty && (last?['check_out_at'] == null || (last?['check_out_at'] ?? '').toString().isEmpty)) ? 'On Duty' : 'Standby', 'Current status'),
              _metricCard('Selfie Proof', 'Captured', 'Latest verification'),
              _metricCard('Hours Today', _formatMinutes(_hoursToday), 'Total shift time'),
              _metricCard('Check-ins Today', _attendance.length.toString(), 'Attendance records'),
            ],
          ),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Quick Actions', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 12,
                    runSpacing: 12,
                    children: [
                      FilledButton(
                        onPressed: widget.onOpenShift,
                        child: const Text('Start Shift'),
                      ),
                      OutlinedButton(
                        onPressed: widget.onOpenShift,
                        child: const Text('Stop Shift'),
                      ),
                      OutlinedButton(
                        onPressed: widget.onOpenRoute,
                        child: const Text('Send Location'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton(
              onPressed: widget.onOpenHistory,
              child: const Text('Open History'),
            ),
          ),
          const SizedBox(height: 16),
          Text('Recent Attendance', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          ..._attendance.take(8).map((row) => Card(
                child: ListTile(
                  title: Text((row['location_label'] ?? 'Field checkpoint').toString()),
                  subtitle: Text(
                    'In: ${_fmt(row['check_in_at'])}\nOut: ${_fmt(row['check_out_at'])}',
                  ),
                  trailing: Text(_duration(row)),
                  isThreeLine: true,
                ),
              )),
          const SizedBox(height: 8),
          Text('Updated ${today.format(DateTime.now())}', style: const TextStyle(color: Color(0xFFA3B2CA))),
        ],
      ),
    );
  }

  Widget _metricCard(String title, String value, String detail) {
    return SizedBox(
      width: 160,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(color: Color(0xFFA3B2CA))),
              const SizedBox(height: 12),
              Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Text(detail, style: const TextStyle(color: Color(0xFFA3B2CA))),
            ],
          ),
        ),
      ),
    );
  }

  Widget _statusBadge(String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0xFF47D8A2).withOpacity(0.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: const Color(0xFF47D8A2).withOpacity(0.28)),
      ),
      child: Text(text, style: const TextStyle(fontSize: 11, letterSpacing: 2)),
    );
  }

  String _fmt(dynamic value) {
    final parsed = DateTime.tryParse((value ?? '').toString());
    if (parsed == null) return '-';
    return DateFormat('dd MMM, hh:mm a').format(parsed);
  }

  String _duration(Map<String, dynamic> row) {
    final inTs = DateTime.tryParse((row['check_in_at'] ?? '').toString());
    if (inTs == null) return '-';
    final outTs = DateTime.tryParse((row['check_out_at'] ?? '').toString()) ?? DateTime.now();
    final mins = outTs.difference(inTs).inMinutes;
    return _formatMinutes(mins);
  }

  String _formatMinutes(int mins) {
    final h = mins ~/ 60;
    final m = mins % 60;
    return '${h}h ${m}m';
  }
}
