<?php
/**
 * API выхода (JSON) — без редиректа на страницу логина
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth-functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

initAuthSystem();

$auth = new AuthManager();
$auth->destroySession();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
