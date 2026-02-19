<?php
/**
 * Управление справочником коробок
 * Доступно только для admin и director
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

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверка доступа (только admin и director)
$hasAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director'])) {
        $hasAccess = true;
        break;
    }
}

if (!$hasAccess) {
    die("У вас нет доступа к управлению справочником");
}

// Подключение к БД (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$mysqli = new mysqli(
    defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    defined('DB_USER') ? DB_USER : 'root',
    defined('DB_PASS') ? DB_PASS : '',
    'press_module'
);

if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД");
}

// Обработка добавления новой коробки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_box') {
    $box_name = $_POST['box_name'] ?? '';
    $length = (int)($_POST['length'] ?? 0);
    $width = (int)($_POST['width'] ?? 0);
    $height = (int)($_POST['height'] ?? 0);
    $description = $_POST['description'] ?? '';
    
    if ($box_name !== '' && $length > 0 && $width > 0 && $height > 0) {
        $stmt = $mysqli->prepare("INSERT INTO box_catalog (box_name, length, width, height, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $created_by = $user['username'] ?? 'unknown';
        $stmt->bind_param("siiiss", $box_name, $length, $width, $height, $description, $created_by);
        
        if ($stmt->execute()) {
            $success_message = "Коробка добавлена успешно!";
        } else {
            $error_message = "Ошибка: " . $mysqli->error;
        }
        $stmt->close();
    } else {
        $error_message = "Заполните все обязательные поля (название и размеры)";
    }
}

// Обработка удаления коробки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_box') {
    $box_id = (int)$_POST['box_id'];
    
    $stmt = $mysqli->prepare("DELETE FROM box_catalog WHERE id = ?");
    $stmt->bind_param("i", $box_id);
    
    if ($stmt->execute()) {
        $success_message = "Коробка удалена успешно!";
    } else {
        $error_message = "Ошибка при удалении: " . $mysqli->error;
    }
    $stmt->close();
}

// Обработка добавления бренда
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_brand') {
    $brand_name = $_POST['brand_name'] ?? '';
    
    if ($brand_name !== '') {
        $stmt = $mysqli->prepare("INSERT INTO brands (brand_name, created_by) VALUES (?, ?)");
        $created_by = $user['username'] ?? 'unknown';
        $stmt->bind_param("ss", $brand_name, $created_by);
        
        if ($stmt->execute()) {
            $success_message = "Бренд добавлен успешно!";
        } else {
            $error_message = "Ошибка: " . $mysqli->error;
        }
        $stmt->close();
    }
}

// Обработка удаления бренда
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_brand') {
    $brand_id = (int)$_POST['brand_id'];
    
    $stmt = $mysqli->prepare("DELETE FROM brands WHERE id = ?");
    $stmt->bind_param("i", $brand_id);
    
    if ($stmt->execute()) {
        $success_message = "Бренд удален успешно!";
    } else {
        $error_message = "Ошибка при удалении: " . $mysqli->error;
    }
    $stmt->close();
}

// Загружаем все бренды
$brands = [];
$result = $mysqli->query("SELECT * FROM brands ORDER BY id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $brands[] = $row;
    }
}

// Загружаем все коробки
$boxes = [];
$result = $mysqli->query("SELECT * FROM box_catalog ORDER BY id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $boxes[] = $row;
    }
}

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление справочником коробок</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #7f8c8d;
        }
        
        .panel {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .panel-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #2c3e50;
            color: white;
        }
        
        .btn-primary:hover {
            background: #34495e;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
            font-size: 12px;
            padding: 6px 14px;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .back-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #ecf0f1;
            color: #2c3e50;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .back-btn:hover {
            background: #bdc3c7;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn">← Назад к модулю</a>
        
        <div class="header">
            <h1>📦 Справочники</h1>
            <p>Управление брендами и типами коробок для тигельного пресса</p>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        
        <div class="panel">
            <div class="panel-title">Добавить новый тип коробки</div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_box">
                
                <div class="form-group">
                    <label>Название коробки *</label>
                    <input type="text" name="box_name" required placeholder="Например: Коробка 350х250х100">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Длина (мм) *</label>
                        <input type="number" name="length" required min="1" placeholder="350">
                    </div>
                    
                    <div class="form-group">
                        <label>Ширина (мм) *</label>
                        <input type="number" name="width" required min="1" placeholder="250">
                    </div>
                    
                    <div class="form-group">
                        <label>Высота (мм) *</label>
                        <input type="number" name="height" required min="1" placeholder="100">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="3" placeholder="Дополнительная информация"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Добавить коробку</button>
            </form>
        </div>
        
        <div class="panel">
            <div class="panel-title">Список коробок</div>
            
            <table>
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Длина (мм)</th>
                        <th>Ширина (мм)</th>
                        <th>Высота (мм)</th>
                        <th>Описание</th>
                        <th>Создана</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boxes as $box): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($box['box_name']) ?></strong></td>
                            <td><?= $box['length'] ?? '—' ?></td>
                            <td><?= $box['width'] ?? '—' ?></td>
                            <td><?= $box['height'] ?? '—' ?></td>
                            <td><?= htmlspecialchars($box['description'] ?? '—') ?></td>
                            <td><?= date('d.m.Y', strtotime($box['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить коробку <?= htmlspecialchars($box['box_name']) ?>?')">
                                    <input type="hidden" name="action" value="delete_box">
                                    <input type="hidden" name="box_id" value="<?= $box['id'] ?>">
                                    <button type="submit" class="btn btn-danger">
                                        Удалить
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="panel">
            <div class="panel-title">Добавить новый бренд</div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_brand">
                
                <div class="form-group">
                    <label>Название бренда *</label>
                    <input type="text" name="brand_name" required placeholder="Например: Beko">
                </div>
                
                <button type="submit" class="btn btn-primary">Добавить бренд</button>
            </form>
        </div>
        
        <div class="panel">
            <div class="panel-title">Список брендов</div>
            
            <table>
                <thead>
                    <tr>
                        <th>Название бренда</th>
                        <th>Создан</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($brands) > 0): ?>
                        <?php foreach ($brands as $brand): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($brand['brand_name']) ?></strong></td>
                                <td><?= date('d.m.Y', strtotime($brand['created_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить бренд <?= htmlspecialchars($brand['brand_name']) ?>?')">
                                        <input type="hidden" name="action" value="delete_brand">
                                        <input type="hidden" name="brand_id" value="<?= $brand['id'] ?>">
                                        <button type="submit" class="btn btn-danger">
                                            Удалить
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center; color:#7f8c8d; padding:20px;">Нет брендов</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

