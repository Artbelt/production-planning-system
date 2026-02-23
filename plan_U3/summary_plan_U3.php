<?php
/**
 * summary_plan_U3.php — Сводный план для участка У3
 * Отображает позиции из всех активных заявок по датам
 * Навигация: неделя вперед/назад
 * По умолчанию: текущая неделя
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверяем авторизацию
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

// Получаем информацию о пользователе
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем доступ к У3
$hasAccessToU3 = false;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U3' && in_array($dept['role_name'], ['assembler', 'supervisor', 'director', 'manager'])) {
        $hasAccessToU3 = true;
        break;
    }
}

if (!$hasAccessToU3) {
    die('<div style="padding: 20px; text-align: center;">
        <h2>❌ Доступ запрещен</h2>
        <p>У вас нет доступа к сводному плану У3</p>
        <p><a href="main.php">← Вернуться на главную</a></p>
    </div>');
}

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

/* ========== ОПРЕДЕЛЕНИЕ ДАТЫ НАЧАЛА НЕДЕЛИ ========== */
// Получаем параметр смещения (в неделях относительно текущей)
$weekOffset = isset($_GET['week']) ? (int)$_GET['week'] : 0;

// Текущая дата
$today = new DateTime();

// Понедельник текущей недели
$mondayOfCurrentWeek = clone $today;
$dayOfWeek = (int)$mondayOfCurrentWeek->format('N'); // 1=понедельник, 7=воскресенье
if ($dayOfWeek !== 1) {
    $mondayOfCurrentWeek->modify('-' . ($dayOfWeek - 1) . ' days');
}

// Применяем смещение
if ($weekOffset !== 0) {
    $mondayOfCurrentWeek->modify(($weekOffset > 0 ? '+' : '') . $weekOffset . ' weeks');
}

$startDate = $mondayOfCurrentWeek->format('Y-m-d');
$endDate = (clone $mondayOfCurrentWeek)->modify('+6 days')->format('Y-m-d');

/* ========== ПОЛУЧЕНИЕ ДАННЫХ ИЗ БАЗЫ ========== */
// Получаем все активные заявки с их позициями
$stmt = $pdo->prepare("
    SELECT 
        o.order_number,
        o.filter,
        o.count as total_count
    FROM orders o
    WHERE (o.hide != 1 OR o.hide IS NULL)
    ORDER BY o.order_number, o.filter
");
$stmt->execute();
$orderPositions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Функция нормализации (объявляем заранее)
function normalizeFilterNameEarly($name) {
    $name = preg_replace('/\[.*$/', '', $name);
    $name = preg_replace('/[●◩⏃]/u', '', $name);
    $name = trim($name);
    // Приводим к верхнему регистру для унификации
    return mb_strtoupper($name, 'UTF-8');
}

// Создаем карту позиций по заявкам с нормализованными ключами
$positionsMap = [];
foreach ($orderPositions as $pos) {
    $normalizedFilter = normalizeFilterNameEarly($pos['filter']);
    $key = $pos['order_number'] . '|' . $normalizedFilter;
    $positionsMap[$key] = [
        'order' => $pos['order_number'],
        'filter' => $normalizedFilter,
        'total_count' => (int)$pos['total_count']
    ];
}

// Получаем план сборки для всех активных заявок на текущую неделю
$stmt = $pdo->prepare("
    SELECT 
        bp.order_number,
        bp.filter,
        bp.day_date,
        bp.shift,
        bp.qty
    FROM build_plans bp
    WHERE bp.day_date BETWEEN ? AND ?
        AND bp.order_number IN (SELECT order_number FROM orders WHERE (hide != 1 OR hide IS NULL))
    ORDER BY bp.day_date, bp.order_number, bp.filter
");
$stmt->execute([$startDate, $endDate]);
$planData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем факт производства для распределения
$stmt = $pdo->prepare("
    SELECT 
        name_of_order,
        TRIM(SUBSTRING_INDEX(name_of_filter,' [',1)) AS base_filter,
        date_of_production,
        SUM(count_of_filters) AS fact_count
    FROM manufactured_production
    WHERE name_of_order IN (SELECT order_number FROM orders WHERE (hide != 1 OR hide IS NULL))
    GROUP BY name_of_order, base_filter, date_of_production
");
$stmt->execute();
$factData = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========== ОБРАБОТКА ДАННЫХ ========== */
// Функция нормализации названий фильтров (используем ту же, что объявили выше)
function normalizeFilterName($name) {
    return normalizeFilterNameEarly($name);
}

// Группируем план по датам
$planByDate = [];
foreach ($planData as $row) {
    $date = $row['day_date'];
    if (!isset($planByDate[$date])) {
        $planByDate[$date] = [];
    }
    $normalizedFilter = normalizeFilterName($row['filter']);
    $key = $row['order_number'] . '|' . $normalizedFilter;
    if (!isset($planByDate[$date][$key])) {
        $planByDate[$date][$key] = [
            'order' => $row['order_number'],
            'filter' => $normalizedFilter,
            'plan' => 0,
            'fact' => 0
        ];
    }
    $planByDate[$date][$key]['plan'] += (int)$row['qty'];
}

// Распределяем факт по плану
$factMap = [];
foreach ($factData as $row) {
    $order = $row['name_of_order'];
    $filter = normalizeFilterName($row['base_filter']);
    $key = $order . '|' . $filter;
    if (!isset($factMap[$key])) {
        $factMap[$key] = [
            'total' => 0,
            'dates' => []
        ];
    }
    $factMap[$key]['total'] += (int)$row['fact_count'];
    if (!isset($factMap[$key]['dates'][$row['date_of_production']])) {
        $factMap[$key]['dates'][$row['date_of_production']] = 0;
    }
    $factMap[$key]['dates'][$row['date_of_production']] += (int)$row['fact_count'];
}

// Распределяем факт по плановым датам
foreach ($planByDate as $date => &$positions) {
    foreach ($positions as $key => &$pos) {
        // Добавляем информацию о полном количестве из заявки (ключи уже нормализованы)
        if (isset($positionsMap[$key])) {
            $pos['total_count'] = $positionsMap[$key]['total_count'];
        } else {
            $pos['total_count'] = 0;
        }
        
        // Добавляем общий факт по позиции
        if (isset($factMap[$key])) {
            $pos['total_fact'] = $factMap[$key]['total'];
            // Для этого дня берем факт, произведенный в этот день
            if (isset($factMap[$key]['dates'][$date])) {
                $pos['fact'] = min($factMap[$key]['dates'][$date], $pos['plan']);
            }
        } else {
            $pos['total_fact'] = 0;
        }
    }
}
unset($positions, $pos);

// Создаем массив дат для отображения
$dates = [];
$currentDate = clone $mondayOfCurrentWeek;
for ($i = 0; $i < 7; $i++) {
    $dates[] = $currentDate->format('Y-m-d');
    $currentDate->modify('+1 day');
}

// Статистика по неделе
$totalPositionsInWeek = 0;
$uniqueOrders = [];
$totalPlanWeek = 0;
$totalFactWeek = 0;

foreach ($planByDate as $date => $positions) {
    foreach ($positions as $pos) {
        $totalPositionsInWeek++;
        $uniqueOrders[$pos['order']] = true;
        $totalPlanWeek += $pos['plan'];
        $totalFactWeek += $pos['fact'];
    }
}

$totalOrdersCount = count($uniqueOrders);

/* ========== ФУНКЦИИ ========== */
function formatDate($date) {
    $dt = new DateTime($date);
    $daysOfWeek = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
    $dayOfWeek = $daysOfWeek[(int)$dt->format('w')];
    return $dayOfWeek . ' ' . $dt->format('d.m');
}

function getStatusClass($fact, $plan) {
    if ($plan == 0) return '';
    $percentage = ($fact / $plan) * 100;
    
    if ($percentage >= 100) {
        return 'ok';
    } elseif ($percentage >= 80) {
        return 'warn';
    } else {
        return 'bad';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сводный план У3</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #fff;
            --text: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --ok: #16a34a;
            --warn: #d97706;
            --bad: #dc2626;
            --accent: #2563eb;
            --header-bg: #f9fafb;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 16px;
            font-size: 14px;
        }
        
        h1 {
            text-align: center;
            margin: 6px 0 12px;
            font-weight: 400;
            font-size: 24px;
        }
        
        .toolbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px;
            background: var(--card);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .nav-btn {
            padding: 10px 20px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f3f4f6;
            color: #374151;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .nav-btn:hover {
            background: #e5e7eb;
        }
        
        .nav-btn.secondary {
            background: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        
        .nav-btn.secondary:hover {
            background: #e5e7eb;
        }
        
        .week-info {
            font-size: 16px;
            font-weight: 600;
            padding: 10px 20px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            color: #374151;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 12px;
            margin-top: 20px;
        }
        
        .day-column {
            background: var(--card);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            min-height: 400px;
            display: flex;
            flex-direction: column;
        }
        
        .day-header {
            background: var(--header-bg);
            padding: 12px;
            text-align: center;
            font-weight: 700;
            border-bottom: 2px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .day-header.today {
            background: #374151;
            color: white;
            border-bottom-color: #1f2937;
        }
        
        .day-content {
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .position-item {
            background: #fafafa;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px 10px;
            transition: all 0.2s;
            font-size: 13px;
            cursor: pointer;
            line-height: 1.4;
        }
        
        .position-item:hover {
            background: #f0f9ff;
            border-color: var(--accent);
            box-shadow: 0 2px 6px rgba(37,99,235,0.15);
        }
        
        .position-item.highlight {
            background: #fef3c7;
            border-color: #facc15;
            box-shadow: 0 0 0 2px rgba(250,204,21,0.35) inset;
        }
        
        .status-badge {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-badge.ok {
            color: var(--ok);
            border-color: #c9f2d9;
            background: #f1f9f4;
        }
        
        .status-badge.warn {
            color: var(--warn);
            border-color: #fde7c3;
            background: #fff9ed;
        }
        
        .status-badge.bad {
            color: var(--bad);
            border-color: #ffc9c9;
            background: #fff1f1;
        }
        
        .empty-day {
            color: var(--muted);
            text-align: center;
            padding: 20px;
            font-style: italic;
        }
        
        .totals {
            margin-top: auto;
            padding: 10px;
            border-top: 1px solid var(--line);
            background: var(--header-bg);
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 1400px) {
            .calendar-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .calendar-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 600px) {
            .calendar-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media print {
            @page { 
                size: landscape; 
                margin: 10mm; 
            }
            
            body {
                background: #fff;
                padding: 8px;
            }
            
            .toolbar {
                display: none;
            }
            
            h1 {
                font-size: 18px;
                margin: 0 0 8px;
            }
            
            [style*="position: fixed"] {
                display: none;
            }
            
            .day-column {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .day-header {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .position-item {
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }
            
            .status-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(7, 1fr);
                gap: 8px;
            }
        }
    </style>
</head>
<body>

<h1>Сводный план участка У3</h1>

<div class="toolbar">
    <a href="?week=<?= $weekOffset - 1 ?>" class="nav-btn">◀ Неделя назад</a>
    
    <div class="week-info">
        <?= formatDate($startDate) ?> — <?= formatDate($endDate) ?>
        <?php if ($weekOffset === 0): ?>
            (Текущая неделя)
        <?php elseif ($weekOffset > 0): ?>
            (+<?= $weekOffset ?> <?= $weekOffset === 1 ? 'неделя' : 'недель' ?>)
        <?php else: ?>
            (<?= $weekOffset ?> <?= abs($weekOffset) === 1 ? 'неделя' : 'недель' ?>)
        <?php endif; ?>
    </div>
    
    <a href="?week=<?= $weekOffset + 1 ?>" class="nav-btn">Неделя вперед ▶</a>
    
    <?php if ($weekOffset !== 0): ?>
        <a href="?" class="nav-btn secondary">⌂ Текущая неделя</a>
    <?php endif; ?>
</div>

<?php if ($totalPositionsInWeek == 0): ?>
<div style="margin: 32px auto; padding: 32px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 800px; text-align: center;">
    <div style="font-size: 48px; margin-bottom: 16px;">📋</div>
    <h2 style="margin: 0 0 12px; color: #374151;">Нет плана на эту неделю</h2>
    <p style="color: #6b7280; font-size: 16px; margin-bottom: 24px;">
        На неделю <?= formatDate($startDate) ?> — <?= formatDate($endDate) ?> пока не создан план сборки.
    </p>
    
    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
        <a href="NP_build_plan.php" style="padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
            📝 Создать план сборки
        </a>
        <a href="main.php" style="padding: 12px 24px; background: #6b7280; color: white; text-decoration: none; border-radius: 8px; font-weight: 600;">
            🏠 Главная
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($totalPositionsInWeek > 0): ?>
<div class="calendar-grid">
    <?php 
    $todayStr = (new DateTime())->format('Y-m-d');
    foreach ($dates as $date): 
        $isToday = ($date === $todayStr);
        $positions = $planByDate[$date] ?? [];
        $totalPlan = 0;
        $totalFact = 0;
        foreach ($positions as $pos) {
            $totalPlan += $pos['plan'];
            $totalFact += $pos['fact'];
        }
    ?>
        <div class="day-column">
            <div class="day-header <?= $isToday ? 'today' : '' ?>">
                <?= formatDate($date) ?>
            </div>
            
            <div class="day-content">
                <?php if (empty($positions)): ?>
                    <div class="empty-day">Нет задач</div>
                <?php else: ?>
                    <?php 
                    // Сортируем по заявкам и фильтрам
                    uasort($positions, function($a, $b) {
                        $cmp = strcmp($a['order'], $b['order']);
                        if ($cmp !== 0) return $cmp;
                        return strcmp($a['filter'], $b['filter']);
                    });
                    
                    foreach ($positions as $key => $pos): 
                        $statusClass = getStatusClass($pos['fact'], $pos['plan']);
                        $searchKey = mb_strtolower($pos['filter'] . ' ' . $pos['order']);
                        
                        // Прогресс по всей позиции заявки
                        $totalCount = $pos['total_count'] ?? 0;
                        $totalFact = $pos['total_fact'] ?? 0;
                        $remaining = $totalCount - $totalFact;
                        $overallProgress = $totalCount > 0 ? round(($totalFact / $totalCount) * 100) : 0;
                    ?>
                        <div class="position-item" data-search="<?= htmlspecialchars($searchKey) ?>" data-filter="<?= htmlspecialchars(mb_strtolower($pos['filter'])) ?>" data-order="<?= htmlspecialchars($pos['order']) ?>" title="<?= htmlspecialchars($pos['filter']) ?>">
                            <strong><?= htmlspecialchars($pos['filter']) ?></strong> · <?= $pos['plan'] ?> шт · <span style="color: #6b7280;"><?= htmlspecialchars($pos['order']) ?><?php if ($totalCount > 0): ?> <span style="font-size: 11px;">(ост: <?= $remaining ?> шт)</span><?php endif; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($positions)): ?>
                <div class="totals">
                    Всего: <?= $totalPlan ?> шт
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
// Подсветка одинаковых фильтров при наведении
const calendarGrid = document.querySelector('.calendar-grid');

if (calendarGrid) {
    function highlightSameFilter(filterName) {
        if (!filterName) return;
        document.querySelectorAll(`.position-item[data-filter="${CSS.escape(filterName)}"]`)
            .forEach(item => item.classList.add('highlight'));
    }

    function removeHighlight() {
        document.querySelectorAll('.position-item.highlight')
            .forEach(item => item.classList.remove('highlight'));
    }

    calendarGrid.addEventListener('mouseover', (e) => {
        const item = e.target.closest('.position-item');
        if (!item) return;
        
        const filter = item.getAttribute('data-filter');
        removeHighlight();
        highlightSameFilter(filter);
    });

    calendarGrid.addEventListener('mouseout', (e) => {
        const item = e.target.closest('.position-item');
        if (!item) return;
        
        const related = e.relatedTarget;
        if (!related || !related.closest || !related.closest('.position-item')) {
            removeHighlight();
        }
    });

    // Клик по позиции - переход на детальный план заявки
    calendarGrid.addEventListener('click', (e) => {
        const item = e.target.closest('.position-item');
        if (!item) return;
        
        const orderNumber = item.getAttribute('data-order');
        if (orderNumber) {
            window.location.href = 'view_production_plan.php?order=' + encodeURIComponent(orderNumber);
        }
    });
}

// Автоматический скролл к текущему дню при загрузке (для мобильных)
window.addEventListener('DOMContentLoaded', function() {
    const todayHeader = document.querySelector('.day-header.today');
    if (todayHeader) {
        const dayColumn = todayHeader.closest('.day-column');
        if (dayColumn) {
            // Небольшая задержка для корректной работы
            setTimeout(() => {
                dayColumn.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                    inline: 'center'
                });
            }, 100);
        }
    }
});
</script>

</body>
</html>
