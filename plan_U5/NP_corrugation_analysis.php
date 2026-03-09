<?php
// NP_corrugation_analysis.php — аналитический просмотр плана гофрирования (ГМ 2)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/settings.php';

$order = $_GET['order'] ?? '';
$showFact = isset($_GET['fact']) && $_GET['fact'] !== '' && $_GET['fact'] !== '0';

// Если заявка не указана — берём последнюю по corrugation_plan
if ($order === '') {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $firstOrderStmt = $pdo->query("
            SELECT DISTINCT cp.order_number 
            FROM corrugation_plan cp
            JOIN orders o ON o.order_number = cp.order_number
            WHERE COALESCE(o.hide, 0) != 1
            ORDER BY cp.order_number DESC 
            LIMIT 1
        ");
        $firstOrder = $firstOrderStmt->fetchColumn();
        if ($firstOrder) {
            $order = $firstOrder;
        } else {
            http_response_code(400);
            exit('Нет данных для отображения. В базе отсутствуют планы гофрирования.');
        }
    } catch (Exception $e) {
        http_response_code(400);
        exit('Ошибка загрузки данных плана гофрирования.');
    }
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Список активных заявок, по которым есть план гофрирования (не скрытые)
    $activeOrdersStmt = $pdo->query("
        SELECT DISTINCT cp.order_number
        FROM corrugation_plan cp
        JOIN orders o ON o.order_number = cp.order_number
        WHERE COALESCE(o.hide, 0) != 1
        ORDER BY cp.order_number DESC
    ");
    $activeOrders = $activeOrdersStmt->fetchAll(PDO::FETCH_COLUMN);

    // Сам план гофрирования по заявке
    $stmt = $pdo->prepare("
        SELECT 
            plan_date,
            filter_label,
            `count`
        FROM corrugation_plan
        WHERE order_number = ?
        ORDER BY plan_date, filter_label
    ");
    $stmt->execute([$order]);
    $rows = $stmt->fetchAll();

    // Группировка по датам
    $planByDate = [];
    foreach ($rows as $row) {
        $date = $row['plan_date'];
        if (!isset($planByDate[$date])) {
            $planByDate[$date] = [];
        }
        $planByDate[$date][] = $row;
    }

    ksort($planByDate);

    // Факт выполнения: план и выполнено по каждому фильтру (при ?fact=1)
    $factByFilter = [];
    $slotFills = [];
    if ($showFact && !empty($order)) {
        // План: сумма по фильтру в corrugation_plan
        $plannedStmt = $pdo->prepare("
            SELECT filter_label, SUM(COALESCE(`count`, 0)) AS total
            FROM corrugation_plan
            WHERE order_number = ?
            GROUP BY filter_label
        ");
        $plannedStmt->execute([$order]);
        while ($r = $plannedStmt->fetch(PDO::FETCH_ASSOC)) {
            $f = trim($r['filter_label']);
            if ($f === '') continue;
            $factByFilter[$f] = ['planned' => (int)$r['total'], 'manufactured' => 0];
        }

        // Факт: из manufactured_corrugated_packages по заявке и фильтру
        $manufacturedByFilter = [];
        $factStmt = $pdo->prepare("
            SELECT 
                TRIM(COALESCE(filter_label, '')) AS filter_label,
                SUM(COALESCE(`count`, 0)) AS total
            FROM manufactured_corrugated_packages
            WHERE order_number = ?
            GROUP BY filter_label
        ");
        $factStmt->execute([$order]);
        while ($r = $factStmt->fetch(PDO::FETCH_ASSOC)) {
            $lbl = trim($r['filter_label']);
            if ($lbl === '') continue;
            $manufacturedByFilter[$lbl] = (int)$r['total'];
        }

        // Связываем план с фактом
        foreach (array_keys($factByFilter) as $f) {
            $factByFilter[$f]['manufactured'] = $manufacturedByFilter[$f] ?? 0;
        }

        // Последовательное закрашивание слотов по датам
        $slotFills = [];
        foreach (array_keys($factByFilter) as $f) {
            $manufactured = $factByFilter[$f]['manufactured'];
            if ($manufactured <= 0) {
                continue;
            }

            // Собираем слоты в порядке отображения: по датам
            $slots = [];
            foreach ($planByDate as $date => $items) {
                foreach ($items as $it) {
                    if (trim($it['filter_label']) === $f) {
                        $slots[] = [
                            'date' => $date,
                            'count' => (int)($it['count'] ?? 0)
                        ];
                    }
                }
            }

            $remaining = $manufactured;
            foreach ($slots as $s) {
                $cnt = $s['count'];
                $key = $s['date'] . '|' . $f;
                if ($cnt <= 0) {
                    $slotFills[$key] = ['pct' => 0, 'done' => 0, 'total' => 0];
                    continue;
                }
                if ($remaining <= 0) {
                    $slotFills[$key] = ['pct' => 0, 'done' => 0, 'total' => $cnt];
                    continue;
                }
                $used = min($cnt, $remaining);
                $slotFills[$key] = [
                    'pct' => 100 * $used / $cnt,
                    'done' => $used,
                    'total' => $cnt
                ];
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
    <title>Анализ гофроплана (ГМ 2)<?= $showFact ? ' (факт)' : '' ?> — <?= h($order) ?></title>
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

        /* На мобильных — контент слева и горизонтальная прокрутка по дням */
        @media (max-width: 768px) {
            body {
                justify-content: flex-start;
                padding: 12px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .scroll-wrapper {
                flex: 0 0 auto;
                min-width: 0;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }
        }

        .page-container {
            display: flex;
            flex-direction: column;
            width: fit-content;
        }

        .gm-section {
            margin-bottom: 8px;
            display: flex;
            flex-direction: column;
        }

        .gm-header {
            font-size: 16px;
            font-weight: 400;
            padding: 8px;
            text-align: center;
            margin-bottom: 4px;
        }

        .days-grid {
            display: grid;
            grid-template-columns: repeat(14, 100px);
            gap: 0;
            grid-auto-flow: row;
            border-top: 1px solid #000;
            border-left: 1px solid #000;
            min-width: fit-content;
        }

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

        .item-name {
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 2px;
        }

        .item-details {
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: flex-end;
            align-items: center;
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

            .gm-section {
                page-break-inside: avoid;
                page-break-after: always;
            }

            .gm-section:last-child {
                page-break-after: auto;
            }

            .day-column {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .days-grid {
                display: grid;
                grid-template-columns: repeat(14, 100px);
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
    </style>
</head>
<body>
    <div class="no-print">
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
    </script>

    <?php if (empty($planByDate)): ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            Нет данных плана гофрирования для выбранной заявки
        </div>
    <?php else: ?>
        <div class="scroll-wrapper">
        <div class="page-container">
            <div class="gm-section">
                <div class="gm-header">Анализ гофроплана • ГМ 2 • Заявка: <?= h($order) ?></div>
                <div class="days-grid">
                    <?php foreach ($planByDate as $date => $items): ?>
                        <div class="day-column">
                            <div class="day-header">
                                <?= date('d.m.Y', strtotime($date)) ?><br>
                                <?= ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($date))] ?>
                            </div>
                            <div class="items-container">
                                <?php if (empty($items)): ?>
                                    <div style="color: #ccc; font-size: 9px; text-align: center; padding: 10px;">—</div>
                                <?php else: ?>
                                    <?php foreach ($items as $item): 
                                        $filterLabel = trim($item['filter_label'] ?? '');
                                        $planned = (int)($item['count'] ?? 0);
                                        $slotKey = $date . '|' . $filterLabel;
                                        $slot = $slotFills[$slotKey] ?? null;
                                        $pct = ($showFact && $slot && $slot['total'] > 0) ? $slot['pct'] : 0;
                                        $factAgg = $factByFilter[$filterLabel] ?? null;
                                        $doneTotal = $factAgg['manufactured'] ?? 0;
                                        $plannedTotal = $factAgg['planned'] ?? $planned;
                                    ?>
                                        <div class="item"<?php if ($showFact && $factAgg): ?> title="Выполнено: <?= $doneTotal ?> из <?= $plannedTotal ?>"<?php endif; ?>>
                                            <?php if ($showFact && $pct > 0): ?>
                                                <div class="item-fact-fill" style="width: <?= round($pct, 1) ?>%"></div>
                                            <?php endif; ?>
                                            <div class="item-name"><?= h($item['filter_label']) ?></div>
                                            <div class="item-details">
                                                <span><strong><?= $planned ?> шт</strong></span>
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

