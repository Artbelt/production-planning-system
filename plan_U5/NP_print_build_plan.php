<?php
// NP_print_build_plan.php — минималистичная страница для печати плана сборки
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/settings.php';

$order = $_GET['order'] ?? '';
$showFact = isset($_GET['fact']) && $_GET['fact'] !== '' && $_GET['fact'] !== '0';

// Если заявка не указана, попробуем получить первую активную
if ($order === '') {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $firstOrderStmt = $pdo->query("SELECT DISTINCT order_number FROM build_plan ORDER BY order_number DESC LIMIT 1");
        $firstOrder = $firstOrderStmt->fetchColumn();
        if ($firstOrder) {
            $order = $firstOrder;
        } else {
            http_response_code(400); 
            exit('Нет данных для отображения. В базе отсутствуют планы сборки.'); 
        }
    } catch (Exception $e) {
        http_response_code(400); 
        exit('Ошибка загрузки данных.'); 
    }
}

function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); 
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Получаем список всех активных заявок (у которых есть план сборки)
    $activeOrdersStmt = $pdo->query("
        SELECT DISTINCT order_number 
        FROM build_plan 
        ORDER BY order_number DESC
    ");
    $activeOrders = $activeOrdersStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Получаем план сборки для выбранной заявки
    $stmt = $pdo->prepare("
        SELECT 
            bp.plan_date,
            bp.filter,
            bp.count,
            bp.brigade,
            COALESCE(sfs.build_complexity, 0) AS complexity,
            pps.p_p_height AS height
        FROM build_plan bp
        LEFT JOIN salon_filter_structure sfs ON TRIM(sfs.filter) = TRIM(bp.filter)
        LEFT JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE bp.order_number = ?
        ORDER BY bp.plan_date, bp.brigade, bp.filter
    ");
    $stmt->execute([$order]);
    $planData = $stmt->fetchAll();
    
    // Группируем по датам и бригадам
    $planByDate = [];
    foreach ($planData as $item) {
        $date = $item['plan_date'];
        $brigade = $item['brigade'] ?? 1;
        
        if (!isset($planByDate[$date])) {
            $planByDate[$date] = [1 => [], 2 => []];
        }
        
        $planByDate[$date][$brigade][] = $item;
    }
    
    // Сортируем даты
    ksort($planByDate);
    
    // Факт выполнения: план и собрано по каждой позиции (при ?fact=1)
    $factByFilter = [];
    $slotFills = [];
    if ($showFact && !empty($order)) {
        // План: сумма по фильтру в build_plan
        $plannedStmt = $pdo->prepare("
            SELECT filter, SUM(COALESCE(count, 0)) AS total
            FROM build_plan
            WHERE order_number = ?
            GROUP BY filter
        ");
        $plannedStmt->execute([$order]);
        while ($r = $plannedStmt->fetch(PDO::FETCH_ASSOC)) {
            $f = trim($r['filter']);
            $factByFilter[$f] = ['planned' => (int)$r['total'], 'manufactured' => 0];
        }
        // Факт: собрано из manufactured_production (по базовому имени фильтра)
        $manufacturedByBase = [];
        $factStmt = $pdo->prepare("
            SELECT 
                TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) AS base_filter,
                SUM(COALESCE(count_of_filters, 0)) AS total
            FROM manufactured_production
            WHERE name_of_order = ?
            GROUP BY base_filter
        ");
        $factStmt->execute([$order]);
        while ($r = $factStmt->fetch(PDO::FETCH_ASSOC)) {
            $manufacturedByBase[trim($r['base_filter'])] = (int)$r['total'];
        }
        // Связываем план с фактом: для каждого фильтра берём manufactured по базовому имени
        foreach (array_keys($factByFilter) as $f) {
            $base = (strpos($f, ' [') !== false) ? trim(explode(' [', $f)[0]) : $f;
            $factByFilter[$f]['manufactured'] = $manufacturedByBase[$base] ?? $manufacturedByBase[$f] ?? 0;
        }
        // Последовательное закрашивание: идём по плану слева направо, «тратим» выполненное
        // Пример: 500 заказано, 400 сделано, план 100+100+150+150 → первые 3 полностью, последний 50/150
        $slotFills = [];
        foreach (array_keys($factByFilter) as $f) {
            $manufactured = $factByFilter[$f]['manufactured'];
            if ($manufactured <= 0) continue;
            // Собираем слоты в порядке отображения: по датам, бригада 1, потом бригада 2
            $slots = [];
            foreach ($planByDate as $date => $brigades) {
                foreach ([1, 2] as $br) {
                    foreach ($brigades[$br] ?? [] as $it) {
                        if (trim($it['filter']) === $f) {
                            $slots[] = ['date' => $date, 'brigade' => $br, 'count' => (int)($it['count'] ?? 0)];
                        }
                    }
                }
            }
            $remaining = $manufactured;
            foreach ($slots as $s) {
                $cnt = $s['count'];
                $key = $s['date'] . '|' . $s['brigade'] . '|' . $f;
                if ($cnt <= 0) {
                    $slotFills[$key] = ['pct' => 0, 'done' => 0, 'total' => 0];
                    continue;
                }
                $used = min($cnt, $remaining);
                $slotFills[$key] = ['pct' => 100 * $used / $cnt, 'done' => $used, 'total' => $cnt];
                $remaining -= $used;
            }
        }
    }
    
} catch (Exception $e) {
    $planByDate = [];
    $activeOrders = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План сборки<?= $showFact ? ' (факт)' : '' ?> — <?= h($order) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            background: white;
            color: #000;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }


        .page-container {
            display: flex;
            flex-direction: column;
            width: fit-content;
        }

        .brigade-section {
            margin-bottom: 8px;
            display: flex;
            flex-direction: column;
        }

        .brigade-section:last-child {
            margin-bottom: 0;
        }

        .brigade-header {
            font-size: 16px;
            font-weight: 400;
            padding: 8px;
            text-align: center;
            margin-bottom: 4px;
        }
        
        .brigade-subheader {
            font-size: 11px;
            color: #666;
            text-align: center;
            margin-bottom: 8px;
        }

        .days-grid {
            display: grid;
            grid-template-columns: repeat(14, 100px);
            gap: 0;
            grid-auto-flow: row;
            border-top: 1px solid #000;
            border-left: 1px solid #000;
        }
        
        /* Первая колонка и начало каждой недели имеют левую границу */
        .day-column:first-child {
            border-left: none;
        }

        .day-column {
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            display: flex;
            flex-direction: column;
        }

        .day-header {
            font-size: 10px;
            font-weight: 400;
            padding: 6px 4px;
            background: #f5f5f5;
            border: 1px solid #000;
            text-align: center;
            line-height: 1.3;
        }

        .items-container {
            padding: 4px;
            display: flex;
            flex-direction: column;
            min-height: 60px;
        }

        .item {
            border: 1px solid #333;
            padding: 4px 6px;
            margin-bottom: 2px;
            position: relative;
            overflow: hidden;
        }

        .item-fact-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            background: rgba(34, 197, 94, 0.35);
            z-index: 0;
        }

        .item > .item-name,
        .item > .item-details {
            position: relative;
            z-index: 1;
        }

        .item-name {
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 2px;
        }

        .item-details {
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .item.item-highlighted {
            outline: 2px solid #2563eb;
            outline-offset: -1px;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.4);
        }

        @media print {
            body {
                background: white;
                padding-top: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            
            .page-container {
                height: auto;
            }
            
            .brigade-section {
                page-break-inside: avoid;
                page-break-after: always;
            }
            
            .brigade-section:last-child {
                page-break-after: auto;
            }
            
            .day-column {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .item.item-highlighted {
                outline: none !important;
                box-shadow: none !important;
            }
            
            /* Ограничиваем количество колонок на странице */
            .days-grid {
                display: grid;
                grid-template-columns: repeat(14, 100px); /* 14 дней по 100px */
            }
            
        }

        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            gap: 8px;
            align-items: center;
            background: white;
            padding: 6px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .btn-group {
            display: flex;
            gap: 4px;
        }

        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .order-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            background: white;
            min-width: 150px;
        }

        .order-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .brigade-section.hidden {
            display: none !important;
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <div style="display: flex; justify-content: center; align-items: center; gap: 12px;">
            <label for="orderSelect" style="font-size: 13px; font-weight: 500;">Заявка:</label>
            <select id="orderSelect" class="order-select" onchange="changeOrder(this.value)">
                <?php foreach ($activeOrders as $activeOrder): ?>
                    <option value="<?= h($activeOrder) ?>" <?= $activeOrder === $order ? 'selected' : '' ?>>
                        <?= h($activeOrder) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px;">
                <input type="checkbox" id="showFact" <?= $showFact ? 'checked' : '' ?> onchange="toggleFact(this.checked)">
                Факт выполнения
            </label>
            <button class="btn" onclick="window.print()">Печать</button>
        </div>
    </div>
    
    <script>
        function changeOrder(orderNumber) {
            if (orderNumber) {
                const fact = document.getElementById('showFact')?.checked ? '1' : '';
                let url = '?order=' + encodeURIComponent(orderNumber);
                if (fact) url += '&fact=' + fact;
                window.location.href = url;
            }
        }
        function toggleFact(show) {
            const params = new URLSearchParams(window.location.search);
            const order = params.get('order') || document.getElementById('orderSelect')?.value || '';
            if (order) params.set('order', order);
            if (show) params.set('fact', '1'); else params.delete('fact');
            window.location.href = '?' + params.toString();
        }
        <?php if ($showFact): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const items = document.querySelectorAll('.item[data-filter]');
            items.forEach(function(el) {
                el.addEventListener('mouseenter', function() {
                    const filter = el.getAttribute('data-filter');
                    items.forEach(function(i) {
                        if (i.getAttribute('data-filter') === filter) i.classList.add('item-highlighted');
                    });
                });
                el.addEventListener('mouseleave', function() {
                    const filter = el.getAttribute('data-filter');
                    items.forEach(function(i) {
                        if (i.getAttribute('data-filter') === filter) i.classList.remove('item-highlighted');
                    });
                });
            });
        });
        <?php endif; ?>
    </script>

    <?php if (empty($planByDate)): ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            Нет данных для отображения
        </div>
    <?php else: ?>
        <div class="page-container">
            <!-- БРИГАДА 1 (верхняя половина) -->
            <div class="brigade-section" id="brigade1">
                <div class="brigade-header">Машина 1 • Заявка: <?= h($order) ?><?= $showFact ? ' • Факт' : '' ?></div>
                <div class="days-grid">
                    <?php foreach ($planByDate as $date => $brigades): ?>
                    <div class="day-column">
                        <div class="day-header">
                            <?= date('d.m.Y', strtotime($date)) ?><br>
                            <?= ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($date))] ?>
                        </div>
                        <div class="items-container">
                            <?php if (empty($brigades[1])): ?>
                                <div style="color: #ccc; font-size: 9px; text-align: center; padding: 10px;">—</div>
                            <?php else: ?>
                                <?php foreach ($brigades[1] as $item): 
                                    $slotKey = $date . '|1|' . trim($item['filter']);
                                    $slot = $slotFills[$slotKey] ?? null;
                                    $pct = ($showFact && $slot && $slot['total'] > 0) ? $slot['pct'] : 0;
                                    $fact = $factByFilter[$item['filter']] ?? null;
                                ?>
                                <div class="item" 
                                     data-filter="<?= h(trim($item['filter'])) ?>"
                                     data-complexity="<?= $item['complexity'] ?>" 
                                     data-count="<?= $item['count'] ?>">
                                    <?php if ($showFact && $pct > 0): ?>
                                    <div class="item-fact-fill" style="width: <?= round($pct, 1) ?>%"></div>
                                    <?php endif; ?>
                                    <div class="item-name"><?= h($item['filter']) ?></div>
                                    <div class="item-details">
                                        <span><?= $item['height'] ? round($item['height']) : '—' ?> мм</span>
                                        <span><strong><?= $item['count'] ?> шт</strong><?= $showFact && $fact ? ' <small style="color:#16a34a">(' . $fact['manufactured'] . '/' . $fact['planned'] . ')</small>' : '' ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- БРИГАДА 2 (нижняя половина) -->
            <div class="brigade-section" id="brigade2">
                <div class="brigade-header">Машина 2 • Заявка: <?= h($order) ?><?= $showFact ? ' • Факт' : '' ?></div>
                <div class="days-grid">
                    <?php foreach ($planByDate as $date => $brigades): ?>
                    <div class="day-column">
                        <div class="day-header">
                            <?= date('d.m.Y', strtotime($date)) ?><br>
                            <?= ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($date))] ?>
                        </div>
                        <div class="items-container">
                            <?php if (empty($brigades[2])): ?>
                                <div style="color: #ccc; font-size: 9px; text-align: center; padding: 10px;">—</div>
                            <?php else: ?>
                                <?php foreach ($brigades[2] as $item): 
                                    $slotKey = $date . '|2|' . trim($item['filter']);
                                    $slot = $slotFills[$slotKey] ?? null;
                                    $pct = ($showFact && $slot && $slot['total'] > 0) ? $slot['pct'] : 0;
                                    $fact = $factByFilter[$item['filter']] ?? null;
                                ?>
                                <div class="item" 
                                     data-filter="<?= h(trim($item['filter'])) ?>"
                                     data-complexity="<?= $item['complexity'] ?>" 
                                     data-count="<?= $item['count'] ?>">
                                    <?php if ($showFact && $pct > 0): ?>
                                    <div class="item-fact-fill" style="width: <?= round($pct, 1) ?>%"></div>
                                    <?php endif; ?>
                                    <div class="item-name"><?= h($item['filter']) ?></div>
                                    <div class="item-details">
                                        <span><?= $item['height'] ? round($item['height']) : '—' ?> мм</span>
                                        <span><strong><?= $item['count'] ?> шт</strong><?= $showFact && $fact ? ' <small style="color:#16a34a">(' . $fact['manufactured'] . '/' . $fact['planned'] . ')</small>' : '' ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</body>
</html>

