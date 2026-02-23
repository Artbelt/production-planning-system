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
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('knives_db_init.php');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'set_status':
            // Установка статуса на дату
            $knife_id = intval($_POST['knife_id'] ?? 0);
            $date = trim($_POST['date'] ?? '');
            $status = trim($_POST['status'] ?? '');
            $comment = trim($_POST['comment'] ?? '');
            // #region agent log
            file_put_contents('c:\\xampp\\htdocs\\.cursor\\debug.log', json_encode(['hypothesisId'=>'E','location'=>'knives_api.php:set_status','message'=>'received status','data'=>['knife_id'=>$knife_id,'date'=>$date,'status'=>$status,'status_len'=>strlen($status)],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            // #endregion
            if (!$knife_id || !$date || !$status) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            if (!in_array($status, ['in_stock', 'in_sharpening', 'out_to_sharpening', 'in_work'])) {
                throw new Exception('Некорректный статус');
            }
            
            $chk = $pdo->prepare("SELECT id FROM knives WHERE id = ?");
            $chk->execute([$knife_id]);
            if ($chk->rowCount() === 0) {
                throw new Exception('Нож не найден');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO knives_calendar (knife_id, date, status, user_id, user_name, comment)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    user_id = VALUES(user_id),
                    user_name = VALUES(user_name),
                    comment = VALUES(comment),
                    created_at = CURRENT_TIMESTAMP
            ");
            if (!$stmt->execute([$knife_id, $date, $status, $user_id, $user_name, $comment])) {
                throw new Exception('Ошибка сохранения');
            }
            
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
            
            $stmt = $pdo->prepare("INSERT INTO knives (knife_name, knife_type, description) VALUES (?, ?, ?)");
            if (!$stmt->execute([$knife_name, $knife_type, $description])) {
                $err = $stmt->errorInfo();
                if (isset($err[1]) && $err[1] === 1062) {
                    throw new Exception('Комплект ножей с таким названием уже существует');
                }
                throw new Exception('Ошибка добавления');
            }
            $knife_id = (int)$pdo->lastInsertId();
            
            ob_clean();
            echo json_encode(['success' => true, 'knife_id' => $knife_id, 'message' => 'Комплект ножей успешно добавлен'], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_history':
            // Получение истории изменений для ножа
            $knife_id = intval($_POST['knife_id'] ?? $_GET['knife_id'] ?? 0);
            
            if (!$knife_id) {
                throw new Exception('Не указан ID ножа');
            }
            
            $stmt = $pdo->prepare("SELECT knife_name, description FROM knives WHERE id = ?");
            $stmt->execute([$knife_id]);
            $knife_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                SELECT date, status, user_name, comment, created_at
                FROM knives_calendar
                WHERE knife_id = ?
                ORDER BY date DESC, created_at DESC
            ");
            $stmt->execute([$knife_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
            
            $stmt = $pdo->prepare("SELECT id, knife_name FROM knives WHERE knife_type = ? ORDER BY knife_name");
            $stmt->execute([$knife_type]);
            $knives = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $knives[$row['id']] = $row;
            }
            
            if (!empty($knives)) {
                $knife_ids = array_keys($knives);
                $placeholders = implode(',', array_fill(0, count($knife_ids), '?'));
                
                $stmt = $pdo->prepare("
                    SELECT knife_id, date, status
                    FROM knives_calendar
                    WHERE knife_id IN ($placeholders) AND date <= ?
                    ORDER BY knife_id, date DESC
                ");
                $params = array_merge($knife_ids, [$end_date]);
                $stmt->execute($params);
                
                $calendar_data = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
            
            $stmt = $pdo->prepare("DELETE FROM knives WHERE id = ?");
            if (!$stmt->execute([$knife_id])) {
                throw new Exception('Ошибка удаления');
            }
            
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

