<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    require_once __DIR__ . '/laser_request_lib.php';
    require_once __DIR__ . '/worker_auth_lib.php';

    $operatorUser = worker_auth_require_user();
    $pdo = getPdo('plan');

    // Получаем данные из POST
    $date_of_production = $_POST['date_of_production'] ?? '';
    $order_number = $_POST['order_number'] ?? '';
    $filter_label = $_POST['filter_label'] ?? '';
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 0;
    $operatorName = trim((string)($operatorUser['full_name'] ?? ''));

    // Валидация
    if (empty($date_of_production) || empty($filter_label) || $count <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Не все поля заполнены корректно'
        ]);
        exit;
    }

    // Проверяем, что фильтр есть в заявке (та же логика, что в get_orders_for_filter.php)
    if (!empty($order_number)) {
        $found = false;
        $filter_label = is_string($filter_label) ? trim($filter_label) : '';
        $order_number = is_string($order_number) ? trim($order_number) : $order_number;

        // [48] <-> [h48]; базовое имя до пробела (AF1973s из "AF1973s [48] ...")
        $filter_alt = preg_replace('/\[(\d+)\]/', '[h$1]', $filter_label);
        $filter_alt2 = preg_replace('/\[h(\d+)\]/', '[$1]', $filter_label);
        $base = preg_split('/\s+/', $filter_label)[0] ?? $filter_label;
        $base = is_string($base) ? trim($base) : $filter_label;

        $checkStmt = $pdo->prepare("
            SELECT 1
            FROM orders
            WHERE order_number = ?
              AND (
                TRIM(`filter`) = ?
                OR TRIM(`filter`) = ?
                OR TRIM(`filter`) = ?
                OR TRIM(`filter`) LIKE CONCAT(?, '%')
                OR TRIM(`filter`) LIKE CONCAT(?, '%')
                OR TRIM(`filter`) LIKE CONCAT(?, '%')
                OR TRIM(`filter`) LIKE CONCAT(?, '%')
              )
              AND COALESCE(hide, 0) != 1
            LIMIT 1
        ");
        $checkStmt->execute([
            $order_number,
            $filter_label, $filter_alt, $filter_alt2,
            $filter_label, $filter_alt, $filter_alt2, $base,
        ]);
        if ($checkStmt->fetchColumn()) {
            $found = true;
        }

        if (!$found) {
            $checkStmt = $pdo->prepare("
                SELECT 1
                FROM corrugation_plan
                WHERE order_number = ?
                  AND (
                    filter_label = ?
                    OR filter_label = ?
                    OR filter_label = ?
                    OR filter_label LIKE CONCAT(?, '%')
                    OR filter_label LIKE CONCAT(?, '%')
                    OR filter_label LIKE CONCAT(?, '%')
                    OR filter_label LIKE CONCAT(?, '%')
                  )
                LIMIT 1
            ");
            $checkStmt->execute([
                $order_number,
                $filter_label, $filter_alt, $filter_alt2,
                $filter_label, $filter_alt, $filter_alt2, $base,
            ]);
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
        (date_of_production, order_number, filter_label, count, team) 
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $date_of_production,
        $order_number,
        $filter_label,
        $count,
        $operatorName !== '' ? $operatorName : null,
    ]);

    $laserRequestId = laser_request_create_for_prefilter($pdo, $filter_label, $count);

    $response = [
        'success' => true,
        'message' => 'Данные сохранены успешно',
    ];
    if ($laserRequestId) {
        $response['laser_request_id'] = $laserRequestId;
        $response['message'] = 'Данные сохранены. Заявка на лазер для предфильтра создана.';
    }

    echo json_encode($response);

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
