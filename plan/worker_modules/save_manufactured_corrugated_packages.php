<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Получаем данные из POST
    $date_of_production = $_POST['date_of_production'] ?? '';
    $order_number = $_POST['order_number'] ?? '';
    $filter_label = $_POST['filter_label'] ?? '';
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 0;

    // Валидация
    if (empty($date_of_production) || empty($filter_label) || $count <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Не все поля заполнены корректно'
        ]);
        exit;
    }

    // Проверяем, что фильтр есть в заявке
    if (!empty($order_number)) {
        $found = false;

        $filter_alt = preg_replace('/\[(\d+)\]/', '[h$1]', $filter_label);
        $checkStmt = $pdo->prepare("
            SELECT 1 
            FROM orders 
            WHERE order_number = ? 
              AND (`filter` = ? OR `filter` = ?)
              AND COALESCE(hide, 0) != 1
            LIMIT 1
        ");
        $checkStmt->execute([$order_number, $filter_label, $filter_alt]);
        if ($checkStmt->fetchColumn()) {
            $found = true;
        }

        if (!$found) {
            // В плане может быть [48] или [h48] — проверяем оба варианта
            $checkStmt = $pdo->prepare("
                SELECT 1 
                FROM corrugation_plan 
                WHERE order_number = ? 
                  AND (filter_label = ? OR filter_label = ?)
                LIMIT 1
            ");
            $checkStmt->execute([$order_number, $filter_label, $filter_alt]);
            if ($checkStmt->fetchColumn()) {
                $found = true;
            }
        }

        if (!$found) {
            echo json_encode([
                'success' => false,
                'message' => 'Этот фильтр не найден в выбранной заявке'
            ]);
            exit;
        }
    }

    // Проверяем существование таблицы, если нет - создаем
    $pdo->exec("CREATE TABLE IF NOT EXISTS manufactured_corrugated_packages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        date_of_production DATE NOT NULL,
        order_number VARCHAR(50) NOT NULL DEFAULT '',
        filter_label TEXT NOT NULL,
        count INT(11) NOT NULL DEFAULT 0,
        bale_id INT(11) DEFAULT NULL,
        strip_no INT(11) DEFAULT NULL,
        team VARCHAR(50) DEFAULT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_date (date_of_production),
        INDEX idx_order (order_number),
        INDEX idx_date_order (date_of_production, order_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("
        INSERT INTO manufactured_corrugated_packages 
        (date_of_production, order_number, filter_label, count) 
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $date_of_production,
        $order_number,
        $filter_label,
        $count
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Данные сохранены успешно'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage()
    ]);
}
