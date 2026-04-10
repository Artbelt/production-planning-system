<?php
/**
 * На общем ПК сессия может пережить смену — оператор оказывается «чужой».
 * Требуем, чтобы сессия была создана сегодняшним входом (см. auth_calendar_day в createSession).
 *
 * Подключать через daily_auth_load.php после успешного $auth->checkSession().
 */

if (!function_exists('laser_operator_require_same_calendar_day')) {

    function laser_operator_login_return_path() {
        $raw = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path)) {
            return 'laser_operator/';
        }
        $path = trim($path, '/');
        if ($path === 'laser_operator') {
            return 'laser_operator/';
        }
        if (preg_match('#^laser_operator/#', $path)) {
            return $path;
        }
        return 'laser_operator/';
    }

    /**
     * @param AuthManager $auth
     * @param bool $isJsonEndpoint ответ 401 JSON вместо редиректа на страницу входа
     */
    function laser_operator_require_same_calendar_day(AuthManager $auth, $isJsonEndpoint = false) {
        $today = date('Y-m-d');
        if (isset($_SESSION['auth_calendar_day']) && $_SESSION['auth_calendar_day'] === $today) {
            return;
        }

        $auth->destroySession();

        if ($isJsonEndpoint) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized', 'code' => 'daily_relogin_required']);
            exit;
        }

        $returnPath = laser_operator_login_return_path();
        if (!preg_match('#^laser_operator(/[a-zA-Z0-9_./\-]+)?/?$#', $returnPath)) {
            $returnPath = 'laser_operator/';
        }

        $q = http_build_query(['return' => $returnPath]);
        header('Location: /auth/login.php?' . $q);
        exit;
    }
}
