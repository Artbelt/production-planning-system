<?php
// NP_monitor.php — мониторинг выполнения процессов: порезка, гофрирование, сборка
$dsn = "mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4";
$user = "root";
$pass = "";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $order = $_GET['order'] ?? '';
    
    // 1. ПОРЕЗКА БУХТ - сравниваем план с фактом
    $cutSql = "
        SELECT 
            COUNT(DISTINCT rp.bale_id) as total_bales,
            SUM(CASE WHEN rp.done = 1 THEN 1 ELSE 0 END) as done_bales,
            MIN(rp.work_date) as start_date,
            MAX(rp.work_date) as end_date
        FROM roll_plans rp
        WHERE 1=1 " . ($order ? "AND rp.order_number = ?" : "");
    
    $stmt = $pdo->prepare($cutSql);
    $order ? $stmt->execute([$order]) : $stmt->execute();
    $cutData = $stmt->fetch();
    $cutData['current_date'] = date('Y-m-d');
    
    // Детальные данные по бухтам с датами
    $cutDetailsSql = "
        SELECT 
            rp.work_date,
            rp.bale_id,
            rp.done,
            rp.order_number,
            c.material
        FROM roll_plans rp
        LEFT JOIN cut_plans c ON c.order_number = rp.order_number AND c.bale_id = rp.bale_id
        WHERE 1=1 " . ($order ? "AND rp.order_number = ?" : "") . "
        GROUP BY rp.work_date, rp.bale_id, rp.done, rp.order_number, c.material
        ORDER BY rp.work_date, rp.bale_id";
    
    $stmt = $pdo->prepare($cutDetailsSql);
    $order ? $stmt->execute([$order]) : $stmt->execute();
    $cutDetails = $stmt->fetchAll();
    
    // Группируем по датам
    $cutByDate = [];
    foreach ($cutDetails as $detail) {
        $date = $detail['work_date'];
        if (!isset($cutByDate[$date])) {
            $cutByDate[$date] = [];
        }
        $cutByDate[$date][] = $detail;
    }
    
    // 2. ГОФРИРОВАНИЕ - план vs факт
    $corrSql = "
        SELECT 
            SUM(cp.count) as total_packs,
            SUM(cp.fact_count) as done_packs,
            MIN(cp.plan_date) as start_date,
            MAX(cp.plan_date) as end_date
        FROM corrugation_plan cp
        WHERE 1=1 " . ($order ? "AND cp.order_number = ?" : "");
    
    $stmt = $pdo->prepare($corrSql);
    $order ? $stmt->execute([$order]) : $stmt->execute();
    $corrData = $stmt->fetch();
    $corrData['current_date'] = date('Y-m-d');
    
    // Детальные данные по гофрированию с датами
    $corrDetailsSql = "
        SELECT 
            cp.plan_date,
            cp.filter_label,
            cp.count,
            cp.fact_count,
            cp.order_number,
            pps.p_p_height as height
        FROM corrugation_plan cp
        LEFT JOIN salon_filter_structure sfs ON TRIM(sfs.filter) = TRIM(cp.filter_label)
        LEFT JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE 1=1 " . ($order ? "AND cp.order_number = ?" : "") . "
        ORDER BY cp.plan_date, cp.filter_label";
    
    $stmt = $pdo->prepare($corrDetailsSql);
    $order ? $stmt->execute([$order]) : $stmt->execute();
    $corrDetails = $stmt->fetchAll();
    
    // Группируем по датам
    $corrByDate = [];
    foreach ($corrDetails as $detail) {
        $date = $detail['plan_date'];
        if (!isset($corrByDate[$date])) {
            $corrByDate[$date] = [];
        }
        $corrByDate[$date][] = $detail;
    }
    
    // 3. СБОРКА - план vs факт
    $buildSql = "
        SELECT 
            SUM(bp.count) as total_items,
            SUM(bp.fact_count) as done_items,
            MIN(bp.plan_date) as start_date,
            MAX(bp.plan_date) as end_date
        FROM build_plan bp
        WHERE 1=1 " . ($order ? "AND bp.order_number = ?" : "");
    
    $stmt = $pdo->prepare($buildSql);
    $order ? $stmt->execute([$order]) : $stmt->execute();
    $buildData = $stmt->fetch();
    $buildData['current_date'] = date('Y-m-d');
    
    // Функция для расчета статуса (опережение/отставание)
    function calculateStatus($startDate, $endDate, $currentDate, $totalPlanned, $totalDone) {
        if (!$startDate || !$endDate || !$totalPlanned) {
            return ['status' => 'no-data', 'percent' => 0, 'message' => 'Нет данных', 'class' => 'muted'];
        }
        
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $current = new DateTime($currentDate);
        
        // Если план еще не начался
        if ($current < $start) {
            return ['status' => 'not-started', 'percent' => 0, 'message' => 'План еще не начался', 'class' => 'muted'];
        }
        
        // Если план завершен
        if ($current > $end) {
            $percentDone = $totalPlanned > 0 ? round(($totalDone / $totalPlanned) * 100, 1) : 0;
            $class = $percentDone >= 100 ? 'success' : 'warning';
            return ['status' => 'completed', 'percent' => $percentDone, 'message' => 'План завершен', 'class' => $class];
        }
        
        // План в процессе - считаем, сколько должно быть сделано к текущей дате
        $totalDays = $start->diff($end)->days + 1;
        $elapsedDays = $start->diff($current)->days + 1;
        
        $expectedDone = ($elapsedDays / $totalDays) * $totalPlanned;
        $percentDone = $totalPlanned > 0 ? round(($totalDone / $totalPlanned) * 100, 1) : 0;
        $percentExpected = round(($expectedDone / $totalPlanned) * 100, 1);
        
        $diff = $totalDone - $expectedDone;
        $diffPercent = round(($diff / $totalPlanned) * 100, 1);
        
        if ($diffPercent > 5) {
            $class = 'success';
            $message = 'Опережаем на ' . abs(round($diff)) . ' (' . abs($diffPercent) . '%)';
        } elseif ($diffPercent < -5) {
            $class = 'danger';
            $message = 'Отстаем на ' . abs(round($diff)) . ' (' . abs($diffPercent) . '%)';
        } else {
            $class = 'on-track';
            $message = 'В графике';
        }
        
        return [
            'status' => 'in-progress',
            'percent' => $percentDone,
            'expected' => $percentExpected,
            'message' => $message,
            'class' => $class,
            'done' => $totalDone,
            'total' => $totalPlanned
        ];
    }
    
    $cutStatus = calculateStatus(
        $cutData['start_date'], 
        $cutData['end_date'], 
        $cutData['current_date'],
        $cutData['total_bales'],
        $cutData['done_bales']
    );
    
    $corrStatus = calculateStatus(
        $corrData['start_date'], 
        $corrData['end_date'], 
        $corrData['current_date'],
        $corrData['total_packs'],
        $corrData['done_packs']
    );
    
    $buildStatus = calculateStatus(
        $buildData['start_date'], 
        $buildData['end_date'], 
        $buildData['current_date'],
        $buildData['total_items'],
        $buildData['done_items']
    );
    
} catch(Exception $e) {
    // Инициализируем данные по умолчанию
    $cutData = ['total_bales' => 0, 'done_bales' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    $corrData = ['total_packs' => 0, 'done_packs' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    $buildData = ['total_items' => 0, 'done_items' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    
    $cutStatus = $corrStatus = $buildStatus = ['status' => 'error', 'percent' => 0, 'message' => 'Ошибка: ' . $e->getMessage(), 'class' => 'danger'];
}

// Проверяем, что данные загружены корректно
if (!isset($cutData) || $cutData === null) {
    $cutData = ['total_bales' => 0, 'done_bales' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    $cutStatus = ['status' => 'no-data', 'percent' => 0, 'message' => 'Нет данных', 'class' => 'muted'];
}
if (!isset($corrData) || $corrData === null) {
    $corrData = ['total_packs' => 0, 'done_packs' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    $corrStatus = ['status' => 'no-data', 'percent' => 0, 'message' => 'Нет данных', 'class' => 'muted'];
}
if (!isset($buildData) || $buildData === null) {
    $buildData = ['total_items' => 0, 'done_items' => 0, 'start_date' => null, 'end_date' => null, 'current_date' => date('Y-m-d')];
    $buildStatus = ['status' => 'no-data', 'percent' => 0, 'message' => 'Нет данных', 'class' => 'muted'];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг выполнения | Производство</title>
    <style>
        :root {
            --background: hsl(220, 20%, 97%);
            --foreground: hsl(220, 15%, 15%);
            --card: hsl(0, 0%, 100%);
            --primary: hsl(217, 91%, 60%);
            --success: hsl(142, 71%, 45%);
            --warning: hsl(38, 92%, 50%);
            --danger: hsl(0, 84%, 60%);
            --muted: hsl(220, 14%, 96%);
            --muted-foreground: hsl(220, 10%, 45%);
            --border: hsl(220, 13%, 91%);
            --radius: 0.75rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--background);
            color: var(--foreground);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
        }

        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .last-update {
            font-size: 0.875rem;
            color: var(--muted-foreground);
        }

        /* Три секции процессов */
        .processes-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }

        .process-section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .process-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .process-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .process-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .icon-cut {
            background: hsla(217, 91%, 60%, 0.1);
            color: var(--primary);
        }

        .icon-corr {
            background: hsla(38, 92%, 50%, 0.1);
            color: var(--warning);
        }

        .icon-build {
            background: hsla(142, 71%, 45%, 0.1);
            color: var(--success);
        }

        /* Прогресс-бар */
        .progress-container {
            margin-bottom: 1rem;
        }

        .progress-bar-wrapper {
            height: 2rem;
            background: var(--muted);
            border-radius: calc(var(--radius) - 2px);
            overflow: hidden;
            position: relative;
        }

        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            color: white;
        }

        .progress-success {
            background: linear-gradient(90deg, var(--success) 0%, hsl(142, 71%, 55%) 100%);
        }

        .progress-warning {
            background: linear-gradient(90deg, var(--warning) 0%, hsl(38, 92%, 60%) 100%);
        }

        .progress-danger {
            background: linear-gradient(90deg, var(--danger) 0%, hsl(0, 84%, 70%) 100%);
        }

        .progress-on-track {
            background: linear-gradient(90deg, var(--primary) 0%, hsl(217, 91%, 70%) 100%);
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        .stat-card {
            background: var(--muted);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            padding: 0.75rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--muted-foreground);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
        }

        .stat-sublabel {
            font-size: 0.6875rem;
            color: var(--muted-foreground);
            margin-top: 0.125rem;
        }

        /* Статус-бейдж */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-success {
            background: hsla(142, 71%, 45%, 0.1);
            color: var(--success);
        }

        .status-warning {
            background: hsla(38, 92%, 50%, 0.1);
            color: var(--warning);
        }

        .status-danger {
            background: hsla(0, 84%, 60%, 0.1);
            color: var(--danger);
        }

        .status-muted {
            background: var(--muted);
            color: var(--muted-foreground);
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Авто-обновление индикатор */
        .auto-update {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--muted-foreground);
        }

        .pulse {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Календарь бухт - горизонтальная лента */
        .calendar-wrapper {
            margin-top: 1rem;
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .nav-btn {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--foreground);
            font-size: 1.25rem;
            transition: all 0.2s;
        }

        .nav-btn:hover {
            background: var(--secondary);
            border-color: var(--primary);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
        }

        .day-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            padding: 0.625rem 0.375rem;
            transition: all 0.2s;
            min-height: 100px;
        }

        .day-card.today {
            border: 2px solid var(--primary);
            box-shadow: 0 0 0 3px hsla(217, 91%, 60%, 0.1);
        }

        .day-card.overdue {
            border-color: var(--danger);
            background: hsla(0, 84%, 60%, 0.05);
        }

        .day-header {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.375rem;
            padding-bottom: 0.375rem;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        .day-date {
            font-size: 0.625rem;
            color: var(--muted-foreground);
            display: block;
            margin-top: 0.0625rem;
        }

        .bales-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            justify-content: center;
        }

        /* Ограничение высоты для гофро-списка и кнопка разворота */
        .bales-list.corr-limited {
            max-height: 96px; /* ~5 рядов при текущих размерах чипов */
            overflow: hidden;
            position: relative;
        }

        .fade-bottom {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 28px;
            background: linear-gradient(to bottom, rgba(255,255,255,0), var(--card));
            pointer-events: none;
        }

        .show-more {
            margin-top: 0.4rem;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--foreground);
            border-radius: 8px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
        }

        /* Модалка деталей смены */
        .modal-backdrop {position: fixed; inset: 0; background: rgba(0,0,0,0.35); display:none; align-items:center; justify-content:center; z-index:100;}
        .modal {background: var(--card); border:1px solid var(--border); border-radius: 10px; max-width: 800px; width: calc(100% - 32px); box-shadow: 0 10px 30px rgba(0,0,0,0.2);}
        .modalHeader {display:flex; align-items:center; justify-content:space-between; padding: 10px 14px; border-bottom:1px solid var(--border);}
        .modalTitle {font-size: 15px; font-weight: 700;}
        .modalClose {border:1px solid var(--border); background: var(--card); border-radius:8px; padding:4px 10px; cursor:pointer; font-size: 12px;}
        .modalBody {padding: 12px; max-height: 60vh; overflow:auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;}
        .item-tile {border-radius:8px; padding:8px 10px; border: 1px solid; display: flex; flex-direction: column; gap: 4px;}
        .item-tile.done {background: hsla(142, 71%, 45%, 0.1); border-color: var(--success);}
        .item-tile.pending {background: hsla(38, 92%, 50%, 0.1); border-color: var(--warning);}
        .item-tile.overdue {background: hsla(0, 84%, 60%, 0.1); border-color: var(--danger);}
        .item-name {font-weight:600; font-size: 13px; line-height: 1.3;}
        .item-sub {font-size:11px; color: var(--muted-foreground);}
        .item-stats {font-size:13px; font-weight:600; margin-top: auto;}
        .item-stats.done {color: var(--success);}
        .item-stats.pending {color: var(--warning);}
        .item-stats.overdue {color: var(--danger);}
        }

        .bale-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 500;
            border: 1px solid;
            line-height: 1.2;
        }

        .bale-done {
            background: hsla(142, 71%, 45%, 0.1);
            color: var(--success);
            border-color: var(--success);
        }

        .bale-pending {
            background: hsla(38, 92%, 50%, 0.1);
            color: var(--warning);
            border-color: var(--warning);
        }

        .bale-overdue {
            background: hsla(0, 84%, 60%, 0.1);
            color: var(--danger);
            border-color: var(--danger);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M2 12h20"/>
                </svg>
                Мониторинг выполнения
            </h1>
            <div class="auto-update">
                <span class="pulse"></span>
                <span class="last-update">Обновлено: <span id="lastUpdate"><?= date('H:i:s') ?></span></span>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="processes-grid">
            
            <!-- 1. ПОРЕЗКА БУХТ -->
            <div class="process-section">
                <div class="process-header">
                    <div class="process-title">
                        <div class="process-icon icon-cut">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="6" cy="6" r="3"/>
                                <circle cx="18" cy="18" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                        </div>
                        Порезка бухт
                    </div>
                </div>

                <!-- Календарь бухт по дням -->
                <div class="calendar-wrapper">
                    <div class="calendar-nav">
                        <button class="nav-btn" onclick="shiftWeek(-7)" title="Предыдущие 7 дней">←</button>
                        <button class="btn-secondary btn-sm" onclick="resetToToday()" style="flex: 1;">Сегодня</button>
                        <button class="nav-btn" onclick="shiftWeek(7)" title="Следующие 7 дней">→</button>
                    </div>
                    <div class="calendar-grid" id="calendarGrid">
                        <!-- Заполняется через JavaScript -->
                    </div>
                </div>
</div>

            <!-- 2. ГОФРИРОВАНИЕ -->
            <div class="process-section">
                <div class="process-header">
                    <div class="process-title">
                        <div class="process-icon icon-corr">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
                                <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                                <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
                                <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                                <path d="M7 12h10"/>
                            </svg>
                        </div>
                        Гофрирование
                    </div>
    </div>

                <!-- Календарь гофрирования по дням -->
                <div class="calendar-wrapper">
                    <div class="calendar-nav">
                        <button class="nav-btn" onclick="shiftCorrWeek(-7)" title="Предыдущие 7 дней">←</button>
                        <button class="btn-secondary btn-sm" onclick="resetCorrToToday()" style="flex: 1;">Сегодня</button>
                        <button class="nav-btn" onclick="shiftCorrWeek(7)" title="Следующие 7 дней">→</button>
    </div>
                    <div class="calendar-grid" id="corrCalendarGrid">
                        <!-- Заполняется через JavaScript -->
    </div>
    </div>
</div>

        </div>
</div>

    <!-- Modal: детали смены гофрирования -->
    <div class="modal-backdrop" id="corrModal">
        <div class="modal">
            <div class="modalHeader">
                <div class="modalTitle" id="corrModalTitle">Детали смены</div>
                <button class="modalClose" onclick="closeCorrModal()">Закрыть</button>
            </div>
            <div class="modalBody" id="corrModalBody"></div>
        </div>
    </div>

    <script>
        // Данные из PHP
        const cutByDate = <?= json_encode($cutByDate, JSON_UNESCAPED_UNICODE) ?>;
        const corrByDate = <?= json_encode($corrByDate, JSON_UNESCAPED_UNICODE) ?>;
        
        // Текущая позиция календарей (центральный день)
        let centerDate = new Date();
        let corrCenterDate = new Date();
        
        function formatDate(date) {
            return date.toISOString().split('T')[0];
        }
        
        function addDays(date, days) {
            const result = new Date(date);
            result.setDate(result.getDate() + days);
            return result;
        }
        
        function renderCalendar() {
            const grid = document.getElementById('calendarGrid');
            if (!grid) return;
            
            grid.innerHTML = '';
            const today = formatDate(new Date());
            
            // Показываем 7 дней: 3 до centerDate, centerDate, 3 после
            for (let i = -3; i <= 3; i++) {
                const date = addDays(centerDate, i);
                const dateStr = formatDate(date);
                const bales = cutByDate[dateStr] || [];
                
                const isToday = dateStr === today;
                const hasOverdue = bales.some(b => b.done != 1 && dateStr < today);
                
                const card = document.createElement('div');
                card.className = 'day-card' + (isToday ? ' today' : '') + (hasOverdue ? ' overdue' : '');
                
                const dayNames = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
                const dayName = dayNames[date.getDay()];
                
                let balesHTML = '';
                let notDoneCount = 0;
                
                bales.forEach(bale => {
                    const isDone = bale.done == 1;
                    const isOverdue = !isDone && dateStr < today;
                    const chipClass = isDone ? 'bale-done' : (isOverdue ? 'bale-overdue' : 'bale-pending');
                    const title = `Бухта #${bale.bale_id} • ${bale.order_number || ''} • ${bale.material || ''}`;
                    
                    if (!isDone) notDoneCount++;
                    
                    balesHTML += `<span class="bale-chip ${chipClass}" title="${title}">#${bale.bale_id}</span>`;
                });
                
                if (balesHTML === '') {
                    balesHTML = '<span style="font-size:0.75rem;color:var(--muted-foreground)">—</span>';
                }
                
                // Отображаем количество непорезанных бухт
                const countBadge = notDoneCount > 0 
                    ? `<span style="font-size:10px;background:var(--warning);color:white;padding:2px 6px;border-radius:10px;margin-left:4px;">${notDoneCount}</span>`
                    : '';
                
                card.innerHTML = `
                    <div class="day-header">
                        <div>${date.getDate().toString().padStart(2, '0')}.${(date.getMonth() + 1).toString().padStart(2, '0')}${countBadge}</div>
                        <span class="day-date">${dayName}</span>
                    </div>
                    <div class="bales-list">${balesHTML}</div>
                `;
                
                grid.appendChild(card);
            }
        }
        
        function shiftWeek(days) {
            centerDate = addDays(centerDate, days);
            renderCalendar();
        }
        
        function resetToToday() {
            centerDate = new Date();
            renderCalendar();
        }
        
        // Календарь гофрирования
        function renderCorrCalendar() {
            const grid = document.getElementById('corrCalendarGrid');
            if (!grid) return;
            
            grid.innerHTML = '';
            const today = formatDate(new Date());
            
            // Показываем 7 дней: 3 до corrCenterDate, corrCenterDate, 3 после
            for (let i = -3; i <= 3; i++) {
                const date = addDays(corrCenterDate, i);
                const dateStr = formatDate(date);
                const items = corrByDate[dateStr] || [];
                
                const isToday = dateStr === today;
                const hasOverdue = items.some(item => {
                    const done = parseInt(item.fact_count) || 0;
                    const planned = parseInt(item.count) || 0;
                    return done < planned && dateStr < today;
                });
                
                const card = document.createElement('div');
                card.className = 'day-card' + (isToday ? ' today' : '') + (hasOverdue ? ' overdue' : '');
                
                const dayNames = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
                const dayName = dayNames[date.getDay()];
                
                let itemsHTML = '';
                let notDoneCount = 0;
                
                items.forEach(item => {
                    const planned = parseInt(item.count) || 0;
                    const done = parseInt(item.fact_count) || 0;
                    const isDone = done >= planned;
                    const isOverdue = !isDone && dateStr < today;
                    const chipClass = isDone ? 'bale-done' : (isOverdue ? 'bale-overdue' : 'bale-pending');
                    
                    if (!isDone) notDoneCount++;
                    
                    const heightStr = item.height ? ` [${Math.round(item.height)}]` : '';
                    const title = `${item.filter_label}${heightStr} • План: ${planned} шт • Факт: ${done} шт • ${item.order_number || ''}`;
                    
                    // Укорачиваем название фильтра
                    let filterName = item.filter_label || '';
                    if (filterName.length > 10) {
                        filterName = filterName.substring(0, 8) + '..';
                    }
                    
                    itemsHTML += `<span class="bale-chip ${chipClass}" title="${title}">${filterName} (${done}/${planned})</span>`;
                });
                
                if (itemsHTML === '') {
                    itemsHTML = '<span style="font-size:0.75rem;color:var(--muted-foreground)">—</span>';
                }
                
                // Отображаем количество несгофрированных наименований
                const countBadge = notDoneCount > 0 
                    ? `<span style="font-size:10px;background:var(--warning);color:white;padding:2px 6px;border-radius:10px;margin-left:4px;">${notDoneCount}</span>`
                    : '';
                
                card.innerHTML = `
                    <div class="day-header">
                        <div>${date.getDate().toString().padStart(2, '0')}.${(date.getMonth() + 1).toString().padStart(2, '0')}${countBadge}</div>
                        <span class="day-date">${dayName}</span>
                    </div>
                    <div class="bales-list corr-limited">${itemsHTML}</div>
                `;
                
                grid.appendChild(card);

                // Если контента больше 5 строк, показываем кнопку "Показать"
                const list = card.querySelector('.bales-list');
                const needsMore = list.scrollHeight > list.clientHeight + 2; // небольшой допуск
                if (needsMore) {
                    const fade = document.createElement('div');
                    fade.className = 'fade-bottom';
                    list.appendChild(fade);

                    const btn = document.createElement('button');
                    btn.className = 'show-more';
                    btn.textContent = 'Показать';
                    btn.addEventListener('click', () => openCorrModal(dateStr, items));
                    card.appendChild(btn);
                }
            }
        }
        
        function shiftCorrWeek(days) {
            corrCenterDate = addDays(corrCenterDate, days);
            renderCorrCalendar();
        }
        
        function resetCorrToToday() {
            corrCenterDate = new Date();
            renderCorrCalendar();
        }
        
        // Инициализация
        renderCalendar();
        renderCorrCalendar();
        
        // Обновляем время каждую секунду
        setInterval(() => {
            const now = new Date();
            document.getElementById('lastUpdate').textContent = 
                now.getHours().toString().padStart(2, '0') + ':' +
                now.getMinutes().toString().padStart(2, '0') + ':' +
                now.getSeconds().toString().padStart(2, '0');
        }, 1000);
        
        // Авто-обновление данных каждые 30 секунд
        setInterval(() => {
            location.reload();
        }, 30000);
        // ---- Модальное окно деталей смены ----
        function openCorrModal(dateStr, items) {
            const modal = document.getElementById('corrModal');
            const body = document.getElementById('corrModalBody');
            const title = document.getElementById('corrModalTitle');
            if (!modal || !body || !title) return;

            const d = new Date(dateStr);
            const today = formatDate(new Date());
            title.textContent = `Гофрирование · ${d.getDate().toString().padStart(2,'0')}.${(d.getMonth()+1).toString().padStart(2,'0')}.${d.getFullYear()}`;

            body.innerHTML = '';
            if (!items || items.length === 0) {
                body.innerHTML = '<div class="item-sub">Нет данных</div>';
            } else {
                items.forEach(item => {
                    const planned = parseInt(item.count) || 0;
                    const done = parseInt(item.fact_count) || 0;
                    const isDone = done >= planned;
                    const isOverdue = !isDone && dateStr < today;
                    
                    let statusClass = isDone ? 'done' : (isOverdue ? 'overdue' : 'pending');
                    
                    const tile = document.createElement('div');
                    tile.className = 'item-tile ' + statusClass;
                    
                    const heightStr = item.height ? Math.round(item.height) : '—';
                    
                    tile.innerHTML = `
                        <div class="item-name">${escapeHtml(item.filter_label || '')}</div>
                        <div class="item-sub">${escapeHtml(item.order_number || '')}</div>
                        <div class="item-sub">Высота: ${heightStr} мм</div>
                        <div class="item-stats ${statusClass}">Факт: ${done} / План: ${planned}</div>
                    `;
                    body.appendChild(tile);
                });
            }

            modal.style.display = 'flex';
        }

        function closeCorrModal() {
            const modal = document.getElementById('corrModal');
            if (modal) modal.style.display = 'flex' && (modal.style.display = 'none');
        }

        function escapeHtml(str){
            return String(str).replace(/[&<>"]+/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]));
        }
    </script>
</body>
</html>
