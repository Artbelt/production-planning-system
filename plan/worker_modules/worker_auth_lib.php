<?php

/**
 * Мягкая проверка сессии для worker-страниц (без редиректа).
 * @return array{authenticated:bool,user:?array{id:int,phone:string,full_name:string}}
 */
function worker_auth_current_user(): array
{
    $empty = ['authenticated' => false, 'user' => null];
    try {
        if (!defined('AUTH_SYSTEM')) {
            define('AUTH_SYSTEM', true);
        }
        require_once __DIR__ . '/../../auth/includes/config.php';
        require_once __DIR__ . '/../../auth/includes/auth-functions.php';
        initAuthSystem();
        $auth = new AuthManager();
        $session = $auth->checkSession();
        if (!is_array($session) || empty($session['user_id'])) {
            return $empty;
        }
        return [
            'authenticated' => true,
            'user' => [
                'id' => (int)$session['user_id'],
                'phone' => (string)($session['phone'] ?? ''),
                'full_name' => trim((string)($session['full_name'] ?? '')),
            ],
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * Для JSON API: если не авторизован — 401 и exit.
 * @return array{id:int,phone:string,full_name:string}
 */
function worker_auth_require_user(): array
{
    $info = worker_auth_current_user();
    if (!$info['authenticated'] || !$info['user']) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Требуется авторизация',
            'auth_required' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $info['user'];
}
