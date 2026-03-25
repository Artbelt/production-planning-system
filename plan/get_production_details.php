<?php
/**
 * AJAX endpoint для получения детализации изготовленной продукции за дату.
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

    $sql = "SELECT
                name_of_order,
                name_of_filter,
                SUM(COALESCE(count_of_filters, 0)) AS total_count
            FROM manufactured_production
            WHERE date_of_production = ?
            GROUP BY name_of_order, name_of_filter
            ORDER BY name_of_order, name_of_filter";

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
        $count = (int)($row['total_count'] ?? 0);
        $total_count += $count;

        $items[] = [
            'order_number' => htmlspecialchars($row['name_of_order'] ?? '', ENT_QUOTES, 'UTF-8'),
            'filter_name' => htmlspecialchars($row['name_of_filter'] ?? '', ENT_QUOTES, 'UTF-8'),
            'count' => $count
        ];
    }

    $stmt->close();
    $mysqli->close();

    echo json_encode([
        'date' => htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
        'total_count' => $total_count,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}

