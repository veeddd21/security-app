<?php

require_once __DIR__ . '/bootstrap.php';

function authenticate(string $email, string $password): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('Invalid email or password.');
    }
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    db()->query("UPDATE users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = " . (int)$user['id']);
    return $user;
}

function logout_user(): void
{
    session_destroy();
}
