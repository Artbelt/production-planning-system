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

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

// Подключаемся к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    die('Ошибка подключения к БД: ' . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");

// Создаем таблицы если их нет (передаем соединение)
require_once('cap_db_init.php');

// Получаем список существующих крышек для автодополнения
$caps_list = [];
$sql = "SELECT DISTINCT cap_name FROM cap_stock ORDER BY cap_name";
if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $caps_list[] = $row['cap_name'];
    }
    $result->close();
}

// Получаем список заявок для выпадающего списка (используем тот же алгоритм, что и на main.php)
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

// Закрываем соединение
$mysqli->close();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Прием крышек на склад - U3</title>
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
        .form-group {
            margin-bottom: 12px;
        }
        label {
            display: block;
            margin-bottom: 3px;
            font-weight: bold;
            color: #555;
            font-size: 13px;
        }
        input[type="date"],
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
            box-sizing: border-box;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            margin-top: 5px;
        }
        button:hover {
            background: #4169e1;
        }
        .message {
            padding: 10px;
            margin-bottom: 12px;
            border-radius: 4px;
            display: none;
            font-size: 13px;
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
        #cap_name {
            font-size: 14px;
        }
    </style>
    <script>
        // Данные для автодополнения
        const capsList = <?php echo json_encode($caps_list, JSON_UNESCAPED_UNICODE); ?>;
        
        function setupAutocomplete() {
            const input = document.getElementById('cap_name');
            const datalist = document.getElementById('caps_datalist');
            
            // Заполняем datalist
            capsList.forEach(cap => {
                const option = document.createElement('option');
                option.value = cap;
                datalist.appendChild(option);
            });
        }
        
        window.onload = function() {
            setupAutocomplete();
            // Устанавливаем сегодняшнюю дату по умолчанию
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date').value = today;
        };
    </script>
</head>
<body>
    <div class="container">
        <h1>Прием крышек на склад</h1>
        
        <?php
        // Показываем сообщение об успехе если есть
        if (isset($_GET['success']) && $_GET['success'] == 1) {
            $cap = htmlspecialchars($_GET['cap'] ?? '');
            $qty = htmlspecialchars($_GET['qty'] ?? '');
            echo '<div class="message success" style="display: block;">';
            echo '✓ Крышки приняты: <strong>' . $cap . '</strong> - <strong>' . $qty . ' шт</strong>';
            echo '</div>';
        }
        ?>
        
        <div id="message" class="message"></div>
        
        <form id="incomeForm" method="POST" action="cap_income_process.php">
            <div class="form-group">
                <label for="date">Дата поступления *</label>
                <input type="date" id="date" name="date" required>
            </div>
            
            <div class="form-group">
                <label for="cap_name">Название крышки *</label>
                <input type="text" id="cap_name" name="cap_name" list="caps_datalist" required 
                       placeholder="Введите название крышки" autocomplete="off">
                <datalist id="caps_datalist"></datalist>
            </div>
            
            <div class="form-group">
                <label for="quantity">Количество *</label>
                <input type="number" id="quantity" name="quantity" min="1" step="1" required 
                       placeholder="Введите количество">
            </div>
            
            <div class="form-group">
                <label for="order_number">Заявка *</label>
                <select id="order_number" name="order_number" required>
                    <option value="">-- Выберите заявку --</option>
                    <?php
                    foreach ($orders_list as $order_num) {
                        echo "<option value='" . htmlspecialchars($order_num) . "'>" . htmlspecialchars($order_num) . "</option>";
                    }
                    ?>
                </select>
            </div>
            
            <button type="submit">Принять на склад</button>
        </form>
    </div>
</body>
</html>

