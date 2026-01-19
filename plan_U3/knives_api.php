<?php
/**
 * API для работы с ножами (AJAX)
 */

// Отключаем вывод ошибок на экран, чтобы не портить JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Включаем буферизацию вывода для перехвата возможных ошибок
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверяем доступ к цеху U3
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Нет доступа к цеху U3'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once('tools/tools.php');
require_once('settings.php');
require_once('knives_db_init.php');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

// Подключаемся к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset("utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'set_status':
            // Установка статуса на дату
            $knife_id = intval($_POST['knife_id'] ?? 0);
            $date = trim($_POST['date'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $comment = trim($_POST['comment'] ?? '');
            
            if (!$knife_id || !$date || !$status) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            if (!in_array($status, ['in_stock', 'in_sharpening', 'in_work'])) {
                throw new Exception('Некорректный статус');
            }
            
            // Проверяем существование ножа
            $stmt = $mysqli->prepare("SELECT id FROM knives WHERE id = ?");
            $stmt->bind_param("i", $knife_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                throw new Exception('Нож не найден');
            }
            $stmt->close();
            
            // Вставляем или обновляем запись
            $stmt = $mysqli->prepare("
                INSERT INTO knives_calendar (knife_id, date, status, user_id, user_name, comment)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    user_id = VALUES(user_id),
                    user_name = VALUES(user_name),
                    comment = VALUES(comment),
                    created_at = CURRENT_TIMESTAMP
            ");
            // Типы: i (knife_id), s (date), s (status), i (user_id), s (user_name), s (comment) = 6 параметров
            $stmt->bind_param("ississ", $knife_id, $date, $status, $user_id, $user_name, $comment);
            
            if (!$stmt->execute()) {
                throw new Exception('Ошибка сохранения: ' . $stmt->error);
            }
            $stmt->close();
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Статус успешно установлен'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'add_knife':
            // Добавление нового комплекта ножей
            $knife_name = trim($_POST['knife_name'] ?? '');
            $knife_type = trim($_POST['knife_type'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (!$knife_name || !$knife_type) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            if (!in_array($knife_type, ['bobinorezka', 'prosechnik'])) {
                throw new Exception('Некорректный тип ножа');
            }
            
            $stmt = $mysqli->prepare("INSERT INTO knives (knife_name, knife_type, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $knife_name, $knife_type, $description);
            
            if (!$stmt->execute()) {
                if ($stmt->errno === 1062) {
                    throw new Exception('Комплект ножей с таким названием уже существует');
                }
                throw new Exception('Ошибка добавления: ' . $stmt->error);
            }
            
            $knife_id = $mysqli->insert_id;
            $stmt->close();
            
            ob_clean();
            echo json_encode(['success' => true, 'knife_id' => $knife_id, 'message' => 'Комплект ножей успешно добавлен'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_history':
            // Получение истории изменений для ножа
            $knife_id = intval($_POST['knife_id'] ?? $_GET['knife_id'] ?? 0);
            
            if (!$knife_id) {
                throw new Exception('Не указан ID ножа');
            }
            
            // Получаем информацию о комплекте ножей (включая описание)
            $stmt = $mysqli->prepare("SELECT knife_name, description FROM knives WHERE id = ?");
            $stmt->bind_param("i", $knife_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $knife_info = $result->fetch_assoc();
            $stmt->close();
            
            // Получаем историю изменений статусов
            $stmt = $mysqli->prepare("
                SELECT date, status, user_name, comment, created_at
                FROM knives_calendar
                WHERE knife_id = ?
                ORDER BY date DESC, created_at DESC
            ");
            $stmt->bind_param("i", $knife_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
            $stmt->close();
            
            ob_clean();
            echo json_encode([
                'success' => true, 
                'history' => $history,
                'knife_name' => $knife_info['knife_name'] ?? '',
                'description' => $knife_info['description'] ?? ''
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_calendar_data':
            // Получение данных календаря для периода
            $knife_type = trim($_POST['knife_type'] ?? $_GET['knife_type'] ?? '');
            $start_date = trim($_POST['start_date'] ?? $_GET['start_date'] ?? '');
            $end_date = trim($_POST['end_date'] ?? $_GET['end_date'] ?? '');
            
            if (!$knife_type || !$start_date || !$end_date) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            if (!in_array($knife_type, ['bobinorezka', 'prosechnik'])) {
                throw new Exception('Некорректный тип ножа');
            }
            
            // Получаем все ножи данного типа
            $stmt = $mysqli->prepare("SELECT id, knife_name FROM knives WHERE knife_type = ? ORDER BY knife_name");
            $stmt->bind_param("s", $knife_type);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $knives = [];
            while ($row = $result->fetch_assoc()) {
                $knives[$row['id']] = $row;
            }
            $stmt->close();
            
            // Получаем все записи календаря для этих ножей в указанном периоде
            if (!empty($knives)) {
                $knife_ids = array_keys($knives);
                $placeholders = implode(',', array_fill(0, count($knife_ids), '?'));
                $types = str_repeat('i', count($knife_ids));
                
                $stmt = $mysqli->prepare("
                    SELECT knife_id, date, status
                    FROM knives_calendar
                    WHERE knife_id IN ($placeholders) AND date <= ?
                    ORDER BY knife_id, date DESC
                ");
                
                $params = array_merge($knife_ids, [$end_date]);
                $types .= 's';
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $calendar_data = [];
                while ($row = $result->fetch_assoc()) {
                    $knife_id = $row['knife_id'];
                    $date = $row['date'];
                    
                    // Берем только последнюю запись для каждой комбинации нож+дата
                    if (!isset($calendar_data[$knife_id][$date])) {
                        if (!isset($calendar_data[$knife_id])) {
                            $calendar_data[$knife_id] = [];
                        }
                        $calendar_data[$knife_id][$date] = $row['status'];
                    }
                }
                $stmt->close();
            } else {
                $calendar_data = [];
            }
            
            // Формируем результат
            $result_data = [];
            foreach ($knives as $knife_id => $knife) {
                $result_data[] = [
                    'id' => $knife_id,
                    'name' => $knife['knife_name'],
                    'statuses' => $calendar_data[$knife_id] ?? []
                ];
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'knives' => $result_data], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete_knife':
            // Удаление комплекта ножей
            $knife_id = intval($_POST['knife_id'] ?? 0);
            
            if (!$knife_id) {
                throw new Exception('Не указан ID ножа');
            }
            
            $stmt = $mysqli->prepare("DELETE FROM knives WHERE id = ?");
            $stmt->bind_param("i", $knife_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Ошибка удаления: ' . $stmt->error);
            }
            $stmt->close();
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Комплект ножей успешно удален'], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Exception $e) {
    // Очищаем буфер на случай, если там что-то есть
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    // Обработка фатальных ошибок PHP 7+
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Ошибка выполнения: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// Закрываем буфер и отправляем вывод
if (ob_get_level() > 0) {
    ob_end_flush();
}

if (isset($mysqli) && $mysqli instanceof mysqli) {
    $mysqli->close();
}
