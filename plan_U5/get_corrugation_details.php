<?php
/**
 * AJAX endpoint для получения детальной информации по сгофрированным гофропакетам
 * за конкретную дату
 */

require_once('tools/tools.php');
require_once('settings.php');

header('Content-Type: application/json; charset=utf-8');

$date = $_GET['date'] ?? '';

if (empty($date)) {
    echo json_encode(['error' => 'Не указана дата']);
    exit;
}

try {
    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
    
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    
    if ($mysqli->connect_errno) {
        echo json_encode(['error' => 'Ошибка подключения к БД']);
        exit;
    }
    
    // Получаем детальную информацию по каждому гофропакету за день
    // Группируем по заявке и фильтру, суммируя количество
    $sql = "SELECT 
                order_number,
                filter_label,
                SUM(COALESCE(count, 0)) AS total_count
            FROM manufactured_corrugated_packages
            WHERE date_of_production = ?
              AND COALESCE(count, 0) > 0
            GROUP BY order_number, filter_label
            ORDER BY order_number, filter_label";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Ошибка подготовки запроса: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $total_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $count = (int)$row['total_count'];
        $order_number = $row['order_number'] ?? '';
        $filter_label = $row['filter_label'] ?? '';
        $total_count += $count;
        
        // Плановая дата гофрирования (из corrugation_plan), формат дд.мм.гг
        $planned_dates_str = '';
        $stmt_plan = $mysqli->prepare("SELECT GROUP_CONCAT(DISTINCT plan_date ORDER BY plan_date) AS plan_dates FROM corrugation_plan WHERE order_number = ? AND filter_label = ?");
        if ($stmt_plan) {
            $stmt_plan->bind_param('ss', $order_number, $filter_label);
            $stmt_plan->execute();
            $result_plan = $stmt_plan->get_result();
            if ($result_plan && ($row_plan = $result_plan->fetch_assoc()) && !empty($row_plan['plan_dates'])) {
                $dates = array_map(function ($d) {
                    $t = strtotime(trim($d));
                    return $t ? date('d.m.y', $t) : $d;
                }, explode(',', $row_plan['plan_dates']));
                $planned_dates_str = implode(', ', $dates);
            }
            $stmt_plan->close();
        }
        
        $items[] = [
            'order_number' => htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8'),
            'filter_label' => htmlspecialchars($filter_label, ENT_QUOTES, 'UTF-8'),
            'count' => $count,
            'planned_dates' => htmlspecialchars($planned_dates_str, ENT_QUOTES, 'UTF-8')
        ];
    }
    
    $stmt->close();
    $mysqli->close();
    
    // Если нет данных
    if (empty($items)) {
        echo json_encode([
            'date' => $date,
            'total_count' => 0,
            'items' => []
        ]);
        exit;
    }
    
    echo json_encode([
        'date' => htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
        'total_count' => $total_count,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}




