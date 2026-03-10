<?php
// view_production_plan.php — план vs факт для выбранной заявки (U3)

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normFilter(string $s): string {
    $s = preg_replace('/\s*\[.*$/u', '', $s);
    $s = preg_replace('/[●◩⏃]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
}

$order    = $_GET['order'] ?? '';
$showFact = isset($_GET['fact']) && $_GET['fact'] !== '' && $_GET['fact'] !== '0';

/* ---------- Список активных заявок для селектора ---------- */
$activeOrders = [];
try {
    $activeOrders = $pdo->query("
        SELECT DISTINCT bp.order_number
        FROM build_plans bp
        JOIN orders o ON o.order_number = bp.order_number
        WHERE o.hide IS NULL OR o.hide != 1
        ORDER BY bp.order_number DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $activeOrders = [];
}

if ($order === '') {
    $order = $activeOrders[0] ?? '';
    if ($order === '') {
        http_response_code(400);
        exit('Нет данных для отображения. В базе отсутствуют планы сборки.');
    }
}

/* ---------- ПЛАН + ВЫСОТА ---------- */
$planByDate   = [];  // [date][base] => ['filter', 'count']
$datesSorted  = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            bp.day_date         AS plan_date,
            bp.filter           AS filter_label,
            COALESCE(bp.qty, 0) AS qty
        FROM build_plans bp
        WHERE bp.order_number = ?
        ORDER BY plan_date, bp.filter
    ");
    $stmt->execute([$order]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $date   = $r['plan_date'] ?? '';
        if ($date === '') continue;
        $base   = normFilter((string)($r['filter_label'] ?? ''));
        if ($base === '') continue;
        $qty    = (int)($r['qty'] ?? 0);

        if (!isset($planByDate[$date])) $planByDate[$date] = [];
        if (!isset($planByDate[$date][$base])) {
            $planByDate[$date][$base] = ['filter' => $base, 'count' => 0];
        }
        $planByDate[$date][$base]['count'] += $qty;
    }
    ksort($planByDate);
    $datesSorted = array_keys($planByDate);
} catch (Exception $e) {
    $planByDate  = [];
    $datesSorted = [];
}

/* ---------- ФАКТ ---------- */
$factByBase = [];  // [base] => ['planned'=>int, 'manufactured'=>int]
$slotFills  = [];  // [date|base] => ['pct'=>float, 'done'=>int, 'total'=>int]

if ($showFact && !empty($planByDate)) {
    try {
        /* Плановый итог по базовому имени */
        $plannedStmt = $pdo->prepare("
            SELECT
                TRIM(REGEXP_REPLACE(filter, '\\\\[.*$', '')) AS base_filter,
                SUM(COALESCE(qty, 0)) AS total
            FROM build_plans
            WHERE order_number = ?
            GROUP BY base_filter
        ");
        $plannedStmt->execute([$order]);
        while ($r = $plannedStmt->fetch(PDO::FETCH_ASSOC)) {
            $base = normFilter((string)($r['base_filter'] ?? ''));
            if ($base === '') continue;
            $factByBase[$base] = ['planned' => (int)($r['total'] ?? 0), 'manufactured' => 0];
        }

        /* Фактически изготовлено */
        $factStmt = $pdo->prepare("
            SELECT
                TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) AS base_filter,
                SUM(COALESCE(count_of_filters, 0)) AS total
            FROM manufactured_production
            WHERE name_of_order = ?
            GROUP BY base_filter
        ");
        $factStmt->execute([$order]);
        $manufactured = [];
        while ($r = $factStmt->fetch(PDO::FETCH_ASSOC)) {
            $base = normFilter((string)($r['base_filter'] ?? ''));
            if ($base === '') continue;
            $manufactured[$base] = (int)($r['total'] ?? 0);
        }
        foreach ($factByBase as $base => $v) {
            $factByBase[$base]['manufactured'] = $manufactured[$base] ?? 0;
        }

        /* Последовательное заполнение слотов */
        foreach ($factByBase as $base => $v) {
            $remaining = (int)($v['manufactured'] ?? 0);
            if ($remaining <= 0) continue;
            foreach ($planByDate as $date => $items) {
                if (!isset($items[$base])) continue;
                $total   = (int)($items[$base]['count'] ?? 0);
                $slotKey = $date . '|' . $base;
                if ($total <= 0) { $slotFills[$slotKey] = ['pct'=>0,'done'=>0,'total'=>0]; continue; }
                if ($remaining <= 0) break;
                $used = min($total, $remaining);
                $slotFills[$slotKey] = ['pct' => 100 * $used / $total, 'done' => $used, 'total' => $total];
                $remaining -= $used;
            }
        }
    } catch (Exception $e) {
        /* факт недоступен — тихо игнорируем */
    }
}

$DOW = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План и факт сборки — переносы | Заявка № <?= h($order) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            background: white;
            color: #000;
            padding: 50px 0 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .section-header {
            font-size: 16px;
            font-weight: 400;
            padding: 8px;
            text-align: center;
            margin-bottom: 4px;
        }

        .days-grid {
            display: grid;
            grid-template-columns: repeat(7, 150px);
            border-top: 1px solid #000;
            border-left: 1px solid #000;
            width: fit-content;
        }
        .day-column {
            width: 150px;
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
            border-bottom: 1px solid #000;
            text-align: center;
            line-height: 1.3;
        }
        .day-header.weekend { background: #fff3cd; }
        .items-container { padding: 4px; display: flex; flex-direction: column; min-height: 60px; }
        .item {
            border: 1px solid #333;
            padding: 4px 6px;
            margin-bottom: 2px;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        .item-fact-fill {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            background: rgba(34, 197, 94, 0.35);
            z-index: 0;
        }
        .item > .item-row { position: relative; z-index: 1; }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 4px;
        }
        .item-name {
            font-weight: 600;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1 1 0;
        }
        .item-qty {
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .item.item-highlighted {
            outline: 2px solid #2563eb;
            outline-offset: -1px;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.4);
        }
        .item.item-highlighted-fixed {
            background: #dbeafe;
        }

        /* Панель управления */
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            gap: 8px;
            align-items: center;
            background: white;
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
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
        .order-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        label.fact-label {
            display: flex; align-items: center; gap: 6px;
            cursor: pointer; font-size: 13px;
        }

        @media print {
            body { padding-top: 0; }
            .no-print { display: none !important; }
            .days-grid { grid-template-columns: repeat(7, 150px); width: fit-content; }
            .day-column { break-inside: avoid; }
            .item.item-highlighted { outline: none !important; box-shadow: none !important; }
            .item.item-highlighted-fixed { background: transparent !important; }
        }

        /* Плавающая панель с наименованием фильтра (перетаскиваемая) */
        .filter-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            max-width: min(90vw, 420px);
            width: 280px;
            background: #fef3c7; /* тёплый жёлтый фон, хорошо заметен */
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            border: 1px solid #f59e0b;
            z-index: 1500;
            display: none;
        }
        .filter-panel.visible {
            display: block;
        }
        .filter-panel-header {
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            background: #fbbf24;
            border-bottom: 1px solid #f59e0b;
            cursor: move;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .filter-panel-title {
            color: #111827;
            white-space: nowrap;
        }
        .filter-panel-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            padding: 0 4px;
            color: #6b7280;
        }
        .filter-panel-body {
            padding: 10px 12px 12px;
        }
        .filter-panel-name {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            word-break: break-word;
        }

        /* Мобильная адаптация */
        @media (max-width: 768px) {
            body {
                padding: 60px 8px 16px;
                align-items: stretch;
            }
            .section-header {
                padding: 8px 4px;
                font-size: 14px;
            }
            .days-grid {
                grid-template-columns: 1fr;
                width: 100%;
            }
            .day-column {
                width: auto;
            }
            .no-print {
                left: 8px;
                right: 8px;
                top: 8px;
                justify-content: space-between;
                flex-wrap: wrap;
            }
            .order-select {
                min-width: 0;
                flex: 1 1 auto;
            }
            .filter-panel {
                left: 8px;
                right: 8px;
                width: auto;
                max-width: 100%;
            }
            .filter-panel-title {
                max-width: 70%;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <label for="orderSelect" style="font-size:13px;font-weight:500;">Заявка:</label>
    <select id="orderSelect" class="order-select" onchange="changeOrder(this.value)">
        <?php foreach ($activeOrders as $ao): ?>
            <option value="<?= h($ao) ?>" <?= $ao === $order ? 'selected' : '' ?>><?= h($ao) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="fact-label">
        <input type="checkbox" id="showFact" <?= $showFact ? 'checked' : '' ?> onchange="toggleFact(this.checked)">
        Факт выполнения
    </label>
</div>

<script>
    function changeOrder(o) {
        if (!o) return;
        const fact = document.getElementById('showFact')?.checked ? '1' : '';
        window.location.href = '?order=' + encodeURIComponent(o) + (fact ? '&fact=1' : '');
    }
    function toggleFact(show) {
        const params = new URLSearchParams(window.location.search);
        const o = params.get('order') || document.getElementById('orderSelect')?.value || '';
        if (o) params.set('order', o);
        if (show) params.set('fact', '1'); else params.delete('fact');
        window.location.href = '?' + params.toString();
    }
    document.addEventListener('DOMContentLoaded', function () {
        const items  = document.querySelectorAll('.item[data-filter]');
        const panel  = document.getElementById('filterPanel');
        const header = document.getElementById('filterPanelHeader');
        const nameEl = document.getElementById('filterPanelName');
        const btnClose = document.getElementById('filterPanelClose');

        let currentSelectedFilter = null;

        function updateHighlight() {
            items.forEach(function (i) {
                const f = i.getAttribute('data-filter');
                if (f && currentSelectedFilter && f === currentSelectedFilter) {
                    i.classList.add('item-highlighted', 'item-highlighted-fixed');
                } else {
                    i.classList.remove('item-highlighted', 'item-highlighted-fixed');
                }
            });
        }

        function openFilterPanel(filterName) {
            if (!panel || !nameEl) return;
            nameEl.textContent = filterName || '';
            panel.classList.add('visible');
            currentSelectedFilter = filterName || null;
            updateHighlight();
        }

        function closeFilterPanel() {
            if (!panel) return;
            panel.classList.remove('visible');
            currentSelectedFilter = null;
            updateHighlight();
        }

        // Перетаскивание панели
        if (panel && header) {
            let dragging = false;
            let startX = 0, startY = 0;
            let startLeft = 0, startTop = 0;

            header.addEventListener('mousedown', function (e) {
                dragging = true;
                const rect = panel.getBoundingClientRect();
                startX = e.clientX;
                startY = e.clientY;
                startLeft = rect.left;
                startTop  = rect.top;
                document.body.style.userSelect = 'none';
            });

            document.addEventListener('mousemove', function (e) {
                if (!dragging) return;
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                panel.style.left = (startLeft + dx) + 'px';
                panel.style.top  = (startTop + dy) + 'px';
                panel.style.right = 'auto';
            });

            document.addEventListener('mouseup', function () {
                if (!dragging) return;
                dragging = false;
                document.body.style.userSelect = '';
            });
        }

        btnClose?.addEventListener('click', closeFilterPanel);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeFilterPanel();
            }
        });

        items.forEach(function (el) {
            el.addEventListener('mouseenter', function () {
                const f = el.getAttribute('data-filter');
                items.forEach(function (i) {
                    if (i.getAttribute('data-filter') === f) i.classList.add('item-highlighted');
                });
            });
            el.addEventListener('mouseleave', function () {
                const f = el.getAttribute('data-filter');
                items.forEach(function (i) {
                    const fi = i.getAttribute('data-filter');
                    // не снимаем подсветку с выбранного фильтра
                    if (fi === f && fi !== currentSelectedFilter) {
                        i.classList.remove('item-highlighted');
                    }
                });
            });
            el.addEventListener('click', function () {
                const f = el.getAttribute('data-filter') || el.querySelector('.item-name')?.textContent || '';
                if (f) {
                    openFilterPanel(f);
                }
            });
        });
    });
</script>

<?php if (empty($planByDate)): ?>
    <div style="text-align:center;padding:40px;color:#999;">Нет данных для отображения</div>
<?php else: ?>

<div class="section-header">
    План и факт сборки — Заявка: <?= h($order) ?><?= $showFact ? ' • Факт' : '' ?>
</div>

<div class="days-grid">
    <?php
    /* Выравниваем начало на понедельник: пустые ячейки для сдвига */
    $firstTs  = strtotime($datesSorted[0]);
    $firstDow = (int)date('w', $firstTs); // 0=Вс,1=Пн,...,6=Сб
    $offset   = ($firstDow === 0) ? 6 : ($firstDow - 1); // кол-во пустых ячеек до первого дня
    for ($i = 0; $i < $offset; $i++):
    ?>
    <div class="day-column" style="border-right:1px solid #ddd;border-bottom:1px solid #ddd;background:#fafafa;"></div>
    <?php endfor; ?>

    <?php foreach ($planByDate as $date => $items):
        $keys = array_keys($items);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        $ts  = strtotime($date);
        $dow = (int)date('w', $ts);
        $isWeekend = ($dow === 0 || $dow === 6);
    ?>
    <div class="day-column">
        <div class="day-header<?= $isWeekend ? ' weekend' : '' ?>">
            <?= date('d.m.Y', $ts) ?> <?= $DOW[$dow] ?>
        </div>
        <div class="items-container">
            <?php if (empty($keys)): ?>
                <div style="color:#bbb;font-size:10px;text-align:center;padding:8px 0;font-style:italic;">Нет задач</div>
            <?php else: ?>
                <?php foreach ($keys as $base):
                    $it      = $items[$base];
                    $total   = (int)$it['count'];
                    $slotKey = $date . '|' . $base;
                    $slot    = $slotFills[$slotKey] ?? null;
                    $pct     = ($showFact && $slot && ($slot['total'] ?? 0) > 0) ? (float)$slot['pct'] : 0;
                    $fact    = $factByBase[$base] ?? null;
                    $factTip = '';
                    if ($showFact && $fact) {
                        $factTip = 'Выполнено: ' . (int)$fact['manufactured'] . ' из ' . (int)$fact['planned'];
                    }
                ?>
                <div class="item"
                     data-filter="<?= h($base) ?>"
                     <?= $factTip ? 'title="' . h($factTip) . '"' : '' ?>>
                    <?php if ($showFact && $pct > 0): ?>
                        <div class="item-fact-fill" style="width:<?= round($pct, 1) ?>%"></div>
                    <?php endif; ?>
                    <div class="item-row">
                        <span class="item-name"><?= h($base) ?></span>
                        <span class="item-qty"><?= $total ?> шт</span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<div class="filter-panel" id="filterPanel">
    <div class="filter-panel-header" id="filterPanelHeader">
        <span class="filter-panel-title">Наименование фильтра</span>
        <button type="button" class="filter-panel-close" id="filterPanelClose" title="Закрыть">×</button>
    </div>
    <div class="filter-panel-body">
        <div class="filter-panel-name" id="filterPanelName"></div>
    </div>
</div>

</body>
</html>
