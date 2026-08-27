<?php

date_default_timezone_set('Asia/Calcutta');
session_start();

require_once __DIR__ . '/env.php';
load_env_file(__DIR__ . '/../.env');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/faceMatch.php';

$appConfig = require __DIR__ . '/config.php';

function config(string $key, $default = null) {
    global $appConfig;
    return $appConfig[$key] ?? $default;
}


function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function redirect(string $path): never {
    header('Location: ' . config('base_url') . $path);
    exit;
}

function asset_url(?string $path): string {
    if (!$path) return '';
    return config('base_url') . '/../' . ltrim($path, '/');
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_role(array $roles): void {
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        redirect('/index.php?page=auth');
    }
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

function flash_get(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}