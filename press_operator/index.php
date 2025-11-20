<?php
/**
 * Модуль оператора тигельного пресса
 * Учет высеченных заготовок и склеенных коробок
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

// Проверяем, есть ли доступ к модулю оператора пресса
$hasPressOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'box_operator'])) {
        $hasPressOperatorAccess = true;
        break;
    }
}

if (!$hasPressOperatorAccess) {
    die("У вас нет доступа к модулю оператора тигельного пресса");
}

// Подключение к отдельной БД для пресса
$pressDbConfig = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => '',
    'name' => 'press_module'
];

// === Автомиграция: создаем БД и таблицы ===
try {
    $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass']);
    
    // Создаем БД если не существует
    $mysqli->query("CREATE DATABASE IF NOT EXISTS press_module DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $mysqli->select_db('press_module');
    
    // Таблица брендов
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS brands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand_name VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(255) NULL,
            KEY idx_brand_name (brand_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Таблица справочника коробок
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS box_catalog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            box_name VARCHAR(255) NOT NULL UNIQUE,
            length INT NULL COMMENT 'Длина в мм',
            width INT NULL COMMENT 'Ширина в мм',
            height INT NULL COMMENT 'Высота в мм',
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(255) NULL,
            is_active TINYINT(1) DEFAULT 1,
            KEY idx_box_name (box_name),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Автомиграция: добавляем новые поля если их нет
    $checkColumns = $mysqli->query("SHOW COLUMNS FROM box_catalog LIKE 'length'");
    if ($checkColumns->num_rows == 0) {
        $mysqli->query("ALTER TABLE box_catalog ADD COLUMN length INT NULL COMMENT 'Длина в мм' AFTER box_name");
        $mysqli->query("ALTER TABLE box_catalog ADD COLUMN width INT NULL COMMENT 'Ширина в мм' AFTER length");
        $mysqli->query("ALTER TABLE box_catalog ADD COLUMN height INT NULL COMMENT 'Высота в мм' AFTER width");
    }
    
    // Таблица для высеченных заготовок
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS press_die_cut_blanks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_date DATE NOT NULL,
            brand_name VARCHAR(255) NULL,
            box_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            operator_name VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(255) NULL,
            KEY idx_date (shift_date),
            KEY idx_brand (brand_name),
            KEY idx_box_name (box_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Автомиграция: добавляем поле brand_name если его нет
    $checkBrand1 = $mysqli->query("SHOW COLUMNS FROM press_die_cut_blanks LIKE 'brand_name'");
    if ($checkBrand1->num_rows == 0) {
        $mysqli->query("ALTER TABLE press_die_cut_blanks ADD COLUMN brand_name VARCHAR(255) NULL AFTER shift_date");
    }
    
    // Таблица для склеенных коробок
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS press_glued_boxes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_date DATE NOT NULL,
            brand_name VARCHAR(255) NULL,
            box_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            operator_name VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(255) NULL,
            KEY idx_date (shift_date),
            KEY idx_brand (brand_name),
            KEY idx_box_name (box_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Автомиграция: добавляем поле brand_name если его нет
    $checkBrand2 = $mysqli->query("SHOW COLUMNS FROM press_glued_boxes LIKE 'brand_name'");
    if ($checkBrand2->num_rows == 0) {
        $mysqli->query("ALTER TABLE press_glued_boxes ADD COLUMN brand_name VARCHAR(255) NULL AFTER shift_date");
    }
    
    // Добавляем базовые бренды если пусто
    $result = $mysqli->query("SELECT COUNT(*) as cnt FROM brands");
    $row = $result->fetch_assoc();
    if ($row['cnt'] == 0) {
        $mysqli->query("
            INSERT INTO brands (brand_name, created_by) VALUES
            ('Бренд 1', 'system'),
            ('Бренд 2', 'system'),
            ('Бренд 3', 'system')
        ");
    }
    
    // Добавляем базовые коробки в справочник если пусто
    $result = $mysqli->query("SELECT COUNT(*) as cnt FROM box_catalog");
    $row = $result->fetch_assoc();
    if ($row['cnt'] == 0) {
        $mysqli->query("
            INSERT INTO box_catalog (box_name, length, width, height, created_by) VALUES
            ('Коробка 350х250х100', 350, 250, 100, 'system'),
            ('Коробка 400х300х150', 400, 300, 150, 'system'),
            ('Коробка 500х350х200', 500, 350, 200, 'system')
        ");
    }
    
    $mysqli->close();
} catch (Exception $e) {
    error_log("Migration error: " . $e->getMessage());
}

// === API для сохранения данных ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $action = $_POST['action'];
        $shift_date = $_POST['shift_date'] ?? '';
        $brand_name = $_POST['brand_name'] ?? null;
        $box_name = $_POST['box_name'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 0);
        $operator_name = $_POST['operator_name'] ?? ($user['username'] ?? 'unknown');
        $notes = $_POST['notes'] ?? '';
        
        if ($shift_date === '' || $box_name === '' || $quantity <= 0) {
            echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
            exit;
        }
        
        $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
        
        if ($mysqli->connect_errno) {
            echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД']);
            exit;
        }
        
        if ($action === 'add_die_cut') {
            $stmt = $mysqli->prepare("
                INSERT INTO press_die_cut_blanks (shift_date, brand_name, box_name, quantity, operator_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssisss", $shift_date, $brand_name, $box_name, $quantity, $operator_name, $notes, $operator_name);
        } elseif ($action === 'add_glued') {
            $stmt = $mysqli->prepare("
                INSERT INTO press_glued_boxes (shift_date, brand_name, box_name, quantity, operator_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssisss", $shift_date, $brand_name, $box_name, $quantity, $operator_name, $notes, $operator_name);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
            $mysqli->close();
            exit;
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $mysqli->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        
        $stmt->close();
        $mysqli->close();
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Получаем текущую дату или выбранную
$currentDate = $_GET['date'] ?? date('Y-m-d');

// Загружаем справочник брендов
$brandCatalog = [];
try {
    $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
    if (!$mysqli->connect_errno) {
        $result = $mysqli->query("SELECT * FROM brands ORDER BY brand_name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $brandCatalog[] = $row;
            }
        }
        $mysqli->close();
    }
} catch (Exception $e) {
    error_log("Error loading brand catalog: " . $e->getMessage());
}

// Загружаем справочник коробок
$boxCatalog = [];
try {
    $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
    if (!$mysqli->connect_errno) {
        $result = $mysqli->query("SELECT * FROM box_catalog ORDER BY id ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $boxCatalog[] = $row;
            }
        }
        $mysqli->close();
    }
} catch (Exception $e) {
    error_log("Error loading box catalog: " . $e->getMessage());
}

// Функция для получения данных за смену
function getShiftData($pressDbConfig, $date) {
    $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
    
    if ($mysqli->connect_errno) {
        return ['die_cut' => [], 'glued' => []];
    }
    
    // Высеченные заготовки
    $dieCutData = [];
    $stmt = $mysqli->prepare("SELECT * FROM press_die_cut_blanks WHERE shift_date = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dieCutData[] = $row;
    }
    
    // Склеенные коробки
    $gluedData = [];
    $stmt = $mysqli->prepare("SELECT * FROM press_glued_boxes WHERE shift_date = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gluedData[] = $row;
    }
    
    $mysqli->close();
    
    return ['die_cut' => $dieCutData, 'glued' => $gluedData];
}

$shiftData = getShiftData($pressDbConfig, $currentDate);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Модуль оператора тигельного пресса</title>
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
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .header p {
            font-size: 16px;
            color: #7f8c8d;
        }
        
        .controls {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        
        .controls input[type="date"] {
            padding: 10px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            color: #2c3e50;
            cursor: pointer;
        }
        
        .controls input[type="date"]:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .card-info {
            flex: 1;
        }
        
        .card-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .card-icon.blue {
            color: #3498db;
        }
        
        .card-icon.orange {
            color: #e67e22;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .card-subtitle {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .card-value {
            font-size: 48px;
            font-weight: 700;
            text-align: right;
        }
        
        .card-value.blue {
            color: #3498db;
        }
        
        .card-value.orange {
            color: #e67e22;
        }
        
        .card-details {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #ecf0f1;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 14px;
            color: #555;
        }
        
        .detail-item:not(:last-child) {
            border-bottom: 1px dashed #e0e0e0;
        }
        
        .detail-name {
            font-weight: 500;
        }
        
        .detail-quantity {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #2c3e50;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .btn-submit {
            width: 100%;
            padding: 14px 24px;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        
        .btn-submit:hover {
            background: #34495e;
        }
        
        .btn-submit:active {
            transform: scale(0.98);
        }
        
        .grid.summary {
            /* Сводные карточки всегда в 2 колонки */
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .grid.summary {
                grid-template-columns: 1fr 1fr; /* Сводки остаются в одну строку */
            }
            
            body {
                padding: 20px 12px;
            }
            
            .card {
                padding: 24px;
            }
            
            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Модуль оператора</h1>
            <p>Тигельный пресс - Учёт производства за смену</p>
        </div>
        
        <div class="controls">
            <input type="date" id="shift-date" value="<?= htmlspecialchars($currentDate) ?>" onchange="updatePage()">
            
            <?php 
            // Проверяем, является ли пользователь admin или director
            $canManageBoxes = false;
            foreach ($userDepartments as $dept) {
                if (in_array($dept['role_name'], ['admin', 'director'])) {
                    $canManageBoxes = true;
                    break;
                }
            }
            if ($canManageBoxes): 
            ?>
                <a href="manage_boxes.php" style="padding: 10px 16px; background: white; color: #2c3e50; text-decoration: none; border-radius: 8px; border: 1px solid #ddd; font-weight: 600; font-size: 14px;">
                    ⚙️ Управление справочниками
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Верхний ряд: сводные карточки -->
        <div class="grid summary">
            <!-- Сводка: Высеченные заготовки -->
            <div class="card">
                <div class="card-header">
                    <div class="card-info">
                        <div class="card-title">Всего высечено</div>
                        <div class="card-subtitle">Заготовок за смену</div>
                    </div>
                    <div class="card-icon blue">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        </svg>
                    </div>
                </div>
                <div class="card-value blue"><?= array_sum(array_column($shiftData['die_cut'], 'quantity')) ?></div>
                
                <?php if (!empty($shiftData['die_cut'])): ?>
                    <div class="card-details">
                        <?php foreach ($shiftData['die_cut'] as $item): ?>
                            <div class="detail-item">
                                <span class="detail-name">
                                    <?= htmlspecialchars($item['box_name']) ?> <?= htmlspecialchars($item['brand_name'] ?? '') ?>
                                </span>
                                <span class="detail-quantity"><?= $item['quantity'] ?> шт</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Сводка: Склеенные коробки -->
            <div class="card">
                <div class="card-header">
                    <div class="card-info">
                        <div class="card-title">Всего склеено</div>
                        <div class="card-subtitle">Коробок за смену</div>
                    </div>
                    <div class="card-icon orange">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                            <polyline points="7.5 4.21 12 6.81 16.5 4.21"/>
                            <polyline points="7.5 19.79 7.5 14.6 3 12"/>
                            <polyline points="21 12 16.5 14.6 16.5 19.79"/>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                            <line x1="12" y1="22.08" x2="12" y2="12"/>
                        </svg>
                    </div>
                </div>
                <div class="card-value orange"><?= array_sum(array_column($shiftData['glued'], 'quantity')) ?></div>
                
                <?php if (!empty($shiftData['glued'])): ?>
                    <div class="card-details">
                        <?php foreach ($shiftData['glued'] as $item): ?>
                            <div class="detail-item">
                                <span class="detail-name">
                                    <?= htmlspecialchars($item['box_name']) ?> <?= htmlspecialchars($item['brand_name'] ?? '') ?>
                                </span>
                                <span class="detail-quantity"><?= $item['quantity'] ?> шт</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Нижний ряд: формы ввода -->
        <div class="grid">
            <!-- Форма: Высеченные заготовки -->
            <div class="card">
                <div class="card-header">
                    <div class="card-info">
                        <div class="card-title">Высеченные заготовки</div>
                        <div class="card-subtitle">Внесите количество высеченных заготовок</div>
                    </div>
                    <div class="card-icon blue">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        </svg>
                    </div>
                </div>
                
                <form id="dieCutForm" onsubmit="return submitDieCut(event)">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Бренд</label>
                            <select name="brand_name" required>
                                <?php foreach ($brandCatalog as $brand): ?>
                                    <option value="<?= htmlspecialchars($brand['brand_name']) ?>" <?= $brand['brand_name'] === 'AF' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($brand['brand_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Номер коробки</label>
                            <select name="box_name" required>
                                <option value="">— Выберите коробку —</option>
                                <?php foreach ($boxCatalog as $box): ?>
                                    <option value="<?= htmlspecialchars($box['box_name']) ?>">
                                        <?= htmlspecialchars($box['box_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Количество заготовок</label>
                        <input type="number" name="quantity" required min="1" placeholder="Введите количество">
                    </div>
                    
                    <input type="hidden" name="operator_name" value="<?= htmlspecialchars($user['username'] ?? '') ?>">
                    
                    <button type="submit" class="btn-submit">Добавить заготовки</button>
                </form>
            </div>
            
            <!-- Форма: Склеенные коробки -->
            <div class="card">
                <div class="card-header">
                    <div class="card-info">
                        <div class="card-title">Склеенные коробки</div>
                        <div class="card-subtitle">Внесите количество склеенных коробок</div>
                    </div>
                    <div class="card-icon orange">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                            <polyline points="7.5 4.21 12 6.81 16.5 4.21"/>
                            <polyline points="7.5 19.79 7.5 14.6 3 12"/>
                            <polyline points="21 12 16.5 14.6 16.5 19.79"/>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                            <line x1="12" y1="22.08" x2="12" y2="12"/>
                        </svg>
                    </div>
                </div>
                
                <form id="gluedForm" onsubmit="return submitGlued(event)">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Бренд</label>
                            <select name="brand_name" required>
                                <?php foreach ($brandCatalog as $brand): ?>
                                    <option value="<?= htmlspecialchars($brand['brand_name']) ?>" <?= $brand['brand_name'] === 'AF' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($brand['brand_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Номер коробки</label>
                            <select name="box_name" required>
                                <option value="">— Выберите коробку —</option>
                                <?php foreach ($boxCatalog as $box): ?>
                                    <option value="<?= htmlspecialchars($box['box_name']) ?>">
                                        <?= htmlspecialchars($box['box_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Количество коробок</label>
                        <input type="number" name="quantity" required min="1" placeholder="Введите количество">
                    </div>
                    
                    <input type="hidden" name="operator_name" value="<?= htmlspecialchars($user['username'] ?? '') ?>">
                    
                    <button type="submit" class="btn-submit">Добавить коробки</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function updatePage() {
            const date = document.getElementById('shift-date').value;
            window.location.href = `?date=${date}`;
        }
        
        async function submitDieCut(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'add_die_cut');
            formData.append('shift_date', document.getElementById('shift-date').value);
            
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Обновляем только значение без перезагрузки
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка при сохранении данных');
            }
            
            return false;
        }
        
        async function submitGlued(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', 'add_glued');
            formData.append('shift_date', document.getElementById('shift-date').value);
            
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Обновляем только значение без перезагрузки
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка при сохранении данных');
            }
            
            return false;
        }
    </script>
</body>
</html>
