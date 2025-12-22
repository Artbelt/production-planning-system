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
        $total_count += $count;
        
        $items[] = [
            'order_number' => htmlspecialchars($row['order_number'] ?? '', ENT_QUOTES, 'UTF-8'),
            'filter_label' => htmlspecialchars($row['filter_label'] ?? '', ENT_QUOTES, 'UTF-8'),
            'count' => $count
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


