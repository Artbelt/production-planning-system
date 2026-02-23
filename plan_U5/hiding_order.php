<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Подключаем настройки базы данных
require_once('settings.php');
require_once('tools/tools.php');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем роль пользователя в цехе U5
$userRole = null;
$canArchiveOrder = false;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U5') {
        $userRole = $dept['role_name'];
        // Только мастер (supervisor) и директор (director) могут отправлять в архив
        if (in_array($userRole, ['supervisor', 'director'])) {
            $canArchiveOrder = true;
        }
        break;
    }
}

// Проверка прав доступа
if (!$canArchiveOrder) {
    http_response_code(403);
    die('Доступ запрещен. Отправлять заявки в архив могут только мастер и директор.');
}

$order = $_POST['order_number'] ?? '';

if (empty($order)) {
    die('Ошибка: не указан номер заявки.');
}

if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$mysqli = new mysqli(defined('DB_HOST') ? DB_HOST : '127.0.0.1', defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', 'plan_u5');

if ($mysqli->connect_error) {
    die('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
}

/** Выполняем запрос SQL для отправки заявки в архив */
$stmt = $mysqli->prepare("UPDATE orders SET hide = 1 WHERE order_number = ?");
if (!$stmt) {
    die('Ошибка подготовки запроса: ' . $mysqli->error);
}

$stmt->bind_param("s", $order);
if (!$stmt->execute()) {
    die('Ошибка выполнения запроса: ' . $stmt->error);
}

$stmt->close();
$mysqli->close();

echo "Заявка отправлена в архив <p>";
echo "<button class='a' onclick='window.close();' style='cursor: pointer;'>Закрыть страницу</button>";
