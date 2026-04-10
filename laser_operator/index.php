<?php
/**
 * Модуль оператора лазерной резки
 * Централизованное управление заявками со всех участков
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

// Проверяем, есть ли доступ к модулю оператора лазера
$hasLaserOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
        $hasLaserOperatorAccess = true;
        break;
    }
}

if (!$hasLaserOperatorAccess) {
    die("У вас нет доступа к модулю оператора лазерной резки");
}

// Создаем таблицу для хранения информации об операторе на день (если её нет)
try {
    $pdo = $db->getConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS laser_operator_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL UNIQUE,
        operator_id INT NOT NULL,
        operator_surname VARCHAR(255) NOT NULL,
        operator_full_name VARCHAR(255) NOT NULL,
        first_login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Ошибка создания таблицы laser_operator_daily: " . $e->getMessage());
}

// Сохраняем фамилию оператора при первом входе утром
try {
    $today = date('Y-m-d');
    
    // Проверяем, есть ли уже запись на сегодня
    $existing = $db->selectOne("SELECT id FROM laser_operator_daily WHERE date = ?", [$today]);
    
    if (!$existing) {
        // Извлекаем фамилию из полного имени
        $fullName = $user['full_name'] ?? '';
        $nameParts = explode(' ', trim($fullName));
        $surname = !empty($nameParts[0]) ? $nameParts[0] : $fullName;
        
        // Сохраняем информацию об операторе на сегодня
        $db->insert("INSERT INTO laser_operator_daily (date, operator_id, operator_surname, operator_full_name) 
                     VALUES (?, ?, ?, ?)", 
                     [
                         $today,
                         $session['user_id'],
                         $surname,
                         $fullName
                     ]);
    }
} catch (Exception $e) {
    // Игнорируем ошибки, чтобы не блокировать работу модуля
    error_log("Ошибка сохранения оператора на день: " . $e->getMessage());
}

// Настройки подключений к базам данных всех участков (из env.php)
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
$databases = [
    'U2' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan'],
    'U3' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u3'],
    'U4' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u4'],
    'U5' => ['host' => $dbHost, 'user' => $dbUser, 'pass' => $dbPass, 'name' => 'plan_u5']
];

// === Автомиграция: добавляем необходимые поля во все БД ===
foreach ($databases as $dept => $dbConfig) {
    try {
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        if (!$mysqli->connect_errno) {
            // Проверяем существование поля progress_count
            $result = $mysqli->query("SHOW COLUMNS FROM laser_requests LIKE 'progress_count'");
            if ($result && $result->num_rows === 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN progress_count INT NOT NULL DEFAULT 0 AFTER quantity");
            }
            
            // Проверяем существование поля is_cancelled
            $result = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'is_cancelled'");
            if ($result && $result->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN is_cancelled BOOLEAN DEFAULT FALSE AFTER is_completed");
            }
            
            // Проверяем существование поля cancelled_at
            $result = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'cancelled_at'");
            if ($result && $result->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN cancelled_at TIMESTAMP NULL AFTER is_cancelled");
            }
            
            // Проверяем существование поля completed_by
            $result = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'completed_by'");
            if ($result && $result->fetch_row()[0] == 0) {
                $mysqli->query("ALTER TABLE laser_requests ADD COLUMN completed_by VARCHAR(255) NULL AFTER completed_at");
            }
            
            $mysqli->close();
        }
    } catch (Exception $e) {
        // Игнорируем ошибки миграции
        error_log("Migration error for {$dept}: " . $e->getMessage());
    }
}

// Функция для получения всех заявок из всех баз данных
function getAllLaserRequests($databases) {
    $allRequests = [];
    
    foreach ($databases as $department => $dbConfig) {
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if ($mysqli->connect_errno) {
            error_log("Ошибка подключения к БД {$department}: " . $mysqli->connect_error);
            continue;
        }
        
        // Проверяем существование колонки is_cancelled перед использованием
        $hasCancelledColumn = false;
        $checkColumn = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laser_requests' AND COLUMN_NAME = 'is_cancelled'");
        if ($checkColumn && $checkColumn->fetch_row()[0] > 0) {
            $hasCancelledColumn = true;
        }
        
        // Получаем заявки из текущей БД (исключаем отмененные, если колонка существует)
        if ($hasCancelledColumn) {
            $sql = "SELECT id, user_name, department, component_name, quantity, progress_count, desired_delivery_time, is_completed, completed_at, created_at, '{$department}' as source_department FROM laser_requests WHERE (is_cancelled = FALSE OR is_cancelled IS NULL) ORDER BY created_at DESC";
        } else {
            $sql = "SELECT id, user_name, department, component_name, quantity, progress_count, desired_delivery_time, is_completed, completed_at, created_at, '{$department}' as source_department FROM laser_requests ORDER BY created_at DESC";
        }
        $result = $mysqli->query($sql);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $allRequests[] = $row;
            }
        }
        
        $mysqli->close();
    }
    
    // Сортируем все заявки по дате создания (новые сначала)
    usort($allRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $allRequests;
}

// === API для обновления прогресса заявки ===
if (isset($_POST['action']) && $_POST['action'] === 'update_progress') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $department = $_POST['department'] ?? '';
        $progress = (int)($_POST['progress'] ?? 0);
        
        if ($request_id <= 0 || $department === '' || !isset($databases[$department])) {
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }
        
        $dbConfig = $databases[$department];
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if ($mysqli->connect_errno) {
            echo json_encode(['success' => false, 'error' => 'Ошибка подключения к БД']);
            exit;
        }
        
        $stmt = $mysqli->prepare("UPDATE laser_requests SET progress_count = ? WHERE id = ?");
        $stmt->bind_param("ii", $progress, $request_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'progress' => $progress]);
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

// Обработка отметки выполнения заявки
if (isset($_POST['action']) && $_POST['action'] === 'mark_completed' && isset($_POST['request_id']) && isset($_POST['department'])) {
    $request_id = (int)$_POST['request_id'];
    $department = $_POST['department'];
    
    // Получаем полное имя текущего пользователя для сохранения в completed_by
    // Используем полное имя, чтобы потом можно было извлечь фамилию
    $operatorLogin = $user['full_name'] ?? ($user['phone'] ?? 'Неизвестно');
    
    if (isset($databases[$department])) {
        $dbConfig = $databases[$department];
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);
        
        if (!$mysqli->connect_errno) {
            // Обновляем статус заявки с сохранением логина оператора
            $update_sql = "UPDATE laser_requests SET is_completed = TRUE, completed_at = NOW(), completed_by = ? WHERE id = ?";
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param("si", $operatorLogin, $request_id);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Заявка отмечена как выполненная!";
            } else {
                $_SESSION['error_message'] = "Ошибка при обновлении заявки";
            }
            
            $stmt->close();
            $mysqli->close();
        }
    }
    
    // Редирект для предотвращения повторной отправки при обновлении страницы
    header('Location: index.php');
    exit;
}

// Заявка оператора себе на поддержание складского запаса (в БД выбранного участка)
if (isset($_POST['action']) && $_POST['action'] === 'create_operator_stock_request') {
    $target_dept = $_POST['target_department'] ?? '';
    $component_type = trim($_POST['stock_component_type'] ?? '');
    $component_detail = trim($_POST['stock_component_detail'] ?? '');
    $quantity = (int)($_POST['stock_quantity'] ?? 0);
    $desired_delivery_date = trim($_POST['stock_delivery_date'] ?? '');
    $desired_delivery_hour = trim($_POST['stock_delivery_hour'] ?? '');

    if (!isset($databases[$target_dept])) {
        $_SESSION['error_message'] = 'Выберите участок для заявки.';
    } elseif ($component_type === '' || $quantity <= 0) {
        $_SESSION['error_message'] = 'Выберите тип комплектующего и укажите количество больше нуля.';
    } else {
        $component_name = $component_type;
        if ($component_detail !== '') {
            $component_name .= ' - ' . $component_detail;
        }
        $component_name = '[Складской запас] ' . $component_name;
        $datetime = null;
        if ($desired_delivery_date !== '' && $desired_delivery_hour !== '') {
            // type="time" отдаёт HH:MM или HH:MM:SS — не дописываем :00:00 (будет 12:15:00:00 и ошибка MySQL)
            try {
                $dt = new DateTime($desired_delivery_date . 'T' . $desired_delivery_hour);
                $datetime = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $datetime = null;
            }
        }

        $operatorName = $user['full_name'] ?? ($user['phone'] ?? 'Оператор лазера');

        $dbConfig = $databases[$target_dept];
        $mysqli = new mysqli($dbConfig['host'], $dbConfig['user'], $dbConfig['pass'], $dbConfig['name']);

        if ($mysqli->connect_errno) {
            $_SESSION['error_message'] = 'Ошибка подключения к базе участка ' . htmlspecialchars($target_dept);
        } else {
            if ($datetime !== null) {
                $ins = $mysqli->prepare('INSERT INTO laser_requests (user_name, department, component_name, quantity, desired_delivery_time) VALUES (?, ?, ?, ?, ?)');
                $ins->bind_param('sssis', $operatorName, $target_dept, $component_name, $quantity, $datetime);
            } else {
                $ins = $mysqli->prepare('INSERT INTO laser_requests (user_name, department, component_name, quantity, desired_delivery_time) VALUES (?, ?, ?, ?, NULL)');
                $ins->bind_param('sssi', $operatorName, $target_dept, $component_name, $quantity);
            }

            if ($ins->execute()) {
                $_SESSION['success_message'] = 'Заявка на поддержание складского запаса создана (' . $target_dept . ').';
            } else {
                $_SESSION['error_message'] = 'Не удалось сохранить заявку: ' . $mysqli->error;
            }
            $ins->close();
            $mysqli->close();
        }
    }

    header('Location: index.php');
    exit;
}

// Получаем сообщения из сессии
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Получаем все заявки
$allRequests = getAllLaserRequests($databases);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Модуль оператора лазерной резки</title>
    <style>
        :root {
            --bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
        }
        
        body {
            margin: 0;
            background: var(--bg-solid);
            color: var(--ink);
            font: 16px/1.6 "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            position: relative;
        }
        
        .mini-info-btn {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: none;
            background: var(--accent-solid);
            color: var(--accent-ink);
            font-weight: 800;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
            transition: transform 0.15s ease, opacity 0.15s ease;
        }

        .mini-info-btn:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .header h1 {
            margin: 0 0 2px 0;
            color: var(--ink);
            font-size: 18px;
            font-weight: 700;
        }
        
        .header p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }
        
        .panel {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .section-title-row {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }

        .section-title {
            color: var(--ink);
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            flex: 1;
            min-width: 0;
        }

        .stock-request-open {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            margin-top: 2px;
            padding: 0;
            border: none;
            border-radius: 8px;
            background: var(--accent-solid);
            color: var(--accent-ink);
            font-size: 20px;
            line-height: 1;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.35);
            transition: transform 0.15s ease, opacity 0.15s ease;
        }

        .stock-request-open:hover {
            opacity: 0.92;
            transform: scale(1.06);
        }

        .stock-form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        @media (max-width: 480px) {
            .stock-form-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }

        .modal-overlay.is-open {
            display: flex;
        }

        .modal-box {
            background: var(--panel);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            max-width: 440px;
            width: 100%;
            padding: 22px 22px 18px;
            position: relative;
        }

        .modal-box h3 {
            margin: 0 0 6px 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
        }

        .modal-box .modal-hint {
            margin: 0 0 18px 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }

        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: var(--muted);
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: #f1f5f9;
            color: var(--ink);
        }

        .modal-field {
            margin-bottom: 14px;
        }

        .modal-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 6px;
        }

        .modal-field input,
        .modal-field select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-family: inherit;
        }

        .modal-field input:focus,
        .modal-field select:focus {
            outline: none;
            border-color: var(--accent-solid);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.12);
        }

        .modal-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn-modal-secondary {
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--ink);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-modal-primary {
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            border: none;
            background: var(--accent);
            color: var(--accent-ink);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-modal-primary:hover,
        .btn-modal-secondary:hover {
            opacity: 0.92;
        }
        
        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
            -webkit-overflow-scrolling: touch;
        }
        
        .requests-table {
            width: 100%;
            min-width: 400px;
            border-collapse: collapse;
        }
        
        .requests-table th,
        .requests-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        
        .requests-table th {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .status-completed {
            color: #059669;
            font-weight: 500;
        }
        
        .status-pending {
            color: #d97706;
            font-weight: 500;
        }
        
        .btn-complete {
            background: var(--accent);
            color: var(--accent-ink);
            border: none;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-complete:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .department-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .department-U2 { background: #dbeafe; color: #1e40af; }
        .department-U3 { background: #dcfce7; color: #166534; }
        .department-U4 { background: #fef3c7; color: #92400e; }
        .department-U5 { background: #fce7f3; color: #be185d; }
        
        /* Прогресс */
        .progress-cell {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }
        
        .progress-input {
            width: 70px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
            font-size: 14px;
        }
        
        .progress-input:focus {
            outline: none;
            border-color: var(--accent-solid);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .progress-total {
            color: var(--muted);
            font-size: 14px;
            font-weight: 500;
        }
        
        .btn-save-progress {
            background: var(--accent-solid);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .btn-save-progress:hover {
            opacity: 0.9;
            transform: scale(1.05);
        }
        
        .progress-bar-container {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
        }
        
        .success-message {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            display: none;
        }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease-in-out;
            max-width: 300px;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .error-message {
            background: #fecaca;
            border: 1px solid #f87171;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
        }
        
        .filters {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--ink);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-btn.active {
            background: var(--accent-solid);
            color: white;
            border-color: var(--accent-solid);
        }
        
        .filter-btn:hover {
            background: var(--border);
        }
        
        .filter-btn.detailed-btn {
            background: var(--accent-solid) !important;
            color: var(--accent-ink) !important;
            border-color: var(--accent-solid) !important;
            font-weight: 600;
        }
        
        .filter-btn.detailed-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        /* Виджет статистики */
        .statistics-widget {
            background: var(--panel);
            border-radius: var(--radius);
            padding: 12px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .statistics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .statistics-title {
            color: var(--ink);
            font-size: 14px;
            font-weight: 600;
            margin: 0;
        }
        
        .statistics-days-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
        }
        
        .statistics-day-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            transition: all 0.2s ease;
        }
        
        .statistics-day-card:hover {
            border-color: var(--accent-solid);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
        }
        
        .statistics-day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        
        .statistics-day-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink);
            margin: 0;
        }
        
        .statistics-day-date {
            font-size: 10px;
            color: var(--muted);
        }
        
        .statistics-day-summary {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            padding: 8px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 6px;
        }
        
        .statistics-day-summary-item {
            flex: 1;
            text-align: center;
        }
        
        .statistics-day-summary-value {
            font-size: 14px;
            font-weight: 700;
            color: var(--accent-solid);
            margin-bottom: 2px;
            line-height: 1.2;
        }
        
        .statistics-day-summary-label {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 500;
            line-height: 1.2;
        }
        
        .statistics-day-empty {
            text-align: center;
            color: var(--muted);
            padding: 12px;
            font-size: 11px;
        }
        
        .statistics-loading {
            text-align: center;
            color: var(--muted);
            padding: 20px;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 12px;
            }
            
            .requests-table th,
            .requests-table td {
                padding: 8px;
                font-size: 14px;
            }
            
            .requests-table {
                min-width: 500px;
            }
            
            .statistics-days-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 8px;
            }
            
            .statistics-widget {
                padding: 10px;
            }
            
            .statistics-day-card {
                padding: 8px;
            }
            
            .statistics-day-summary-value {
                font-size: 12px;
            }
            
            .statistics-day-summary-label {
                font-size: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <button type="button" class="mini-info-btn" onclick="window.location.href='items_statistics.php'" title="Статистика заказанных изделий по лазеру">i</button>
            <h1>Модуль оператора лазерной резки</h1>
            <p>Управление заявками со всех участков производства</p>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div id="toast" class="toast"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        
        <!-- Виджет статистики -->
        <div class="statistics-widget">
            <div class="statistics-header">
                <h2 class="statistics-title">Статистика за последние 6 дней</h2>
            </div>
            <div id="statistics-content" class="statistics-loading">
                Загрузка статистики...
            </div>
        </div>
        
        <div class="panel">
            <div class="section-title-row">
                <div class="section-title">
                    Все заявки на лазерную резку
                    <span id="status-indicator" style="font-size: 12px; color: var(--muted); margin-left: 10px;">
                        <span id="connection-status">🟢 Активно</span>
                        <span id="last-update" style="margin-left: 10px;"></span>
                    </span>
                </div>
                <button type="button"
                        class="stock-request-open"
                        id="openStockRequestModal"
                        title="Заявка на поддержание складского запаса (себе)"
                        aria-label="Создать заявку на поддержание складского запаса">+</button>
            </div>
            
            <div class="filters">
                <button class="filter-btn detailed-btn" onclick="window.open('detailed.php', '_blank')">Подробно</button>
                <button class="filter-btn" onclick="filterRequests('all')">Все заявки</button>
                <button class="filter-btn active" onclick="filterRequests('pending')">В работе</button>
                <button class="filter-btn" onclick="filterRequests('completed')">Выполнено</button>
            </div>
            
            <div class="table-wrapper">
                <table class="requests-table">
                    <thead>
                        <tr>
                            <th>Участок</th>
                            <th>Подал заявку</th>
                            <th>Комплектующие</th>
                            <th>Прогресс</th>
                            <th>Дата подачи</th>
                            <th>Время поставки</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="requestsTableBody">
                        <?php if (count($allRequests) > 0): ?>
                            <?php foreach ($allRequests as $request): 
                                $progress = (int)($request['progress_count'] ?? 0);
                                $total = (int)$request['quantity'];
                                $progressPercent = $total > 0 ? round(($progress / $total) * 100) : 0;
                            ?>
                                <tr data-status="<?= $request['is_completed'] ? 'completed' : 'pending' ?>" 
                                    data-department="<?= $request['source_department'] ?>"
                                    data-request-id="<?= $request['id'] ?>">
                                    <td><span class="department-badge department-<?= $request['source_department'] ?>"><?= $request['source_department'] ?></span></td>
                                    <td><?= htmlspecialchars($request['user_name'] ?? 'Не указано') ?></td>
                                    <td><?= htmlspecialchars($request['component_name']) ?></td>
                                    <td>
                                        <?php if (!$request['is_completed']): ?>
                                            <div class="progress-cell">
                                                <input type="number" 
                                                       class="progress-input" 
                                                       value="<?= $progress > 0 ? $progress : '' ?>"
                                                       placeholder="0"
                                                       min="0" 
                                                       max="<?= $total ?>"
                                                       data-request-id="<?= $request['id'] ?>"
                                                       data-department="<?= $request['source_department'] ?>"
                                                       oninput="updateProgressBar(this)"
                                                       onkeydown="if(event.key === 'Enter') saveProgress(this)">
                                                <span class="progress-total">/ <?= $total ?></span>
                                                <button type="button" class="btn-save-progress" onclick="saveProgress(this.previousElementSibling.previousElementSibling)" title="Сохранить">✓</button>
                                            </div>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill" style="width: <?= $progressPercent ?>%"></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="status-completed"><?= $progress ?> / <?= $total ?> (100%)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($request['created_at']): ?>
                                            <?= date('d.m.Y H:i', strtotime($request['created_at'])) ?>
                                        <?php else: ?>
                                            Не указано
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($request['desired_delivery_time']): ?>
                                            <?= date('d.m.Y H:i', strtotime($request['desired_delivery_time'])) ?>
                                        <?php else: ?>
                                            Не указано
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$request['is_completed']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="mark_completed">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <input type="hidden" name="department" value="<?= $request['source_department'] ?>">
                                                <button type="submit" class="btn-complete">
                                                    Выполнено
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="status-completed">✓</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--muted); padding: 40px;">
                                    Нет заявок на лазерную резку
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="stockRequestModal" role="dialog" aria-modal="true" aria-labelledby="stockModalTitle">
        <div class="modal-box">
            <button type="button" class="modal-close" id="closeStockRequestModal" aria-label="Закрыть">×</button>
            <h3 id="stockModalTitle">Заявка на складской запас</h3>
            <p class="modal-hint">Заявка создаётся от вашего имени в базе выбранного участка — для поддержания запаса на складе.</p>
            <form method="POST" action="index.php" id="stockRequestForm">
                <input type="hidden" name="action" value="create_operator_stock_request">
                <div class="modal-field">
                    <label for="target_department">Участок (куда пишем заявку)</label>
                    <select name="target_department" id="target_department" required>
                        <option value="" disabled selected>— выберите —</option>
                        <option value="U2">U2</option>
                        <option value="U3">U3</option>
                        <option value="U4">U4</option>
                        <option value="U5">U5</option>
                    </select>
                </div>
                <div class="modal-field">
                    <div class="stock-form-grid-2">
                        <div>
                            <label for="stock_component_type">Комплектующее</label>
                            <select name="stock_component_type" id="stock_component_type" required>
                                <option value="">Выберите тип</option>
                                <option value="Фланец">Фланец</option>
                                <option value="Вставка">Вставка</option>
                                <option value="Язычек">Язычек</option>
                                <option value="Рамка">Рамка</option>
                                <option value="Коробка">Коробка</option>
                                <option value="Ящик">Ящик</option>
                                <option value="Поролон">Поролон</option>
                                <option value="Форсунка">Форсунка</option>
                                <option value="Боковая лента">Боковая лента</option>
                            </select>
                        </div>
                        <div>
                            <label for="stock_component_detail">Номер</label>
                            <input type="text" name="stock_component_detail" id="stock_component_detail"
                                   placeholder="Введите номер…" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-field">
                    <label for="stock_quantity">Количество штук</label>
                    <input type="number" name="stock_quantity" id="stock_quantity" required
                           min="1" step="1" value="1" placeholder="Количество">
                </div>
                <div class="modal-field">
                    <label>Желаемое время готовности (необязательно)</label>
                    <div class="modal-row-2">
                        <input type="date" name="stock_delivery_date" id="stock_delivery_date" aria-label="Дата">
                        <input type="time" name="stock_delivery_hour" id="stock_delivery_hour" aria-label="Время">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal-secondary" id="cancelStockRequestModal">Отмена</button>
                    <button type="submit" class="btn-modal-primary">Создать заявку</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let lastCheckTimestamp = <?= time() ?>;
        let isUpdating = false;
        let retryCount = 0;
        const maxRetries = 3;
        
        // Функция для воспроизведения звука уведомления
        function playNotificationSound() {
            try {
                // Создаем аудио контекст для веб-уведомлений
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                
                // Создаем простой звуковой сигнал
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                // Настройки звука
                oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
                oscillator.frequency.setValueAtTime(600, audioContext.currentTime + 0.1);
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.3);
            } catch (e) {
                console.log('Audio not available:', e);
                // Fallback - попытаемся использовать HTML audio элемент
                try {
                    const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj2Z2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkFJHfH8N2QQAoUXrTp66hVFApGn+DyvmsbCzaL0PLNeSkF');
                    audio.play().catch(() => {});
                } catch (e2) {
                    console.log('Fallback audio also failed:', e2);
                }
            }
        }
        
        // Функция для обновления данных таблицы
        async function updateTable() {
            if (isUpdating) return;
            isUpdating = true;
            
            // Сохраняем текущие несохраненные значения перед обновлением
            savePendingProgress();
            
            // Обновляем индикатор состояния
            updateConnectionStatus('🟡 Обновление...');
            
            try {
                const response = await fetch(`api/get_requests.php?last_check=${lastCheckTimestamp}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    cache: 'no-cache'
                });
                
                if (!response.ok) {
                    // Читаем текст ошибки для диагностики
                    let errorText = '';
                    try {
                        errorText = await response.text();
                        console.error('Server error response:', errorText);
                    } catch (e) {
                        errorText = 'Не удалось прочитать ответ сервера';
                    }
                    
                    throw new Error(`HTTP ${response.status}: ${response.statusText}. Ответ: ${errorText.substring(0, 100)}`);
                }
                
                let data;
                try {
                    data = await response.json();
                } catch (jsonError) {
                    throw new Error(`Ошибка разбора JSON: ${jsonError.message}`);
                }
                
                // Проверяем структуру ответа
                if (!data || typeof data !== 'object') {
                    throw new Error('Некорректный ответ от сервера');
                }
                
                if (data.error) {
                    console.error('API Error:', data.error);
                    updateConnectionStatus(`🔴 Ошибка: ${data.error}`);
                    return;
                }
                
                // Обрабатываем ошибки базы данных
                if (data.errors && data.errors.length > 0) {
                    console.warn('Database errors:', data.errors);
                    // Не показываем ошибки БД как критичные, но логируем их
                }
                
                // Проверяем наличие данных
                if (data.requests && Array.isArray(data.requests)) {
                    updateTableContent(data.requests);
                }
                
                // Если есть новые заявки, обрабатываем их
                if (data.has_new && data.new_requests && Array.isArray(data.new_requests)) {
                    data.new_requests.forEach(request => {
                        console.log('Новая заявка:', request);
                        playNotificationSound(); // Воспроизводим звук
                    });
                    
                    // Показываем уведомление пользователю
                    if (data.new_requests.length > 0) {
                        showNotification(`Получено ${data.new_requests.length} новых заявок`);
                    }
                }
                
                if (data.timestamp) {
                    lastCheckTimestamp = data.timestamp;
                }
                updateConnectionStatus('🟢 Активно');
                updateLastUpdateTime();
                retryCount = 0; // Сбрасываем счетчик повторов при успешном запросе
                
            } catch (error) {
                console.error('Error updating table:', error);
                console.error('Error details:', {
                    message: error.message,
                    stack: error.stack,
                    userAgent: navigator.userAgent,
                    url: window.location.href,
                    retryCount: retryCount
                });
                
                retryCount++;
                if (retryCount <= maxRetries) {
                    updateConnectionStatus(`🟡 Повтор ${retryCount}/${maxRetries}: ${error.message.substring(0, 20)}...`);
                    console.log(`Retrying in 3 seconds... (${retryCount}/${maxRetries})`);
                    
                    isUpdating = false; // Освобождаем флаг перед retry
                    setTimeout(() => {
                        updateTable();
                    }, 3000);
                } else {
                    updateConnectionStatus(`🔴 Ошибка: ${error.message}`);
                    retryCount = 0; // Сбрасываем счетчик после максимального количества попыток
                    isUpdating = false; // Освобождаем флаг при окончательной ошибке
                    
                    // Показываем детальную ошибку пользователю
                    alert(`Ошибка соединения: ${error.message}\n\nПроверьте:\n1. Интернет соединение\n2. Нажмите кнопку "Тест" для диагностики`);
                }
            } finally {
                if (retryCount >= maxRetries || retryCount === 0) {
                    isUpdating = false;
                }
            }
        }
        
        // Функция для обновления статуса подключения
        function updateConnectionStatus(status) {
            const statusElement = document.getElementById('connection-status');
            if (statusElement) {
                statusElement.textContent = status;
            }
        }
        
        // Функция для обновления времени последнего обновления
        function updateLastUpdateTime() {
            const lastUpdateElement = document.getElementById('last-update');
            if (lastUpdateElement) {
                lastUpdateElement.textContent = `Обновлено: ${new Date().toLocaleTimeString('ru-RU')}`;
            }
        }
        
        // Функция для обновления содержимого таблицы
        function updateTableContent(requests) {
            const tbody = document.getElementById('requestsTableBody');
            
            if (requests.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--muted); padding: 40px;">
                            Нет заявок на лазерную резку
                        </td>
                    </tr>
                `;
                return;
            }
            
            const html = requests.map(request => {
                const department = request.source_department;
                const isCompleted = request.is_completed == 1;
                const statusClass = isCompleted ? 'status-completed' : 'status-pending';
                const statusText = isCompleted ? 'Выполнено' : 'В работе';
                
                const deliveryTime = request.desired_delivery_time 
                    ? new Date(request.desired_delivery_time).toLocaleString('ru-RU')
                    : 'Не указано';
                
                const createdTime = new Date(request.created_at).toLocaleString('ru-RU');
                const userName = request.user_name ? escapeHtml(request.user_name) : 'Не указано';
                
                const progress = parseInt(request.progress_count) || 0;
                const total = parseInt(request.quantity) || 1;
                const progressPercent = Math.round((progress / total) * 100);
                
                let progressHtml;
                if (!isCompleted) {
                    progressHtml = `
                        <div class="progress-cell">
                            <input type="number" 
                                   class="progress-input" 
                                   value="${progress > 0 ? progress : ''}"
                                   placeholder="0"
                                   min="0" 
                                   max="${total}"
                                   data-request-id="${request.id}"
                                   data-department="${request.source_department}"
                                   oninput="updateProgressBar(this)"
                                   onkeydown="if(event.key === 'Enter') saveProgress(this)">
                            <span class="progress-total">/ ${total}</span>
                            <button type="button" class="btn-save-progress" onclick="saveProgress(this.previousElementSibling.previousElementSibling)" title="Сохранить">✓</button>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: ${progressPercent}%"></div>
                        </div>
                    `;
                } else {
                    progressHtml = `<span class="status-completed">${progress} / ${total} (100%)</span>`;
                }
                
                let actionHtml;
                if (!isCompleted) {
                    actionHtml = `
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="mark_completed">
                            <input type="hidden" name="request_id" value="${request.id}">
                            <input type="hidden" name="department" value="${request.source_department}">
                            <button type="submit" class="btn-complete">
                                Выполнено
                            </button>
                        </form>
                    `;
                } else {
                    actionHtml = '<span class="status-completed">✓</span>';
                }
                
                return `
                    <tr data-status="${isCompleted ? 'completed' : 'pending'}" 
                        data-department="${request.source_department}"
                        data-request-id="${request.id}">
                        <td><span class="department-badge department-${request.source_department}">${request.source_department}</span></td>
                        <td>${userName}</td>
                        <td>${escapeHtml(request.component_name)}</td>
                        <td>${progressHtml}</td>
                        <td>${createdTime}</td>
                        <td>${deliveryTime}</td>
                        <td>${actionHtml}</td>
                    </tr>
                `;
            }).join('');
            
            tbody.innerHTML = html;
            
            // Восстанавливаем несохраненные значения из localStorage
            restorePendingProgress();
            
            // Добавляем обработчики для автосохранения при вводе
            attachInputHandlers();
            
            // Восстанавливаем состояние фильтров после обновления таблицы
            restoreFilterState();
        }
        
        // === Добавление обработчиков для автосохранения ===
        function attachInputHandlers() {
            const progressInputs = document.querySelectorAll('.progress-input');
            progressInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Обновляем прогресс-бар в реальном времени (внутри также сохраняется в localStorage)
                    updateProgressBar(this);
                });
            });
        }
        
        // === Обновление прогресс-бара в реальном времени ===
        function updateProgressBar(inputElement) {
            const progress = parseInt(inputElement.value) || 0;
            const total = parseInt(inputElement.max) || 1;
            const percent = Math.round((progress / total) * 100);
            
            const row = inputElement.closest('tr');
            const progressBar = row.querySelector('.progress-bar-fill');
            
            if (progressBar) {
                progressBar.style.width = percent + '%';
            }
            
            // Также сохраняем в localStorage
            savePendingProgress();
        }
        
        // Функция для экранирования HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Функция для показа уведомлений
        function showNotification(message) {
            try {
                // Сначала пытаемся использовать стандартный Notification API
                if (typeof Notification !== 'undefined' && 'Notification' in window) {
                    showStandardNotification(message);
                } 
                // Если стандартный API не поддерживается, пытаемся Service Worker
                else if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.ready.then(registration => {
                        registration.showNotification('Новая заявка на лазер', {
                            body: message,
                            icon: '/favicon.ico',
                            badge: '/favicon.ico',
                            tag: 'laser-request'
                        });
                    }).catch(() => {
                        // Fallback на звуковое уведомление
                        console.log('Service Worker notification failed, using sound');
                        playNotificationSound();
                    });
                } 
                // Если ничего не поддерживается, используем звук
                else {
                    console.log('Уведомления не поддерживаются, используем звук:', message);
                    playNotificationSound();
                }
            } catch (error) {
                console.log('Ошибка показа уведомления:', error);
                // Fallback на звуковое уведомление
                playNotificationSound();
            }
        }

        // Функция для показа стандартных уведомлений
        function showStandardNotification(message) {
            try {
                // Дополнительная проверка поддержки Notification API
                if (typeof Notification === 'undefined' || !window.Notification) {
                    console.log('Notification API не поддерживается');
                    playNotificationSound();
                    return;
                }

                // Проверяем, можно ли создать уведомление
                try {
                    // Тестируем конструктор
                    if (Notification.permission === 'granted') {
                        const notification = new Notification('Новая заявка на лазер', {
                            body: message,
                            icon: '/favicon.ico'
                        });
                        
                        // Автоматически закрываем уведомление через 5 секунд
                        setTimeout(() => {
                            if (notification && typeof notification.close === 'function') {
                                notification.close();
                            }
                        }, 5000);
                        
                    } else if (Notification.permission !== 'denied') {
                        // Запрашиваем разрешение с дополнительной проверкой
                        if (typeof Notification.requestPermission === 'function') {
                            Notification.requestPermission().then(permission => {
                                if (permission === 'granted') {
                                    const notification = new Notification('Новая заявка на лазер', {
                                        body: message,
                                        icon: '/favicon.ico'
                                    });
                                    
                                    // Автоматически закрываем уведомление через 5 секунд
                                    setTimeout(() => {
                                        if (notification && typeof notification.close === 'function') {
                                            notification.close();
                                        }
                                    }, 5000);
                                } else {
                                    // Если разрешение не дано, используем звук
                                    playNotificationSound();
                                }
                            }).catch(() => {
                                // Если не удалось запросить разрешение, используем звук
                                playNotificationSound();
                            });
                        } else {
                            // Если нет метода requestPermission, используем звук
                            playNotificationSound();
                        }
                    } else {
                        // Разрешение отклонено, используем звук
                        playNotificationSound();
                    }
                } catch (constructorError) {
                    console.log('Ошибка конструктора Notification:', constructorError);
                    // Fallback на звуковое уведомление
                    playNotificationSound();
                }
            } catch (error) {
                console.log('Общая ошибка в showStandardNotification:', error);
                // Fallback на звуковое уведомление
                playNotificationSound();
            }
        }
        
        
        function filterRequests(filter) {
            const rows = document.querySelectorAll('#requestsTableBody tr');
            const buttons = document.querySelectorAll('.filter-btn');
            
            // Обновляем активную кнопку
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Сохраняем выбранный фильтр в localStorage
            localStorage.setItem('laser_operator_filter', filter);
            
            // Фильтруем строки только по статусу
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else if (filter === 'pending') {
                    row.style.display = row.dataset.status === 'pending' ? '' : 'none';
                } else if (filter === 'completed') {
                    row.style.display = row.dataset.status === 'completed' ? '' : 'none';
                }
            });
        }
        
        function showToast(message) {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.remove();
                    }, 300);
                }, 3000);
            }
        }
        
        // === Автосохранение несохраненных значений в localStorage ===
        function savePendingProgress() {
            const progressInputs = document.querySelectorAll('.progress-input');
            const pendingData = {};
            
            progressInputs.forEach(input => {
                const requestId = input.dataset.requestId;
                const department = input.dataset.department;
                const value = input.value.trim();
                
                if (requestId && department && value !== '') {
                    const key = `${department}_${requestId}`;
                    pendingData[key] = value;
                }
            });
            
            localStorage.setItem('laser_operator_pending_progress', JSON.stringify(pendingData));
        }
        
        // === Восстановление несохраненных значений из localStorage ===
        function restorePendingProgress() {
            try {
                const savedData = localStorage.getItem('laser_operator_pending_progress');
                if (!savedData) return;
                
                const pendingData = JSON.parse(savedData);
                
                Object.keys(pendingData).forEach(key => {
                    const [department, requestId] = key.split('_');
                    const input = document.querySelector(
                        `.progress-input[data-request-id="${requestId}"][data-department="${department}"]`
                    );
                    
                    if (input && input.value === '') {
                        input.value = pendingData[key];
                    }
                });
            } catch (error) {
                console.error('Ошибка восстановления данных:', error);
            }
        }
        
        // === Очистка сохраненного значения после успешной отправки ===
        function clearPendingProgress(requestId, department) {
            try {
                const savedData = localStorage.getItem('laser_operator_pending_progress');
                if (!savedData) return;
                
                const pendingData = JSON.parse(savedData);
                const key = `${department}_${requestId}`;
                
                delete pendingData[key];
                localStorage.setItem('laser_operator_pending_progress', JSON.stringify(pendingData));
            } catch (error) {
                console.error('Ошибка очистки данных:', error);
            }
        }
        
        // === Сохранение прогресса ===
        async function saveProgress(inputElement) {
            const requestId = inputElement.dataset.requestId;
            const department = inputElement.dataset.department;
            const progress = parseInt(inputElement.value) || 0;
            
            if (!requestId || !department) {
                console.error('Отсутствуют необходимые данные');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'update_progress');
                formData.append('request_id', requestId);
                formData.append('department', department);
                formData.append('progress', progress);
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Очищаем сохраненное значение из localStorage
                    clearPendingProgress(requestId, department);
                    
                    // Обновляем прогресс-бар
                    const row = inputElement.closest('tr');
                    const progressBar = row.querySelector('.progress-bar-fill');
                    const total = parseInt(inputElement.max) || 1;
                    const percent = Math.round((progress / total) * 100);
                    
                    if (progressBar) {
                        progressBar.style.width = percent + '%';
                    }
                    
                    // Показываем уведомление
                    console.log('Прогресс сохранен:', progress);
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error saving progress:', error);
                alert('Ошибка при сохранении прогресса');
            }
        }
        
        function restoreFilterState() {
            const savedFilter = localStorage.getItem('laser_operator_filter') || 'pending';
            const buttons = document.querySelectorAll('.filter-btn');
            
            // Проверяем, что сохраненный фильтр является валидным статусным фильтром
            const validStatusFilters = ['all', 'pending', 'completed'];
            const filterToUse = validStatusFilters.includes(savedFilter) ? savedFilter : 'pending';
            
            buttons.forEach(btn => {
                btn.classList.remove('active');
                const buttonText = btn.textContent.trim();
                if (buttonText === 'Все заявки' && filterToUse === 'all') {
                    btn.classList.add('active');
                } else if (buttonText === 'В работе' && filterToUse === 'pending') {
                    btn.classList.add('active');
                } else if (buttonText === 'Выполнено' && filterToUse === 'completed') {
                    btn.classList.add('active');
                }
            });
            
            // Применяем фильтр без сохранения (чтобы не перезаписать)
            const rows = document.querySelectorAll('#requestsTableBody tr');
            rows.forEach(row => {
                if (filterToUse === 'all') {
                    row.style.display = '';
                } else if (filterToUse === 'pending') {
                    row.style.display = row.dataset.status === 'pending' ? '' : 'none';
                } else if (filterToUse === 'completed') {
                    row.style.display = row.dataset.status === 'completed' ? '' : 'none';
                }
            });
        }
        
        // === Функция для загрузки статистики ===
        async function loadStatistics() {
            try {
                const response = await fetch('api/get_statistics.php');
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success && data.days) {
                    displayStatistics(data.days);
                } else {
                    document.getElementById('statistics-content').innerHTML = 
                        '<div class="statistics-day-empty">Нет данных</div>';
                }
            } catch (error) {
                console.error('Ошибка загрузки статистики:', error);
                document.getElementById('statistics-content').innerHTML = 
                    '<div class="statistics-day-empty">Ошибка загрузки статистики</div>';
            }
        }
        
        // === Функция для отображения статистики ===
        function displayStatistics(days) {
            const content = document.getElementById('statistics-content');
            
            if (!days || days.length === 0) {
                content.innerHTML = '<div class="statistics-day-empty">Нет данных</div>';
                return;
            }
            
            // Формируем HTML для плашек по дням
            const html = days.map(day => {
                // Формируем заголовок с фамилией оператора (но не для сегодня)
                let dayTitle = day.date_label;
                // Для сегодняшнего дня не показываем фамилию, только "Сегодня"
                if (day.date_label !== 'Сегодня' && day.operator_surname) {
                    dayTitle = day.operator_surname;
                }
                
                return `
                    <div class="statistics-day-card">
                        <div class="statistics-day-header">
                            <h3 class="statistics-day-title">${dayTitle}</h3>
                            <span class="statistics-day-date">${day.date_formatted}</span>
                        </div>
                        <div class="statistics-day-summary">
                            <div class="statistics-day-summary-item">
                                <div class="statistics-day-summary-value">${day.created_count || 0}</div>
                                <div class="statistics-day-summary-label">Создано</div>
                            </div>
                            <div class="statistics-day-summary-item">
                                <div class="statistics-day-summary-value">${day.completed_count || 0}</div>
                                <div class="statistics-day-summary-label">Выполнено</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            content.innerHTML = `<div class="statistics-days-grid">${html}</div>`;
        }
        
        // === Обновление статистики ===
        function updateStatistics() {
            loadStatistics();
        }
        
        // Модальное окно: заявка на складской запас
        function openStockModal() {
            const m = document.getElementById('stockRequestModal');
            if (m) {
                m.classList.add('is-open');
                document.getElementById('stock_component_type')?.focus();
            }
        }

        function closeStockModal() {
            const m = document.getElementById('stockRequestModal');
            if (m) m.classList.remove('is-open');
        }

        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            const stockModal = document.getElementById('stockRequestModal');
            document.getElementById('openStockRequestModal')?.addEventListener('click', openStockModal);
            document.getElementById('closeStockRequestModal')?.addEventListener('click', closeStockModal);
            document.getElementById('cancelStockRequestModal')?.addEventListener('click', closeStockModal);
            stockModal?.addEventListener('click', function(e) {
                if (e.target === stockModal) closeStockModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && stockModal?.classList.contains('is-open')) closeStockModal();
            });

            // Инициализация всплывающего окна
            const toast = document.getElementById('toast');
            if (toast) {
                setTimeout(() => {
                    showToast(toast.textContent);
                }, 100);
            }
            
            // Восстанавливаем состояние фильтров
            restoreFilterState();
            
            // Восстанавливаем несохраненные значения из localStorage
            restorePendingProgress();
            
            // Добавляем обработчики для автосохранения при вводе
            attachInputHandlers();
            
            // Загружаем статистику
            loadStatistics();
            
            // Запрашиваем разрешение на уведомления с проверкой поддержки
            try {
                if (typeof Notification !== 'undefined' && 'Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission().catch(error => {
                        console.log('Не удалось запросить разрешение на уведомления:', error);
                    });
                }
            } catch (error) {
                console.log('Notification API не поддерживается:', error);
            }
            
            // Увеличенный интервал обновления для предотвращения потери данных
            const updateInterval = 30000; // 30 секунд
            
            console.log(`Update interval: ${updateInterval}ms (30 секунд)`);
            
            // Обновляем таблицу с соответствующим интервалом
            setInterval(updateTable, updateInterval);
            
            // Обновляем статистику каждые 60 секунд
            setInterval(updateStatistics, 60000);
            
            // Первое обновление через 2 секунды после загрузки
            setTimeout(updateTable, 2000);
        });
    </script>
</body>
</html>
