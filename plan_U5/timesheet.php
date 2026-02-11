<?php
// Система табелирования У5
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');
require_once('tools/tools.php');
require_once('tools/ensure_salary_warehouse_tables.php');

// #region agent log
$debug_log = function ($location, $message, $data, $hypothesisId) {
    $path = __DIR__ . '/../.cursor/debug.log';
    $line = json_encode([
        'id' => 'log_' . uniqid(),
        'timestamp' => round(microtime(true) * 1000),
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'hypothesisId' => $hypothesisId,
    ]) . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
};
// #endregion

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

// Получаем текущий месяц, год и период (1-15 / 16-31 / весь месяц)
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$period = isset($_GET['period']) ? $_GET['period'] : 'full';
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($period === 'first') {
    $day_start = 1;
    $day_end = min(15, $days_in_month);
} elseif ($period === 'second') {
    $day_start = 16;
    $day_end = $days_in_month;
} else {
    $day_start = 1;
    $day_end = $days_in_month;
}
$display_days_count = $day_end - $day_start + 1;

// Диапазон дат для расчёта заработка (как в salary_report_monthly)
$first_day = sprintf("%04d-%02d-%02d", $year, $month, $day_start);
$last_day = sprintf("%04d-%02d-%02d", $year, $month, $day_end);

// #region agent log
$debug_log('timesheet.php:period', 'timesheet period bounds', [
    'year' => $year,
    'month' => $month,
    'period' => $period,
    'day_start' => $day_start,
    'day_end' => $day_end,
    'first_day' => $first_day,
    'last_day' => $last_day,
], 'TH_period');
// #endregion

// Заработок бригады за смену — расчёт как в generate_monthly_salary_report.php
$brigade_earnings = [1 => [], 2 => [], 3 => [], 4 => []];
foreach ([1,2,3,4] as $t) {
    for ($d = $day_start; $d <= $day_end; $d++) {
        $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $brigade_earnings[$t][$dt] = 0.0;
    }
}
try {
    $addition_rows = mysql_execute("SELECT code, amount FROM salary_additions");
    $additions = [];
    foreach ($addition_rows as $a) {
        $additions[$a['code']] = (float)$a['amount'];
    }
    $hours_raw = mysql_execute("SELECT filter, order_number, date_of_work, hours FROM hourly_work_log WHERE date_of_work BETWEEN '$first_day' AND '$last_day'");
    $hours_map = [];
    foreach ($hours_raw as $h) {
        $key = $h['filter'] . '_' . $h['order_number'] . '_' . $h['date_of_work'];
        $hours_map[$key] = $h['hours'];
    }
    $sql = "
        SELECT mp.date_of_production, mp.name_of_filter, mp.count_of_filters, mp.name_of_order, mp.team,
               sfs.tail, sfs.form_factor, sfs.has_edge_cuts,
               st.rate_per_unit, st.type, st.tariff_name
        FROM manufactured_production mp
        LEFT JOIN (SELECT filter, MAX(tail) AS tail, MAX(form_factor) AS form_factor, MAX(has_edge_cuts) AS has_edge_cuts, MAX(tariff_id) AS tariff_id FROM salon_filter_structure GROUP BY filter) sfs ON sfs.filter = mp.name_of_filter
        LEFT JOIN salary_tariffs st ON st.id = sfs.tariff_id
        WHERE mp.date_of_production BETWEEN '$first_day' AND '$last_day'
        AND (mp.handed_to_warehouse_at IS NOT NULL OR mp.salary_closed_advance = 1)
        ORDER BY mp.date_of_production, mp.team
    ";
    $result = mysql_execute($sql);
    $raw_dates = is_array($result) ? array_slice(array_column($result, 'date_of_production'), 0, 10) : [];
    $debug_log('timesheet.php:after_sql', 'brigade earnings source rows', [
        'first_day' => $first_day,
        'last_day' => $last_day,
        'result_count' => is_array($result) ? count($result) : null,
        'raw_dates_sample' => $raw_dates,
    ], 'TH1');

    $total_rows = is_array($result) ? count($result) : 0;
    $skipped_rows = 0;
    foreach ($result as $row) {
        $team = (int)$row['team'];
        if ($team < 1 || $team > 4) continue;
        $date = $row['date_of_production'];
        if (!isset($brigade_earnings[$team][$date])) {
            $skipped_rows++;
            continue;
        }
        $base_rate = (float)($row['rate_per_unit'] ?? 0);
        $tail = mb_strtolower(trim($row['tail'] ?? ''));
        $form = mb_strtolower(trim($row['form_factor'] ?? ''));
        $has_edge_cuts = trim($row['has_edge_cuts'] ?? '');
        $tariff_type = strtolower(trim($row['type'] ?? ''));
        $tariff_name_lower = mb_strtolower(trim($row['tariff_name'] ?? ''));
        $is_hourly = $tariff_name_lower === 'почасовый';
        $apply_additions = $tariff_type !== 'fixed' && !$is_hourly;
        $apply_edge_cuts = !$is_hourly;
        $final_rate = $base_rate;
        if ($apply_additions && strpos($tail, 'языч') !== false && isset($additions['tongue_glue'])) $final_rate += $additions['tongue_glue'];
        if ($apply_additions && $form === 'трапеция' && isset($additions['edge_trim_glue'])) $final_rate += $additions['edge_trim_glue'];
        if ($apply_edge_cuts && !empty($has_edge_cuts) && isset($additions['edge_cuts'])) $final_rate += $additions['edge_cuts'];
        $count = (int)$row['count_of_filters'];
        $key = $row['name_of_filter'] . '_' . $row['name_of_order'] . '_' . $date;
        $hours = $is_hourly ? ($hours_map[$key] ?? 0) : 0;
        $display_count = $is_hourly ? $hours : $count;
        $amount = $display_count * $final_rate;
        $brigade_earnings[$team][$date] += $amount;
    }

    $debug_log('timesheet.php:brigade_earnings_summary', 'brigade earnings fill summary', [
        'total_rows' => $total_rows,
        'skipped_rows' => $skipped_rows,
    ], 'TH2');
} catch (Throwable $e) {
    // при ошибке оставляем нули
}

// Получаем список сотрудников ТОЛЬКО из цеха U5: сначала начальник/мастер, потом сборщицы по бригадам
// Исключаем директоров, менеджеров, операторов лазера и тигельного пресса
$allEmployeesRaw = $db->select("
    SELECT DISTINCT u.id, u.full_name, r.name as role_name
    FROM auth_users u
    INNER JOIN auth_user_departments ud ON u.id = ud.user_id
    INNER JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.department_code = 'U5' 
    AND ud.is_active = 1 
    AND u.is_active = 1
    AND r.name NOT IN ('director', 'manager', 'laser_operator', 'cut_operator')
");
// Сортируем: сначала supervisor, потом по full_name (бригады назначим ниже)
$supervisors = [];
$assemblers = [];
foreach ($allEmployeesRaw as $e) {
    if (($e['role_name'] ?? '') === 'supervisor') {
        $supervisors[] = $e;
    } else {
        $assemblers[] = $e;
    }
}
$allEmployees = array_merge($supervisors, $assemblers);

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

// Почасовые тарифы для рабочих (при выборе «Индивидуально»)
$hourly_worker_rates = [];
try {
    $hrStmt = $pdo->query("SELECT id, name, rate_per_hour FROM salary_hourly_worker_rates ORDER BY sort_order, name");
    $hourly_worker_rates = $hrStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Таблица может отсутствовать
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

// Группируем только сборщиц по бригадам; начальник выводится отдельно первым
foreach ($assemblers as $emp) {
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

$hasTeamIdCol = false;
$hasHourlyRateIdCol = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM timesheet_hours")->fetchAll(PDO::FETCH_COLUMN);
    $hasTeamIdCol = in_array('team_id', $cols);
    $hasHourlyRateIdCol = in_array('hourly_rate_id', $cols);
} catch (PDOException $e) { /* ignore */ }
$selectFields = $hasTeamIdCol
    ? ($hasHourlyRateIdCol
        ? "{$fieldName} as user_id, DATE_FORMAT(date, '%Y-%m-%d') as date, hours_worked, team_id, hourly_rate_id, comments"
        : "{$fieldName} as user_id, DATE_FORMAT(date, '%Y-%m-%d') as date, hours_worked, team_id, comments")
    : "{$fieldName} as user_id, DATE_FORMAT(date, '%Y-%m-%d') as date, hours_worked, comments";
$stmt = $pdo->prepare("SELECT {$selectFields} FROM timesheet_hours WHERE YEAR(date) = ? AND MONTH(date) = ?");
$stmt->execute([$year, $month]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $timesheets[$row['user_id']][$row['date']] = [
        'hours' => $row['hours_worked'],
        'comments' => $row['comments'],
        'team_id' => $hasTeamIdCol ? ($row['team_id'] ?? null) : null,
        'hourly_rate_id' => $hasHourlyRateIdCol ? ($row['hourly_rate_id'] ?? null) : null
    ];
}

// Почасовые тарифы: id => rate_per_hour
$hourly_rates_by_id = [];
foreach ($hourly_worker_rates as $hr) {
    $hourly_rates_by_id[(int)$hr['id']] = (float)$hr['rate_per_hour'];
}

// Тариф «Сборщица почасово» для закрытия ЗП бригады почасово (по имени)
$assembler_hourly_rate = 0.0;
foreach ($hourly_worker_rates as $hr) {
    if (stripos(trim($hr['name']), 'Сборщица') !== false && stripos(trim($hr['name']), 'почасово') !== false) {
        $assembler_hourly_rate = (float)$hr['rate_per_hour'];
        break;
    }
}

// Режим закрытия ЗП по смене бригады: piece | hourly
$brigade_shift_pay_mode = [];
for ($t = 1; $t <= 4; $t++) {
    for ($d = $day_start; $d <= $day_end; $d++) {
        $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $brigade_shift_pay_mode[$t][$dt] = 'piece';
    }
}
try {
    $stmt = $pdo->prepare("SELECT team_id, date, pay_mode FROM salary_brigade_shift_pay_mode WHERE team_id BETWEEN 1 AND 4 AND date >= ? AND date <= ?");
    $stmt->execute([$first_day, $last_day]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $t = (int)$row['team_id'];
        $dt = $row['date'];
        if (isset($brigade_shift_pay_mode[$t][$dt])) {
            $brigade_shift_pay_mode[$t][$dt] = $row['pay_mode'] === 'hourly' ? 'hourly' : 'piece';
        }
    }
} catch (PDOException $e) {
    // таблица может отсутствовать
}

// Количество участников бригады по сменам (кто работал в этой бригаде в этот день с hours > 0)
$brigade_participants_count = [];
for ($d = $day_start; $d <= $day_end; $d++) {
    $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
    $brigade_participants_count[$dt] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
}
foreach ($allEmployees as $emp) {
    $eid = $emp['id'];
    for ($d = $day_start; $d <= $day_end; $d++) {
        $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $rec = $timesheets[$eid][$dt] ?? null;
        if (!$rec) continue;
        $h = (float)($rec['hours'] ?? 0);
        if ($h <= 0) continue;
        $tid = isset($rec['team_id']) ? (int)$rec['team_id'] : 0;
        if ($tid >= 1 && $tid <= 4) {
            $brigade_participants_count[$dt][$tid]++;
        }
    }
}

// Сменный заработок по каждому работнику и дню + признак «почасово» для выделения цветом
$shift_earnings = [];
$shift_earnings_hourly = [];
foreach ($allEmployees as $emp) {
    $eid = $emp['id'];
    $shift_earnings[$eid] = [];
    $shift_earnings_hourly[$eid] = [];
    for ($d = $day_start; $d <= $day_end; $d++) {
        $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $rec = $timesheets[$eid][$dt] ?? null;
        $amount = 0.0;
        $is_hourly = false;
        if ($rec) {
            $h = (float)($rec['hours'] ?? 0);
            $tid = isset($rec['team_id']) ? $rec['team_id'] : null;
            $hid = isset($rec['hourly_rate_id']) ? (int)$rec['hourly_rate_id'] : null;
            if ($tid !== null && $tid !== '' && (int)$tid >= 1 && (int)$tid <= 4) {
                $team_num = (int)$tid;
                $mode = $brigade_shift_pay_mode[$team_num][$dt] ?? 'piece';
                if ($mode === 'hourly' && $assembler_hourly_rate > 0 && $h > 0) {
                    $amount = $assembler_hourly_rate * $h;
                    $is_hourly = true;
                } else {
                    $cnt = $brigade_participants_count[$dt][$team_num] ?? 0;
                    if ($cnt > 0) {
                        $amount = ($brigade_earnings[$team_num][$dt] ?? 0) / $cnt;
                    }
                }
            } elseif ($hid && isset($hourly_rates_by_id[$hid]) && $h > 0) {
                $amount = $hourly_rates_by_id[$hid] * $h;
                // символ почасово не показываем для тех, кто не в бригаде (индивидуально)
            }
        }
        $shift_earnings[$eid][$dt] = $amount;
        $shift_earnings_hourly[$eid][$dt] = $is_hourly;
    }
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
        .table td.clickable { cursor: pointer; }
        .table td.brigade-cell-clickable { cursor: pointer; }
        .table td.brigade-cell-clickable:hover { background-color: #e8f4fd !important; }
        /* Выделение ячеек «закрыто почасово» — цветом, без символа */
        .pay-hourly { background-color: #e0f2f1 !important; }
        .table td.brigade-cell-clickable.pay-hourly:hover { background-color: #b2dfdb !important; }
        .timesheet-table { font-size: 0.75rem; border-collapse: collapse; border-top: none; }
        .timesheet-table thead th { border-top: none; }
        .timesheet-table th,
        .timesheet-table td { font-size: inherit; border-left: 1px solid #dee2e6; text-align: center; vertical-align: middle; padding-top: 0.1rem; padding-bottom: 0.1rem; }
        .timesheet-table th:first-child,
        .timesheet-table td:first-child { border-left: none; border-right: 1px solid #dee2e6; text-align: left; }
        .timesheet-table td:first-child { vertical-align: middle; }
        .day-weekend {
            background-color: #f0f0f0 !important;
        }
        .table td.clickable.day-weekend:hover {
            background-color: #e5e5e5 !important;
        }
        /* Цвета бригад (работа в бригаде в смену) */
        .team-1 { background-color: #bbdefb !important; }
        .table td.clickable.team-1:hover { background-color: #90caf9 !important; }
        .team-2 { background-color: #f8bbd9 !important; }
        .table td.clickable.team-2:hover { background-color: #f48fb1 !important; }
        .team-3 { background-color: #c8e6c9 !important; }
        .table td.clickable.team-3:hover { background-color: #81c784 !important; }
        .team-4 { background-color: #ffe0b2 !important; }
        .table td.clickable.team-4:hover { background-color: #ffcc80 !important; }
        .month-selector {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 0;
        }
        .month-year-card .card-body {
            padding: 0.2rem 1rem;
        }
        .month-year-card .form-select {
            padding: 0.2rem 0.5rem;
            font-size: 0.8125rem;
            min-height: 1.6rem;
            line-height: 1.3;
        }
        /* Год: чтобы стрелка не налезала на цифры */
        .month-year-card #year {
            min-width: 5.5em;
            padding-right: 1.25rem;
        }
        .month-year-card label {
            margin-bottom: 0;
            font-size: 0.8125rem;
        }
        /* Убрать кнопки увеличения/уменьшения у поля времени в модальном окне */
        #hours_worked::-webkit-outer-spin-button,
        #hours_worked::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        #hours_worked {
            -moz-appearance: textfield;
        }
        /* Бригада: радиокнопки как кнопки */
        .team-btn-group input[name="team_id"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .team-btn-group label.btn-team {
            min-width: 2.5em;
            cursor: pointer;
        }
        .team-btn-group input:checked + label.btn-team {
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-light<?php echo ($period === 'first' || $period === 'second') ? ' period-half' : ''; ?>">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Табель У5</h1>
            <div>
            </div>
        </div>

        <!-- Выбор месяца и года -->
        <div class="card mb-3 month-year-card">
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
                    <label for="period">Период:</label>
                    <select name="period" id="period" class="form-select" style="width: auto;" onchange="this.form.submit()">
                        <option value="full" <?php echo $period === 'full' ? 'selected' : ''; ?>>Весь месяц</option>
                        <option value="first" <?php echo $period === 'first' ? 'selected' : ''; ?>>1–15</option>
                        <option value="second" <?php echo $period === 'second' ? 'selected' : ''; ?>>16–31</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Таблица табеля -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table timesheet-table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <?php for ($day = $day_start; $day <= $day_end; $day++):
                                    $ts = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
                                    $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                    $th_class = $is_weekend ? 'day-weekend' : '';
                                ?>
                                    <th class="<?php echo $th_class; ?>"><?php echo $day; ?></th>
                                <?php endfor; ?>
                                <th>Сумма часов</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allEmployees)): ?>
                                <tr>
                                    <td colspan="<?php echo $display_days_count + 2; ?>" class="text-center text-muted">
                                        Нет сотрудников с доступом к цеху U5. Управление сотрудниками через систему авторизации.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php if (!empty($supervisors)): ?>
                                    <?php foreach ($supervisors as $employee): 
                                        $row_sum = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_sum += floatval($timesheets[$employee['id']][$dt]['hours'] ?? 0);
                                        }
                                    ?>
                                        <tr>
                                            <td class="employee-name">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++): 
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                                $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                                $cell_team_id = $timesheets[$employee['id']][$date]['team_id'] ?? null;
                                                $cell_hourly_rate_id = $timesheets[$employee['id']][$date]['hourly_rate_id'] ?? null;
                                                $display_hours = '';
                                                if ($hours !== '' && floatval($hours) != 0) {
                                                    $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                                }
                                                $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                                $td_class = 'clickable day-col';
                                                if (!empty($cell_team_id) && $cell_team_id >= 1 && $cell_team_id <= 4) {
                                                    $td_class .= ' team-' . (int)$cell_team_id;
                                                } elseif ($is_weekend) {
                                                    $td_class .= ' day-weekend';
                                                }
                                            ?>
                                                <td class="<?php echo $td_class; ?>" 
                                                    data-employee-id="<?php echo $employee['id']; ?>" 
                                                    data-date="<?php echo $date; ?>" 
                                                    data-hours="<?php echo $hours; ?>" 
                                                    data-team-id="<?php echo $cell_team_id ?? ''; ?>"
                                                    data-hourly-rate-id="<?php echo $cell_hourly_rate_id ?? ''; ?>"
                                                    data-comments="<?php echo htmlspecialchars($comments); ?>"
                                                    <?php echo $tooltip_attrs; ?>>
                                                    <?php echo htmlspecialchars($display_hours); ?>
                                                </td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_sum > 0 ? rtrim(rtrim(number_format($row_sum, 2, '.', ' '), '0'), '.') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php 
                                foreach ($teams as $team): 
                                    if (!isset($employeesByTeam[$team['id']]) || empty($employeesByTeam[$team['id']])) continue;
                                    foreach ($employeesByTeam[$team['id']] as $employee): 
                                        $row_sum = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_sum += floatval($timesheets[$employee['id']][$dt]['hours'] ?? 0);
                                        }
                                    ?>
                                        <tr>
                                            <td class="employee-name">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++): 
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                                $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                                $cell_team_id = $timesheets[$employee['id']][$date]['team_id'] ?? null;
                                                $cell_hourly_rate_id = $timesheets[$employee['id']][$date]['hourly_rate_id'] ?? null;
                                                $display_hours = '';
                                                if ($hours !== '' && floatval($hours) != 0) {
                                                    $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                                }
                                                $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                                $td_class = 'clickable day-col';
                                                if (!empty($cell_team_id) && $cell_team_id >= 1 && $cell_team_id <= 4) {
                                                    $td_class .= ' team-' . (int)$cell_team_id;
                                                } elseif ($is_weekend) {
                                                    $td_class .= ' day-weekend';
                                                }
                                            ?>
                                                <td class="<?php echo $td_class; ?>" 
                                                    data-employee-id="<?php echo $employee['id']; ?>" 
                                                    data-date="<?php echo $date; ?>" 
                                                    data-hours="<?php echo $hours; ?>" 
                                                    data-team-id="<?php echo $cell_team_id ?? ''; ?>"
                                                    data-hourly-rate-id="<?php echo $cell_hourly_rate_id ?? ''; ?>"
                                                    data-comments="<?php echo htmlspecialchars($comments); ?>"
                                                    <?php echo $tooltip_attrs; ?>>
                                                    <?php echo htmlspecialchars($display_hours); ?>
                                                </td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_sum > 0 ? rtrim(rtrim(number_format($row_sum, 2, '.', ' '), '0'), '.') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; endforeach; ?>
                                
                                <?php if (!empty($employeesWithoutTeam)): ?>
                                    <?php foreach ($employeesWithoutTeam as $employee): 
                                        $row_sum = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_sum += floatval($timesheets[$employee['id']][$dt]['hours'] ?? 0);
                                        }
                                    ?>
                                        <tr>
                                            <td class="employee-name">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                            </td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++): 
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                                $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                                $cell_team_id = $timesheets[$employee['id']][$date]['team_id'] ?? null;
                                                $cell_hourly_rate_id = $timesheets[$employee['id']][$date]['hourly_rate_id'] ?? null;
                                                $display_hours = '';
                                                if ($hours !== '' && floatval($hours) != 0) {
                                                    $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                                }
                                                $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                                $td_class = 'clickable day-col';
                                                if (!empty($cell_team_id) && $cell_team_id >= 1 && $cell_team_id <= 4) {
                                                    $td_class .= ' team-' . (int)$cell_team_id;
                                                } elseif ($is_weekend) {
                                                    $td_class .= ' day-weekend';
                                                }
                                            ?>
                                                <td class="<?php echo $td_class; ?>" 
                                                    data-employee-id="<?php echo $employee['id']; ?>" 
                                                    data-date="<?php echo $date; ?>" 
                                                    data-hours="<?php echo $hours; ?>" 
                                                    data-team-id="<?php echo $cell_team_id ?? ''; ?>"
                                                    data-hourly-rate-id="<?php echo $cell_hourly_rate_id ?? ''; ?>"
                                                    data-comments="<?php echo htmlspecialchars($comments); ?>"
                                                    <?php echo $tooltip_attrs; ?>>
                                                    <?php echo htmlspecialchars($display_hours); ?>
                                                </td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_sum > 0 ? rtrim(rtrim(number_format($row_sum, 2, '.', ' '), '0'), '.') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Сменный заработок работников (грн) -->
        <div class="card mt-3">
            <div class="card-body">
                <div class="section-title mb-2">Сменный заработок работников (грн)</div>
                <div class="table-responsive">
                    <table class="table timesheet-table">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <?php for ($day = $day_start; $day <= $day_end; $day++):
                                    $ts = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
                                    $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                    $th_class = $is_weekend ? 'day-weekend' : '';
                                ?>
                                    <th class="<?php echo $th_class; ?>"><?php echo $day; ?></th>
                                <?php endfor; ?>
                                <th>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allEmployees)): ?>
                                <tr>
                                    <td colspan="<?php echo $display_days_count + 2; ?>" class="text-center text-muted">Нет сотрудников</td>
                                </tr>
                            <?php else: ?>
                                <?php if (!empty($supervisors)): ?>
                                    <?php foreach ($supervisors as $employee):
                                        $row_total = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_total += $shift_earnings[$employee['id']][$dt] ?? 0;
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++):
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $amt = $shift_earnings[$employee['id']][$date] ?? 0;
                                                $td_class = $is_weekend ? 'day-weekend' : '';
                                            ?>
                                                <td class="<?php echo $td_class; ?>"><?php echo $amt > 0 ? number_format($amt, 2, '.', ' ') : ''; ?></td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_total > 0 ? number_format($row_total, 2, '.', ' ') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php foreach ($teams as $team):
                                    if (!isset($employeesByTeam[$team['id']]) || empty($employeesByTeam[$team['id']])) continue;
                                    foreach ($employeesByTeam[$team['id']] as $employee):
                                        $row_total = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_total += $shift_earnings[$employee['id']][$dt] ?? 0;
                                        }
                                ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++):
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $amt = $shift_earnings[$employee['id']][$date] ?? 0;
                                                $cell_hourly = $shift_earnings_hourly[$employee['id']][$date] ?? false;
                                                $td_class = $is_weekend ? 'day-weekend' : '';
                                                if ($cell_hourly) $td_class .= ' pay-hourly';
                                            ?>
                                                <td class="<?php echo trim($td_class); ?>"<?php echo $cell_hourly ? ' title="Почасово"' : ''; ?>><?php echo $amt > 0 ? number_format($amt, 2, '.', ' ') : ''; ?></td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_total > 0 ? number_format($row_total, 2, '.', ' ') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; endforeach; ?>
                                <?php if (!empty($employeesWithoutTeam)): ?>
                                    <?php foreach ($employeesWithoutTeam as $employee):
                                        $row_total = 0;
                                        for ($d = $day_start; $d <= $day_end; $d++) {
                                            $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                                            $row_total += $shift_earnings[$employee['id']][$dt] ?? 0;
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                            <?php for ($day = $day_start; $day <= $day_end; $day++):
                                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                                $ts = strtotime($date);
                                                $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                                $amt = $shift_earnings[$employee['id']][$date] ?? 0;
                                                $cell_hourly = $shift_earnings_hourly[$employee['id']][$date] ?? false;
                                                $td_class = $is_weekend ? 'day-weekend' : '';
                                                if ($cell_hourly) $td_class .= ' pay-hourly';
                                            ?>
                                                <td class="<?php echo trim($td_class); ?>"<?php echo $cell_hourly ? ' title="Почасово"' : ''; ?>><?php echo $amt > 0 ? number_format($amt, 2, '.', ' ') : ''; ?></td>
                                            <?php endfor; ?>
                                            <td class="text-center fw-semibold"><?php echo $row_total > 0 ? number_format($row_total, 2, '.', ' ') : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Таблица заработка бригады за смену -->
        <div class="card mt-3">
            <div class="card-body">
                <div class="section-title mb-2">Заработок бригады за смену (грн)</div>
                <div class="table-responsive">
                    <table class="table timesheet-table">
                        <thead>
                            <tr>
                                <th>Бригада</th>
                                <?php for ($day = $day_start; $day <= $day_end; $day++):
                                    $ts = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
                                    $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                    $th_class = $is_weekend ? 'day-weekend' : '';
                                ?>
                                    <th class="<?php echo $th_class; ?>"><?php echo $day; ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($team_num = 1; $team_num <= 4; $team_num++): ?>
                            <tr>
                                <td>Бригада <?php echo $team_num; ?></td>
                                <?php for ($day = $day_start; $day <= $day_end; $day++):
                                    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                    $ts = strtotime($date);
                                    $is_weekend = in_array((int)date('N', $ts), [6, 7]);
                                    $sum = $brigade_earnings[$team_num][$date] ?? 0;
                                    $pay_mode_cell = $brigade_shift_pay_mode[$team_num][$date] ?? 'piece';
                                    $td_class = ($is_weekend ? 'day-weekend ' : '') . 'brigade-cell-clickable' . ($pay_mode_cell === 'hourly' ? ' pay-hourly' : '');
                                    $title_brigade = $pay_mode_cell === 'hourly' ? 'Закрыто почасово. Клик: выбор способа закрытия ЗП (сдельно/почасово)' : 'Клик: выбор способа закрытия ЗП (сдельно/почасово)';
                                ?>
                                    <td class="<?php echo $td_class; ?>" data-team-id="<?php echo $team_num; ?>" data-date="<?php echo $date; ?>" data-pay-mode="<?php echo $pay_mode_cell; ?>" title="<?php echo htmlspecialchars($title_brigade); ?>"><?php echo $sum > 0 ? rtrim(rtrim(number_format($sum, 2, '.', ' '), '0'), '.') : ''; ?></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
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
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="number" step="0.25" min="0" max="24" class="form-control" id="hours_worked" name="hours_worked" required style="width: 10ch;">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setHours(8)">8 ч</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setHours(11.5)">11.5 ч</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setHours(0)">Очистить</button>
                            </div>
                        </div>
                        <div class="mb-3 team-btn-group">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="form-label mb-0">Бригада</span>
                                <?php foreach ($teams as $t): ?>
                                <div class="form-check d-flex align-items-stretch mb-0">
                                    <input class="form-check-input" type="radio" name="team_id" id="team_id_<?php echo (int)$t['id']; ?>" value="<?php echo (int)$t['id']; ?>">
                                    <label class="btn btn-outline-secondary btn-sm btn-team mb-0" for="team_id_<?php echo (int)$t['id']; ?>"><?php echo (int)$t['id']; ?></label>
                                </div>
                                <?php endforeach; ?>
                                <div class="d-flex align-items-center gap-2 ms-2">
                                    <input class="form-check-input" type="radio" name="team_id" id="team_id_0" value="">
                                    <label class="form-label mb-0 text-muted" for="team_id_0">Индивидуально</label>
                                    <select class="form-select form-select-sm" name="hourly_rate_id" id="hourly_rate_id" style="width: auto; min-width: 140px;">
                                        <option value="">— тариф —</option>
                                        <?php foreach ($hourly_worker_rates as $hr): ?>
                                        <option value="<?php echo (int)$hr['id']; ?>"><?php echo htmlspecialchars($hr['name']); ?> (<?php echo number_format($hr['rate_per_hour'], 2, '.', ' '); ?> грн/ч)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleComments" aria-expanded="false">
                                <span id="toggleCommentsIcon">+</span> Добавить комментарий
                            </button>
                            <div id="commentsBlock" class="mt-2" style="display: none;">
                                <label for="comments" class="form-label">Комментарии</label>
                                <textarea class="form-control" id="comments" name="comments" rows="3"></textarea>
                            </div>
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

    <!-- Модальное окно: способ закрытия ЗП бригады за смену -->
    <div class="modal fade" id="brigadePayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="brigadePayModalLabel">Закрытие ЗП бригады за смену</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="brigadePayModalText">Бригада <span id="brigadePayTeam">1</span>, дата <span id="brigadePayDate">—</span>.</p>
                    <p class="small text-muted mb-2">Сейчас: <span id="brigadePayCurrentMode">сдельно</span>. Выберите способ закрытия:</p>
                    <input type="hidden" id="brigadePayTeamId" value="">
                    <input type="hidden" id="brigadePayDateVal" value="">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary" id="brigadePaySetHourly">Закрыть почасово</button>
                        <button type="button" class="btn btn-outline-secondary" id="brigadePaySetPiece">Закрыть сдельно</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
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
                var teamId = $(this).data('team-id');
                var hourlyRateId = $(this).data('hourly-rate-id');
                $('#employee_id').val(employeeId);
                $('#date').val(date);
                $('#hours_worked').val(hours || '');
                $('#comments').val(comments || '');
                var teamVal = (teamId !== undefined && teamId !== null && teamId !== '') ? String(teamId) : '';
                $('input[name="team_id"]').prop('checked', false);
                $('input[name="team_id"][value="' + teamVal + '"]').prop('checked', true);
                var hrVal = (hourlyRateId !== undefined && hourlyRateId !== null && hourlyRateId !== '') ? String(hourlyRateId) : '';
                $('#hourly_rate_id').val(hrVal || '');
                if (comments) {
                    $('#commentsBlock').show();
                    $('#toggleComments').attr('aria-expanded', 'true').html('<span id="toggleCommentsIcon">−</span> Свернуть');
                } else {
                    $('#commentsBlock').hide();
                    $('#toggleComments').attr('aria-expanded', 'false').html('<span id="toggleCommentsIcon">+</span> Добавить комментарий');
                }
                $('#hoursModal').modal('show');
            });

            $('#toggleComments').on('click', function() {
                var block = $('#commentsBlock');
                if (block.is(':visible')) {
                    block.hide();
                    $(this).attr('aria-expanded', 'false').html('<span id="toggleCommentsIcon">+</span> Добавить комментарий');
                } else {
                    block.show();
                    $(this).attr('aria-expanded', 'true').html('<span id="toggleCommentsIcon">−</span> Свернуть');
                }
            });

            $('input[name="team_id"]').on('change', function() {
                $('.team-btn-group label.btn-team').removeClass('btn-primary').addClass('btn-outline-secondary');
                $(this).next('label.btn-team').removeClass('btn-outline-secondary').addClass('btn-primary');
            });

            $('#hoursModal').on('show.bs.modal', function() {
                var checked = $('input[name="team_id"]:checked');
                $('.team-btn-group label.btn-team').removeClass('btn-primary').addClass('btn-outline-secondary');
                if (checked.length) checked.next('label.btn-team').removeClass('btn-outline-secondary').addClass('btn-primary');
            });

            // После закрытия модального окна обновляем таблицу (перезагрузка страницы)
            $('#hoursModal').on('hidden.bs.modal', function () {
                location.reload();
            });

            // Модальное окно выбора способа закрытия ЗП бригады за смену
            $(document).on('click', '.brigade-cell-clickable', function() {
                var $cell = $(this);
                var teamId = $cell.data('team-id');
                var date = $cell.data('date');
                var payMode = $cell.data('pay-mode') || 'piece';
                if (!teamId || !date) return;
                var dateParts = date.split('-');
                var dateDisplay = dateParts.length === 3 ? (dateParts[2] + '.' + dateParts[1] + '.' + dateParts[0]) : date;
                $('#brigadePayTeam').text(teamId);
                $('#brigadePayDate').text(dateDisplay);
                $('#brigadePayCurrentMode').text(payMode === 'hourly' ? 'почасово' : 'сдельно');
                $('#brigadePayTeamId').val(teamId);
                $('#brigadePayDateVal').val(date);
                $('#brigadePayModal').modal('show');
            });

            function saveBrigadePayMode(payMode) {
                var teamId = $('#brigadePayTeamId').val();
                var date = $('#brigadePayDateVal').val();
                if (!teamId || !date) return;
                $.ajax({
                    url: 'timesheet_brigade_pay_mode.php',
                    type: 'POST',
                    data: { team_id: teamId, date: date, pay_mode: payMode },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#brigadePayModal').modal('hide');
                            location.reload();
                        } else {
                            alert('Ошибка: ' + (response.error || 'Неизвестная ошибка'));
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Ошибка при сохранении: ' + error);
                    }
                });
            }
            $('#brigadePaySetHourly').on('click', function() { saveBrigadePayMode('hourly'); });
            $('#brigadePaySetPiece').on('click', function() { saveBrigadePayMode('piece'); });

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
                var teamVal = ($('input[name="team_id"]:checked').val() || '').toString();
                var formData = {
                    employee_id: $('#employee_id').val(),
                    date: $('#date').val(),
                    hours_worked: $('#hours_worked').val() || 0,
                    team_id: teamVal,
                    hourly_rate_id: (teamVal === '' ? ($('#hourly_rate_id').val() || '') : ''),
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
                            var teamId = formData.team_id || '';
                            var hourlyRateId = formData.hourly_rate_id || '';
                            var comments = formData.comments;
                            var cell = $(`td[data-employee-id="${employeeId}"][data-date="${date}"]`);
                            var displayHours = '';
                            if (hours && parseFloat(hours) != 0) {
                                displayHours = parseFloat(hours).toFixed(2).replace(/\.?0+$/, '');
                            }
                            cell.text(displayHours);
                            cell.data('hours', hours);
                            cell.data('team-id', teamId);
                            cell.data('hourly-rate-id', hourlyRateId);
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

