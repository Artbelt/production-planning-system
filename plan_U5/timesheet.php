<?php
// Система табелирования У5
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');

// Инициализация системы авторизации
initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

// Подключение к БД plan_U5 для табеля
try {
    $pdo = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем текущий месяц и год
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Получаем список сотрудников ТОЛЬКО из цеха U5
// Исключаем директоров, менеджеров, операторов лазера и тигельного пресса - показываем только рабочих сотрудников
$allEmployees = $db->select("
    SELECT DISTINCT u.id, u.full_name
    FROM auth_users u
    INNER JOIN auth_user_departments ud ON u.id = ud.user_id
    INNER JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.department_code = 'U5' 
    AND ud.is_active = 1 
    AND u.is_active = 1
    AND r.name NOT IN ('director', 'manager', 'laser_operator', 'cut_operator')
    ORDER BY u.full_name
");

// Получаем список бригад
$teams = [];
try {
    $teamsStmt = $pdo->query("SELECT id, name, sort_order FROM timesheet_teams WHERE is_active = 1 ORDER BY sort_order, name");
    $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Если таблицы нет, создаем её
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timesheet_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL COMMENT 'Название бригады/машины',
            description TEXT DEFAULT NULL COMMENT 'Описание',
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0 COMMENT 'Порядок сортировки',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (name),
            INDEX idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        INSERT IGNORE INTO timesheet_teams (name, description, sort_order) VALUES
        ('Бригада 1', 'Первая бригада', 1),
        ('Бригада 2', 'Вторая бригада', 2),
        ('Бригада 3', 'Третья бригада', 3),
        ('Бригада 4', 'Четвертая бригада', 4)
    ");
    $teamsStmt = $pdo->query("SELECT id, name, sort_order FROM timesheet_teams WHERE is_active = 1 ORDER BY sort_order, name");
    $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получаем назначения сотрудников на бригады
$userTeams = [];
$employeesByTeam = [];
$employeesWithoutTeam = [];

try {
    $userTeamsStmt = $pdo->query("SELECT user_id, team_id FROM timesheet_user_teams WHERE is_active = 1");
    while ($row = $userTeamsStmt->fetch(PDO::FETCH_ASSOC)) {
        $userTeams[$row['user_id']] = $row['team_id'];
    }
} catch (PDOException $e) {
    // Если таблицы нет, создаем её
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timesheet_user_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT 'ID пользователя из auth_users',
            team_id INT NOT NULL COMMENT 'ID бригады',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (team_id) REFERENCES timesheet_teams(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_team (user_id, team_id),
            INDEX idx_user_id (user_id),
            INDEX idx_team_id (team_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Группируем сотрудников по бригадам
foreach ($allEmployees as $emp) {
    if (isset($userTeams[$emp['id']])) {
        $teamId = $userTeams[$emp['id']];
        if (!isset($employeesByTeam[$teamId])) {
            $employeesByTeam[$teamId] = [];
        }
        $employeesByTeam[$teamId][] = $emp;
    } else {
        $employeesWithoutTeam[] = $emp;
    }
}

// Получаем данные табеля за текущий месяц
$timesheets = [];
// Проверяем, какое поле используется в таблице (для совместимости со старой структурой)
try {
    $checkStmt = $pdo->query("SHOW COLUMNS FROM timesheet_hours LIKE 'user_id'");
    $hasUserId = $checkStmt->rowCount() > 0;
} catch (PDOException $e) {
    $hasUserId = false;
}

$fieldName = $hasUserId ? 'user_id' : 'employee_id';
$stmt = $pdo->prepare("SELECT {$fieldName} as user_id, DATE_FORMAT(date, '%Y-%m-%d') as date, hours_worked, comments 
    FROM timesheet_hours 
    WHERE YEAR(date) = ? AND MONTH(date) = ?");
$stmt->execute([$year, $month]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $timesheets[$row['user_id']][$row['date']] = [
        'hours' => $row['hours_worked'],
        'comments' => $row['comments']
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Табель У5</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .table th, .table td { 
            text-align: center; 
            vertical-align: middle; 
            font-size: 0.9rem;
        }
        .table td.clickable { 
            cursor: pointer; 
            transition: background-color 0.2s;
        }
        .table td.clickable:hover { 
            background-color: #f0f0f0; 
        }
        .table-responsive {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
        }
        .employee-name {
            white-space: nowrap;
            min-width: 200px;
            text-align: left !important;
            font-weight: 500;
        }
        .month-selector {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Табель У5</h1>
            <div>
                <a href="timesheet_teams.php" target="_blank" class="btn btn-primary btn-sm me-2">Управление бригадами</a>
                <span class="me-3">Пользователь: <?php echo htmlspecialchars($user['full_name'] ?? 'Пользователь'); ?></span>
                <a href="../auth/logout.php" class="btn btn-secondary btn-sm">Выйти</a>
            </div>
        </div>

        <!-- Выбор месяца и года -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="month-selector">
                    <label for="month">Месяц:</label>
                    <select name="month" id="month" class="form-select" style="width: auto;" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <label for="year">Год:</label>
                    <select name="year" id="year" class="form-select" style="width: auto;" onchange="this.form.submit()">
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
        </div>

        <!-- Таблица табеля -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Сотрудник</th>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <th style="min-width: 50px;"><?php echo $day; ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allEmployees)): ?>
                                <tr>
                                    <td colspan="<?php echo $days_in_month + 1; ?>" class="text-center text-muted">
                                        Нет сотрудников с доступом к цеху U5. Управление сотрудниками через систему авторизации.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                // Выводим сотрудников по бригадам
                                foreach ($teams as $team): 
                                    if (!isset($employeesByTeam[$team['id']]) || empty($employeesByTeam[$team['id']])) continue;
                                ?>
                                    <!-- Заголовок бригады -->
                                    <tr style="background-color: #e9ecef; font-weight: bold;">
                                        <td colspan="<?php echo $days_in_month + 1; ?>" style="padding: 10px;">
                                            👥 <?php echo htmlspecialchars($team['name']); ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($employeesByTeam[$team['id']] as $employee): ?>
                                        <tr>
                                            <td class="employee-name" style="padding-left: 30px;">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </td>
                                            <?php for ($day = 1; $day <= $days_in_month; $day++): 
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                                $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                                $display_hours = '';
                                                if ($hours !== '' && floatval($hours) != 0) {
                                                    $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                                }
                                                $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                            ?>
                                                <td class="clickable" 
                                                    data-employee-id="<?php echo $employee['id']; ?>" 
                                                    data-date="<?php echo $date; ?>" 
                                                    data-hours="<?php echo $hours; ?>" 
                                                    data-comments="<?php echo htmlspecialchars($comments); ?>"
                                                    <?php echo $tooltip_attrs; ?>>
                                                    <?php echo htmlspecialchars($display_hours); ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                
                                <?php if (!empty($employeesWithoutTeam)): ?>
                                    <!-- Сотрудники без бригады -->
                                    <tr style="background-color: #fff3cd; font-weight: bold;">
                                        <td colspan="<?php echo $days_in_month + 1; ?>" style="padding: 10px;">
                                            ⚠️ Сотрудники без бригады
                                        </td>
                                    </tr>
                                    <?php foreach ($employeesWithoutTeam as $employee): ?>
                                        <tr>
                                            <td class="employee-name" style="padding-left: 30px;">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </td>
                                            <?php for ($day = 1; $day <= $days_in_month; $day++): 
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                                $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                                $display_hours = '';
                                                if ($hours !== '' && floatval($hours) != 0) {
                                                    $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                                }
                                                $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                            ?>
                                                <td class="clickable" 
                                                    data-employee-id="<?php echo $employee['id']; ?>" 
                                                    data-date="<?php echo $date; ?>" 
                                                    data-hours="<?php echo $hours; ?>" 
                                                    data-comments="<?php echo htmlspecialchars($comments); ?>"
                                                    <?php echo $tooltip_attrs; ?>>
                                                    <?php echo htmlspecialchars($display_hours); ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для ввода часов -->
    <div class="modal fade" id="hoursModal" tabindex="-1" aria-labelledby="hoursModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="hoursModalLabel">Ввод часов работы</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="hoursForm">
                        <input type="hidden" id="employee_id" name="employee_id">
                        <input type="hidden" id="date" name="date">
                        <div class="mb-3">
                            <label for="hours_worked" class="form-label">Часы работы (например, 8.5)</label>
                            <input type="number" step="0.25" min="0" max="24" class="form-control" id="hours_worked" name="hours_worked" required>
                            <div class="mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="setHours(8)">8 ч</button>
                                <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="setHours(11.5)">11.5 ч</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setHours(0)">Очистить</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="comments" class="form-label">Комментарии</label>
                            <textarea class="form-control" id="comments" name="comments" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveHours">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Инициализация всплывающих подсказок
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Открытие модального окна при клике на ячейку
            $('.clickable').click(function() {
                var employeeId = $(this).data('employee-id');
                var date = $(this).data('date');
                var hours = $(this).data('hours');
                var comments = $(this).data('comments');
                $('#employee_id').val(employeeId);
                $('#date').val(date);
                $('#hours_worked').val(hours || '');
                $('#comments').val(comments || '');
                $('#hoursModal').modal('show');
            });

            // Сохранение данных
            $('#saveHours').click(function() {
                saveHours();
            });

            // Установка часов через кнопки
            window.setHours = function(hours) {
                $('#hours_worked').val(hours);
                if (hours == 0) {
                    $('#comments').val('');
                }
            };

            // Функция сохранения часов
            function saveHours() {
                var formData = {
                    employee_id: $('#employee_id').val(),
                    date: $('#date').val(),
                    hours_worked: $('#hours_worked').val() || 0,
                    comments: $('#comments').val()
                };

                $.ajax({
                    url: 'timesheet_save.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var employeeId = formData.employee_id;
                            var date = formData.date;
                            var hours = formData.hours_worked;
                            var comments = formData.comments;
                            var cell = $(`td[data-employee-id="${employeeId}"][data-date="${date}"]`);
                            var displayHours = '';
                            if (hours && parseFloat(hours) != 0) {
                                displayHours = parseFloat(hours).toFixed(2).replace(/\.?0+$/, '');
                            }
                            cell.text(displayHours);
                            cell.data('hours', hours);
                            cell.data('comments', comments);
                            
                            // Обновляем tooltip
                            if (comments) {
                                cell.attr('data-bs-toggle', 'tooltip');
                                cell.attr('data-bs-placement', 'top');
                                cell.attr('title', comments);
                                var tooltip = bootstrap.Tooltip.getInstance(cell[0]);
                                if (tooltip) {
                                    tooltip.dispose();
                                }
                                new bootstrap.Tooltip(cell[0]);
                            } else {
                                cell.removeAttr('data-bs-toggle');
                                cell.removeAttr('data-bs-placement');
                                cell.removeAttr('title');
                                var tooltip = bootstrap.Tooltip.getInstance(cell[0]);
                                if (tooltip) {
                                    tooltip.dispose();
                                }
                            }
                            
                            $('#hoursModal').modal('hide');
                        } else {
                            alert('Ошибка: ' + (response.error || 'Неизвестная ошибка'));
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Ошибка при сохранении данных: ' + error);
                    }
                });
            }
        });
    </script>
</body>
</html>

