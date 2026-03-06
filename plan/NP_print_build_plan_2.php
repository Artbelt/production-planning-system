<?php
// NP_print_build_plan_2.php — просмотр/печать плана сборки (U2) с опцией "факт"
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan');
$order = $_GET['order'] ?? '';
$showFact = isset($_GET['fact']) && $_GET['fact'] !== '' && $_GET['fact'] !== '0';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normFilter(string $s): string {
    $s = preg_replace('~\s*\[.*$~u', '', $s);     // убрать хвост вида " [..]"
    $s = preg_replace('/[●◩⏃]/u', '', $s);        // убрать тех. метки
    $s = preg_replace('~\s+~u', ' ', trim($s));   // нормализовать пробелы
    return $s;
}

// Берём список заявок для селектора
$activeOrders = [];
try {
    $activeOrders = $pdo->query("
        SELECT DISTINCT order_number
        FROM build_plan
        ORDER BY order_number DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $activeOrders = [];
}

// Если заявка не указана — берём последнюю
if ($order === '') {
    $order = $activeOrders[0] ?? '';
    if ($order === '') {
        http_response_code(400);
        exit('Нет данных для отображения. В базе отсутствуют планы сборки.');
    }
}

// Слоты плана: даты → позиции (агрегировано по фильтру, без разделения по местам)
$planByDate = [];
$datesSorted = [];

// Факт по базовому имени фильтра
$factByBase = []; // [base] => ['planned'=>int, 'manufactured'=>int]
$slotFills = [];  // [date|base] => ['pct'=>float, 'done'=>int, 'total'=>int]

try {
    $stmt = $pdo->prepare("
        SELECT
            DATE(bp.assign_date) AS plan_date,
            bp.place,
            bp.filter_label,
            COALESCE(bp.`count`, 0) AS qty,
            ppp.p_p_height AS height
        FROM build_plan bp
        LEFT JOIN panel_filter_structure pfs
            ON TRIM(pfs.filter) = TRIM(SUBSTRING_INDEX(COALESCE(bp.filter_label,''), ' [', 1))
        LEFT JOIN paper_package_panel ppp
            ON ppp.p_p_name = pfs.paper_package
        WHERE bp.order_number = ?
        ORDER BY plan_date, bp.place, bp.id
    ");
    $stmt->execute([$order]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $date = $r['plan_date'] ?? '';
        if ($date === '') continue;
        $place = (int)($r['place'] ?? 0);

        $base = normFilter((string)($r['filter_label'] ?? ''));
        if ($base === '') continue;

        $qty = (int)($r['qty'] ?? 0);
        $height = $r['height'] !== null ? (float)$r['height'] : null;

        if (!isset($planByDate[$date])) $planByDate[$date] = [];
        if (!isset($planByDate[$date][$base])) {
            $planByDate[$date][$base] = [
                'filter' => $base,
                'count' => 0,
                'height' => $height,
                'places' => []
            ];
        }
        $planByDate[$date][$base]['count'] += $qty;
        if ($planByDate[$date][$base]['height'] === null && $height !== null) {
            $planByDate[$date][$base]['height'] = $height;
        }
        if ($place > 0) {
            $planByDate[$date][$base]['places'][$place] = true;
        }
    }

    ksort($planByDate);
    $datesSorted = array_keys($planByDate);

    // Факт выполнения (если включен)
    if ($showFact) {
        // План: сумма по базовому имени (по всем местам/дням)
        $plannedStmt = $pdo->prepare("
            SELECT
                TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) AS base_filter,
                SUM(COALESCE(`count`, 0)) AS total
            FROM build_plan
            WHERE order_number = ?
            GROUP BY base_filter
        ");
        $plannedStmt->execute([$order]);
        while ($r = $plannedStmt->fetch(PDO::FETCH_ASSOC)) {
            $base = normFilter((string)($r['base_filter'] ?? ''));
            if ($base === '') continue;
            $factByBase[$base] = ['planned' => (int)($r['total'] ?? 0), 'manufactured' => 0];
        }

        // Факт: изготовлено из manufactured_production (по базовому имени)
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

        // Последовательное закрашивание по порядку отображения (дата → алфавит)
        foreach ($factByBase as $base => $v) {
            $remaining = (int)($v['manufactured'] ?? 0);
            if ($remaining <= 0) continue;

            foreach ($planByDate as $date => $items) {
                if (!isset($items[$base])) continue;
                $total = (int)($items[$base]['count'] ?? 0);
                $slotKey = $date . '|' . $base;
                if ($total <= 0) {
                    $slotFills[$slotKey] = ['pct' => 0, 'done' => 0, 'total' => 0];
                    continue;
                }
                if ($remaining <= 0) break;
                $used = min($total, $remaining);
                $slotFills[$slotKey] = ['pct' => 100 * $used / $total, 'done' => $used, 'total' => $total];
                $remaining -= $used;
            }
        }
    }
} catch (Exception $e) {
    $planByDate = [];
    $datesSorted = [];
    $activeOrders = $activeOrders ?: [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План сборки<?= $showFact ? ' (факт)' : '' ?> — <?= h($order) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body{
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            background: white;
            color: #000;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 50px 12px 20px;
        }
        .scroll-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
        }
        .page-container { display: flex; flex-direction: column; width: fit-content; }
        .brigade-section { margin-bottom: 8px; display: flex; flex-direction: column; }
        .brigade-section:last-child { margin-bottom: 0; }
        .brigade-header { font-size: 16px; font-weight: 400; padding: 8px; text-align: center; margin-bottom: 4px; }

        .days-grid {
            display: grid;
            grid-template-columns: repeat(14, 110px);
            gap: 0;
            grid-auto-flow: row;
            border-top: 1px solid #000;
            border-left: 1px solid #000;
            min-width: fit-content;
        }
        .day-column { border-right: 1px solid #000; border-bottom: 1px solid #000; display: flex; flex-direction: column; }
        .day-header {
            font-size: 10px;
            font-weight: 400;
            padding: 6px 4px;
            background: #f5f5f5;
            border: 1px solid #000;
            text-align: center;
            line-height: 1.3;
        }
        .items-container { padding: 4px; display: flex; flex-direction: column; min-height: 60px; }
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
        .item > .item-name, .item > .item-details { position: relative; z-index: 1; }
        .item-name { font-weight: 600; font-size: 12px; margin-bottom: 2px; }
        .item-details {
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
        }
        .item.item-highlighted {
            outline: 2px solid #2563eb;
            outline-offset: -1px;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.4);
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
        .btn:hover { background: #1d4ed8; }
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
        @media print {
            body { background: white; padding-top: 0; }
            .no-print { display: none !important; }
            .brigade-section { page-break-inside: avoid; page-break-after: always; }
            .brigade-section:last-child { page-break-after: auto; }
            .day-column { page-break-inside: avoid; break-inside: avoid; }
            .item.item-highlighted { outline: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <div style="display:flex; justify-content:center; align-items:center; gap:12px;">
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
        <div class="scroll-wrapper">
        <div class="page-container">
            <div class="brigade-section" id="daysOnly">
                <div class="brigade-header">План сборки • Заявка: <?= h($order) ?><?= $showFact ? ' • Факт' : '' ?></div>
                <div class="days-grid" style="grid-template-columns: repeat(<?= max(1, count($datesSorted)) ?>, 110px);">
                    <?php foreach ($planByDate as $date => $items): ?>
                        <?php
                            $keys = array_keys($items);
                            sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
                        ?>
                        <div class="day-column">
                            <div class="day-header">
                                <?= date('d.m.Y', strtotime($date)) ?><br>
                                <?= ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($date))] ?>
                            </div>
                            <div class="items-container">
                                <?php if (empty($keys)): ?>
                                    <div style="color: #ccc; font-size: 9px; text-align: center; padding: 10px;">—</div>
                                <?php else: ?>
                                    <?php foreach ($keys as $base):
                                        $it = $items[$base];
                                        $slotKey = $date . '|' . $base;
                                        $slot = $slotFills[$slotKey] ?? null;
                                        $pct = ($showFact && $slot && ($slot['total'] ?? 0) > 0) ? (float)$slot['pct'] : 0;
                                        $fact = $factByBase[$base] ?? null;
                                        $places = isset($it['places']) ? implode(',', array_keys($it['places'])) : '';
                                    ?>
                                        <div class="item"
                                             data-filter="<?= h($base) ?>"
                                             <?php if ($showFact && $fact): ?>title="Выполнено: <?= (int)$fact['manufactured'] ?> из <?= (int)$fact['planned'] ?><?= $places ? " • Места: {$places}" : '' ?>"<?php endif; ?>>
                                            <?php if ($showFact && $pct > 0): ?>
                                                <div class="item-fact-fill" style="width: <?= round($pct, 1) ?>%"></div>
                                            <?php endif; ?>
                                            <div class="item-name"><?= h($base) ?></div>
                                            <div class="item-details">
                                                <span><?= $it['height'] !== null ? round($it['height']) : '—' ?> мм</span>
                                                <span><strong><?= (int)$it['count'] ?> шт</strong></span>
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
        </div>
    <?php endif; ?>
</body>
</html>

