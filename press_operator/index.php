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

// Подключение к отдельной БД для пресса (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$pressDbConfig = [
    'host' => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    'user' => defined('DB_USER') ? DB_USER : 'root',
    'pass' => defined('DB_PASS') ? DB_PASS : '',
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
        $box_name = trim((string)($_POST['box_name'] ?? ''));
        $quantity = (int)($_POST['quantity'] ?? 0);
        $operator_name = $_POST['operator_name'] ?? ($user['username'] ?? 'unknown');
        $notes = $_POST['notes'] ?? '';
        
        $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
        
        if ($mysqli->connect_errno) {
            echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД']);
            exit;
        }
        
        if ($action === 'add_die_cut') {
            if ($shift_date === '' || $box_name === '' || $quantity <= 0) {
                echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
                $mysqli->close();
                exit;
            }
            $stmt = $mysqli->prepare("
                INSERT INTO press_die_cut_blanks (shift_date, brand_name, box_name, quantity, operator_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssisss", $shift_date, $brand_name, $box_name, $quantity, $operator_name, $notes, $operator_name);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $mysqli->insert_id]);
            } else {
                echo json_encode(['success' => false, 'error' => $mysqli->error]);
            }
            $stmt->close();
        } elseif ($action === 'add_glued') {
            if ($shift_date === '' || $box_name === '' || $quantity <= 0) {
                echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
                $mysqli->close();
                exit;
            }
            $stmt = $mysqli->prepare("
                INSERT INTO press_glued_boxes (shift_date, brand_name, box_name, quantity, operator_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssisss", $shift_date, $brand_name, $box_name, $quantity, $operator_name, $notes, $operator_name);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'id' => $mysqli->insert_id]);
            } else {
                echo json_encode(['success' => false, 'error' => $mysqli->error]);
            }
            $stmt->close();
        } elseif ($action === 'delete_die_cut') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Неверный ID']);
                $mysqli->close();
                exit;
            }
            $stmt = $mysqli->prepare("DELETE FROM press_die_cut_blanks WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $mysqli->error]);
            }
            $stmt->close();
        } elseif ($action === 'delete_glued') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Неверный ID']);
                $mysqli->close();
                exit;
            }
            $stmt = $mysqli->prepare("DELETE FROM press_glued_boxes WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $mysqli->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
            $mysqli->close();
            exit;
        }
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

$dieCutTotal = array_sum(array_column($shiftData['die_cut'], 'quantity'));
$gluedTotal = array_sum(array_column($shiftData['glued'], 'quantity'));

$canManageBoxes = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director'], true)) {
        $canManageBoxes = true;
        break;
    }
}

function renderBrandSelect(array $brandCatalog, string $defaultBrand = 'AF'): void
{
    foreach ($brandCatalog as $brand) {
        $name = (string)($brand['brand_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $selected = $name === $defaultBrand ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
            . htmlspecialchars($name) . '</option>';
    }
}

function renderBoxDatalist(array $boxCatalog, string $listId): void
{
    echo '<datalist id="' . htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($boxCatalog as $box) {
        $name = (string)($box['box_name'] ?? '');
        if ($name === '') {
            continue;
        }
        echo '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"></option>';
    }
    echo '</datalist>';
}

function renderShiftEntries(array $items, string $deleteHandler): void
{
    if (empty($items)) {
        echo '<div class="entry-empty">За смену пока нет записей</div>';
        return;
    }

    foreach ($items as $item) {
        $brand = trim((string)($item['brand_name'] ?? ''));
        $box = (string)($item['box_name'] ?? '');
        $qty = (int)($item['quantity'] ?? 0);
        $id = (int)($item['id'] ?? 0);
        $meta = trim($brand !== '' ? $brand . ' · ' . $box : $box);

        echo '<div class="entry-item">';
        echo '<span class="entry-item__name" title="' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($meta) . '</span>';
        echo '<span class="entry-item__qty">' . $qty . ' шт</span>';
        echo '<button type="button" class="btn-delete" onclick="' . htmlspecialchars($deleteHandler, ENT_QUOTES, 'UTF-8')
            . '(' . $id . ')" title="Удалить">×</button>';
        echo '</div>';
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Модуль оператора тигельного пресса</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            padding: 10px 8px 20px;
            color: #1f2937;
        }

        .container { max-width: 1100px; margin: 0 auto; }

        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 2px; }
        .header p { font-size: 12px; color: #6b7280; }

        .controls {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 10px;
        }

        .controls input[type="date"] {
            width: 100%;
            display: block;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #1f2937;
            cursor: pointer;
        }

        .controls__actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .controls__actions a {
            flex: 1 1 0;
            min-width: 0;
            white-space: nowrap;
            text-align: center;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #1f2937;
            text-decoration: none;
        }

        .controls input[type="date"]:focus { outline: none; border-color: #3b82f6; }
        .controls .btn-stats { background: #3498db; border-color: #2980b9; color: #fff; }
        .controls .btn-demand { background: #e67e22; border-color: #d35400; color: #fff; }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .work-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .work-card--die { border-top: 3px solid #3498db; }
        .work-card--glue { border-top: 3px solid #e67e22; }

        .work-card__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .work-card__title { font-size: 16px; font-weight: 700; color: #111827; line-height: 1.2; }
        .work-card__subtitle { font-size: 11px; color: #9ca3af; margin-top: 1px; display: none; }

        .work-card__total {
            font-size: 26px;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;
        }

        .work-card--die .work-card__total { color: #3498db; }
        .work-card--glue .work-card__total { color: #e67e22; }

        .entry-form {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 6px;
            align-items: stretch;
        }

        .field {
            display: block;
            min-width: 0;
        }

        .field-brand { flex: 0 0 96px; }
        .field-box { flex: 0 0 56px; }
        .field-qty { flex: 0 0 80px; }

        .field-label {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .field select,
        .field input {
            display: block;
            width: 100%;
            height: 42px;
            padding: 0 8px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
            color: #111827;
            font-size: 16px;
            line-height: 42px;
        }

        .field input[type="number"] {
            text-align: center;
            padding: 0 4px;
        }

        .field select {
            padding-right: 4px;
        }

        .field-brand select {
            text-align: left;
            text-align-last: left;
            padding-left: 6px;
        }

        .field-box input {
            text-align: center;
            padding: 0 4px;
        }

        .field select:focus,
        .field input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .btn-add {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            padding: 0;
            border: none;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            font-size: 26px;
            font-weight: 500;
            line-height: 1;
            cursor: pointer;
        }

        .work-card--die .btn-add { background: #2563eb; }
        .work-card--glue .btn-add { background: #ea580c; }

        .btn-add:active { transform: scale(0.97); }

        .entry-list {
            border-top: 1px solid #eef2f7;
            padding-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            max-height: 240px;
            overflow-y: auto;
        }

        .entry-empty {
            padding: 6px 2px;
            font-size: 12px;
            color: #9ca3af;
        }

        .entry-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 6px;
            align-items: center;
            padding: 6px 8px;
            border-radius: 8px;
            background: #f9fafb;
            font-size: 13px;
        }

        .entry-item__name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
        }

        .entry-item__qty {
            font-weight: 700;
            color: #111827;
            white-space: nowrap;
        }

        .btn-delete {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 22px;
            line-height: 1;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #9ca3af;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-delete:hover,
        .btn-delete:active {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        @media (max-width: 900px) {
            .main-grid { grid-template-columns: 1fr; }
        }

        @media (min-width: 768px) {
            body { padding: 16px 12px 24px; }
            .header { margin-bottom: 14px; }
            .header h1 { font-size: 24px; }
            .header p { font-size: 14px; }
            .controls { margin-bottom: 14px; }
            .controls__actions { max-width: 640px; margin: 0 auto; width: 100%; }
            .work-card { padding: 14px; gap: 10px; }
            .work-card__total { font-size: 32px; }
            .work-card__subtitle { display: block; }
            .field-brand { flex-basis: 112px; }
            .field-box { flex-basis: 64px; }
            .field-qty { flex-basis: 92px; }
            .btn-add { flex-basis: 46px; width: 46px; height: 46px; }
            .field select,
            .field input { height: 46px; line-height: 46px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Модуль оператора</h1>
            <p>Тигельный пресс — учёт за смену</p>
        </div>

        <div class="controls">
            <input type="date" id="shift-date" value="<?= htmlspecialchars($currentDate) ?>" onchange="updatePage()">
            <div class="controls__actions">
                <a href="statistics.php" class="btn-stats">Статистика</a>
                <a href="box_demand.php" class="btn-demand">Потребность</a>
                <?php if ($canManageBoxes): ?>
                    <a href="manage_boxes.php">Справочники</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="main-grid">
            <section class="work-card work-card--die">
                <div class="work-card__head">
                    <div>
                        <div class="work-card__title">Высечка</div>
                        <div class="work-card__subtitle">Заготовки за смену</div>
                    </div>
                    <div class="work-card__total"><?= (int)$dieCutTotal ?></div>
                </div>

                <form id="dieCutForm" class="entry-form" onsubmit="return submitDieCut(event)">
                    <label class="field field-brand">
                        <span class="field-label">Бренд</span>
                        <select name="brand_name" required aria-label="Бренд">
                            <?php renderBrandSelect($brandCatalog); ?>
                        </select>
                    </label>
                    <label class="field field-box">
                        <span class="field-label">Коробка</span>
                        <input type="text" name="box_name" list="box-list-die" required
                               aria-label="Номер коробки" placeholder="№" autocomplete="off" autocapitalize="off" spellcheck="false">
                    </label>
                    <label class="field field-qty">
                        <span class="field-label">Количество</span>
                        <input type="number" name="quantity" required min="1" inputmode="numeric"
                               aria-label="Количество" placeholder="шт">
                    </label>
                    <button type="submit" class="btn-add" aria-label="Добавить заготовки">+</button>
                    <input type="hidden" name="operator_name" value="<?= htmlspecialchars($user['username'] ?? '') ?>">
                </form>
                <?php renderBoxDatalist($boxCatalog, 'box-list-die'); ?>

                <div class="entry-list">
                    <?php renderShiftEntries($shiftData['die_cut'], 'deleteDieCut'); ?>
                </div>
            </section>

            <section class="work-card work-card--glue">
                <div class="work-card__head">
                    <div>
                        <div class="work-card__title">Поклейка</div>
                        <div class="work-card__subtitle">Коробки за смену</div>
                    </div>
                    <div class="work-card__total"><?= (int)$gluedTotal ?></div>
                </div>

                <form id="gluedForm" class="entry-form" onsubmit="return submitGlued(event)">
                    <label class="field field-brand">
                        <span class="field-label">Бренд</span>
                        <select name="brand_name" required aria-label="Бренд">
                            <?php renderBrandSelect($brandCatalog); ?>
                        </select>
                    </label>
                    <label class="field field-box">
                        <span class="field-label">Коробка</span>
                        <input type="text" name="box_name" list="box-list-glue" required
                               aria-label="Номер коробки" placeholder="№" autocomplete="off" autocapitalize="off" spellcheck="false">
                    </label>
                    <label class="field field-qty">
                        <span class="field-label">Количество</span>
                        <input type="number" name="quantity" required min="1" inputmode="numeric"
                               aria-label="Количество" placeholder="шт">
                    </label>
                    <button type="submit" class="btn-add" aria-label="Добавить коробки">+</button>
                    <input type="hidden" name="operator_name" value="<?= htmlspecialchars($user['username'] ?? '') ?>">
                </form>
                <?php renderBoxDatalist($boxCatalog, 'box-list-glue'); ?>

                <div class="entry-list">
                    <?php renderShiftEntries($shiftData['glued'], 'deleteGlued'); ?>
                </div>
            </section>
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
                    form.reset();
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
                    form.reset();
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
        
        async function deleteDieCut(id) {
            if (!confirm('Вы уверены, что хотите удалить эту позицию?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_die_cut');
                formData.append('id', id);
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка при удалении позиции');
            }
        }
        
        async function deleteGlued(id) {
            if (!confirm('Вы уверены, что хотите удалить эту позицию?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_glued');
                formData.append('id', id);
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка при удалении позиции');
            }
        }
    </script>
</body>
</html>
