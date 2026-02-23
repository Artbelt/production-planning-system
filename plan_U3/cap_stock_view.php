<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Проверяем, есть ли у пользователя доступ к цеху U3
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U3');
}

require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('cap_db_init.php');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

// Обработка AJAX запроса на корректировку остатка
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust') {
    header('Content-Type: application/json; charset=utf-8');
    
    $cap_name = trim($_POST['cap_name'] ?? '');
    $new_quantity = intval($_POST['new_quantity'] ?? 0);
    $old_quantity = intval($_POST['old_quantity'] ?? 0);
    
    if (empty($cap_name)) {
        echo json_encode(['success' => false, 'error' => 'Не указано название крышки']);
        exit;
    }
    
    if ($new_quantity < 0) {
        echo json_encode(['success' => false, 'error' => 'Количество не может быть отрицательным']);
        exit;
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE cap_stock SET current_quantity = ?, last_updated = CURRENT_TIMESTAMP WHERE cap_name = ?");
        if (!$stmt->execute([$new_quantity, $cap_name])) {
            throw new Exception('Ошибка обновления остатка');
        }
        
        $difference = $new_quantity - $old_quantity;
        
        if ($difference != 0) {
            $today = date('Y-m-d');
            $comment = "Корректировка остатка: было {$old_quantity}, стало {$new_quantity}";
            
            $stmt = $pdo->prepare("INSERT INTO cap_movements (date, cap_name, operation_type, quantity, user_id, user_name, comment) VALUES (?, ?, 'ADJUSTMENT', ?, ?, ?, ?)");
            if (!$stmt->execute([$today, $cap_name, $difference, $user_id, $user_name, $comment])) {
                throw new Exception('Ошибка записи движения');
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Остаток успешно скорректирован',
            'new_quantity' => $new_quantity
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$result = $pdo->query("SELECT cap_name, current_quantity, last_updated FROM cap_stock ORDER BY cap_name ASC");

$total_caps = 0;
$stock_data = [];
if ($result) {
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $stock_data[] = $row;
        $total_caps += $row['current_quantity'];
    }
}

$capStockMap = [];
foreach ($stock_data as $row) {
    $capStockMap[$row['cap_name']] = (int)$row['current_quantity'];
}

$ordersForTooltip = [];
$filtersForTooltip = [];
$ordersSql = "
    SELECT order_number, `filter`, `count`
    FROM orders
    WHERE (hide IS NULL OR hide = 0)
      AND `filter` IS NOT NULL AND `filter` <> ''
      AND `count` IS NOT NULL AND `count` > 0
    ORDER BY order_number DESC
    LIMIT 30
";

if ($ordersResult = $pdo->query($ordersSql)) {
    while ($orderRow = $ordersResult->fetch(PDO::FETCH_ASSOC)) {
        $orderRow['filter'] = trim($orderRow['filter']);
        if ($orderRow['filter'] === '') {
            continue;
        }
        $ordersForTooltip[] = $orderRow;
        $filtersForTooltip[] = $orderRow['filter'];
    }
}

$filterCapsMap = [];
if (!empty($filtersForTooltip)) {
    $uniqueFilters = array_values(array_unique($filtersForTooltip));
    $placeholders = implode(',', array_fill(0, count($uniqueFilters), '?'));
    $types = str_repeat('s', count($uniqueFilters));
    $stmt = $pdo->prepare("SELECT filter, up_cap, down_cap FROM round_filter_structure WHERE filter IN ($placeholders)");
    if ($stmt && $stmt->execute($uniqueFilters)) {
            while ($capRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $filterCapsMap[$capRow['filter']] = [
                    'up_cap' => $capRow['up_cap'],
                    'down_cap' => $capRow['down_cap']
                ];
            }
    }
}

$capHintLines = [];
if (!empty($stock_data)) {
    $topCaps = $stock_data;
    usort($topCaps, function ($a, $b) {
        return (int)$b['current_quantity'] <=> (int)$a['current_quantity'];
    });
    $topCaps = array_slice($topCaps, 0, 5);
    
    $capSummaries = [];
    foreach ($topCaps as $capRow) {
        $capSummaries[] = $capRow['cap_name'] . ' — ' . number_format((int)$capRow['current_quantity'], 0, ',', ' ') . ' шт';
    }
    
    if (!empty($capSummaries)) {
        $capHintLines[] = 'На складе: ' . implode('; ', $capSummaries);
    }
}

$entriesAdded = 0;
$maxEntries = 5;
foreach ($ordersForTooltip as $orderData) {
    $filterName = $orderData['filter'];
    if (!$filterName || !isset($filterCapsMap[$filterName])) {
        continue;
    }
    
    $capsMeta = $filterCapsMap[$filterName];
    $capsInfoParts = [];
    $possibleQty = (int)$orderData['count'];
    $minAvailable = PHP_INT_MAX;
    
    foreach (['up_cap', 'down_cap'] as $capKey) {
        $capName = trim($capsMeta[$capKey] ?? '');
        if ($capName === '') {
            continue;
        }
        $available = $capStockMap[$capName] ?? 0;
        $capsInfoParts[] = $capName . ' — ' . number_format($available, 0, ',', ' ') . ' шт';
        $possibleQty = min($possibleQty, $available);
        $minAvailable = min($minAvailable, $available);
    }
    
    if (empty($capsInfoParts)) {
        continue;
    }
    
    if ($possibleQty > 0) {
        $capHintLines[] = sprintf(
            'Заявка %s: %s — можно собрать %s шт (крышки: %s)',
            $orderData['order_number'],
            $filterName,
            number_format($possibleQty, 0, ',', ' '),
            implode('; ', $capsInfoParts)
        );
    } else {
        $availableText = $minAvailable === PHP_INT_MAX ? '0' : number_format(max(0, $minAvailable), 0, ',', ' ');
        $capHintLines[] = sprintf(
            'Заявка %s: %s — пока не хватает крышек (нужно %s шт, доступно %s шт; %s)',
            $orderData['order_number'],
            $filterName,
            number_format((int)$orderData['count'], 0, ',', ' '),
            $availableText,
            implode('; ', $capsInfoParts)
        );
    }
    
    $entriesAdded++;
    if ($entriesAdded >= $maxEntries) {
        break;
    }
}

if (empty($capHintLines)) {
    $capHintLines[] = 'Нет активных заявок, для которых хватает обоих типов крышек.';
}

$capHintText = implode("\n", $capHintLines);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Остатки крышек на складе - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 10px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 6px;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .summary {
            background: #e8f0fe;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .stock-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 8px;
            font-size: 12px;
            color: #4169e1;
            cursor: help;
            font-weight: 600;
        }
        .stock-hint .icon-circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #4169e1;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            line-height: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }
        th, td {
            padding: 4px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #6495ed;
            color: white;
            font-weight: bold;
            font-size: 13px;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .quantity {
            font-weight: bold;
            color: #333;
        }
        .edit-btn {
            background: transparent;
            color: #6495ed;
            border: 1px solid #6495ed;
            padding: 0;
            border-radius: 3px;
            cursor: pointer;
            margin-left: 5px;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .edit-btn:hover {
            background: #6495ed;
            color: white;
        }
        .edit-btn svg {
            width: 12px;
            height: 12px;
        }
        .edit-input {
            width: 80px;
            padding: 4px;
            border: 1px solid #6495ed;
            border-radius: 3px;
            font-size: 13px;
            text-align: right;
        }
        .save-btn, .cancel-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            margin-left: 3px;
        }
        .cancel-btn {
            background: #6c757d;
        }
        .save-btn:hover {
            background: #218838;
        }
        .cancel-btn:hover {
            background: #5a6268;
        }
        .message {
            padding: 8px;
            margin-bottom: 10px;
            border-radius: 4px;
            font-size: 12px;
            display: none;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Остатки крышек на складе</h1>
        
        <div id="message" class="message"></div>
        
        <div class="summary">
            <strong>Всего позиций:</strong> <span id="total_positions"><?php echo count($stock_data); ?></span> | 
            <strong>Общее количество:</strong> <span id="total_quantity"><?php echo number_format($total_caps, 0, ',', ' '); ?></span> шт
            <span class="stock-hint" title="<?php echo htmlspecialchars($capHintText, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="icon-circle">i</span>
                Подсказка по сборке
            </span>
        </div>
        
        <?php if (empty($stock_data)): ?>
            <p>На складе нет крышек.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Название крышки</th>
                        <th style="text-align: right;">Количество</th>
                        <th>Последнее обновление</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_data as $row): 
                        $qty = intval($row['current_quantity']);
                    ?>
                        <tr data-cap-name="<?php echo htmlspecialchars($row['cap_name']); ?>">
                            <td><?php echo htmlspecialchars($row['cap_name']); ?></td>
                            <td style="text-align: right;" class="quantity-cell" data-quantity="<?php echo $qty; ?>">
                                <span class="quantity-display"><?php echo number_format($qty, 0, ',', ' '); ?> шт</span>
                                <button class="edit-btn" onclick="editQuantity(this)" title="Изменить количество">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                            </td>
                            <td>
                                <?php 
                                if ($row['last_updated']) {
                                    $date = new DateTime($row['last_updated']);
                                    echo $date->format('d.m.Y H:i');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <script>
    function editQuantity(btn) {
        const cell = btn.parentElement;
        const currentQty = parseInt(cell.getAttribute('data-quantity'));
        const displaySpan = cell.querySelector('.quantity-display');
        
        // Создаем поле ввода
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'edit-input';
        input.value = currentQty;
        input.min = 0;
        input.style.marginRight = '3px';
        
        // Создаем кнопки сохранения и отмены
        const saveBtn = document.createElement('button');
        saveBtn.className = 'save-btn';
        saveBtn.textContent = 'Сохранить';
        saveBtn.onclick = function() { saveQuantity(cell, input.value, currentQty); };
        
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'cancel-btn';
        cancelBtn.textContent = 'Отмена';
        cancelBtn.onclick = function() { cancelEdit(cell, currentQty); };
        
        // Заменяем содержимое ячейки
        cell.innerHTML = '';
        cell.appendChild(input);
        cell.appendChild(saveBtn);
        cell.appendChild(cancelBtn);
        
        // Фокусируемся на поле ввода
        input.focus();
        input.select();
    }
    
    function cancelEdit(cell, originalQty) {
        const displaySpan = document.createElement('span');
        displaySpan.className = 'quantity-display';
        displaySpan.textContent = numberFormat(originalQty) + ' шт';
        
        const editBtn = document.createElement('button');
        editBtn.className = 'edit-btn';
        editBtn.title = 'Изменить количество';
        editBtn.onclick = function() { editQuantity(editBtn); };
        editBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        
        cell.innerHTML = '';
        cell.appendChild(displaySpan);
        cell.appendChild(editBtn);
    }
    
    function saveQuantity(cell, newQty, oldQty) {
        const newQuantity = parseInt(newQty);
        if (isNaN(newQuantity) || newQuantity < 0) {
            showMessage('Некорректное количество', 'error');
            cancelEdit(cell, oldQty);
            return;
        }
        
        const row = cell.closest('tr');
        const capName = row.getAttribute('data-cap-name');
        
        // Отправляем AJAX запрос
        const formData = new FormData();
        formData.append('action', 'adjust');
        formData.append('cap_name', capName);
        formData.append('new_quantity', newQuantity);
        formData.append('old_quantity', oldQty);
        
        fetch('cap_stock_view.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Обновляем отображение
                cell.setAttribute('data-quantity', newQuantity);
                
                const displaySpan = document.createElement('span');
                displaySpan.className = 'quantity-display';
                displaySpan.textContent = numberFormat(newQuantity) + ' шт';
                
                const editBtn = document.createElement('button');
                editBtn.className = 'edit-btn';
                editBtn.title = 'Изменить количество';
                editBtn.onclick = function() { editQuantity(editBtn); };
                editBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
                
                cell.innerHTML = '';
                cell.appendChild(displaySpan);
                cell.appendChild(editBtn);
                
                // Обновляем класс строки если нужно
                updateRowClass(row, newQuantity);
                
                // Пересчитываем общее количество
                recalculateTotal();
                
                showMessage('Остаток успешно скорректирован', 'success');
                
                // Перезагружаем страницу через 1 секунду для обновления даты
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showMessage('Ошибка: ' + data.error, 'error');
                cancelEdit(cell, oldQty);
            }
        })
        .catch(error => {
            showMessage('Ошибка при сохранении: ' + error.message, 'error');
            cancelEdit(cell, oldQty);
        });
    }
    
    function updateRowClass(row, qty) {
        // Цветовое выделение строк отключено
    }
    
    function recalculateTotal() {
        let total = 0;
        document.querySelectorAll('.quantity-cell').forEach(cell => {
            total += parseInt(cell.getAttribute('data-quantity') || 0);
        });
        document.getElementById('total_quantity').textContent = numberFormat(total);
    }
    
    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
    
    function showMessage(text, type) {
        const messageDiv = document.getElementById('message');
        messageDiv.textContent = text;
        messageDiv.className = 'message ' + type;
        messageDiv.style.display = 'block';
        
        setTimeout(() => {
            messageDiv.style.display = 'none';
        }, 3000);
    }
    </script>
</body>
</html>

