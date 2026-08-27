import 'package:flutter/foundation.dart';

class AppConfig {
  static String defaultBaseUrl = 'http://192.168.0.130/php-core/public';
  static String? customBaseUrl;

  static String get apiBaseUrl {
    if (customBaseUrl != null && customBaseUrl!.trim().isNotEmpty) {
      return customBaseUrl!.trim();
    }

    const override = String.fromEnvironment('API_BASE_URL');
    if (override.isNotEmpty) {
      return override;
    }

    if (kIsWeb) {
      return 'http://localhost/php-core/public';
    }

    return defaultBaseUrl;
  }
}

