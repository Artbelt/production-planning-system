<?php
// Отключаем вывод ошибок, чтобы не портить JSON ответ
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Включаем буферизацию вывода для предотвращения случайного вывода
ob_start();

// API для сохранения часов работы в табеле
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');

// Очищаем буфер на случай, если что-то было выведено
ob_clean();

// Устанавливаем заголовок JSON в самом начале
header('Content-Type: application/json; charset=utf-8');

// Инициализация системы авторизации
initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Получаем экземпляр Database для работы с auth_users
$db = Database::getInstance();

try {
    $user_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
    $date = isset($_POST['date']) ? $_POST['date'] : null;
    $hours_worked = isset($_POST['hours_worked']) ? (float)$_POST['hours_worked'] : 0;
    $team_id = isset($_POST['team_id']) ? ($_POST['team_id'] === '' || $_POST['team_id'] === '0' ? null : (int)$_POST['team_id']) : null;
    $hourly_rate_id = isset($_POST['hourly_rate_id']) && $_POST['hourly_rate_id'] !== '' ? (int)$_POST['hourly_rate_id'] : null;
    $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';

    if (!$user_id || !$date) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Недостаточно данных'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация даты
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Неверный формат даты'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация часов
    if ($hours_worked < 0 || $hours_worked > 24) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Часы должны быть от 0 до 24'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверяем существование пользователя и доступ ТОЛЬКО к цеху U5
    // Исключаем директоров, менеджеров, операторов лазера и тигельного пресса - сохранять часы можно только для рабочих сотрудников
    $userCheck = $db->selectOne("
        SELECT u.id 
        FROM auth_users u
        INNER JOIN auth_user_departments ud ON u.id = ud.user_id
        INNER JOIN auth_roles r ON ud.role_id = r.id
        WHERE u.id = ? 
        AND ud.department_code = 'U5' 
        AND ud.is_active = 1 
        AND u.is_active = 1
        AND r.name NOT IN ('director', 'manager', 'laser_operator', 'cut_operator')
    ", [$user_id]);
    
    if (!$userCheck) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден, не относится к цеху U5 или имеет исключенную роль'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Подключение к БД plan_U5
    $pdo = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Проверяем существование таблицы и создаем её, если не существует
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'timesheet_hours'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        $tableExists = false;
    }

    if (!$tableExists) {
        // Создаем таблицу, если её нет
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS timesheet_hours (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL COMMENT 'ID пользователя из auth_users',
                date DATE NOT NULL,
                hours_worked DECIMAL(5,2) DEFAULT 0.00,
                team_id INT NULL COMMENT 'Бригада в смену (NULL = индивидуально)',
                hourly_rate_id INT NULL COMMENT 'Почасовой тариф при работе индивидуально',
                comments TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_date (user_id, date),
                INDEX idx_date (date),
                INDEX idx_user_date (user_id, date),
                INDEX idx_user_id (user_id),
                INDEX idx_team_id (team_id),
                INDEX idx_hourly_rate_id (hourly_rate_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Если таблица существует, проверяем и исправляем структуру
        try {
            // Проверяем наличие внешнего ключа на timesheet_employees
            $fkCheck = $pdo->query("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = '{$mysql_database}' 
                AND TABLE_NAME = 'timesheet_hours' 
                AND REFERENCED_TABLE_NAME = 'timesheet_employees'
                LIMIT 1
            ");
            $fkExists = $fkCheck->fetch(PDO::FETCH_ASSOC);
            
            if ($fkExists) {
                // Удаляем внешний ключ
                $pdo->exec("ALTER TABLE timesheet_hours DROP FOREIGN KEY {$fkExists['CONSTRAINT_NAME']}");
            }
            
            // Проверяем наличие колонки employee_id и переименовываем в user_id
            $colCheck = $pdo->query("SHOW COLUMNS FROM timesheet_hours LIKE 'employee_id'");
            if ($colCheck->rowCount() > 0) {
                // Удаляем старый индекс, если есть
                try {
                    $pdo->exec("DROP INDEX unique_employee_date ON timesheet_hours");
                } catch (PDOException $e) {
                    // Игнорируем, если индекс не существует
                }
                
                // Переименовываем колонку
                $pdo->exec("ALTER TABLE timesheet_hours CHANGE COLUMN employee_id user_id INT NOT NULL COMMENT 'ID пользователя из auth_users'");
                
                // Создаем новый индекс
                try {
                    $pdo->exec("CREATE UNIQUE INDEX unique_user_date ON timesheet_hours(user_id, date)");
                } catch (PDOException $e) {
                    // Игнорируем, если индекс уже существует
                }
            }
            // Добавляем team_id, если колонки нет (работа в бригаде в смену)
            $colTeam = $pdo->query("SHOW COLUMNS FROM timesheet_hours LIKE 'team_id'");
            if ($colTeam->rowCount() == 0) {
                $pdo->exec("ALTER TABLE timesheet_hours ADD COLUMN team_id INT NULL COMMENT 'Бригада в смену (NULL = индивидуально)' AFTER hours_worked, ADD INDEX idx_team_id (team_id)");
            }
            // Добавляем hourly_rate_id, если колонки нет (почасовой тариф при индивидуальной работе)
            $colHourlyRate = $pdo->query("SHOW COLUMNS FROM timesheet_hours LIKE 'hourly_rate_id'");
            if ($colHourlyRate->rowCount() == 0) {
                $pdo->exec("ALTER TABLE timesheet_hours ADD COLUMN hourly_rate_id INT NULL COMMENT 'Почасовой тариф при работе индивидуально' AFTER team_id, ADD INDEX idx_hourly_rate_id (hourly_rate_id)");
            }
        } catch (PDOException $e) {
            // Логируем, но продолжаем работу
            error_log('Table structure check error: ' . $e->getMessage());
        }
    }

    // Проверяем, какое поле используется в таблице (для совместимости со старой структурой)
    $hasUserId = false;
    $hasEmployeeId = false;
    try {
        $checkStmt = $pdo->query("SHOW COLUMNS FROM timesheet_hours");
        $columns = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasUserId = in_array('user_id', $columns);
        $hasEmployeeId = in_array('employee_id', $columns);
    } catch (PDOException $e) {
        // Если таблица не существует или ошибка, используем user_id по умолчанию
        $hasUserId = true;
        $hasEmployeeId = false;
    }

    $fieldName = $hasUserId ? 'user_id' : ($hasEmployeeId ? 'employee_id' : 'user_id');

    // Проверяем наличие колонки team_id и hourly_rate_id
    $hasTeamId = false;
    $hasHourlyRateId = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM timesheet_hours")->fetchAll(PDO::FETCH_COLUMN);
        $hasTeamId = in_array('team_id', $cols);
        $hasHourlyRateId = in_array('hourly_rate_id', $cols);
    } catch (PDOException $e) { /* ignore */ }

    // Если часы = 0 и нет комментариев, удаляем запись
    if ($hours_worked == 0 && empty($comments)) {
        $stmt = $pdo->prepare("DELETE FROM timesheet_hours WHERE {$fieldName} = ? AND date = ?");
        $stmt->execute([$user_id, $date]);
        ob_clean();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверяем, есть ли запись
    $stmt = $pdo->prepare("SELECT id FROM timesheet_hours WHERE {$fieldName} = ? AND date = ?");
    $stmt->execute([$user_id, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $hourlyRateForDb = ($hasHourlyRateId && $team_id === null) ? $hourly_rate_id : null;
    if (!$hasHourlyRateId) {
        $hourlyRateForDb = null;
    }

    if ($existing) {
        if ($hasTeamId && $hasHourlyRateId) {
            $stmt = $pdo->prepare("UPDATE timesheet_hours SET hours_worked = ?, team_id = ?, hourly_rate_id = ?, comments = ? WHERE id = ?");
            $stmt->execute([$hours_worked, $team_id, $hourlyRateForDb, $comments, $existing['id']]);
        } elseif ($hasTeamId) {
            $stmt = $pdo->prepare("UPDATE timesheet_hours SET hours_worked = ?, team_id = ?, comments = ? WHERE id = ?");
            $stmt->execute([$hours_worked, $team_id, $comments, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE timesheet_hours SET hours_worked = ?, comments = ? WHERE id = ?");
            $stmt->execute([$hours_worked, $comments, $existing['id']]);
        }
    } else {
        if ($hasTeamId && $hasHourlyRateId) {
            $stmt = $pdo->prepare("INSERT INTO timesheet_hours ({$fieldName}, date, hours_worked, team_id, hourly_rate_id, comments) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $hours_worked, $team_id, $hourlyRateForDb, $comments]);
        } elseif ($hasTeamId) {
            $stmt = $pdo->prepare("INSERT INTO timesheet_hours ({$fieldName}, date, hours_worked, team_id, comments) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $hours_worked, $team_id, $comments]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO timesheet_hours ({$fieldName}, date, hours_worked, comments) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $hours_worked, $comments]);
        }
    }

    ob_clean();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    // Логируем ошибку БД с полной информацией
    $errorMsg = $e->getMessage();
    $errorCode = $e->getCode();
    error_log("Timesheet save PDO error [{$errorCode}]: {$errorMsg}");
    error_log("SQL State: " . $e->getCode());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    
    ob_clean();
    // В режиме разработки можно показать детали ошибки, в продакшене - общее сообщение
    $debugError = (ini_get('display_errors') == 1) ? ": {$errorMsg}" : '';
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных' . $debugError], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // Логируем общую ошибку
    error_log('Timesheet save error: ' . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    ob_clean();
    $debugError = (ini_get('display_errors') == 1) ? ": " . $e->getMessage() : '';
    echo json_encode(['success' => false, 'error' => 'Ошибка при сохранении данных' . $debugError], JSON_UNESCAPED_UNICODE);
}

