<?php
/**
 * API: рейтинг фильтров — все данные из БД (количество штук, заказов и номера заявок по каждому фильтру).
 */
require_once __DIR__ . '/settings.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new PDO(
        "mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4",
        $mysql_user,
        $mysql_user_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $sql = "
        SELECT
            TRIM(o.`filter`) AS filter_name,
            COALESCE(SUM(o.`count`), 0) AS total_pieces,
            COUNT(DISTINCT o.order_number) AS orders_count,
            GROUP_CONCAT(DISTINCT o.order_number ORDER BY o.order_number SEPARATOR ', ') AS order_numbers
        FROM orders o
        WHERE TRIM(IFNULL(o.`filter`, '')) <> ''
        GROUP BY TRIM(o.`filter`)
        ORDER BY total_pieces DESC, filter_name
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'filter' => $r['filter_name'],
            'total_pieces' => (int) $r['total_pieces'],
            'orders_count' => (int) $r['orders_count'],
            'order_numbers' => $r['order_numbers'] !== null ? $r['order_numbers'] : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
