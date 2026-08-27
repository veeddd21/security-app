import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../config/app_config.dart';
import '../../services/api_client.dart';
import '../../services/session_store.dart';

class GuardHistoryTab extends StatefulWidget {
  const GuardHistoryTab({super.key, required this.sessionStore, required this.onSessionExpired});
  final SessionStore sessionStore;
  final Future<void> Function() onSessionExpired;

  @override
  State<GuardHistoryTab> createState() => _GuardHistoryTabState();
}

class _GuardHistoryTabState extends State<GuardHistoryTab> {
  late final ApiClient _api = ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: widget.sessionStore);
  final _search = TextEditingController();
  bool _loading = true;
  String _error = '';
  List<Map<String, dynamic>> _rows = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final res = await _api.getJson('/api/guard/history?limit=20');
      final data = res['data'] as Map<String, dynamic>? ?? const {};
      final raw = (data['records'] as List<dynamic>? ??
              data['history'] as List<dynamic>? ??
              data['attendance'] as List<dynamic>? ??
              const []);
      setState(() {
        _rows = raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      });
    } on ApiException catch (e) {
      if (e.statusCode == 401) return widget.onSessionExpired();
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<Map<String, dynamic>> get _filtered {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return _rows.where((row) {
      final haystack = [
        row['full_name'],
        row['email'],
        row['employee_code'],
        row['shift_label'],
        row['status'],
        row['location_label'],
      ].map((e) => (e ?? '').toString().toLowerCase()).join(' ');
      return haystack.contains(q);
    }).toList();
  }

  String _fmt(dynamic value) {
    final parsed = DateTime.tryParse((value ?? '').toString());
    if (parsed == null) return '-';
    return DateFormat('dd MMM, hh:mm a').format(parsed);
  }

  String _fmtDate(dynamic value) {
    final parsed = DateTime.tryParse((value ?? '').toString());
    if (parsed == null) return '-';
    return DateFormat('dd MMM yyyy').format(parsed);
  }

  String _duration(Map<String, dynamic> row) {
    final mins = (row['duration_minutes'] as num?)?.toInt();
    if (mins == null) return '-';
    final h = mins ~/ 60;
    final m = mins % 60;
    return '${h}h ${m}m';
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error.isNotEmpty) {
      return Center(child: Text(_error));
    }
    final rows = _filtered;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Attendance History', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 12),
          TextField(
            controller: _search,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Search history',
              prefixIcon: Icon(Icons.search),
            ),
          ),
          const SizedBox(height: 16),
          if (rows.isEmpty)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('No history found', style: TextStyle(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 8),
                    Text(
                      _search.text.trim().isEmpty
                          ? 'No attendance records have been loaded yet.'
                          : 'No records match "${_search.text.trim()}".',
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: _load,
                      child: const Text('Refresh'),
                    ),
                  ],
                ),
              ),
            )
          else
            ...rows.map(
              (row) => Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _fmtDate(row['check_in_at'] ?? row['created_at']),
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: 8),
                      Text('Check in: ${_fmt(row['check_in_at'])}'),
                      Text('Check out: ${_fmt(row['check_out_at'])}'),
                      Text('Duration: ${_duration(row)}'),
                      if ((row['location_label'] ?? '').toString().isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text('Location: ${row['location_label']}'),
                      ],
                      const SizedBox(height: 8),
                      Text(
                        (row['status'] ?? 'completed').toString().toUpperCase(),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
