<?php
/**
 * Auth-хелперы модуля diagnostics.
 */

function diagnostics_auth_bootstrap(): void
{
    if (!defined('AUTH_SYSTEM')) {
        define('AUTH_SYSTEM', true);
    }
    require_once __DIR__ . '/../auth/includes/config.php';
    require_once __DIR__ . '/../auth/includes/auth-functions.php';
    initAuthSystem();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * @return array{user_id:int,user_name:string,roles:string[],is_editor:bool}|null
 */
function diagnostics_require_user(bool $editorOnly = false): ?array
{
    diagnostics_auth_bootstrap();
    $auth = new AuthManager();
    $session = $auth->checkSession();
    if (!$session || empty($session['user_id'])) {
        return null;
    }

    $db = Database::getInstance();
    $users = $db->select('SELECT * FROM auth_users WHERE id = ?', [$session['user_id']]);
    $user = $users[0] ?? null;
    if (!$user) {
        return null;
    }

    $userDepartments = $db->select(
        "SELECT ud.department_code, r.name as role_name
         FROM auth_user_departments ud
         JOIN auth_roles r ON ud.role_id = r.id
         WHERE ud.user_id = ?",
        [$session['user_id']]
    );

    $roles = [];
    foreach ($userDepartments as $dept) {
        $roles[] = (string)$dept['role_name'];
    }
    $roles = array_values(array_unique($roles));

    $editorRoles = ['supervisor', 'admin', 'director'];
    $isEditor = count(array_intersect($roles, $editorRoles)) > 0;
    // Если ролей нет в сессии — любой залогиненный может редактировать (как другие редакторы У2)
    if (!$roles) {
        $isEditor = true;
    }

    if ($editorOnly && !$isEditor) {
        return null;
    }

    $name = trim((string)($user['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($user['username'] ?? ''));
    }
    if ($name === '') {
        $name = 'user#' . (int)$session['user_id'];
    }

    return [
        'user_id' => (int)$session['user_id'],
        'user_name' => $name,
        'roles' => $roles,
        'is_editor' => $isEditor,
    ];
}

function diagnostics_require_user_or_redirect(bool $editorOnly = false): array
{
    $info = diagnostics_require_user($editorOnly);
    if (!$info) {
        $login = '../auth/login.php';
        if ($editorOnly) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Нет доступа</title></head><body>';
            echo '<p>Нет доступа к редактору/журналу диагностики. Нужна роль мастер/админ/директор.</p>';
            echo '<p><a href="' . htmlspecialchars($login) . '">Войти</a></p></body></html>';
            exit;
        }
        header('Location: ' . $login);
        exit;
    }
    return $info;
}

function diagnostics_json_require_user(bool $editorOnly = false): array
{
    $info = diagnostics_require_user($editorOnly);
    if (!$info) {
        http_response_code($editorOnly ? 403 : 401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $editorOnly ? 'Нет прав редактора' : 'Требуется авторизация',
            'auth_required' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $info;
}
