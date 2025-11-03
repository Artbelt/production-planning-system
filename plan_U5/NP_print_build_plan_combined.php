<?php
// NP_print_build_plan_combined.php — план сборки с объединенными машинами в одном дне
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dsn = "mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4";
$user = "root";
$pass = "";

$order = $_GET['order'] ?? '';

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
    
    // Получаем список всех активных заявок
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
    
    // Группируем по датам, объединяя обе машины
    $planByDate = [];
    foreach ($planData as $item) {
        $date = $item['plan_date'];
        
        if (!isset($planByDate[$date])) {
            $planByDate[$date] = [];
        }
        
        $planByDate[$date][] = $item;
    }
    
    // Сортируем даты
    ksort($planByDate);
    
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
    <title>План сборки (объединенный) — <?= h($order) ?></title>
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

        .page-header {
            font-size: 16px;
            font-weight: 400;
            padding: 8px;
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
            margin-bottom: 0;
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

        @media print {
            body {
                background: white;
                padding-top: 0;
            }
            
            .page-container {
                height: auto;
            }
            
            .day-column {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            
            .days-grid {
                grid-template-columns: repeat(14, 100px);
            }
        }

        .no-print {
            position: fixed;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
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

        @media print {
            .no-print {
                display: none !important;
            }
        }

        /* Разделение недель */
        .day-column:nth-child(7n) {
            border-right: 2px solid #000;
        }

        .day-column:nth-child(n+8) .day-header {
            border-top: 2px solid #000;
        }

        .day-column:nth-child(7n+1) {
            border-left: 1px solid #000;
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
            <button class="btn" onclick="window.print()">Печать</button>
        </div>
    </div>
    
    <script>
        function changeOrder(orderNumber) {
            if (orderNumber) {
                window.location.href = '?order=' + encodeURIComponent(orderNumber);
            }
        }
    </script>

    <?php if (empty($planByDate)): ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            Нет данных для отображения
        </div>
    <?php else: ?>
        <div class="page-container">
            <div class="page-header">План сборки • Заявка: <?= h($order) ?></div>
            
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
                            <?php foreach ($items as $item): ?>
                            <div class="item" 
                                 data-complexity="<?= $item['complexity'] ?>" 
                                 data-count="<?= $item['count'] ?>">
                                <div class="item-name"><?= h($item['filter']) ?></div>
                                <div class="item-details">
                                    <span><?= $item['height'] ? round($item['height']) : '—' ?> мм</span>
                                    <span><strong><?= $item['count'] ?> шт</strong></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</body>
</html>

