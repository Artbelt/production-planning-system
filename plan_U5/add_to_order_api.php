<?php
// API для добавления позиции к существующей заявке
header('Content-Type: application/json; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Получить список всех заявок
if (($_GET['action'] ?? '') === 'get_orders') {
    try {
        $orders = $pdo->query("
            SELECT DISTINCT order_number 
            FROM orders 
            WHERE COALESCE(hide, 0) != 1 
            ORDER BY order_number
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode(['ok' => true, 'orders' => $orders]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Получить список всех фильтров
if (($_GET['action'] ?? '') === 'get_filters') {
    try {
        $filters = $pdo->query("
            SELECT DISTINCT TRIM(`filter`) AS f 
            FROM salon_filter_structure 
            WHERE TRIM(`filter`) <> '' 
            ORDER BY f
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode(['ok' => true, 'filters' => $filters]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Добавить позицию к заявке
if (($_GET['action'] ?? '') === 'add_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое тело запроса']);
            exit;
        }

        $order_number = trim((string)($payload['order_number'] ?? ''));
        $filter = trim((string)($payload['filter'] ?? ''));
        $count = (int)($payload['count'] ?? 0);
        $marking = trim((string)($payload['marking'] ?? '')) ?: 'стандарт';
        $personal_packaging = trim((string)($payload['personal_packaging'] ?? '')) ?: 'стандарт';
        $personal_label = trim((string)($payload['personal_label'] ?? '')) ?: 'стандарт';
        $group_packaging = trim((string)($payload['group_packaging'] ?? '')) ?: 'стандарт';
        $packaging_rate = (int)($payload['packaging_rate'] ?? 10);
        $group_label = trim((string)($payload['group_label'] ?? '')) ?: 'стандарт';
        $remark = trim((string)($payload['remark'] ?? '')) ?: 'дополнение';

        // Валидация
        if ($order_number === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Выберите заявку']);
            exit;
        }

        if ($filter === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Выберите фильтр']);
            exit;
        }

        if ($count <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Количество должно быть больше 0']);
            exit;
        }

        // Проверяем, существует ли заявка
        $orderExists = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ?");
        $orderExists->execute([$order_number]);
        if ($orderExists->fetchColumn() == 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        // Получаем workshop из существующей заявки
        $workshopStmt = $pdo->prepare("SELECT workshop FROM orders WHERE order_number = ? LIMIT 1");
        $workshopStmt->execute([$order_number]);
        $workshop = $workshopStmt->fetchColumn();

        // Добавляем позицию
        $ins = $pdo->prepare("INSERT INTO orders (
            order_number, workshop, `filter`, `count`, marking,
            personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark,
            hide, cut_ready, cut_confirmed, plan_ready, corr_ready, build_ready
        ) VALUES (
            :order_number, :workshop, :filter, :count, :marking,
            :personal_packaging, :personal_label, :group_packaging, :packaging_rate, :group_label, :remark,
            0, 0, 0, 0, 0, 0
        )");

        $ins->execute([
            ':order_number' => $order_number,
            ':workshop' => $workshop,
            ':filter' => $filter,
            ':count' => $count,
            ':marking' => $marking,
            ':personal_packaging' => $personal_packaging,
            ':personal_label' => $personal_label,
            ':group_packaging' => $group_packaging,
            ':packaging_rate' => $packaging_rate,
            ':group_label' => $group_label,
            ':remark' => $remark,
        ]);

        echo json_encode(['ok' => true, 'message' => 'Позиция успешно добавлена']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Неверный запрос']);

