<?php
header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!isset($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'Нет ID']); 
        exit;
    }
    
    $id = (int)$_GET['id'];

    // Получаем полную информацию о позиции включая историю
    $stmt = $pdo->prepare("
        SELECT 
            id,
            order_number,
            plan_date,
            filter_label,
            count as plan_count,
            fact_count,
            history
        FROM corrugation_plan 
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Запись не найдена']); 
        exit;
    }
    
    // Парсим историю
    $history = [];
    if (!empty($row['history'])) {
        $history = json_decode($row['history'], true);
        if (!is_array($history)) {
            $history = [];
        }
    }
    
    // Подсчитываем статистику
    $totalFromHistory = 0;
    $datesCount = count($history);
    
    foreach ($history as $entry) {
        $totalFromHistory += (int)$entry['quantity'];
    }
    
    // Формируем ответ
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int)$row['id'],
            'order_number' => $row['order_number'],
            'plan_date' => $row['plan_date'],
            'filter_label' => $row['filter_label'],
            'plan_count' => (int)$row['plan_count'],
            'fact_count' => (int)$row['fact_count'],
            'history' => $history,
            'stats' => [
                'total_from_history' => $totalFromHistory,
                'production_days' => $datesCount,
                'is_match' => ($totalFromHistory == (int)$row['fact_count'])
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
















