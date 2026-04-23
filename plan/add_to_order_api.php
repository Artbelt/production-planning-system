<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan');
$targetWorkshop = 'U2';
$action = $_GET['action'] ?? '';

if ($action === 'get_orders') {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT order_number
            FROM orders
            WHERE workshop = ? AND COALESCE(hide, 0) != 1
            ORDER BY order_number
        ");
        $stmt->execute([$targetWorkshop]);
        $orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode(['ok' => true, 'orders' => $orders]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_filters') {
    try {
        $filters = [];

        // Для У2 основной справочник фильтров - panel_filter_structure
        try {
            $filters = $pdo->query("
                SELECT DISTINCT TRIM(`filter`) AS f
                FROM panel_filter_structure
                WHERE TRIM(`filter`) <> ''
                ORDER BY f
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Fallback для сред, где таблица называется иначе
            $filters = $pdo->query("
                SELECT DISTINCT TRIM(`filter`) AS f
                FROM salon_filter_structure
                WHERE TRIM(`filter`) <> ''
                ORDER BY f
            ")->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!$filters) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT TRIM(`filter`) AS f
                FROM orders
                WHERE workshop = ? AND TRIM(`filter`) <> ''
                ORDER BY f
            ");
            $stmt->execute([$targetWorkshop]);
            $filters = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        echo json_encode(['ok' => true, 'filters' => $filters]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое тело запроса']);
            exit;
        }

        $orderNumber = trim((string)($payload['order_number'] ?? ''));
        $filter = trim((string)($payload['filter'] ?? ''));
        $count = (int)($payload['count'] ?? 0);
        $marking = trim((string)($payload['marking'] ?? '')) ?: 'стандарт';
        $personalPackaging = trim((string)($payload['personal_packaging'] ?? '')) ?: 'стандарт';
        $personalLabel = trim((string)($payload['personal_label'] ?? '')) ?: 'стандарт';
        $groupPackaging = trim((string)($payload['group_packaging'] ?? '')) ?: 'стандарт';
        $packagingRate = (int)($payload['packaging_rate'] ?? 10);
        $groupLabel = trim((string)($payload['group_label'] ?? '')) ?: 'стандарт';
        $remark = trim((string)($payload['remark'] ?? '')) ?: 'дополнение';

        if ($orderNumber === '') {
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

        if ($packagingRate <= 0) {
            $packagingRate = 10;
        }

        $orderStmt = $pdo->prepare("
            SELECT workshop
            FROM orders
            WHERE order_number = ?
            LIMIT 1
        ");
        $orderStmt->execute([$orderNumber]);
        $workshop = $orderStmt->fetchColumn();

        if (!$workshop) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Заявка не найдена']);
            exit;
        }

        if ($workshop !== $targetWorkshop) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Добавление доступно только для заявок U2']);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, workshop, `filter`, `count`, marking,
                personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark,
                hide, cut_ready, cut_confirmed, plan_ready, corr_ready, build_ready
            ) VALUES (
                :order_number, :workshop, :filter, :count, :marking,
                :personal_packaging, :personal_label, :group_packaging, :packaging_rate, :group_label, :remark,
                0, 0, 0, 0, 0, 0
            )
        ");

        $insertStmt->execute([
            ':order_number' => $orderNumber,
            ':workshop' => $workshop,
            ':filter' => $filter,
            ':count' => $count,
            ':marking' => $marking,
            ':personal_packaging' => $personalPackaging,
            ':personal_label' => $personalLabel,
            ':group_packaging' => $groupPackaging,
            ':packaging_rate' => $packagingRate,
            ':group_label' => $groupLabel,
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
