<?php
/**
 * Модуль оператора бумагорезки
 * Централизованное управление заданиями на порезку со всех участков
 */

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once '../auth/includes/config.php';
require_once '../auth/includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем, есть ли доступ к модулю оператора бумагорезки
$hasCutOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'cut_operator'])) {
        $hasCutOperatorAccess = true;
        break;
    }
}

if (!$hasCutOperatorAccess) {
    die("У вас нет доступа к модулю оператора бумагорезки");
}

// Настройки подключений к базам данных (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
$databases = [
    'U2' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan', 'table' => 'roll_plan'],
    'U3' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u3', 'table' => 'roll_plans'],
    'U4' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u4', 'table' => 'roll_plans'],
    'U5' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u5', 'table' => 'roll_plans']
];

// Получаем дату
$date = $_GET['date'] ?? date('Y-m-d');

// Функция для получения всех заданий из всех баз данных
function getAllCutTasks($databases, $date) {
    $allTasks = [];
    
    foreach ($databases as $department => $dbConfig) {
        try {
            $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
            $mysqli->set_charset("utf8mb4");
            
            if ($mysqli->connect_errno) {
                error_log("Ошибка подключения к БД {$department}: " . $mysqli->connect_error);
                continue;
            }
            
            // Определяем название поля даты в зависимости от участка
            $dateField = ($department === 'U2') ? 'plan_date' : 'work_date';
            
            // Сначала получаем основные записи из roll_plans
            $mainSql = "SELECT id, order_number, bale_id, {$dateField} as plan_date, done
                        FROM {$dbConfig['table']}
                        WHERE {$dateField} = ?
                        ORDER BY order_number, bale_id";
            
            $stmt = $mysqli->prepare($mainSql);
            if (!$stmt) {
                error_log("Ошибка подготовки запроса для {$department}: " . $mysqli->error);
                $mysqli->close();
                continue;
            }
            
            $stmt->bind_param('s', $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $bales = [];
            $processedIds = []; // Дополнительная защита от дублирования по ID
            
            while ($row = $result->fetch_assoc()) {
                if (!$row['id']) continue;
                
                // Проверяем, не обработали ли мы уже эту запись
                if (isset($processedIds[$row['id']])) {
                    continue;
                }
                $processedIds[$row['id']] = true;
                
                $key = $row['id'];
                $bales[$key] = [
                    'id' => $row['id'],
                    'order_number' => $row['order_number'],
                    'bale_id' => $row['bale_id'],
                    'plan_date' => $row['plan_date'],
                    'done' => $row['done'],
                    'department' => $department,
                    'filters' => [],
                    'total_width' => 0
                ];
                
                // Теперь получаем детали фильтров для каждой бухты отдельно
                $cutSql = "SELECT filter, length, width, height 
                           FROM cut_plans 
                           WHERE bale_id = ? AND order_number = ?";
                $cutStmt = $mysqli->prepare($cutSql);
                if ($cutStmt) {
                    $cutStmt->bind_param('ss', $row['bale_id'], $row['order_number']);
                    $cutStmt->execute();
                    $cutResult = $cutStmt->get_result();
                    
                    while ($cutRow = $cutResult->fetch_assoc()) {
                        $bales[$key]['filters'][] = [
                            'name' => $cutRow['filter'],
                            'length' => $cutRow['length'],
                            'width' => $cutRow['width'],
                            'height' => $cutRow['height']
                        ];
                        $bales[$key]['total_width'] += (float)$cutRow['width'];
                    }
                    $cutStmt->close();
                }
            }
            
            $stmt->close();
            $mysqli->close();
            
            $allTasks[$department] = array_values($bales);
            
        } catch (Exception $e) {
            error_log("Ошибка при работе с БД {$department}: " . $e->getMessage());
        }
    }
    
    return $allTasks;
}

// Получаем просроченные задания
function getOverdueTasks($databases, $today) {
    $overdueTasks = [];
    
    foreach ($databases as $department => $dbConfig) {
        try {
            $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
            $mysqli->set_charset("utf8mb4");
            
            if ($mysqli->connect_errno) {
                continue;
            }
            
            $dateField = ($department === 'U2') ? 'plan_date' : 'work_date';
            
            // Проверяем наличие поля status в таблице orders
            $hasStatusField = false;
            $checkResult = $mysqli->query("SHOW COLUMNS FROM orders LIKE 'status'");
            if ($checkResult && $checkResult->num_rows > 0) {
                $hasStatusField = true;
            }
            
            // Формируем запрос в зависимости от наличия поля status
            if ($hasStatusField) {
                $sql = "SELECT r.id, r.order_number, r.bale_id, r.{$dateField} as plan_date, r.done
                        FROM {$dbConfig['table']} r
                        LEFT JOIN orders o ON r.order_number = o.order_number
                        WHERE r.{$dateField} < ? 
                          AND (r.done IS NULL OR r.done = 0)
                          AND (o.hide IS NULL OR o.hide != 1)
                          AND (o.status IS NULL OR o.status NOT IN ('completed', 'closed', 'finished'))
                        ORDER BY r.{$dateField} ASC, r.order_number, r.bale_id";
            } else {
                $sql = "SELECT r.id, r.order_number, r.bale_id, r.{$dateField} as plan_date, r.done
                        FROM {$dbConfig['table']} r
                        LEFT JOIN orders o ON r.order_number = o.order_number
                        WHERE r.{$dateField} < ? 
                          AND (r.done IS NULL OR r.done = 0)
                          AND (o.hide IS NULL OR o.hide != 1)
                        ORDER BY r.{$dateField} ASC, r.order_number, r.bale_id";
            }
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                error_log("Ошибка подготовки запроса для просроченных задач {$department}: " . $mysqli->error);
                $mysqli->close();
                continue;
            }
            
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $processedBales = []; // Для отслеживания уже обработанных бухт
            
            while ($row = $result->fetch_assoc()) {
                if (!$row['id']) continue;
                
                // Создаем уникальный ключ для бухты
                $baleKey = $department . '_' . $row['order_number'] . '_' . $row['bale_id'];
                if (isset($processedBales[$baleKey])) {
                    continue; // Пропускаем дубликаты
                }
                $processedBales[$baleKey] = true;
                
                $daysOverdue = (strtotime($today) - strtotime($row['plan_date'])) / (60 * 60 * 24);
                
                // Получаем детали фильтров для этой бухты
                $cutStmt = $mysqli->prepare("SELECT filter, length, width, height FROM cut_plans WHERE order_number = ? AND bale_id = ?");
                $cutStmt->bind_param('ss', $row['order_number'], $row['bale_id']);
                $cutStmt->execute();
                $cutResult = $cutStmt->get_result();
                
                $filters = [];
                $total_width = 0;
                while ($cutRow = $cutResult->fetch_assoc()) {
                    $filters[] = [
                        'name' => $cutRow['filter'],
                        'length' => $cutRow['length'],
                        'width' => $cutRow['width'],
                        'height' => $cutRow['height']
                    ];
                    $total_width += (float)$cutRow['width'];
                }
                $cutStmt->close();
                
                $overdueTasks[] = [
                    'id' => $row['id'],
                    'order_number' => $row['order_number'],
                    'bale_id' => $row['bale_id'],
                    'plan_date' => $row['plan_date'],
                    'done' => $row['done'],
                    'department' => $department,
                    'days_overdue' => (int)$daysOverdue,
                    'filters' => $filters,
                    'total_width' => $total_width
                ];
            }
            
            $stmt->close();
            $mysqli->close();
            
        } catch (Exception $e) {
            error_log("Ошибка при получении просроченных задач {$department}: " . $e->getMessage());
        }
    }
    
    return $overdueTasks;
}

// Получаем данные
$allTasks = getAllCutTasks($databases, $date);
$overdueTasks = getOverdueTasks($databases, date('Y-m-d'));

// Обработка AJAX запросов
if (isset($_POST['action'])) {
    // Очищаем буфер вывода перед отправкой JSON
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_POST['action'] === 'mark_done') {
        $taskId = (int)$_POST['id'];
        $department = $_POST['department'] ?? '';
        
        if (!$taskId || !isset($databases[$department])) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            exit;
        }
        
        $dbConfig = $databases[$department];
        
        try {
            $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
            $mysqli->set_charset("utf8mb4");
            
            if ($mysqli->connect_errno) {
                throw new Exception("Ошибка подключения к БД: " . $mysqli->connect_error);
            }
            
            // Проверяем, существует ли запись с таким ID
            $checkStmt = $mysqli->prepare("SELECT id FROM {$dbConfig['table']} WHERE id = ?");
            if (!$checkStmt) {
                throw new Exception("Ошибка подготовки проверочного запроса: " . $mysqli->error);
            }
            $checkStmt->bind_param('i', $taskId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                $checkStmt->close();
                $mysqli->close();
                echo json_encode(['success' => false, 'message' => 'Запись не найдена']);
                exit;
            }
            $checkStmt->close();
            
            // Обновляем статус
            $stmt = $mysqli->prepare("UPDATE {$dbConfig['table']} SET done = 1 WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса обновления: " . $mysqli->error);
            }
            
            $stmt->bind_param('i', $taskId);
            $success = $stmt->execute();
            
            if (!$success) {
                throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
            }
            
            $stmt->close();
            $mysqli->close();
            
            echo json_encode(['success' => true, 'message' => 'Статус успешно обновлен']);
            exit;
            
        } catch (Exception $e) {
            error_log("Ошибка mark_done для department={$department}, id={$taskId}: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    // Если неизвестное действие
    echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оператор бумагорезки</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 14px;
            background: #f0f0f0;
            margin: 0;
            padding: 10px;
        }
        
        .header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 24px;
        }
        
        .filters {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            justify-content: center;
        }
        
        .filters label {
            font-weight: bold;
            color: #555;
        }
        
        input[type="date"] {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        
        select {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .department-section {
            max-width: 1200px;
            margin: 0 auto 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .department-header {
            background: #4a90e2;
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        tr.done {
            background-color: #d4edda;
        }
        
        tr.main-row {
            cursor: pointer;
            background: #fafafa;
        }
        
        tr.main-row:hover {
            background: #f1f1f1;
        }
        
        .details-row {
            display: none;
        }
        
        .positions {
            border-collapse: collapse;
            width: 100%;
            font-size: 12px;
        }
        
        .positions th, .positions td {
            border: 1px solid #ccc;
            padding: 4px;
            text-align: center;
        }
        
        .positions th {
            background: #f0f0f0;
        }
        
        button {
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            border-radius: 4px;
            background: #28a745;
            color: white;
        }
        
        button:hover {
            background: #218838;
        }
        
        .residual {
            font-weight: bold;
        }
        
        .residual.low {
            color: red;
        }
        
        .overdue-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #856404;
        }
        
        .main-row.overdue.warning {
            background-color: #fef3c7 !important;
            border-left: 4px solid #f59e0b;
        }
        
        .main-row.overdue.critical {
            background-color: #fee2e2 !important;
            border-left: 4px solid #dc2626;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-wrapper {
                font-size: 12px;
            }
            
            th, td {
                padding: 4px;
            }
            
            button {
                width: 100%;
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Оператор бумагорезки</h1>
        <p>Централизованное управление заданиями на порезку со всех участков</p>
    </div>

    <div class="filters">
        <label>Дата: </label>
        <input type="date" id="date-selector" value="<?= htmlspecialchars($date) ?>">
        
        <label>Участок: </label>
        <select id="department-filter">
            <option value="">Все участки</option>
            <option value="U2">U2</option>
            <option value="U3">U3</option>
            <option value="U4">U4</option>
            <option value="U5">U5</option>
        </select>
        
        <label>Статус: </label>
        <select id="status-filter">
            <option value="">Все задания</option>
            <option value="pending">Не выполнено</option>
            <option value="done">Выполнено</option>
        </select>
    </div>

    <?php if (!empty($overdueTasks)): ?>
    <div class="department-section">
        <div class="department-header">
            ⚠️ Просроченные бухты
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Участок</th>
                        <th>Заявка</th>
                        <th>Бухта</th>
                        <th>План дата</th>
                        <th>Прошло дней</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdueTasks as $task): 
                        $overdueClass = $task['days_overdue'] >= 2 ? 'critical' : 'warning';
                    ?>
                    <tr class="main-row overdue <?= $overdueClass ?>" data-id="overdue-<?= $task['id'] ?>" onclick="toggleDetails('overdue-<?= $task['id'] ?>')">
                        <td><?= htmlspecialchars(str_replace('U', '', $task['department'])) ?></td>
                        <td><?= htmlspecialchars($task['order_number']) ?></td>
                        <td><?= htmlspecialchars($task['bale_id']) ?></td>
                        <td><?= htmlspecialchars($task['plan_date']) ?></td>
                        <td style="font-weight: bold; color: red;"><?= $task['days_overdue'] ?></td>
                        <td>
                            <button onclick="event.stopPropagation(); markAsDone(<?= $task['id'] ?>, '<?= htmlspecialchars($task['department'], ENT_QUOTES) ?>')">
                                Выполнено
                            </button>
                        </td>
                    </tr>
                    <tr class="details-row" id="details-overdue-<?= $task['id'] ?>">
                        <td colspan="6" style="text-align: left; padding: 15px; background: #f9f9f9;">
                            <strong>Детали:</strong><br><br>
                            <?php if (!empty($task['filters'])): ?>
                            <table class="positions">
                                <thead>
                                    <tr>
                                        <th>Фильтр</th>
                                        <th>Ширина (мм)</th>
                                        <th>Высота (мм)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($task['filters'] as $filter): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($filter['name']) ?></td>
                                        <td><?= $filter['width'] ?></td>
                                        <td><?= $filter['height'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p><strong>Суммарная ширина:</strong> <?= $task['total_width'] ?> мм</p>
                            <?php $residual = 1200 - $task['total_width']; ?>
                            <p class="residual <?= ($residual < 100) ? 'low' : '' ?>">
                                <strong>Остаток:</strong> <?= max(0, $residual) ?> мм
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($allTasks as $department => $tasks): ?>
        <?php if (!empty($tasks)): ?>
        <div class="department-section" data-department="<?= htmlspecialchars($department) ?>">
            <div class="department-header">
                Задания от участка <?= htmlspecialchars(str_replace('U', '', $department)) ?>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Заявка</th>
                            <th>Бухта</th>
                            <th>План дата</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $bale): ?>
                        <tr class="main-row <?= $bale['done'] ? 'done' : '' ?>" data-id="<?= $bale['id'] ?>" data-status="<?= $bale['done'] ? 'done' : 'pending' ?>" onclick="toggleDetails(<?= $bale['id'] ?>)">
                            <td><?= htmlspecialchars($bale['order_number']) ?></td>
                            <td>
                                <?= htmlspecialchars($bale['bale_id']) ?>
                                <?php if (!empty($bale['filters'])): ?>
                                    [<?= number_format($bale['filters'][0]['length'], 0, '.', '') ?> м]
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($bale['plan_date']) ?></td>
                            <td>
                                <span class="bale-status"><?= $bale['done'] ? 'Готово' : 'Не готово' ?></span>
                            </td>
                            <td>
                                <?php if (!$bale['done']): ?>
                                    <button onclick="event.stopPropagation(); markAsDone(<?= $bale['id'] ?>, '<?= htmlspecialchars($department, ENT_QUOTES) ?>')">
                                        ВЫПОЛНЕНО
                                    </button>
                                <?php else: ?>
                                    <span style="color:green;">✔ Выполнено</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="details-row" id="details-<?= $bale['id'] ?>">
                            <td colspan="5" style="text-align: left; padding: 15px; background: #f9f9f9;">
                                <strong>Детали бухты:</strong><br><br>
                                <?php if (!empty($bale['filters'])): ?>
                                <table class="positions">
                                    <thead>
                                        <tr>
                                            <th>Фильтр</th>
                                            <th>Ширина (мм)</th>
                                            <th>Высота (мм)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bale['filters'] as $filter): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($filter['name']) ?></td>
                                            <td><?= $filter['width'] ?></td>
                                            <td><?= $filter['height'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p><strong>Суммарная ширина:</strong> <?= number_format($bale['total_width'], 1) ?> мм</p>
                                <?php $residual = 1200 - $bale['total_width']; ?>
                                <p class="residual <?= ($residual < 100) ? 'low' : '' ?>">
                                    <strong>Остаток:</strong> <?= max(0, number_format($residual, 1)) ?> мм
                                </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <script>
        function toggleDetails(id) {
            const detailsRow = document.getElementById("details-" + id);
            if (detailsRow) {
                detailsRow.style.display = detailsRow.style.display === "table-row" ? "none" : "table-row";
            }
        }

        function markAsDone(id, department) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            location.reload();
                        } else {
                            alert("Ошибка: " + (data.message || "не удалось обновить статус"));
                        }
                    } catch (e) {
                        alert("Ошибка обработки ответа: " + e.message + "\nОтвет сервера: " + xhr.responseText.substring(0, 200));
                    }
                } else {
                    alert("Ошибка запроса. Код: " + xhr.status);
                }
            };
            
            xhr.onerror = function() {
                alert("Ошибка соединения с сервером");
            };
            
            xhr.send('action=mark_done&id=' + encodeURIComponent(id) + '&department=' + encodeURIComponent(department));
        }

        // Фильтры
        document.addEventListener('DOMContentLoaded', function() {
            var dateSelector = document.getElementById('date-selector');
            var departmentFilter = document.getElementById('department-filter');
            var statusFilter = document.getElementById('status-filter');

            function updateDate() {
                var selectedDate = dateSelector.value;
                if (selectedDate) {
                    window.location.href = '?date=' + selectedDate;
                }
            }

            function filterTable() {
                var selectedDepartment = departmentFilter.value;
                var selectedStatus = statusFilter.value;

                document.querySelectorAll('.department-section').forEach(section => {
                    const department = section.getAttribute('data-department');
                    
                    if (selectedDepartment && department !== selectedDepartment) {
                        section.style.display = 'none';
                        return;
                    }
                    
                    section.style.display = 'block';
                    
                    // Фильтр по статусу
                    if (selectedStatus) {
                        section.querySelectorAll('.main-row').forEach(row => {
                            const rowStatus = row.getAttribute('data-status');
                            if (selectedStatus === 'done' && rowStatus !== 'done') {
                                row.style.display = 'none';
                            } else if (selectedStatus === 'pending' && rowStatus === 'done') {
                                row.style.display = 'none';
                            } else {
                                row.style.display = '';
                            }
                        });
                    } else {
                        section.querySelectorAll('.main-row').forEach(row => {
                            row.style.display = '';
                        });
                    }
                });
            }

            dateSelector.addEventListener('change', updateDate);
            departmentFilter.addEventListener('change', filterTable);
            statusFilter.addEventListener('change', filterTable);
        });
    </script>
</body>
</html>
