<?php
// delete_orders_api.php — API для удаления заявок
// Отключаем вывод ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Включаем буферизацию вывода
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Устанавливаем заголовки
header('Content-Type: application/json; charset=utf-8');

// Подключаем настройки напрямую
require_once('settings.php');

// Подключение к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

if ($mysqli->connect_errno) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка подключения к БД: ' . $mysqli->connect_error]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_orders') {
    // Получаем список всех заявок в хронологическом порядке
    $sql = "SELECT o.order_number, 
                   COUNT(*) as positions_count,
                   SUM(o.count) as total_count,
                   MAX(o.status) as status,
                   MAX(COALESCE(o.hide, 0)) as is_hidden,
                   MIN(COALESCE(bp.created_at, rp.created_at, bp.source_date, bp.plan_date, rp.work_date, NOW())) as created_date
            FROM orders o
            LEFT JOIN (
                SELECT order_number, 
                       MIN(created_at) as created_at,
                       MIN(COALESCE(source_date, plan_date)) as source_date,
                       MIN(plan_date) as plan_date
                FROM build_plan
                GROUP BY order_number
            ) bp ON o.order_number = bp.order_number
            LEFT JOIN (
                SELECT order_number,
                       MIN(created_at) as created_at,
                       MIN(work_date) as work_date
                FROM roll_plans
                GROUP BY order_number
            ) rp ON o.order_number = rp.order_number
            GROUP BY o.order_number
            ORDER BY created_date ASC, o.order_number ASC";
    
    $result = $mysqli->query($sql);
    
    if (!$result) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Ошибка выполнения запроса: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = [
            'order_number' => $row['order_number'],
            'positions_count' => (int)$row['positions_count'],
            'total_count' => (int)$row['total_count'],
            'status' => $row['status'] ?? 'normal',
            'is_hidden' => (int)$row['is_hidden'] > 0,
            'order_date' => $row['order_date']
        ];
    }
    
    ob_end_clean();
    echo json_encode(['ok' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
    
} elseif ($action === 'delete_orders') {
    // Удаляем выбранные заявки
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ошибка парсинга JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
        $mysqli->close();
        exit;
    }
    
    if (!isset($data['orders']) || !is_array($data['orders']) || empty($data['orders'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Не указаны заявки для удаления'], JSON_UNESCAPED_UNICODE);
        $mysqli->close();
        exit;
    }
    
    $orders = $data['orders'];
    $deleteType = $data['delete_type'] ?? 'hide';
    
    $mysqli->begin_transaction();
    
    try {
        $placeholders = str_repeat('?,', count($orders) - 1) . '?';
        $types = str_repeat('s', count($orders));
        
        if ($deleteType === 'full') {
            // Полное удаление - проверяем существование таблиц перед удалением
            $tables = ['build_plan', 'corrugation_plan', 'roll_plans', 'cut_plans'];
            
            foreach ($tables as $table) {
                // Проверяем существование таблицы
                $checkTable = $mysqli->query("SHOW TABLES LIKE '{$table}'");
                if ($checkTable && $checkTable->num_rows > 0) {
                    $sql = "DELETE FROM `{$table}` WHERE order_number IN ({$placeholders})";
                    $stmt = $mysqli->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Ошибка подготовки запроса для таблицы {$table}: " . $mysqli->error);
                    }
                    $stmt->bind_param($types, ...$orders);
                    if (!$stmt->execute()) {
                        throw new Exception("Ошибка выполнения запроса для таблицы {$table}: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
            
            // Удаляем из orders
            $sql = "DELETE FROM orders WHERE order_number IN ({$placeholders})";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса для orders: " . $mysqli->error);
            }
            $stmt->bind_param($types, ...$orders);
            if (!$stmt->execute()) {
                throw new Exception("Ошибка выполнения запроса для orders: " . $stmt->error);
            }
            $deletedCount = $stmt->affected_rows;
            $stmt->close();
        } else {
            // Скрытие заявок (hide = 1)
            $sql = "UPDATE orders SET hide = 1 WHERE order_number IN ({$placeholders})";
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса: " . $mysqli->error);
            }
            $stmt->bind_param($types, ...$orders);
            if (!$stmt->execute()) {
                throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
            }
            $deletedCount = $stmt->affected_rows;
            $stmt->close();
        }
        
        $mysqli->commit();
        ob_end_clean();
        echo json_encode([
            'ok' => true, 
            'deleted_count' => $deletedCount,
            'delete_type' => $deleteType
        ], JSON_UNESCAPED_UNICODE);
        $mysqli->close();
        exit;
        
    } catch (Throwable $e) {
        // Пытаемся откатить транзакцию
        if (isset($mysqli)) {
            @$mysqli->rollback();
        }
        ob_end_clean();
        http_response_code(500);
        $errorMsg = $e->getMessage();
        // Логируем полную ошибку для отладки
        error_log("Delete orders error: " . $errorMsg . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
        echo json_encode(['ok' => false, 'error' => 'Ошибка удаления: ' . $errorMsg], JSON_UNESCAPED_UNICODE);
        if (isset($mysqli)) {
            $mysqli->close();
        }
        exit;
    }
    
} else {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}
