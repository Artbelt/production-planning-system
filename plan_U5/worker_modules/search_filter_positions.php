<?php
header('Content-Type: application/json; charset=utf-8');

$dsn = "mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4";
$user = "root";
$pass = "";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $filterName = $_POST['filter_name'] ?? '';
    
    if (empty($filterName) || strlen($filterName) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'Введите минимум 2 символа для поиска'
        ]);
        exit;
    }

    // Поиск позиций по названию фильтра
    // План из corrugation_plan, факт из manufactured_corrugated_packages
    $stmt = $pdo->prepare("
        SELECT 
            cp.order_number,
            cp.filter_label,
            cp.plan_date,
            SUM(cp.count) as plan_sum,
            COALESCE(SUM(mcp.count), 0) as fact_sum
        FROM corrugation_plan cp
        LEFT JOIN manufactured_corrugated_packages mcp 
            ON mcp.order_number = cp.order_number 
            AND mcp.filter_label = cp.filter_label 
            AND mcp.date_of_production = cp.plan_date
        WHERE cp.filter_label LIKE :filter_name
          AND (cp.order_number IN (
              SELECT order_number 
              FROM orders 
              WHERE hide IS NULL OR hide != 1
          ) OR cp.order_number IS NOT NULL)
        GROUP BY cp.order_number, cp.filter_label, cp.plan_date
        ORDER BY cp.plan_date DESC, cp.order_number, cp.filter_label
        LIMIT 50
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


