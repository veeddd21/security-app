import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

class SessionStore {
  static const _authTokenKey = 'auth_token';
  static const _tokenKey = 'secure360_token';
  static const _roleKey = 'secure360_role';
  static const _userKey = 'secure360_user';

  Future<SharedPreferences> get _prefs async => SharedPreferences.getInstance();

  Future<void> saveSession({required String token, required String role}) async {
    final prefs = await _prefs;
    await prefs.setString(_authTokenKey, token);
    await prefs.setString(_tokenKey, token);
    await prefs.setString(_roleKey, role);
    // ignore: avoid_print
    print('Saved token: $token');
  }

  Future<void> saveUser(Map<String, dynamic> user) async {
    final prefs = await _prefs;
    await prefs.setString(_userKey, jsonEncode(user));
  }

  Future<void> saveServerUrl(String url) async {
    final prefs = await _prefs;
    await prefs.setString('secure360_server_url', url);
  }

  Future<String?> serverUrl() async {
    final prefs = await _prefs;
    return prefs.getString('secure360_server_url');
  }

  Future<void> clear() async {
    final prefs = await _prefs;
    await prefs.remove(_authTokenKey);
    await prefs.remove(_tokenKey);
    await prefs.remove(_roleKey);
    await prefs.remove(_userKey);
  }

  Future<bool> hasSession() async {
    final token = await this.token();
    return (token ?? '').isNotEmpty;
  }

  Future<String?> token() async {
    final prefs = await _prefs;
    return prefs.getString(_authTokenKey) ?? prefs.getString(_tokenKey);
  }

  Future<String?> role() async {
    final prefs = await _prefs;
    return prefs.getString(_roleKey);
  }

  Future<Map<String, dynamic>?> user() async {
    final prefs = await _prefs;
    final value = prefs.getString(_userKey);
    if (value == null || value.isEmpty) return null;
    final decoded = jsonDecode(value);
    return decoded is Map<String, dynamic> ? decoded : null;
  }
}
