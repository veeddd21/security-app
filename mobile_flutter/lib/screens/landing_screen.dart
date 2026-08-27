import 'dart:ui';
import 'package:flutter/material.dart';

class LandingScreen extends StatelessWidget {
  const LandingScreen({super.key, required this.onSignIn});

  final VoidCallback onSignIn;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          const _Backdrop(),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          Container(
                            width: 48,
                            height: 48,
                            decoration: const BoxDecoration(
                              shape: BoxShape.circle,
                              gradient: LinearGradient(colors: [Color(0xFFF3C24F), Color(0xFF47D8A2)]),
                            ),
                          ),
                          const SizedBox(width: 12),
                          const Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('Secure360', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: Colors.white)),
                              Text('Infipre Security', style: TextStyle(color: Color(0xFFA3B2CA))),
                            ],
                          ),
                        ],
                      ),
                      FilledButton(
                        onPressed: onSignIn,
                        style: FilledButton.styleFrom(
                          backgroundColor: const Color(0xFFF3C24F),
                          foregroundColor: const Color(0xFF08101D),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
                        ),
                        child: const Text('Sign In'),
                      ),
                    ],
                  ),
                  const Spacer(),
                  const Text('Guard & Admin operations', style: TextStyle(fontSize: 44, fontWeight: FontWeight.w800, color: Colors.white, height: 1.02)),
                  const SizedBox(height: 12),
                  const Text(
                    'Live attendance, selfie verification, location tracking, and admin control in one security console.',
                    style: TextStyle(color: Color(0xFFA3B2CA), fontSize: 16, height: 1.7),
                  ),
                  const SizedBox(height: 28),
                  Wrap(
                    spacing: 12,
                    runSpacing: 12,
                    children: const [
                      _FeatureChip(label: 'Verified shifts'),
                      _FeatureChip(label: 'Live GPS sync'),
                      _FeatureChip(label: 'Selfie proof'),
                    ],
                  ),
                  const Spacer(),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FeatureChip extends StatelessWidget {
  const _FeatureChip({required this.label});
  final String label;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xFF111827),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: const Color(0xFF94A3B4).withOpacity(0.18)),
      ),
      child: Text(label, style: const TextStyle(color: Colors.white)),
    );
  }
}

class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFF0A1320),
      child: Stack(
        children: const [
          Positioned(top: -20, left: -20, child: _Blob(color: Color(0x33F3C24F), size: 240)),
          Positioned(top: 20, right: -10, child: _Blob(color: Color(0x3347D8A2), size: 220)),
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
    return Container(width: size, height: size, decoration: BoxDecoration(shape: BoxShape.circle, color: color));
  }
}
