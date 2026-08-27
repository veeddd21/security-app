# Secure360 Flutter Mobile

This folder contains a separate Flutter mobile client for the existing Secure360 / Infipre Security PHP backend.

## What it does

- Keeps the PHP web app untouched
- Uses token-based login against the PHP API
- Provides a mobile shell with role-based tabs
- Includes account and sign-out handling

## What to configure

The app now picks a sensible default API base URL automatically:

```dart
API_BASE_URL=http://localhost/php-core/public
```

- Chrome/web uses `http://localhost/php-core/public`
- Android emulator uses `http://10.0.2.2/php-core/public`
- A physical device should use your machine's LAN IP

## Next steps

- Wire the remaining API endpoints into the Flutter client
- Add guard selfie capture, live location sync, and history
- Replace placeholder tabs with real role-specific views
