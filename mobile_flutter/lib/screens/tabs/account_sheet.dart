import 'package:flutter/material.dart';
import '../../config/app_config.dart';
import '../../services/api_client.dart';
import '../../services/session_store.dart';

class AccountSheet extends StatefulWidget {
  const AccountSheet({
    super.key,
    required this.sessionStore,
    required this.onClose,
    required this.onSignOut,
  });

  final SessionStore sessionStore;
  final VoidCallback onClose;
  final Future<void> Function() onSignOut;

  @override
  State<AccountSheet> createState() => _AccountSheetState();
}

class _AccountSheetState extends State<AccountSheet> {
  late final ApiClient _api = ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: widget.sessionStore);
  Map<String, dynamic>? _user;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final res = await _api.getJson('/api/guard/profile');
      final data = res['data'] as Map<String, dynamic>? ?? const {};
      setState(() => _user = data['user'] as Map<String, dynamic>?);
    } on ApiException catch (e) {
      if (e.statusCode == 401) {
        await widget.onSignOut();
        return;
      }
      final user = await widget.sessionStore.user();
      if (mounted) setState(() => _user = user);
    } catch (_) {
      final user = await widget.sessionStore.user();
      if (mounted) setState(() => _user = user);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return 'IS';
    final first = parts.first.substring(0, 1);
    final second = parts.length > 1 ? parts[1].substring(0, 1) : (parts.first.length > 1 ? parts.first.substring(1, 2) : 'S');
    return (first + second).toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final user = _user ?? const {};
    final name = (user['full_name'] ?? 'Infipre Guard').toString();
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: _loading
            ? const SizedBox(height: 160, child: Center(child: CircularProgressIndicator()))
            : Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Account', style: Theme.of(context).textTheme.titleLarge),
                      IconButton(onPressed: widget.onClose, icon: const Icon(Icons.close)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Container(
                        width: 56,
                        height: 56,
                        decoration: const BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                        ),
                        alignment: Alignment.center,
                        child: Text(
                          _initials(name),
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                            Text((user['email'] ?? '').toString(), style: const TextStyle(color: Color(0xFFA3B2CA))),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  _infoRow('Phone', (user['phone'] ?? '-').toString()),
                  _infoRow('Employee code', (user['employee_code'] ?? '-').toString()),
                  _infoRow('Shift label', (user['shift_label'] ?? '-').toString()),
                  _infoRow('Status', (user['status'] ?? '-').toString()),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: () async {
                      await widget.onSignOut();
                    },
                    child: const Text('Logout'),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _infoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Color(0xFFA3B2CA))),
          Flexible(child: Text(value, textAlign: TextAlign.right)),
        ],
      ),
    );
  }
}
