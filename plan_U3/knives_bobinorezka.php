<?php
/**
 * Страница учета ножей бобинорезки
 * Календарная таблица состояний ножей
 */

// Проверяем авторизацию
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

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

// Проверяем доступ к цеху U3
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
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('knives_db_init.php');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

// Определяем период (3 месяца)
$monthOffset = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$today = new DateTime();
$startDate = clone $today;
$startDate->modify('first day of this month');
if ($monthOffset !== 0) {
    $startDate->modify(($monthOffset > 0 ? '+' : '') . $monthOffset . ' months');
}

$endDate = clone $startDate;
$endDate->modify('+3 months -1 day'); // 3 месяца

// Создаем массив дат для отображения (все дни за 3 месяца)
$dates = [];
$currentDate = clone $startDate;
$endDateClone = clone $endDate;
while ($currentDate <= $endDateClone) {
    $dates[] = $currentDate->format('Y-m-d');
    $currentDate->modify('+1 day');
}

$knife_type = 'bobinorezka';
$stmt = $pdo->prepare("SELECT id, knife_name FROM knives WHERE knife_type = ? ORDER BY knife_name");
$stmt->execute([$knife_type]);

$knives = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $knives[$row['id']] = $row;
}

// Получаем данные календаря для всех ножей
$calendar_data = [];
$todayStr = (new DateTime())->format('Y-m-d');

if (!empty($knives)) {
    $knife_ids = array_keys($knives);
    $placeholders = implode(',', array_fill(0, count($knife_ids), '?'));
    $types = str_repeat('i', count($knife_ids));
    
    // Для каждой даты получаем статус
    foreach ($dates as $date) {
        $isFuture = ($date > $todayStr);
        
        if ($isFuture) {
            $stmt = $pdo->prepare("
                SELECT knife_id, status
                FROM knives_calendar
                WHERE knife_id IN ($placeholders) 
                  AND date = ?
            ");
            
            $params = array_merge($knife_ids, [$date]);
        } else {
            $stmt = $pdo->prepare("
                SELECT kc1.knife_id, kc1.status
                FROM knives_calendar kc1
                WHERE kc1.knife_id IN ($placeholders) 
                  AND kc1.date <= ?
                  AND kc1.date = (
                      SELECT MAX(kc2.date)
                      FROM knives_calendar kc2
                      WHERE kc2.knife_id = kc1.knife_id AND kc2.date <= ?
                  )
            ");
            
            $params = array_merge($knife_ids, [$date, $date]);
        }
        
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // #region agent log
            if (strpos($row['status'], 'sharpening') !== false || $row['status'] === 'out_to_sharpening') {
                file_put_contents('c:\\xampp\\htdocs\\.cursor\\debug.log', json_encode(['hypothesisId'=>'A','location'=>'knives_bobinorezka.php:calendar_data','message'=>'DB row status','data'=>['knife_id'=>$row['knife_id'],'date'=>$date,'status'=>$row['status'],'status_raw_len'=>strlen($row['status'])],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
            }
            // #endregion
            if (!isset($calendar_data[$row['knife_id']])) {
                $calendar_data[$row['knife_id']] = [];
            }
            $calendar_data[$row['knife_id']][$date] = $row['status'];
        }
    }
    // #region agent log
    $all_statuses = [];
    foreach ($calendar_data as $by_date) {
        foreach ($by_date as $s) { $all_statuses[$s] = true; }
    }
    file_put_contents('c:\\xampp\\htdocs\\.cursor\\debug.log', json_encode(['hypothesisId'=>'D','location'=>'knives_bobinorezka.php:after_calendar','message'=>'unique statuses in calendar_data','data'=>['unique_statuses'=>array_keys($all_statuses)],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    // #endregion
    // Загружаем все записи календаря для расчёта статистики за всё время (с начала учёта)
    $all_time_rows = [];
    $placeholders_at = implode(',', array_fill(0, count($knife_ids), '?'));
    $stmt = $pdo->prepare("SELECT knife_id, date, status FROM knives_calendar WHERE knife_id IN ($placeholders_at) ORDER BY knife_id, date");
    $stmt->execute($knife_ids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $all_time_rows[] = $row;
    }
} else {
    $all_time_rows = [];
}

// Вычисляем статистику за всё время для каждого ножа (с начала учёта до сегодня)
$statistics = [];
$todayStr = (new DateTime())->format('Y-m-d');
$todayDt = new DateTime($todayStr);

foreach ($knives as $knife_id => $knife) {
    $stats = [
        'in_stock' => 0,
        'in_sharpening' => 0,
        'out_to_sharpening' => 0,
        'in_work' => 0,
        'total_days' => 0,
        'last_status' => null,
        'last_status_date' => null
    ];
    
    $knife_rows = array_filter($all_time_rows, function ($r) use ($knife_id) { return (int)$r['knife_id'] === (int)$knife_id; });
    $knife_rows = array_values($knife_rows);
    
    if (empty($knife_rows)) {
        $stats['percent_in_stock'] = 0;
        $stats['percent_in_sharpening'] = 0;
        $stats['percent_in_work'] = 0;
        $statistics[$knife_id] = $stats;
        continue;
    }
    
    $first_date = $knife_rows[0]['date'];
    $last_status = $knife_rows[count($knife_rows) - 1]['status'];
    $last_status_date = $knife_rows[count($knife_rows) - 1]['date'];
    $stats['last_status'] = $last_status;
    $stats['last_status_date'] = $last_status_date;
    
    for ($i = 0; $i < count($knife_rows); $i++) {
        $d1 = $knife_rows[$i]['date'];
        $s1 = $knife_rows[$i]['status'];
        if ($i + 1 < count($knife_rows)) {
            $d2 = $knife_rows[$i + 1]['date'];
            $days = (new DateTime($d2))->diff(new DateTime($d1))->days;
        } else {
            $end = $todayDt > new DateTime($d1) ? $todayDt : new DateTime($d1);
            $days = (new DateTime($d1))->diff($end)->days + 1;
        }
        $stats[$s1] += $days;
        $stats['total_days'] += $days;
    }
    
    $total = $stats['total_days'];
    $stats['percent_in_stock'] = $total > 0 ? round(($stats['in_stock'] / $total) * 100, 1) : 0;
    $stats['percent_in_sharpening'] = $total > 0 ? round(($stats['in_sharpening'] / $total) * 100, 1) : 0;
    $stats['percent_in_work'] = $total > 0 ? round(($stats['in_work'] / $total) * 100, 1) : 0;
    
    $statistics[$knife_id] = $stats;
}

// Функция форматирования даты (компактный формат)
function formatDate($date) {
    $dt = new DateTime($date);
    return $dt->format('d.m');
}

// Функция получения названия месяца для группировки
function getMonthHeader($date) {
    $dt = new DateTime($date);
    $months = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 
               'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    return $months[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

// Функция получения класса статуса
function getStatusClass($status) {
    $classes = [
        'in_stock' => 'status-in-stock',
        'in_sharpening' => 'status-in-sharpening',
        'out_to_sharpening' => 'status-out-to-sharpening',
        'in_work' => 'status-in-work'
    ];
    $result = $classes[$status] ?? '';
    // #region agent log
    if ($status !== null && (strpos((string)$status, 'out_to') !== false || $result === '')) {
        file_put_contents('c:\\xampp\\htdocs\\.cursor\\debug.log', json_encode(['hypothesisId'=>'B','location'=>'knives_bobinorezka.php:getStatusClass','message'=>'getStatusClass in/out','data'=>['status'=>$status,'result'=>$result,'has_key'=>isset($classes[$status])],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }
    // #endregion
    return $result;
}

// Функция получения названия статуса
function getStatusName($status) {
    $names = [
        'in_stock' => 'В запасе',
        'in_sharpening' => 'В заточке',
        'out_to_sharpening' => 'Выведен в заточку',
        'in_work' => 'В работе'
    ];
    return $names[$status] ?? '';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ножи бобинорезки - U3</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        h1 {
            color: #1f2937;
            font-size: 24px;
        }
        .nav-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-success:hover {
            background: #059669;
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }
        .calendar-table th,
        .calendar-table td {
            border: 1px solid #e5e7eb;
            padding: 4px 6px;
            text-align: center;
            font-size: 11px;
        }
        .calendar-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .calendar-table th:first-child {
            position: sticky;
            left: 0;
            background: #f9fafb;
            z-index: 11;
            min-width: 150px;
            font-size: 13px;
        }
        .calendar-table td:first-child {
            position: sticky;
            left: 0;
            background: white;
            z-index: 9;
            font-weight: 500;
            text-align: left;
            font-size: 13px;
            padding: 8px;
        }
        .calendar-table tr:hover td:first-child {
            background: #f9fafb;
        }
        .status-cell {
            cursor: pointer;
            min-width: 35px;
            width: 35px;
            height: 30px;
            transition: all 0.2s;
            font-size: 10px;
            padding: 2px;
        }
        .status-cell:hover {
            opacity: 0.8;
            transform: scale(1.05);
        }
        .status-in-stock {
            background: #10b981;
            color: white;
        }
        .status-in-sharpening {
            background: #f59e0b;
            color: white;
        }
        .status-out-to-sharpening {
            background: #facc15;
            color: #713f12;
        }
        .status-in-work {
            background: #3b82f6;
            color: white;
        }
        .status-empty {
            background: #f3f4f6;
            color: #9ca3af;
        }
        .today {
            background: #fef3c7 !important;
            font-weight: 600;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }
        .close {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: #9ca3af;
        }
        .close:hover {
            color: #374151;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #374151;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 6px;
            display: none;
        }
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 12px;
        }
        .history-table th,
        .history-table td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            text-align: left;
        }
        .history-table th {
            background: #f9fafb;
            font-weight: 600;
        }
        .statistics-section {
            margin-top: 30px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .statistics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .statistics-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .statistics-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .statistics-header {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .statistics-body {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }
        .stat-label {
            color: #6b7280;
            font-weight: 500;
        }
        .stat-value {
            font-weight: 600;
        }
        .stat-in-stock {
            color: #10b981;
        }
        .stat-in-sharpening {
            color: #f59e0b;
        }
        .stat-in-work {
            color: #3b82f6;
        }
        .stat-last {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e5e7eb;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ножи бобинорезки</h1>
            <div class="nav-buttons">
                <a href="?month=<?php echo $monthOffset - 1; ?>" class="btn btn-secondary">← Месяц назад</a>
                <a href="?month=0" class="btn btn-secondary">Текущий месяц</a>
                <a href="?month=<?php echo $monthOffset + 1; ?>" class="btn btn-secondary">Месяц вперед →</a>
                <button onclick="openAddKnifeModal()" class="btn btn-success">+ Добавить комплект</button>
            </div>
        </div>
        
        <div id="message" class="message"></div>
        
        <div style="overflow-x: auto;">
            <table class="calendar-table">
                <thead>
                    <tr>
                        <th>Комплект ножей</th>
                        <?php 
                        $todayStr = (new DateTime())->format('Y-m-d');
                        // Сначала создаем строку с заголовками месяцев
                        $monthHeaders = [];
                        $currentMonth = '';
                        $monthStartIndex = 0;
                        foreach ($dates as $index => $date) {
                            $dt = new DateTime($date);
                            $monthKey = $dt->format('Y-m');
                            
                            if ($monthKey !== $currentMonth) {
                                if ($currentMonth !== '') {
                                    // Сохраняем информацию о предыдущем месяце
                                    $monthHeaders[] = [
                                        'start' => $monthStartIndex,
                                        'end' => $index - 1,
                                        'month' => $currentMonth,
                                        'name' => getMonthHeader($dates[$monthStartIndex])
                                    ];
                                }
                                $currentMonth = $monthKey;
                                $monthStartIndex = $index;
                            }
                        }
                        // Добавляем последний месяц
                        if ($currentMonth !== '') {
                            $monthHeaders[] = [
                                'start' => $monthStartIndex,
                                'end' => count($dates) - 1,
                                'month' => $currentMonth,
                                'name' => getMonthHeader($dates[$monthStartIndex])
                            ];
                        }
                        
                        // Выводим заголовки месяцев
                        foreach ($monthHeaders as $header) {
                            $colspan = $header['end'] - $header['start'] + 1;
                            echo '<th colspan="' . $colspan . '" style="background: #e5e7eb; font-weight: 600; font-size: 12px;">' . htmlspecialchars($header['name']) . '</th>';
                        }
                        ?>
                    </tr>
                    <tr>
                        <th>Комплект ножей</th>
                        <?php 
                        foreach ($dates as $date): 
                            $isToday = ($date === $todayStr);
                            $dt = new DateTime($date);
                        ?>
                            <th class="<?php echo $isToday ? 'today' : ''; ?>" title="<?php echo $dt->format('d.m.Y'); ?>">
                                <?php echo $dt->format('d'); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($knives)): ?>
                        <tr>
                            <td colspan="<?php echo count($dates) + 1; ?>" style="text-align: center; padding: 40px; color: #9ca3af;">
                                Нет комплектов ножей. <a href="#" onclick="openAddKnifeModal(); return false;">Добавить первый комплект</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($knives as $knife_id => $knife): ?>
                            <tr>
                                <td>
                                    <a href="#" onclick="showHistory(<?php echo $knife_id; ?>, '<?php echo htmlspecialchars($knife['knife_name'], ENT_QUOTES); ?>'); return false;">
                                        <?php echo htmlspecialchars($knife['knife_name']); ?>
                                    </a>
                                </td>
                                <?php foreach ($dates as $date): 
                                    $status = $calendar_data[$knife_id][$date] ?? null;
                                    $statusClass = $status ? getStatusClass($status) : 'status-empty';
                                    $statusText = $status ? getStatusName($status) : '';
                                    // #region agent log
                                    if ($status !== null && $status !== '') {
                                        static $_logOutCount = 0;
                                        if (strpos($status, 'out_to') !== false || $_logOutCount < 2) {
                                            $_logOutCount++;
                                            file_put_contents('c:\\xampp\\htdocs\\.cursor\\debug.log', json_encode(['hypothesisId'=>'D','location'=>'knives_bobinorezka.php:render_td','message'=>'cell status and class','data'=>['knife_id'=>$knife_id,'date'=>$date,'status'=>$status,'statusClass'=>$statusClass],'timestamp'=>round(microtime(true)*1000)], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
                                        }
                                    }
                                    // #endregion
                                ?>
                                    <td class="status-cell <?php echo $statusClass; ?>" 
                                        onclick="openStatusModal(<?php echo $knife_id; ?>, '<?php echo htmlspecialchars($knife['knife_name'], ENT_QUOTES); ?>', '<?php echo $date; ?>', '<?php echo $status ?? ''; ?>')"
                                        title="<?php echo htmlspecialchars($knife['knife_name']); ?> - <?php echo (new DateTime($date))->format('d.m.Y'); ?>: <?php echo $statusText ?: 'Не установлен'; ?>">
                                        <?php 
                                        // Показываем только иконку или короткий текст
                                        if ($status) {
                                            if ($status === 'in_stock') echo '✓';
                                            elseif ($status === 'in_sharpening') echo '⚙';
                                            elseif ($status === 'out_to_sharpening') echo '→';
                                            else echo '●';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Блок статистики -->
        <?php if (!empty($knives)): ?>
        <div class="statistics-section">
            <h3 style="margin: 20px 0 10px 0; color: #374151; font-size: 16px; font-weight: 600;">Статистика с начала учёта</h3>
            <div class="statistics-grid">
                <?php foreach ($knives as $knife_id => $knife): 
                    $stats = $statistics[$knife_id] ?? [];
                ?>
                    <div class="statistics-card">
                        <div class="statistics-header">
                            <strong><?php echo htmlspecialchars($knife['knife_name']); ?></strong>
                        </div>
                        <div class="statistics-body">
                            <div class="stat-row">
                                <span class="stat-label">В запасе:</span>
                                <span class="stat-value stat-in-stock">
                                    <?php echo $stats['in_stock'] ?? 0; ?> дн. 
                                    (<?php echo $stats['percent_in_stock'] ?? 0; ?>%)
                                </span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">В заточке:</span>
                                <span class="stat-value stat-in-sharpening">
                                    <?php echo $stats['in_sharpening'] ?? 0; ?> дн. 
                                    (<?php echo $stats['percent_in_sharpening'] ?? 0; ?>%)
                                </span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">В работе:</span>
                                <span class="stat-value stat-in-work">
                                    <?php echo $stats['in_work'] ?? 0; ?> дн. 
                                    (<?php echo $stats['percent_in_work'] ?? 0; ?>%)
                                </span>
                            </div>
                            <?php if ($stats['last_status'] ?? null): ?>
                            <div class="stat-row stat-last">
                                <span class="stat-label">Последний статус:</span>
                                <span class="stat-value stat-<?php echo $stats['last_status']; ?>">
                                    <?php echo getStatusName($stats['last_status']); ?>
                                    (<?php echo (new DateTime($stats['last_status_date']))->format('d.m.Y'); ?>)
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно установки статуса -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Установить статус</div>
                <span class="close" onclick="closeStatusModal()">&times;</span>
            </div>
            <form id="statusForm">
                <input type="hidden" id="status_knife_id" name="knife_id">
                <input type="hidden" id="status_date" name="date">
                <div class="form-group">
                    <label>Комплект ножей:</label>
                    <input type="text" id="status_knife_name" readonly>
                </div>
                <div class="form-group">
                    <label>Дата:</label>
                    <input type="date" id="status_date_input" name="date_input" required>
                </div>
                <div class="form-group">
                    <label>Статус:</label>
                    <select id="status_status" name="status" required>
                        <option value="">-- Выберите статус --</option>
                        <option value="in_stock">В запасе</option>
                        <option value="in_sharpening">В заточке</option>
                        <option value="out_to_sharpening">Выведен в заточку</option>
                        <option value="in_work">В работе</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Комментарий (необязательно):</label>
                    <textarea id="status_comment" name="comment"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeStatusModal()" class="btn btn-secondary">Отмена</button>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Модальное окно добавления ножа -->
    <div id="addKnifeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Добавить комплект ножей</div>
                <span class="close" onclick="closeAddKnifeModal()">&times;</span>
            </div>
            <form id="addKnifeForm">
                <div class="form-group">
                    <label>Название комплекта:</label>
                    <input type="text" id="add_knife_name" name="knife_name" required>
                </div>
                <div class="form-group">
                    <label>Описание (необязательно):</label>
                    <textarea id="add_knife_description" name="description"></textarea>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddKnifeModal()" class="btn btn-secondary">Отмена</button>
                    <button type="submit" class="btn btn-success">Добавить</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Модальное окно истории -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">История изменений: <span id="history_knife_name"></span></div>
                <span class="close" onclick="closeHistoryModal()">&times;</span>
            </div>
            <div id="historyContent">
                <p>Загрузка...</p>
            </div>
        </div>
    </div>
    
    <script>
        // Установка статуса
        function openStatusModal(knifeId, knifeName, date, currentStatus) {
            document.getElementById('status_knife_id').value = knifeId;
            document.getElementById('status_knife_name').value = knifeName;
            document.getElementById('status_date').value = date;
            document.getElementById('status_date_input').value = date;
            document.getElementById('status_status').value = currentStatus || '';
            document.getElementById('status_comment').value = '';
            document.getElementById('statusModal').style.display = 'block';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        document.getElementById('statusForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'set_status');
            formData.append('knife_id', document.getElementById('status_knife_id').value);
            formData.append('date', document.getElementById('status_date_input').value);
            formData.append('status', document.getElementById('status_status').value);
            formData.append('comment', document.getElementById('status_comment').value);
            
            fetch('knives_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Проверяем, что ответ действительно JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        throw new Error('Сервер вернул не JSON. Ответ: ' + text.substring(0, 200));
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showMessage('Статус успешно установлен', 'success');
                    closeStatusModal();
                    setTimeout(() => location.reload(), 500);
                } else {
                    showMessage('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            })
            .catch(error => {
                showMessage('Ошибка: ' + error.message, 'error');
                console.error('Ошибка при установке статуса:', error);
            });
        });
        
        // Добавление ножа
        function openAddKnifeModal() {
            document.getElementById('add_knife_name').value = '';
            document.getElementById('add_knife_description').value = '';
            document.getElementById('addKnifeModal').style.display = 'block';
        }
        
        function closeAddKnifeModal() {
            document.getElementById('addKnifeModal').style.display = 'none';
        }
        
        document.getElementById('addKnifeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'add_knife');
            formData.append('knife_name', document.getElementById('add_knife_name').value);
            formData.append('knife_type', 'bobinorezka');
            formData.append('description', document.getElementById('add_knife_description').value);
            
            fetch('knives_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('Комплект ножей успешно добавлен', 'success');
                    closeAddKnifeModal();
                    setTimeout(() => location.reload(), 500);
                } else {
                    showMessage('Ошибка: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showMessage('Ошибка: ' + error.message, 'error');
            });
        });
        
        // История изменений
        function showHistory(knifeId, knifeName) {
            document.getElementById('history_knife_name').textContent = knifeName;
            document.getElementById('historyContent').innerHTML = '<p>Загрузка...</p>';
            document.getElementById('historyModal').style.display = 'block';
            
            const formData = new FormData();
            formData.append('action', 'get_history');
            formData.append('knife_id', knifeId);
            
            fetch('knives_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    
                    // Показываем описание комплекта, если оно есть
                    if (data.description && data.description.trim() !== '') {
                        html += '<div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px; margin-bottom: 15px;">';
                        html += '<div style="font-weight: 600; color: #0369a1; margin-bottom: 4px; font-size: 13px;">Описание комплекта:</div>';
                        html += '<div style="color: #0c4a6e; font-size: 13px; line-height: 1.5;">' + escapeHtml(data.description) + '</div>';
                        html += '</div>';
                    }
                    
                    if (data.history.length === 0) {
                        html += '<p>История изменений отсутствует</p>';
                    } else {
                        html += '<table class="history-table"><thead><tr><th>Дата</th><th>Статус</th><th>Пользователь</th><th>Комментарий</th></tr></thead><tbody>';
                        data.history.forEach(item => {
                            const statusNames = {
                                'in_stock': 'В запасе',
                                'in_sharpening': 'В заточке',
                                'out_to_sharpening': 'Выведен в заточку',
                                'in_work': 'В работе'
                            };
                            html += `<tr>
                                <td>${item.date}</td>
                                <td>${statusNames[item.status] || item.status}</td>
                                <td>${item.user_name || '-'}</td>
                                <td>${item.comment || '-'}</td>
                            </tr>`;
                        });
                        html += '</tbody></table>';
                    }
                    
                    document.getElementById('historyContent').innerHTML = html;
                } else {
                    document.getElementById('historyContent').innerHTML = '<p style="color: red;">Ошибка: ' + data.error + '</p>';
                }
            })
            .catch(error => {
                document.getElementById('historyContent').innerHTML = '<p style="color: red;">Ошибка: ' + error.message + '</p>';
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }
        
        // Закрытие модальных окон при клике вне их
        window.onclick = function(event) {
            const statusModal = document.getElementById('statusModal');
            const addKnifeModal = document.getElementById('addKnifeModal');
            const historyModal = document.getElementById('historyModal');
            
            if (event.target == statusModal) {
                closeStatusModal();
            }
            if (event.target == addKnifeModal) {
                closeAddKnifeModal();
            }
            if (event.target == historyModal) {
                closeHistoryModal();
            }
        }
        
        function showMessage(text, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = text;
            messageDiv.className = 'message ' + type;
            messageDiv.style.display = 'block';
            
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 3000);
        }
    </script>
</body>
</html>
