import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:intl/intl.dart';
import '../../config/app_config.dart';
import '../../services/api_client.dart';
import '../../services/session_store.dart';

class GuardRouteTab extends StatefulWidget {
  const GuardRouteTab({super.key, required this.sessionStore, required this.onSessionExpired});
  final SessionStore sessionStore;
  final Future<void> Function() onSessionExpired;

  @override
  State<GuardRouteTab> createState() => _GuardRouteTabState();
}

class _GuardRouteTabState extends State<GuardRouteTab> {
  late final ApiClient _api = ApiClient(baseUrl: AppConfig.apiBaseUrl, sessionStore: widget.sessionStore);
  bool _busy = false;
  String _statusText = 'Tap "Send Location" to sync GPS.';
  Position? _lastPosition;
  List<Map<String, dynamic>> _locationRows = [];
  bool _loadingHistory = true;
  bool _isOnDuty = false;

  double _toDouble(dynamic v) => double.tryParse(v?.toString() ?? '') ?? 0.0;

  @override
  void initState() {
    super.initState();
    _loadHistory();
    _loadLocations();
  }

  Future<void> _loadHistory() async {
    try {
      final res = await _api.getJson('/api/guard/overview');
      final data = res['data'] as Map<String, dynamic>? ?? const {};
      final attendance = (data['attendance'] as List<dynamic>? ?? const []);
      final isOnDuty = attendance.whereType<Map>().any((row) {
        final map = Map<String, dynamic>.from(row);
        final checkOut = (map['check_out_at'] ?? '').toString();
        return map['check_out_at'] == null || checkOut.isEmpty;
      });

      final locations = (data['locations'] as List<dynamic>? ?? const []);
      final parsedLocations = locations
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .map((row) {
            row['latitude'] = _toDouble(row['latitude']);
            row['longitude'] = _toDouble(row['longitude']);
            return row;
          })
          .toList();

      if (!mounted) return;
      setState(() {
        _isOnDuty = isOnDuty;
        _locationRows = parsedLocations;
        _loadingHistory = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loadingHistory = false;
      });
    }
  }

  Future<void> _loadLocations() async {
    try {
      final res = await _api.getJson('/api/guard/locations?limit=10');
      final rows = (res['locations'] as List<dynamic>? ?? const []);
      final parsed = rows
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .map((row) {
            row['latitude'] = _toDouble(row['latitude']);
            row['longitude'] = _toDouble(row['longitude']);
            return row;
          })
          .toList();

      if (!mounted) return;
      setState(() {
        _locationRows = parsed;
      });
    } catch (_) {}
  }

  Future<void> _sendLocation() async {
    setState(() {
      _busy = true;
      _statusText = 'Requesting current GPS...';
    });
    try {
      final enabled = await Geolocator.isLocationServiceEnabled();
      if (!enabled) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Location services are off.'), duration: Duration(seconds: 3)),
        );
        setState(() => _statusText = 'Location services are off.');
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever || permission == LocationPermission.denied) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Location permission denied.'), duration: Duration(seconds: 3)),
        );
        setState(() => _statusText = 'Location permission denied.');
        return;
      }

      final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
      final now = DateTime.now();
      _lastPosition = pos;

      await _api.postJson('/api/guard/location', {
        'latitude': pos.latitude,
        'longitude': pos.longitude,
        'accuracy': pos.accuracy.round(),
        'address': '${pos.latitude.toStringAsFixed(5)}, ${pos.longitude.toStringAsFixed(5)}',
        'duty_label': 'Field checkpoint',
      });

      setState(() {
        _statusText = 'Last sent: ${pos.latitude.toStringAsFixed(5)}, ${pos.longitude.toStringAsFixed(5)}';
        _lastPosition = pos;
      });
      await _loadLocations();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Location sent.'), duration: Duration(seconds: 3)),
      );
    } on ApiException catch (e) {
      if (e.statusCode == 401) return widget.onSessionExpired();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.message), duration: const Duration(seconds: 3)),
      );
      setState(() => _statusText = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final center = _locationRows.isNotEmpty
        ? LatLng(_toDouble(_locationRows.first['latitude']), _toDouble(_locationRows.first['longitude']))
        : const LatLng(15.2993, 74.1240);

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
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Live Route Map', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                        const SizedBox(height: 4),
                        Text(
                          'Live GPS while this screen is open.',
                          style: TextStyle(fontSize: 13, color: Color(0xFFA3B2CA)),
                        ),
                      ],
                    ),
                    _trackingBadge(),
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
                Text('Send Location', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
                const SizedBox(height: 4),
                Text(_statusText, style: const TextStyle(fontSize: 13, color: Color(0xFFA3B2CA))),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _busy ? null : _sendLocation,
                    icon: _busy
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.my_location_outlined),
                    label: Text(_busy ? 'Sending...' : 'Send Location'),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        Card(
          child: ClipRRect(
            borderRadius: BorderRadius.circular(24),
            child: Column(
              children: [
                if (_loadingHistory)
                  SizedBox(
                    height: 260,
                    child: Center(
                      child: CircularProgressIndicator(
                        color: Theme.of(context).colorScheme.primary,
                      ),
                    ),
                  )
                else
                  SizedBox(
                    height: 260,
                    child: FlutterMap(
                      options: MapOptions(
                        initialCenter: center,
                        initialZoom: 15,
                      ),
                      children: [
                        TileLayer(
                          urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                          userAgentPackageName: 'com.secure360.mobile',
                        ),
                        if (_locationRows.length > 1)
                          PolylineLayer(
                            polylines: [
                              Polyline(
                                points: _locationRows
                                    .map((r) => LatLng(_toDouble(r['latitude']), _toDouble(r['longitude'])))
                                    .toList(),
                                strokeWidth: 3,
                                color: const Color(0xFF47D8A2),
                              ),
                            ],
                          ),
                        if (_locationRows.isNotEmpty)
                          MarkerLayer(
                            markers: [
                              Marker(
                                point: LatLng(
                                  _toDouble(_locationRows.first['latitude']),
                                  _toDouble(_locationRows.first['longitude']),
                                ),
                                width: 40,
                                height: 40,
                                child: Container(
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    color: const Color(0xFF47D8A2),
                                    border: Border.all(color: Colors.white, width: 2),
                                  ),
                                  child: const Icon(Icons.person_pin_circle, color: Colors.white, size: 22),
                                ),
                              ),
                            ],
                          ),
                      ],
                    ),
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
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Location History', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
                    OutlinedButton(
                      onPressed: _loadingHistory ? null : _loadLocations,
                      child: const Text('Refresh'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (_loadingHistory)
                  const Center(child: CircularProgressIndicator())
                else if (_locationRows.isEmpty)
                  const Center(
                    child: Padding(
                      padding: EdgeInsets.symmetric(vertical: 24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.location_off_outlined, size: 40, color: Color(0xFFA3B2CA)),
                          SizedBox(height: 8),
                          Text('No GPS points saved yet.', style: TextStyle(color: Color(0xFFA3B2CA))),
                        ],
                      ),
                    ),
                  )
                else
                  Column(
                    children: _locationRows.take(8).map((row) => _locationRow(row)).toList(),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _trackingBadge() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: _isOnDuty ? const Color(0xFF47D8A2).withOpacity(0.15) : const Color(0xFFA3B2CA).withOpacity(0.10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color: _isOnDuty ? const Color(0xFF47D8A2).withOpacity(0.4) : const Color(0xFFA3B2CA).withOpacity(0.3),
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_isOnDuty)
            Container(
              width: 8,
              height: 8,
              decoration: const BoxDecoration(shape: BoxShape.circle, color: Color(0xFF47D8A2)),
            ),
          if (_isOnDuty) const SizedBox(width: 6),
          Text(
            _isOnDuty ? 'Tracking' : 'Idle',
            style: const TextStyle(fontSize: 11, letterSpacing: 1.5),
          ),
        ],
      ),
    );
  }

  Widget _locationRow(Map<String, dynamic> row) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Theme.of(context).scaffoldBackgroundColor,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 36,
              height: 36,
              margin: const EdgeInsets.only(right: 10),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: const Color(0xFF47D8A2).withOpacity(0.15),
              ),
              child: const Icon(Icons.location_on_outlined, color: Color(0xFF47D8A2), size: 18),
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${_toDouble(row['latitude']).toStringAsFixed(5)}, ${_toDouble(row['longitude']).toStringAsFixed(5)}',
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    (row['duty_label'] ?? 'Field checkpoint').toString(),
                    style: const TextStyle(color: Color(0xFFA3B2CA), fontSize: 12),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    _fmtTime(row['tracked_at']),
                    style: const TextStyle(color: Color(0xFFA3B2CA), fontSize: 11),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _fmtTime(dynamic v) {
    final dt = DateTime.tryParse((v ?? '').toString());
    if (dt == null) return '-';
    return DateFormat('dd MMM, hh:mm a').format(dt.toLocal());
  }
}
