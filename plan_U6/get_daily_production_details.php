<?php
/**
 * Детализация выпущенной продукции за день (клик по количеству на главной).
 */

require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once __DIR__ . '/../auth/includes/db.php';

initAuthSystem();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Не указана дата']);
    exit;
}

try {
    $pdo = getPdo('plan_u6');
    $sql = "SELECT
                mp.name_of_filter,
                mp.name_of_order,
                SUM(mp.count_of_filters) AS count_of_filters
            FROM manufactured_production mp
            WHERE mp.date_of_production = ?
            GROUP BY mp.name_of_filter, mp.name_of_order
            ORDER BY mp.name_of_filter, mp.name_of_order";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);

    $items = [];
    $total_count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = (int)$row['count_of_filters'];
        $total_count += $count;
        $items[] = [
            'filter_name' => $row['name_of_filter'] ?? '',
            'order_number' => $row['name_of_order'] ?? '',
            'count' => $count,
        ];
    }

    echo json_encode([
        'date' => $date,
        'total_count' => $total_count,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}
