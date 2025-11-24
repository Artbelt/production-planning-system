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
require_once('cap_db_init.php');

// Подключаемся к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    die('Ошибка подключения к БД: ' . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// Получаем список заявок (используем тот же алгоритм, что и на main.php)
$orders_list = [];
$sql_orders = "SELECT DISTINCT order_number, workshop, hide FROM orders";
if ($result_orders = $mysqli->query($sql_orders)) {
    // Группируем заявки для отображения (как на main.php)
    $orders_temp = [];
    while ($orders_data = $result_orders->fetch_assoc()) {
        if ($orders_data['hide'] != 1) {
            $order_num = $orders_data['order_number'];
            if (!isset($orders_temp[$order_num])) {
                $orders_temp[$order_num] = $orders_data;
            }
        }
    }
    // Преобразуем в простой массив и сортируем по убыванию
    foreach ($orders_temp as $order_num => $order_data) {
        $orders_list[] = $order_num;
    }
    // Сортируем по убыванию (новые заявки сверху)
    rsort($orders_list);
    $result_orders->close();
}

// Получаем выбранную заявку
$selected_order = $_GET['order'] ?? '';

$movements = [];
$summary = [];
$order_info = null;

if (!empty($selected_order)) {
    // Получаем движение крышек по заявке
    $stmt = $mysqli->prepare("
        SELECT date, cap_name, operation_type, quantity, filter_name, production_date, user_name, comment
        FROM cap_movements
        WHERE order_number = ?
        ORDER BY date DESC, id DESC
    ");
    
    if ($stmt) {
        $stmt->bind_param("s", $selected_order);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $movements[] = $row;
            
            // Подсчитываем итоги по крышкам
            $cap_name = $row['cap_name'];
            if (!isset($summary[$cap_name])) {
                $summary[$cap_name] = 0;
            }
            if ($row['operation_type'] == 'PRODUCTION_OUT') {
                $summary[$cap_name] += abs($row['quantity']);
            }
        }
        
        $stmt->close();
    }
    
    // Проверяем существование заявки
    $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM orders WHERE order_number = ?");
    if ($stmt) {
        $stmt->bind_param("s", $selected_order);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row['cnt'] > 0) {
            $order_info = $selected_order;
        }
        $stmt->close();
    }
}

$mysqli->close();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Движение крышек по заявке - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        select {
            width: 100%;
            max-width: 400px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            background: #4169e1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #6495ed;
            color: white;
            font-weight: bold;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .income {
            color: #28a745;
            font-weight: bold;
        }
        .outcome {
            color: #dc3545;
            font-weight: bold;
        }
        .summary-box {
            background: #e8f0fe;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .summary-box h3 {
            margin-top: 0;
            color: #6495ed;
        }
        .summary-table {
            margin-top: 10px;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #6495ed;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .back-link:hover {
            background: #4169e1;
        }
        .no-data {
            padding: 20px;
            text-align: center;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Движение крышек по заявке</h1>
        
        <form method="GET" action="cap_movements_view.php">
            <div class="form-group">
                <label for="order">Выберите заявку:</label>
                <select id="order" name="order" onchange="this.form.submit()">
                    <option value="">-- Выберите заявку --</option>
                    <?php foreach ($orders_list as $order): ?>
                        <option value="<?php echo htmlspecialchars($order); ?>" 
                                <?php echo ($selected_order === $order) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($order); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        
        <?php if (!empty($selected_order)): ?>
            <?php if ($order_info): ?>
                <div class="summary-box">
                    <h3>Заявка: <?php echo htmlspecialchars($selected_order); ?></h3>
                    
                    <?php if (!empty($summary)): ?>
                        <h4>Итого списано крышек по заявке:</h4>
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>Название крышки</th>
                                    <th style="text-align: right;">Количество</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary as $cap_name => $total_qty): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cap_name); ?></td>
                                        <td style="text-align: right; font-weight: bold;">
                                            <?php echo number_format($total_qty, 0, ',', ' '); ?> шт
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>По этой заявке еще не было списания крышек.</p>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($movements)): ?>
                    <h3>История операций:</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Тип операции</th>
                                <th>Крышка</th>
                                <th style="text-align: right;">Количество</th>
                                <th>Фильтр</th>
                                <th>Дата производства</th>
                                <th>Пользователь</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($movement['date'])); ?></td>
                                    <td>
                                        <?php 
                                        $op_type = $movement['operation_type'];
                                        if ($op_type == 'INCOME') {
                                            echo '<span class="income">Поступление</span>';
                                        } elseif ($op_type == 'PRODUCTION_OUT') {
                                            echo '<span class="outcome">Списание</span>';
                                        } else {
                                            echo htmlspecialchars($op_type);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($movement['cap_name']); ?></td>
                                    <td style="text-align: right;">
                                        <?php 
                                        if ($movement['operation_type'] == 'PRODUCTION_OUT') {
                                            echo '<span class="outcome">-' . number_format(abs($movement['quantity']), 0, ',', ' ') . '</span>';
                                        } else {
                                            echo '<span class="income">+' . number_format($movement['quantity'], 0, ',', ' ') . '</span>';
                                        }
                                        ?> шт
                                    </td>
                                    <td><?php echo htmlspecialchars($movement['filter_name'] ?? '-'); ?></td>
                                    <td>
                                        <?php 
                                        if ($movement['production_date']) {
                                            echo date('d.m.Y', strtotime($movement['production_date']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($movement['user_name'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>По выбранной заявке нет операций с крышками.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-data">
                    <p>Заявка не найдена.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-data">
                <p>Выберите заявку для просмотра движения крышек.</p>
            </div>
        <?php endif; ?>
        
        <a href="cap_management.php" class="back-link">← Назад</a>
    </div>
</body>
</html>

