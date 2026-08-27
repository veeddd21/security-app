import 'package:flutter/material.dart';
import '../../services/session_store.dart';

class GuardSelfieTab extends StatefulWidget {
  const GuardSelfieTab({
    super.key,
    required this.sessionStore,
    required this.onGoToShift,
    required this.onSessionExpired,
  });

  final SessionStore sessionStore;
  final VoidCallback onGoToShift;
  final Future<void> Function() onSessionExpired;

  @override
  State<GuardSelfieTab> createState() => _GuardSelfieTabState();
}

class _GuardSelfieTabState extends State<GuardSelfieTab> {
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.camera_alt_outlined, size: 48),
                const SizedBox(height: 16),
                const Text(
                  'Selfie verification is now part of Shift.',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 16),
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: widget.onGoToShift,
                  child: const Text('Go to Shift'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
