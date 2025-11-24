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

// Получаем остатки
$sql = "SELECT cap_name, current_quantity, last_updated 
        FROM cap_stock 
        ORDER BY cap_name ASC";
$result = $mysqli->query($sql);

$total_caps = 0;
$stock_data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $stock_data[] = $row;
        $total_caps += $row['current_quantity'];
    }
    $result->close();
}

$mysqli->close();

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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }
        th, td {
            padding: 8px;
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
        .low-stock {
            background: #fff3cd;
        }
        .zero-stock {
            background: #f8d7da;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Остатки крышек на складе</h1>
        
        <div class="summary">
            <strong>Всего позиций:</strong> <?php echo count($stock_data); ?> | 
            <strong>Общее количество:</strong> <?php echo number_format($total_caps, 0, ',', ' '); ?> шт
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
                        $row_class = '';
                        if ($qty == 0) {
                            $row_class = 'zero-stock';
                        } elseif ($qty < 100) {
                            $row_class = 'low-stock';
                        }
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><?php echo htmlspecialchars($row['cap_name']); ?></td>
                            <td style="text-align: right;" class="quantity">
                                <?php echo number_format($qty, 0, ',', ' '); ?> шт
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
</body>
</html>

