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

// Параметры фильтрации
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$operation_type = $_GET['operation_type'] ?? '';
$cap_name_filter = $_GET['cap_name'] ?? '';

// Формируем запрос
$where_conditions = ["date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$param_types = "ss";

if (!empty($operation_type)) {
    $where_conditions[] = "operation_type = ?";
    $params[] = $operation_type;
    $param_types .= "s";
}

if (!empty($cap_name_filter)) {
    $where_conditions[] = "cap_name LIKE ?";
    $params[] = "%$cap_name_filter%";
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);
$sql = "SELECT date, cap_name, operation_type, quantity, order_number, user_name, created_at
        FROM cap_movements
        WHERE $where_clause
        ORDER BY date DESC, id DESC
        LIMIT 500";

$movements = [];
$stmt = $pdo->prepare($sql);
if ($stmt && $stmt->execute($params)) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $movements[] = $row;
    }
}

$caps_list = [];
$st = $pdo->query("SELECT DISTINCT cap_name FROM cap_stock ORDER BY cap_name");
if ($st) {
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $caps_list[] = $row['cap_name'];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>История операций с крышками - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1400px;
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
        .filters {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
            font-size: 14px;
        }
        input[type="date"],
        input[type="text"],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            height: 36px;
        }
        button:hover {
            background: #4169e1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #6495ed;
            color: white;
            font-weight: bold;
            position: sticky;
            top: 0;
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
        .stats {
            background: #e8f0fe;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>История операций с крышками</h1>
        
        <form method="GET" action="cap_history.php">
            <div class="filters">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="date_from">Дата от:</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_to">Дата до:</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="operation_type">Тип операции:</label>
                        <select id="operation_type" name="operation_type">
                            <option value="">Все</option>
                            <option value="INCOME" <?php echo ($operation_type === 'INCOME') ? 'selected' : ''; ?>>Поступление</option>
                            <option value="PRODUCTION_OUT" <?php echo ($operation_type === 'PRODUCTION_OUT') ? 'selected' : ''; ?>>Списание</option>
                            <option value="ADJUSTMENT" <?php echo ($operation_type === 'ADJUSTMENT') ? 'selected' : ''; ?>>Корректировка</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="cap_name">Название крышки:</label>
                        <input type="text" id="cap_name" name="cap_name" value="<?php echo htmlspecialchars($cap_name_filter); ?>" 
                               placeholder="Поиск по названию">
                    </div>
                    <div class="filter-group">
                        <button type="submit">Применить фильтры</button>
                    </div>
                </div>
            </div>
        </form>
        
        <div class="stats">
            <strong>Найдено записей:</strong> <?php echo count($movements); ?>
        </div>
        
        <?php if (!empty($movements)): ?>
            <div style="max-height: 600px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Крышка</th>
                            <th style="text-align: right;">Количество</th>
                            <th>Заявка</th>
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
                                <td><?php echo htmlspecialchars($movement['order_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($movement['user_name'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <p>Нет записей по заданным критериям.</p>
            </div>
        <?php endif; ?>
        
        <a href="cap_management.php" class="back-link">← Назад</a>
    </div>
</body>
</html>

