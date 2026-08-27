import 'dart:typed_data';
import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

Future<Uint8List?> showSelfieCaptureScreen(BuildContext context) {
  return Navigator.of(context).push<Uint8List?>(
    MaterialPageRoute(builder: (_) => const SelfieCaptureScreen()),
  );
}

class SelfieCaptureScreen extends StatefulWidget {
  const SelfieCaptureScreen({super.key});

  @override
  State<SelfieCaptureScreen> createState() => _SelfieCaptureScreenState();
}

class _SelfieCaptureScreenState extends State<SelfieCaptureScreen> {
  CameraController? _controller;
  List<CameraDescription> _cameras = const [];
  Uint8List? _captured;
  bool _loading = true;
  bool _capturing = false;

  @override
  void initState() {
    super.initState();
    _initCamera();
  }

  Future<void> _initCamera() async {
    setState(() => _loading = true);
    try {
      _cameras = await availableCameras();
      final camera = _cameras.where((c) => c.lensDirection == CameraLensDirection.front).cast<CameraDescription?>().firstWhere(
            (c) => c != null,
            orElse: () => _cameras.isNotEmpty ? _cameras.first : null,
          );
      if (camera == null) {
        if (mounted) setState(() => _loading = false);
        return;
      }
      _controller = CameraController(camera, ResolutionPreset.high, enableAudio: false);
      await _controller!.initialize();
    } catch (_) {
      // Keep screen usable even if camera initialization fails.
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _capture() async {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized || _capturing) return;
    setState(() => _capturing = true);
    try {
      final file = await controller.takePicture();
      final bytes = await file.readAsBytes();
      if (!mounted) return;
      setState(() => _captured = bytes);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Unable to capture selfie.')));
      }
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  Future<void> _retake() async {
    setState(() => _captured = null);
    await _controller?.dispose();
    _controller = null;
    await _initCamera();
  }

  void _usePhoto() {
    Navigator.of(context).pop(_captured);
  }

  void _cancel() {
    Navigator.of(context).pop(null);
  }

  @override
  Widget build(BuildContext context) {
    final preview = _captured != null
        ? Image.memory(_captured!, fit: BoxFit.cover, width: double.infinity, height: double.infinity)
        : (_controller != null && _controller!.value.isInitialized)
            ? CameraPreview(_controller!)
            : const Center(child: CircularProgressIndicator());

    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Stack(
          children: [
            Positioned.fill(child: preview),
            Positioned.fill(
              child: IgnorePointer(
                child: Center(
                  child: Container(
                    width: 240,
                    height: 320,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(160),
                      border: Border.all(color: Colors.white.withOpacity(0.7), width: 2),
                    ),
                  ),
                ),
              ),
            ),
            Positioned(
              top: 12,
              left: 12,
              child: Material(
                color: Colors.black.withOpacity(0.35),
                shape: const CircleBorder(),
                child: IconButton(
                  onPressed: _cancel,
                  icon: const Icon(Icons.close, color: Colors.white),
                ),
              ),
            ),
            if (_loading)
              const Positioned.fill(
                child: Center(child: CircularProgressIndicator(color: Colors.white)),
              ),
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Container(
                padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Colors.transparent, Colors.black.withOpacity(0.86)],
                  ),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    TextButton(
                      onPressed: _captured == null ? null : _retake,
                      child: const Text('Retake', style: TextStyle(color: Colors.white)),
                    ),
                    GestureDetector(
                      onTap: _captured == null ? _capture : null,
                      child: Container(
                        width: 78,
                        height: 78,
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 3),
                        ),
                        child: Container(
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: _capturing ? Colors.white54 : Colors.white,
                          ),
                        ),
                      ),
                    ),
                    TextButton(
                      onPressed: _captured == null ? null : _usePhoto,
                      child: const Text('Use Photo', style: TextStyle(color: Colors.white)),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
