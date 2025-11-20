<?php
// mobile_build_plan.php — мобильный календарь плана сборки для сборщиц
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dsn = "mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4";
$user = "root";
$pass = "";

// Получаем дату из параметров или используем текущую
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Валидация даты
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); 
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Получаем все уникальные даты с планами (для навигации)
    $datesStmt = $pdo->query("
        SELECT DISTINCT plan_date 
        FROM build_plan 
        ORDER BY plan_date
    ");
    $availableDates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Получаем план сборки для выбранной даты с информацией об остатках в буфере
    $stmt = $pdo->prepare("
        SELECT 
            bp.order_number,
            bp.plan_date,
            bp.filter,
            bp.count,
            bp.brigade,
            bp.done,
            bp.fact_count,
            COALESCE(sfs.build_complexity, 0) AS complexity,
            pps.p_p_height AS height,
            -- Общее количество в заявке по этой позиции
            COALESCE((
                SELECT SUM(COALESCE(bp2.count, 0))
                FROM build_plan bp2
                WHERE bp2.order_number = bp.order_number
                  AND bp2.filter = bp.filter
            ), 0) AS total_in_order,
            -- Расчет буфера гофропакетов
            COALESCE((
                SELECT SUM(COALESCE(c.fact_count, 0))
                FROM corrugation_plan c
                WHERE c.order_number = bp.order_number
                  AND c.filter_label = bp.filter
                  AND c.fact_count > 0
            ), 0) AS corrugated,
            COALESCE((
                SELECT SUM(COALESCE(m.count_of_filters, 0))
                FROM manufactured_production m
                WHERE m.name_of_order = bp.order_number
                  AND m.name_of_filter = bp.filter
            ), 0) AS assembled,
            (COALESCE((
                SELECT SUM(COALESCE(c.fact_count, 0))
                FROM corrugation_plan c
                WHERE c.order_number = bp.order_number
                  AND c.filter_label = bp.filter
                  AND c.fact_count > 0
            ), 0) - COALESCE((
                SELECT SUM(COALESCE(m.count_of_filters, 0))
                FROM manufactured_production m
                WHERE m.name_of_order = bp.order_number
                  AND m.name_of_filter = bp.filter
            ), 0)) AS buffer
        FROM build_plan bp
        LEFT JOIN salon_filter_structure sfs ON TRIM(sfs.filter) = TRIM(bp.filter)
        LEFT JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE bp.plan_date = ?
        ORDER BY bp.order_number, bp.brigade, bp.filter
    ");
    $stmt->execute([$selectedDate]);
    $planData = $stmt->fetchAll();
    
    // Группируем по заявкам и бригадам
    $planByOrderAndBrigade = [];
    foreach ($planData as $item) {
        $order = $item['order_number'];
        $brigade = $item['brigade'] ?? 1;
        
        if (!isset($planByOrderAndBrigade[$order])) {
            $planByOrderAndBrigade[$order] = [1 => [], 2 => []];
        }
        
        $planByOrderAndBrigade[$order][$brigade][] = $item;
    }
    
    // Находим индекс текущей даты
    $currentDateIndex = array_search($selectedDate, $availableDates);
    if ($currentDateIndex === false) {
        $currentDateIndex = 0;
    }
    
    // Предыдущая и следующая даты
    $prevDate = $currentDateIndex > 0 ? $availableDates[$currentDateIndex - 1] : null;
    $nextDate = $currentDateIndex < count($availableDates) - 1 ? $availableDates[$currentDateIndex + 1] : null;
    
} catch (Exception $e) {
    $planByOrderAndBrigade = [];
    $availableDates = [];
    $prevDate = null;
    $nextDate = null;
}

// Определяем день недели
$dayOfWeek = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($selectedDate))];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>План сборки — <?= date('d.m.Y', strtotime($selectedDate)) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            height: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            touch-action: pan-y;
        }

        .app-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            max-width: 100vw;
            overflow: hidden;
        }

        /* Шапка */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            flex-shrink: 0;
            position: relative;
            z-index: 100;
        }

        .date-navigation {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .nav-btn:active {
            background: rgba(255, 255, 255, 0.3);
        }

        .nav-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .current-date {
            text-align: center;
            flex: 1;
            margin: 0 12px;
        }

        .current-date .date-large {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .current-date .date-small {
            font-size: 14px;
            opacity: 0.9;
        }

        /* Переключатель машин */
        .machine-toggle {
            display: flex;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 4px;
            gap: 4px;
        }

        .machine-btn {
            flex: 1;
            padding: 10px 16px;
            background: transparent;
            border: none;
            color: white;
            font-size: 15px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .machine-btn.active {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Контент */
        .content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            padding: 16px;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .no-data-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .no-data-text {
            font-size: 16px;
        }

        /* Заявка */
        .order-card {
            background: white;
            border-radius: 8px;
            margin-bottom: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .order-header {
            display: none;
        }

        .order-number {
            font-size: 16px;
        }

        .order-stats {
            font-size: 11px;
            opacity: 0.9;
        }

        .items-list {
            padding: 0;
        }

        .item {
            border-bottom: 1px solid #f0f0f0;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .item:last-child {
            border-bottom: none;
        }

        .item.done {
            background: #f0f9f0;
        }

        .item-order {
            font-size: 10px;
            color: #9ca3af;
            margin-bottom: 0;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .item-name {
            font-weight: 600;
            font-size: 14px;
            color: #1f2937;
            line-height: 1.3;
            flex: 1;
        }

        .item-count {
            font-size: 15px;
            font-weight: 700;
            color: #667eea;
            white-space: nowrap;
        }

        .item-stats {
            font-size: 10px;
            color: #6b7280;
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .stat-item {
            white-space: nowrap;
        }

        .stat-buffer {
            font-weight: 600;
        }

        .stat-buffer.low {
            color: #dc2626;
        }

        .stat-buffer.medium {
            color: #f59e0b;
        }

        .stat-buffer.high {
            color: #059669;
        }

        .stat-made {
            font-weight: 600;
        }

        .stat-made.complete {
            color: #059669;
        }

        .stat-made.incomplete {
            color: #0d6efd;
        }

        .done-badge {
            background: #10b981;
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 4px;
        }

        /* Машина скрыта */
        .machine-content {
            display: none;
        }

        .machine-content.active {
            display: block;
        }

        /* Индикатор загрузки */
        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        /* Свайп индикатор */
        .swipe-hint {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
            z-index: 1000;
        }

        .swipe-hint.show {
            opacity: 1;
        }

        /* Анимация перехода */
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideInLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .slide-in-right {
            animation: slideInRight 0.3s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.3s ease-out;
        }

        /* Адаптивные стили для ПК */
        @media (min-width: 768px) {
            .app-container {
                max-width: 700px;
                margin: 0 auto;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 30px rgba(0,0,0,0.1);
            }

            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                background-attachment: fixed;
                padding: 20px;
            }

            .header {
                padding: 24px 32px;
            }

            .date-navigation {
                margin-bottom: 24px;
            }

            .nav-btn {
                width: 50px;
                height: 50px;
                min-width: 50px;
                font-size: 20px;
                border-radius: 50%;
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .current-date .date-large {
                font-size: 32px;
            }

            .current-date .date-small {
                font-size: 16px;
            }

            .machine-toggle {
                gap: 12px;
            }

            .machine-btn {
                padding: 12px 40px;
                font-size: 16px;
            }

            .content {
                padding: 24px;
            }

            .order-card {
                margin-bottom: 20px;
                border-radius: 12px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.08);
                transition: all 0.3s;
            }

            .order-card:hover {
                box-shadow: 0 8px 24px rgba(102, 126, 234, 0.15);
                transform: translateY(-2px);
            }

            .item {
                padding: 12px 16px;
            }

            .item-order {
                font-size: 11px;
            }

            .item-header {
                margin-bottom: 2px;
            }

            .item-name {
                font-size: 15px;
            }

            .item-count {
                font-size: 16px;
            }

            .item-stats {
                font-size: 11px;
                gap: 8px;
            }

            /* Убираем свайп-индикатор на ПК */
            .swipe-hint {
                display: none;
            }
        }

        /* Стили для больших экранов (Full HD+) */
        @media (min-width: 1400px) {
            .app-container {
                max-width: 800px;
            }

            .header {
                padding: 28px 40px;
            }

            .nav-btn {
                width: 56px;
                height: 56px;
                min-width: 56px;
                font-size: 22px;
            }

            .current-date .date-large {
                font-size: 36px;
            }

            .machine-btn {
                padding: 14px 50px;
                font-size: 17px;
            }

            .content {
                padding: 32px;
            }

            .order-card {
                margin-bottom: 24px;
            }

            .item {
                padding: 14px 20px;
            }

            .item-name {
                font-size: 16px;
            }

            .item-count {
                font-size: 17px;
            }

            .item-stats {
                font-size: 12px;
            }
        }

    </style>
</head>
<body>
    <div class="app-container">
        <!-- Шапка -->
        <div class="header">
            <div class="date-navigation">
                <button class="nav-btn" id="prevBtn" <?= $prevDate ? '' : 'disabled' ?> 
                        onclick="navigateToDate('<?= $prevDate ?>')">‹</button>
                <div class="current-date">
                    <div class="date-large"><?= date('d.m.Y', strtotime($selectedDate)) ?></div>
                    <div class="date-small"><?= $dayOfWeek ?></div>
                </div>
                <button class="nav-btn" id="nextBtn" <?= $nextDate ? '' : 'disabled' ?> 
                        onclick="navigateToDate('<?= $nextDate ?>')">›</button>
            </div>
            
            <div class="machine-toggle">
                <button class="machine-btn active" data-machine="1" onclick="switchMachine(1)">
                    Машина 1
                </button>
                <button class="machine-btn" data-machine="2" onclick="switchMachine(2)">
                    Машина 2
                </button>
            </div>
        </div>

        <!-- Контент для Машины 1 -->
        <div class="content machine-content active" id="machine1">
            <?php if (empty($planByOrderAndBrigade)): ?>
                <div class="no-data">
                    <div class="no-data-icon">📅</div>
                    <div class="no-data-text">Нет заданий на этот день</div>
                </div>
            <?php else: ?>
                <?php 
                $totalItems1 = 0;
                foreach ($planByOrderAndBrigade as $order => $brigades) {
                    if (!empty($brigades[1])) {
                        $totalItems1 += count($brigades[1]);
                    }
                }
                ?>
                <?php if ($totalItems1 === 0): ?>
                    <div class="no-data">
                        <div class="no-data-icon">✓</div>
                        <div class="no-data-text">Нет заданий для Машины 1</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($planByOrderAndBrigade as $order => $brigades): ?>
                        <?php if (!empty($brigades[1])): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <span class="order-number">Заявка <?= h($order) ?></span>
                                <span class="order-stats"><?= count($brigades[1]) ?> поз.</span>
                            </div>
                            <div class="items-list">
                                <?php foreach ($brigades[1] as $item): ?>
                                <?php 
                                    $buffer = (int)$item['buffer'];
                                    $bufferClass = 'high';
                                    if ($buffer < 50) {
                                        $bufferClass = 'low';
                                    } elseif ($buffer < 150) {
                                        $bufferClass = 'medium';
                                    }
                                    
                                    $factCount = (int)$item['assembled'];
                                    $totalInOrder = (int)$item['total_in_order'];
                                    $madeClass = $factCount >= $totalInOrder ? 'complete' : 'incomplete';
                                ?>
                                <div class="item <?= $item['done'] ? 'done' : '' ?>">
                                    <div class="item-order">
                                        Заявка: <?= h($item['order_number']) ?>
                                    </div>
                                    <div class="item-header">
                                        <div class="item-name"><?= h($item['filter']) ?></div>
                                        <div class="item-count">
                                            <?= $item['count'] ?> шт
                                            <?php if ($item['done']): ?>
                                                <span class="done-badge">✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="item-stats">
                                        <span class="stat-item">В заказе <strong><?= $totalInOrder ?></strong></span>
                                        <span class="stat-item">/ Изготовлено <strong class="stat-made <?= $madeClass ?>"><?= $factCount ?></strong></span>
                                        <span class="stat-item">/ Буфер <strong class="stat-buffer <?= $bufferClass ?>"><?= $buffer ?></strong></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Контент для Машины 2 -->
        <div class="content machine-content" id="machine2">
            <?php if (empty($planByOrderAndBrigade)): ?>
                <div class="no-data">
                    <div class="no-data-icon">📅</div>
                    <div class="no-data-text">Нет заданий на этот день</div>
                </div>
            <?php else: ?>
                <?php 
                $totalItems2 = 0;
                foreach ($planByOrderAndBrigade as $order => $brigades) {
                    if (!empty($brigades[2])) {
                        $totalItems2 += count($brigades[2]);
                    }
                }
                ?>
                <?php if ($totalItems2 === 0): ?>
                    <div class="no-data">
                        <div class="no-data-icon">✓</div>
                        <div class="no-data-text">Нет заданий для Машины 2</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($planByOrderAndBrigade as $order => $brigades): ?>
                        <?php if (!empty($brigades[2])): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <span class="order-number">Заявка <?= h($order) ?></span>
                                <span class="order-stats"><?= count($brigades[2]) ?> поз.</span>
                            </div>
                            <div class="items-list">
                                <?php foreach ($brigades[2] as $item): ?>
                                <?php 
                                    $buffer = (int)$item['buffer'];
                                    $bufferClass = 'high';
                                    if ($buffer < 50) {
                                        $bufferClass = 'low';
                                    } elseif ($buffer < 150) {
                                        $bufferClass = 'medium';
                                    }
                                    
                                    $factCount = (int)$item['assembled'];
                                    $totalInOrder = (int)$item['total_in_order'];
                                    $madeClass = $factCount >= $totalInOrder ? 'complete' : 'incomplete';
                                ?>
                                <div class="item <?= $item['done'] ? 'done' : '' ?>">
                                    <div class="item-order">
                                        Заявка: <?= h($item['order_number']) ?>
                                    </div>
                                    <div class="item-header">
                                        <div class="item-name"><?= h($item['filter']) ?></div>
                                        <div class="item-count">
                                            <?= $item['count'] ?> шт
                                            <?php if ($item['done']): ?>
                                                <span class="done-badge">✓</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="item-stats">
                                        <span class="stat-item">В заказе <strong><?= $totalInOrder ?></strong></span>
                                        <span class="stat-item">/ Изготовлено <strong class="stat-made <?= $madeClass ?>"><?= $factCount ?></strong></span>
                                        <span class="stat-item">/ Буфер <strong class="stat-buffer <?= $bufferClass ?>"><?= $buffer ?></strong></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Индикатор свайпа -->
    <div class="swipe-hint" id="swipeHint"></div>

    <script>
        let currentMachine = 1;
        let touchStartX = 0;
        let touchEndX = 0;
        
        // Переключение между машинами
        function switchMachine(machine) {
            if (currentMachine === machine) return;
            
            currentMachine = machine;
            
            // Обновляем кнопки
            document.querySelectorAll('.machine-btn').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.machine) === machine);
            });
            
            // Обновляем контент
            document.querySelectorAll('.machine-content').forEach(content => {
                content.classList.toggle('active', content.id === 'machine' + machine);
            });
        }
        
        // Навигация по датам
        function navigateToDate(date) {
            if (!date) return;
            window.location.href = '?date=' + encodeURIComponent(date);
        }
        
        // Обработка свайпов для навигации по датам
        const appContainer = document.querySelector('.app-container');
        
        appContainer.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        
        appContainer.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
        
        function handleSwipe() {
            const swipeThreshold = 100;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) < swipeThreshold) return;
            
            if (diff > 0) {
                // Свайп влево - следующий день
                const nextBtn = document.getElementById('nextBtn');
                if (!nextBtn.disabled) {
                    showSwipeHint('Следующий день →');
                    setTimeout(() => {
                        const date = nextBtn.onclick.toString().match(/'([^']+)'/)?.[1];
                        if (date) navigateToDate(date);
                    }, 200);
                }
            } else {
                // Свайп вправо - предыдущий день
                const prevBtn = document.getElementById('prevBtn');
                if (!prevBtn.disabled) {
                    showSwipeHint('← Предыдущий день');
                    setTimeout(() => {
                        const date = prevBtn.onclick.toString().match(/'([^']+)'/)?.[1];
                        if (date) navigateToDate(date);
                    }, 200);
                }
            }
        }
        
        function showSwipeHint(text) {
            const hint = document.getElementById('swipeHint');
            hint.textContent = text;
            hint.classList.add('show');
            setTimeout(() => {
                hint.classList.remove('show');
            }, 1500);
        }
        
        // Предотвращаем масштабирование двойным тапом
        let lastTouchEnd = 0;
        document.addEventListener('touchend', (e) => {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
        
        // Сохраняем состояние машины в localStorage
        window.addEventListener('beforeunload', () => {
            localStorage.setItem('selectedMachine', currentMachine);
        });
        
        // Восстанавливаем состояние при загрузке
        window.addEventListener('load', () => {
            const savedMachine = localStorage.getItem('selectedMachine');
            if (savedMachine) {
                switchMachine(parseInt(savedMachine));
            }
        });
    </script>
</body>
</html>

