<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/actions.php';

function api_boot_headers(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
    }
}

function api_respond(bool $ok, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    api_boot_headers();
    echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
    exit;
}

function api_param(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function api_request_data(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    return api_json_body();
}

function api_bearer_token(): ?string
{
    $header =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        $header =
            $headers['Authorization']
            ?? $headers['authorization']
            ?? '';
    }

    if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function api_ensure_token_table(): void
{
    db()->query(
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(128) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_api_tokens_user_id (user_id),
            CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
}

function api_issue_token(array $user): string
{
    api_ensure_token_table();
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare("INSERT INTO api_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $stmt->bind_param('is', $user['id'], $token);
    $stmt->execute();
    return $token;
}

function api_auth_user(): array
{
    api_ensure_token_table();
    $token = api_bearer_token();
    if (!$token) {
        api_respond(false, 'Unauthorized', [], 401);
    }
    $stmt = db()->prepare(
        "SELECT u.* FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND (t.expires_at IS NULL OR t.expires_at > NOW())
         LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        api_respond(false, 'Unauthorized', [], 401);
    }
    return $user;
}

function api_public_user(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'organization_id' => isset($user['organization_id']) ? (int)$user['organization_id'] : null,
        'role' => $user['role'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'] ?? null,
        'employee_code' => $user['employee_code'] ?? null,
        'shift_label' => $user['shift_label'] ?? null,
        'status' => $user['status'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null,
        'identity_photo_path' => $user['identity_photo_path'] ?? null,
        'identity_selfie_path' => $user['identity_selfie_path'] ?? null,
    ];
}

