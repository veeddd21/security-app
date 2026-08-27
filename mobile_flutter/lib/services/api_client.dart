import 'dart:convert';
import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';
import 'package:image_picker/image_picker.dart';
import 'session_store.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode});
  final String message;
  final int? statusCode;
  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({
    required this.baseUrl,
    required this.sessionStore,
  });

  final String baseUrl;
  final SessionStore sessionStore;

  Future<Map<String, String>> _headers({bool jsonBody = true}) async {
    final token = await sessionStore.token();
    final headers = <String, String>{
      'Accept': 'application/json',
      if (jsonBody) 'Content-Type': 'application/json',
    };
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
      // ignore: avoid_print
      print('Sending token: Bearer $token');
    }
    return headers;
  }

  Uri _uri(String path) => Uri.parse('$baseUrl$path');

  Future<Map<String, dynamic>> getJson(String path) async {
    final response = await http.get(_uri(path), headers: await _headers());
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> postJson(String path, Map<String, dynamic> body) async {
    final response = await http.post(
      _uri(path),
      headers: await _headers(),
      body: jsonEncode(body),
    );
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> patchJson(String path, Map<String, dynamic> body) async {
    final response = await http.patch(
      _uri(path),
      headers: await _headers(),
      body: jsonEncode(body),
    );
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> deleteJson(String path) async {
    final response = await http.delete(_uri(path), headers: await _headers());
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> logout() => postJson('/api/auth/logout', {});

  Future<Map<String, dynamic>> uploadFile(
    String path, {
    required XFile file,
    required String fieldName,
    Map<String, String> fields = const {},
  }) async {
    final request = http.MultipartRequest('POST', _uri(path));
    request.headers.addAll(await _headers(jsonBody: false));
    request.fields.addAll(fields);
    request.files.add(http.MultipartFile.fromBytes(fieldName, await file.readAsBytes(), filename: file.name));
    final streamed = await request.send();
    final response = await http.Response.fromStream(streamed);
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> uploadBytes(
    String path,
    Uint8List bytes, {
    required String fieldName,
    Map<String, String> fields = const {},
    String filename = 'selfie.jpg',
  }) async {
    final request = http.MultipartRequest('POST', _uri(path));
    request.headers.addAll(await _headers(jsonBody: false));
    request.fields.addAll(fields);
    request.files.add(
      http.MultipartFile.fromBytes(
        fieldName,
        bytes,
        filename: filename,
        contentType: MediaType('image', filename.endsWith('.png') ? 'png' : 'jpeg'),
      ),
    );
    final streamed = await request.send();
    final response = await http.Response.fromStream(streamed);
    // ignore: avoid_print
    print(response.statusCode);
    // ignore: avoid_print
    print(response.body);
    return _decode(response);
  }

  Future<Map<String, dynamic>> _decode(http.Response response) async {
    Map<String, dynamic> decoded = {};
    if (response.body.isNotEmpty) {
      final jsonBody = jsonDecode(response.body);
      if (jsonBody is Map<String, dynamic>) {
        decoded = jsonBody;
      }
    }

    if (response.statusCode == 401) {
      throw ApiException(decoded['message']?.toString() ?? 'Unauthorized', statusCode: 401);
    }

    if (response.statusCode >= 400 || decoded['ok'] == false) {
      throw ApiException(decoded['message']?.toString() ?? 'Request failed', statusCode: response.statusCode);
    }

    return decoded;
  }
}
