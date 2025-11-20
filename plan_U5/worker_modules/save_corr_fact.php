<?php
header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!isset($_POST['id'])) {
        echo json_encode(['success' => false, 'message' => 'Нет ID']); exit;
    }
    $id = (int)$_POST['id'];

    if (isset($_POST['fact'])) {
        $fact = max(0, (int)$_POST['fact']);
        
        // Получаем текущие значения для определения дельты
        $stmt = $pdo->prepare("SELECT fact_count, history FROM corrugation_plan WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Запись не найдена']); exit;
        }
        
        $oldFact = (int)$row['fact_count'];
        $delta = $fact - $oldFact;
        
        // Обновляем fact_count и добавляем запись в историю, если есть изменение
        if ($delta != 0) {
            // Получаем текущую дату
            $currentDate = date('Y-m-d');
            
            // Парсим существующую историю или создаем новый массив
            $history = [];
            if (!empty($row['history'])) {
                $history = json_decode($row['history'], true);
                if (!is_array($history)) {
                    $history = [];
                }
            }
            
            // Проверяем, есть ли уже запись за сегодня
            $todayIndex = -1;
            foreach ($history as $index => $entry) {
                if ($entry['date'] === $currentDate) {
                    $todayIndex = $index;
                    break;
                }
            }
            
            // Если есть запись за сегодня - обновляем, иначе добавляем новую
            if ($todayIndex >= 0) {
                $history[$todayIndex]['quantity'] += $delta;
                // Удаляем запись, если количество стало 0 или отрицательным
                if ($history[$todayIndex]['quantity'] <= 0) {
                    unset($history[$todayIndex]);
                    $history = array_values($history); // переиндексируем массив
                }
            } else if ($delta > 0) {
                // Добавляем новую запись только если дельта положительная
                $history[] = [
                    'date' => $currentDate,
                    'quantity' => $delta,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
            
            // Обновляем базу данных
            $historyJson = json_encode($history, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("UPDATE corrugation_plan SET fact_count = ?, history = ? WHERE id = ?");
            $stmt->execute([$fact, $historyJson, $id]);
        } else {
            // Если дельта 0, просто обновляем fact_count (на случай ручной корректировки)
            $stmt = $pdo->prepare("UPDATE corrugation_plan SET fact_count = ? WHERE id = ?");
            $stmt->execute([$fact, $id]);
        }
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
