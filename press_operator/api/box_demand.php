<?php
/**
 * API актуальной потребности в коробках по активным заявкам всех участков.
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/../../auth/includes/config.php';
require_once __DIR__ . '/../../auth/includes/auth-functions.php';
require_once __DIR__ . '/../includes/box_demand_service.php';

initAuthSystem();

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT r.name AS role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$hasAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'box_operator'], true)) {
        $hasAccess = true;
        break;
    }
}

if (!$hasAccess) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Нет доступа'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'detail') {
        $boxName = trim((string)($_GET['box'] ?? ''));
        if ($boxName === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Не указана коробка'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $data = boxDemandFetchBoxDetail($boxName);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = boxDemandFetchSummary();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
