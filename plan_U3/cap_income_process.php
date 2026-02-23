<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

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

require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('cap_db_init.php');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

// Получаем данные из формы
$date = $_POST['date'] ?? '';
$cap_name = trim($_POST['cap_name'] ?? '');
$quantity = intval($_POST['quantity'] ?? 0);
$order_number = trim($_POST['order_number'] ?? '');
$comment = ''; // Комментарий больше не используется

// Валидация
$errors = [];
if (empty($date)) {
    $errors[] = 'Не указана дата поступления';
}
if (empty($cap_name)) {
    $errors[] = 'Не указано название крышки';
}
if ($quantity <= 0) {
    $errors[] = 'Количество должно быть больше нуля';
}
if (empty($order_number)) {
    $errors[] = 'Необходимо выбрать заявку';
}

if (!empty($errors)) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body>';
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 5px; margin: 20px;">';
    echo '<h2>Ошибка при приеме крышек:</h2><ul>';
    foreach ($errors as $error) {
        echo '<li>' . htmlspecialchars($error) . '</li>';
    }
    echo '</ul><a href="cap_income.php">← Вернуться</a></div></body></html>';
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO cap_movements (date, cap_name, operation_type, quantity, order_number, user_id, user_name, comment) VALUES (?, ?, 'INCOME', ?, ?, ?, ?, ?)");
    if (!$stmt->execute([$date, $cap_name, $quantity, $order_number, $user_id, $user_name, $comment])) {
        throw new Exception('Ошибка записи движения');
    }
    
    $stmt = $pdo->prepare("INSERT INTO cap_stock (cap_name, current_quantity) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_quantity = current_quantity + ?, last_updated = CURRENT_TIMESTAMP");
    if (!$stmt->execute([$cap_name, $quantity, $quantity])) {
        throw new Exception('Ошибка обновления остатков');
    }
    
    $pdo->commit();
    
    // Перенаправляем с сообщением об успехе
    header('Location: cap_income.php?success=1&cap=' . urlencode($cap_name) . '&qty=' . $quantity);
    exit;
    
} catch (Exception $e) {
    $pdo->rollBack();
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ошибка</title></head><body>';
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border-radius: 5px; margin: 20px;">';
    echo '<h2>Ошибка при приеме крышек:</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<a href="cap_income.php">← Вернуться</a></div></body></html>';
    exit;
}

