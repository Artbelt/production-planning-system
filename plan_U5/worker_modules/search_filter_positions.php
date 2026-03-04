<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../../auth/includes/db.php';

try {
    $pdo = getPdo('plan_u5');

    $filterName = trim((string)($_POST['filter_name'] ?? ''));
    
    if ($filterName === '' || strlen($filterName) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'Введите минимум 2 символа для поиска'
        ]);
        exit;
    }

    $likePattern = '%' . $filterName . '%';

    // В plan_U5 в corrugation_plan может быть колонка filter или filter_label
    $hasFilterLabel = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'corrugation_plan' AND COLUMN_NAME = 'filter_label'")->fetchColumn();
    $filterCol = $hasFilterLabel ? 'filter_label' : 'filter';

    // Поиск позиций по названию фильтра: план из corrugation_plan, факт из manufactured_corrugated_packages
    $sql = "
        SELECT 
            cp.order_number,
            cp.{$filterCol} AS filter_label,
            cp.plan_date,
            SUM(cp.count) AS plan_sum,
            COALESCE(SUM(mcp.count), 0) AS fact_sum
        FROM corrugation_plan cp
        LEFT JOIN manufactured_corrugated_packages mcp 
            ON mcp.order_number = cp.order_number 
            AND mcp.filter_label = cp.{$filterCol} 
            AND mcp.date_of_production = cp.plan_date
        WHERE cp.{$filterCol} LIKE :filter_name
        GROUP BY cp.order_number, cp.{$filterCol}, cp.plan_date
        ORDER BY cp.plan_date DESC, cp.order_number, cp.{$filterCol}
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['filter_name' => $likePattern]);
    $results = $stmt->fetchAll();

    // Если в corrugation_plan ничего не нашли — ищем только по факту (manufactured_corrugated_packages)
    if (count($results) === 0) {
        $stmtFact = $pdo->prepare("
            SELECT 
                order_number,
                filter_label,
                date_of_production AS plan_date,
                0 AS plan_sum,
                SUM(count) AS fact_sum
            FROM manufactured_corrugated_packages
            WHERE filter_label LIKE :filter_name
            GROUP BY order_number, filter_label, date_of_production
            ORDER BY date_of_production DESC, order_number, filter_label
            LIMIT 50
        ");
        $stmtFact->execute(['filter_name' => $likePattern]);
        $results = $stmtFact->fetchAll();
    }

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


