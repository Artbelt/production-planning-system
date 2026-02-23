<?php
// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Устанавливаем заголовок для JSON
header('Content-Type: application/json; charset=utf-8');

session_start();

try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

require_once __DIR__ . '/audit_logger.php';
$auditLogger = new AuditLogger($pdo);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'load_data':
        loadPartsData();
        break;
    case 'load_data_by_date':
        loadDataByDate();
        break;
    case 'load_parts':
        loadParts();
        break;
    case 'load_orders':
        loadOrders();
        break;
    case 'load_orders_for_dropdown':
        loadOrdersForDropdown();
        break;
    case 'update_quantity':
        updateQuantity();
        break;
    case 'move_to_order':
        moveToOrder();
        break;
    case 'add_position':
        addPosition();
        break;
    case 'remove_position':
        removePosition();
        break;
    default:
        echo json_encode(['error' => 'Неизвестное действие']);
        break;
}

function loadPartsData() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT * FROM manufactured_parts LIMIT 10");
        if (!$stmt) {
            throw new Exception('Ошибка запроса к таблице manufactured_parts');
        }
        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Создаем виртуальный ID
            $virtual_id = $row['name_of_order'] . '_' . $row['name_of_parts'] . '_' . $row['date_of_production'];
            
            $data[] = [
                'virtual_id' => $virtual_id,
                'part_name' => $row['name_of_parts'],
                'quantity' => $row['count_of_parts'],
                'production_date' => $row['date_of_production'],
                'order_number' => $row['name_of_order'],
            ];
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $data,
            'message' => 'Данные успешно загружены'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function loadDataByDate() {
    global $pdo;
    
    try {
        $selectedDate = $_POST['date'] ?? '';
        
        if (empty($selectedDate)) {
            throw new Exception('Дата не указана');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM manufactured_parts WHERE date_of_production = ? ORDER BY name_of_parts");
        $stmt->execute([$selectedDate]);
        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Создаем виртуальный ID
            $virtual_id = $row['name_of_order'] . '_' . $row['name_of_parts'] . '_' . $row['date_of_production'];
            
            $data[] = [
                'virtual_id' => $virtual_id,
                'part_name' => $row['name_of_parts'],
                'quantity' => $row['count_of_parts'],
                'production_date' => $row['date_of_production'],
                'order_number' => $row['name_of_order'],
            ];
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $data,
            'date' => $selectedDate,
            'message' => 'Данные за дату успешно загружены'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function loadParts() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT DISTINCT p_p_name FROM paper_package_round ORDER BY p_p_name");
        if (!$stmt) {
            throw new Exception('Ошибка загрузки гофропакетов');
        }
        $parts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parts[] = $row['p_p_name'];
        }
        
        echo json_encode([
            'success' => true,
            'parts' => $parts,
            'count' => count($parts)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function loadOrders() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT DISTINCT order_number FROM orders WHERE hide != 1 OR hide IS NULL ORDER BY order_number DESC");
        if (!$stmt) {
            throw new Exception('Ошибка загрузки заявок');
        }
        $orders = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orders[] = $row['order_number'];
        }
        
        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'count' => count($orders)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function loadOrdersForDropdown() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT DISTINCT order_number FROM orders WHERE hide != 1 OR hide IS NULL ORDER BY order_number DESC");
        if (!$stmt) {
            throw new Exception('Ошибка загрузки заявок');
        }
        $orders = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $orders[] = $row['order_number'];
        }
        
        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'count' => count($orders)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function updateQuantity() {
    global $pdo, $auditLogger;
    
    $virtual_id = $_POST['id'];
    $quantity = intval($_POST['quantity']);
    
    if (empty($virtual_id) || $quantity < 0) {
        echo json_encode(['error' => 'Некорректные данные']);
        return;
    }
    
    // Парсим virtual_id для получения компонентов
    $parts = explode('_', $virtual_id);
    if (count($parts) < 3) {
        echo json_encode(['error' => 'Некорректный ID записи']);
        return;
    }
    
    $order_name = $parts[0];
    $part_name = $parts[1];
    $production_date = $parts[2];
    
    try {
        $old_stmt = $pdo->prepare("SELECT * FROM manufactured_parts WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        $old_stmt->execute([$order_name, $part_name, $production_date]);
        $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("UPDATE manufactured_parts SET count_of_parts = ? WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        if ($stmt->execute([$quantity, $order_name, $part_name, $production_date])) {
            // Записываем в аудит
            $new_values = $old_values;
            $new_values['count_of_parts'] = $quantity;
            
            $auditLogger->logUpdate(
                'manufactured_parts',
                $virtual_id,
                $old_values,
                $new_values,
                ['count_of_parts'],
                "Обновление количества гофропакетов: {$old_values['count_of_parts']} → {$quantity}"
            );
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Ошибка обновления']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function moveToOrder() {
    global $pdo, $auditLogger;
    
    $virtual_id = $_POST['id'];
    $new_order_name = $_POST['new_order_id'];
    
    if (empty($virtual_id) || empty($new_order_name)) {
        echo json_encode(['error' => 'Некорректные данные']);
        return;
    }
    
    // Парсим virtual_id для получения компонентов
    $parts = explode('_', $virtual_id);
    if (count($parts) < 3) {
        echo json_encode(['error' => 'Некорректный ID записи']);
        return;
    }
    
    $old_order_name = $parts[0];
    $part_name = $parts[1];
    $production_date = $parts[2];
    
    try {
        $old_stmt = $pdo->prepare("SELECT * FROM manufactured_parts WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        $old_stmt->execute([$old_order_name, $part_name, $production_date]);
        $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("UPDATE manufactured_parts SET name_of_order = ? WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        if ($stmt->execute([$new_order_name, $old_order_name, $part_name, $production_date])) {
            // Записываем в аудит
            $new_values = $old_values;
            $new_values['name_of_order'] = $new_order_name;
            
            $auditLogger->logUpdate(
                'manufactured_parts',
                $virtual_id,
                $old_values,
                $new_values,
                ['name_of_order'],
                "Перенос в заявку: {$old_order_name} → {$new_order_name}"
            );
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Ошибка обновления']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function addPosition() {
    global $pdo, $auditLogger;
    
    $part_name = $_POST['part_name'] ?? '';
    $quantity = intval($_POST['quantity']);
    $order_name = $_POST['order_name'] ?? '';
    $production_date = $_POST['production_date'] ?? date('Y-m-d');
    
    if (empty($part_name) || $quantity <= 0 || empty($order_name)) {
        echo json_encode(['error' => 'Некорректные данные']);
        return;
    }
    
    $check_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM manufactured_parts WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
    $check_stmt->execute([$order_name, $part_name, $production_date]);
    $check_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (($check_row['cnt'] ?? 0) > 0) {
        echo json_encode(['error' => 'Запись с такими параметрами уже существует']);
        return;
    }
    
    $stmt = $pdo->prepare("INSERT INTO manufactured_parts (name_of_parts, count_of_parts, name_of_order, date_of_production) VALUES (?, ?, ?, ?)");
    
    if ($stmt->execute([$part_name, $quantity, $order_name, $production_date])) {
        // Записываем в аудит
        $virtual_id = $order_name . '_' . $part_name . '_' . $production_date;
        $new_values = [
            'name_of_parts' => $part_name,
            'count_of_parts' => $quantity,
            'name_of_order' => $order_name,
            'date_of_production' => $production_date
        ];
        
        $auditLogger->logInsert(
            'manufactured_parts',
            $virtual_id,
            $new_values,
            "Добавлена новая позиция: гофропакет {$part_name}, количество {$quantity}, заявка {$order_name}"
        );
        
        echo json_encode(['success' => true, 'new_id' => $pdo->lastInsertId()]);
    } else {
        echo json_encode(['error' => 'Ошибка добавления']);
    }
}

function removePosition() {
    global $pdo, $auditLogger;
    
    $virtual_id = $_POST['id'];
    
    if (empty($virtual_id)) {
        echo json_encode(['error' => 'Некорректные данные']);
        return;
    }
    
    // Парсим virtual_id для получения компонентов
    $parts = explode('_', $virtual_id);
    if (count($parts) < 3) {
        echo json_encode(['error' => 'Некорректный ID записи']);
        return;
    }
    
    $order_name = $parts[0];
    $part_name = $parts[1];
    $production_date = $parts[2];
    
    try {
        $old_stmt = $pdo->prepare("SELECT * FROM manufactured_parts WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        $old_stmt->execute([$order_name, $part_name, $production_date]);
        $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("DELETE FROM manufactured_parts WHERE name_of_order = ? AND name_of_parts = ? AND date_of_production = ?");
        if ($stmt->execute([$order_name, $part_name, $production_date])) {
            // Записываем в аудит
            $auditLogger->logDelete(
                'manufactured_parts',
                $virtual_id,
                $old_values,
                "Удалена позиция: гофропакет {$part_name}, количество {$old_values['count_of_parts']}, заявка {$order_name}"
            );
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Ошибка удаления']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>








