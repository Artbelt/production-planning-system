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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Управление крышками - U3</title>
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
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 10px;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 30px;
        }
        .menu-card {
            background: #f9f9f9;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            display: block;
        }
        .menu-card:hover {
            border-color: #6495ed;
            background: #e8f0fe;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .menu-card h2 {
            margin: 0 0 10px 0;
            color: #6495ed;
        }
        .menu-card p {
            margin: 0;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Управление крышками</h1>
        
        <div class="menu-grid">
            <a href="cap_income.php" class="menu-card" target="_blank">
                <h2>Прием крышек</h2>
                <p>Внести информацию о поступлении крышек на склад</p>
            </a>
            
            <a href="cap_stock_view.php" class="menu-card" target="_blank">
                <h2>Остатки на складе</h2>
                <p>Просмотр текущих остатков крышек</p>
            </a>
            
            <a href="cap_movements_view.php" class="menu-card" target="_blank">
                <h2>Движение по заявке</h2>
                <p>Просмотр движения крышек по конкретной заявке</p>
            </a>
            
            <a href="cap_history.php" class="menu-card" target="_blank">
                <h2>История операций</h2>
                <p>Просмотр всех операций с крышками</p>
            </a>
        </div>
    </div>
</body>
</html>

