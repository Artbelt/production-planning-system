<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../auth/includes/db.php';

try {
    $pdo = getPdo('plan');

    $filterName = $_POST['filter_name'] ?? '';
    
    if (empty($filterName) || strlen($filterName) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'Введите минимум 2 символа для поиска'
        ]);
        exit;
    }

    // Поиск позиций по названию фильтра; факт гофропакетов из manufactured_corrugated_packages
    $stmt = $pdo->prepare("
        SELECT 
            cp.id,
            cp.order_number,
            cp.filter_label,
            cp.plan_date,
            cp.count AS plan_sum,
            COALESCE(m.fact_sum, 0) AS fact_sum
        FROM corrugation_plan cp
        LEFT JOIN (
            SELECT order_number, filter_label, SUM(count) AS fact_sum
            FROM manufactured_corrugated_packages
            GROUP BY order_number, filter_label
        ) m ON m.order_number = cp.order_number AND m.filter_label = cp.filter_label
        WHERE cp.filter_label LIKE :filter_name
          AND cp.order_number IN (
              SELECT order_number 
              FROM orders 
              WHERE hide IS NULL OR hide != 1
          )
        ORDER BY cp.plan_date DESC, cp.order_number, cp.filter_label, cp.id
        LIMIT 100
    ");
    
    $stmt->execute(['filter_name' => '%' . $filterName . '%']);
    $results = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных: ' . $e->getMessage()
    ]);
}
?>
