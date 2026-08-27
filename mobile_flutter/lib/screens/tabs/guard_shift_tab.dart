import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import 'package:intl/intl.dart';
import 'package:image_picker/image_picker.dart';
import '../../config/app_config.dart';
import '../../services/api_client.dart';
import '../../services/session_store.dart';
import '../../widgets/selfie_capture_screen.dart';

class GuardShiftTab extends StatefulWidget {
  const GuardShiftTab({
    super.key,
    required this.sessionStore,
    required this.onOpenSelfie,
    required this.onSessionExpired,
  });

  final SessionStore sessionStore;
  final VoidCallback onOpenSelfie;
  final Future<void> Function() onSessionExpired;

  @override
  State<GuardShiftTab> createState() => _GuardShiftTabState();
}

class _GuardShiftTabState extends State<GuardShiftTab> {
  late final ApiClient _api = ApiClient(
    baseUrl: AppConfig.apiBaseUrl,
    sessionStore: widget.sessionStore,
  );
  bool _busy = false;
  bool _loading = true;
  Map<String, dynamic>? _activeAttendance;
  Uint8List? _selfieBytes;
  String _locationLabel = 'Main Gate';
  String _shiftNote = '';
  // List<String> _siteOptions = ['Main Gate', 'North Perimeter', 'Lobby', 'Control Room', 'South Gate'];

  @override
  void initState() {
    super.initState();
    _loadState();
  }

 Future<void> _loadState() async {
  setState(() => _loading = true);
  try {
    final res = await _api.getJson('/api/guard/overview');
    final data = res['data'] as Map<String, dynamic>? ?? const {};
    
    // Set the assigned zone from the user profile (non-editable)
    final userMap = data['user'] as Map<String, dynamic>? ?? const {};
    final assignedZone = (userMap['shift_label'] ?? '').toString();
    if (assignedZone.isNotEmpty) {
      _locationLabel = assignedZone;
    }

    final attendanceList = data['attendance'] as List? ?? const [];
    final attendance = attendanceList.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
    final active = attendance.firstWhere(
      (r) => r['check_out_at'] == null || r['check_out_at'].toString().isEmpty,
      orElse: () => {},
    );
    if (!mounted) return;
    setState(() => _activeAttendance = active.isEmpty ? null : active);
  } catch (_) {
  } finally {
    if (mounted) setState(() => _loading = false);
  }
}
  Future<Uint8List?> _captureSelfieInApp() async {
    final file = await showSelfieCaptureScreen(context);
    if (file == null) return null;
    return file;
  }

  bool get _onDuty => _activeAttendance != null;

  Future<void> _captureAndPreview() async {
    final bytes = await _captureSelfieInApp();
    if (bytes == null) return;
    if (!mounted) return;
    setState(() => _selfieBytes = bytes);
  }

   Future<void> _startShift() async {
    if (_selfieBytes == null || _busy) return;
    setState(() => _busy = true);
    try {
      // Step 1: upload selfie using _api.uploadBytes (handles auth automatically)
      await _api.uploadBytes(
        '/api/guard/selfie',
        _selfieBytes!,
        fieldName: 'selfie',
        filename: 'selfie.jpg',
        fields: {'capture_phase': 'check_in'},
      );
      // Step 2: check in
      await _api.postJson('/api/guard/checkin', {
        'location_label': _locationLabel,
        'note': _shiftNote,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Shift started successfully.'), duration: Duration(seconds: 3)),
      );
      setState(() { _selfieBytes = null; _shiftNote = ''; });
      await _loadState();
    } on ApiException catch (e) {
      if (e.statusCode == 401) { await widget.onSessionExpired(); return; }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Start shift failed: ${e.message}'), duration: const Duration(seconds: 4)),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: ${e.toString()}'), duration: const Duration(seconds: 4)),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _stopShift() async {
    if (_selfieBytes == null || _busy || _activeAttendance == null) return;
    setState(() => _busy = true);
    try {
      // Step 1: upload selfie
      await _api.uploadBytes(
        '/api/guard/selfie',
        _selfieBytes!,
        fieldName: 'selfie',
        filename: 'selfie.jpg',
        fields: {'capture_phase': 'check_out'},
      );
      // Step 2: check out
      await _api.postJson('/api/guard/checkout', {
        'attendance_id': _activeAttendance!['id'],
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Shift stopped.'), duration: Duration(seconds: 3)),
      );
      setState(() { _selfieBytes = null; _shiftNote = ''; });
      await _loadState();
    } on ApiException catch (e) {
      if (e.statusCode == 401) { await widget.onSessionExpired(); return; }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Stop shift failed: ${e.message}'), duration: const Duration(seconds: 4)),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: ${e.toString()}'), duration: const Duration(seconds: 4)),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _badgeLabel() => _loading ? 'Loading' : (_onDuty ? 'On Duty' : 'Standby');

  Color _badgeColor() => _onDuty ? const Color(0xFF47D8A2) : const Color(0xFFA3B2CA);

  @override
  Widget build(BuildContext context) {
    final title = _onDuty ? 'Check-out selfie verification' : 'Check-in selfie verification';
    final helper = _onDuty ? 'Capture a live selfie before ending the shift.' : 'Live selfie required before starting the shift.';

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: const [
                          Text('Attendance & Selfie', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                          SizedBox(height: 4),
                          Text('Selfie and GPS proof required.', style: TextStyle(fontSize: 13, color: Color(0xFFA3B2CA))),
                        ],
                      ),
                    ),
                    _loading
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                        : Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(999),
                              color: _badgeColor().withOpacity(0.12),
                              border: Border.all(color: _badgeColor().withOpacity(0.35)),
                            ),
                            child: Text(
                              _badgeLabel(),
                              style: TextStyle(fontSize: 11, letterSpacing: 1.5, color: _badgeColor()),
                            ),
                          ),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _onDuty ? 'Check-out selfie verification' : 'Check-in selfie verification',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 4),
                Text(
                  _onDuty
                      ? 'Capture a live selfie before ending the shift.'
                      : 'Live selfie required before starting the shift.',
                  style: const TextStyle(fontSize: 13, color: Color(0xFFA3B2CA)),
                ),
                const SizedBox(height: 16),
                if (_selfieBytes == null)
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: _busy ? null : _captureAndPreview,
                      icon: const Icon(Icons.camera_alt_outlined),
                      label: const Text('Capture live selfie'),
                    ),
                  )
                else
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: Image.memory(_selfieBytes!, width: 90, height: 90, fit: BoxFit.cover),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _onDuty ? 'Check the image before stopping.' : 'Check the image before starting.',
                              style: const TextStyle(fontSize: 13, color: Color(0xFFA3B2CA)),
                            ),
                            const SizedBox(height: 10),
                            OutlinedButton.icon(
                              onPressed: _busy ? null : () => setState(() => _selfieBytes = null),
                              icon: const Icon(Icons.refresh_outlined, size: 16),
                              label: const Text('Retake'),
                              style: OutlinedButton.styleFrom(
                                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                textStyle: const TextStyle(fontSize: 13),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Shift Details', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
                const SizedBox(height: 16),
               Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xFF94A3B4).withOpacity(0.4)),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.location_on_outlined, size: 18, color: Color(0xFFA3B2CA)),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Assigned Duty Zone', style: TextStyle(fontSize: 11, color: Color(0xFFA3B2CA))),
                          Text(_locationLabel.isNotEmpty ? _locationLabel : 'Not assigned', style: const TextStyle(fontWeight: FontWeight.w600)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
                const SizedBox(height: 12),
                TextField(
                  onChanged: (value) => _shiftNote = value,
                  enabled: !_busy,
                  decoration: InputDecoration(
                    labelText: 'Shift note (optional)',
                    hintText: 'e.g. Normal patrol shift',
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  ),
                ),
                // const SizedBox(height: 12),
                // SizedBox(
                //   width: double.infinity,
                //   child: OutlinedButton(
                //     onPressed: widget.onOpenSelfie,
                //     child: const Text('Open Selfie Screen'),
                //   ),
                // ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Current status',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 4),
                Text(
                  _onDuty ? 'You are currently on duty.' : 'You are currently on standby.',
                  style: const TextStyle(fontSize: 13, color: Color(0xFFA3B2CA)),
                ),
                const SizedBox(height: 8),
                Text(
                  _busy ? 'Working...' : DateFormat('dd MMM, hh:mm a').format(DateTime.now()),
                  style: const TextStyle(fontSize: 12, color: Color(0xFFA3B2CA)),
                ),
                const SizedBox(height: 16),
                  if (!_onDuty)
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        onPressed: (_busy || _selfieBytes == null) ? null : _startShift,
                        icon: _busy
                            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : const Icon(Icons.login_outlined),
                        label: Text(_busy ? 'Starting...' : 'Start Shift'),
                      ),
                    )
                  else
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
                        onPressed: (_busy || _selfieBytes == null) ? null : _stopShift,
                        icon: _busy
                            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : const Icon(Icons.logout_outlined),
                        label: Text(_busy ? 'Stopping...' : 'Stop Shift'),
                      ),
                    ),
                  if (_selfieBytes == null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        'Capture a selfie above to enable this button.',
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Color(0xFFA3B2CA), fontSize: 12),
                      ),
                    ),
                ],
            ),
          ),
        ),
        
      ],
    );
  }
}
