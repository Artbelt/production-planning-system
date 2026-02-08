<?php
ob_start();
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');
require_once('tools/ensure_salary_warehouse_tables.php');
ob_clean();

header('Content-Type: application/json; charset=utf-8');
initAuthSystem();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$date = isset($_POST['date']) ? trim($_POST['date']) : '';
$pay_mode = isset($_POST['pay_mode']) ? trim($_POST['pay_mode']) : '';

if ($team_id < 1 || $team_id > 4 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Неверные параметры'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($pay_mode, ['piece', 'hourly'])) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'pay_mode должен быть piece или hourly'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("INSERT INTO salary_brigade_shift_pay_mode (team_id, date, pay_mode) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pay_mode = VALUES(pay_mode)");
    $stmt->execute([$team_id, $date, $pay_mode]);
    ob_clean();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения'], JSON_UNESCAPED_UNICODE);
}
