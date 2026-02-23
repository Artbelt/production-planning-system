<?php
require_once __DIR__ . '/../../auth/includes/db.php';
$pdo = getPdo('plan_u5');
$date = $_GET['date'] ?? date('Y-m-d');

// грузим сырые строки плана
$stmt = $pdo->prepare("
    SELECT id, order_number, plan_date, filter_label, `count`
    FROM corrugation_plan
    WHERE plan_date = ?
    ORDER BY order_number, id
");
$stmt->execute([$date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// группируем по (order_number, filter_label)
$groups = [];
foreach ($rows as $r) {
    $key = $r['order_number'].'|'.$r['filter_label'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'order_number' => $r['order_number'],
            'filter_label' => $r['filter_label'],
            'ids'          => [],
            'items'        => [],
            'plan_sum'     => 0,
        ];
    }
    $groups[$key]['ids'][] = (int)$r['id'];
    $groups[$key]['items'][] = [
        'id'         => (int)$r['id'],
        'count'      => (int)$r['count'],
    ];
    $groups[$key]['plan_sum'] += (int)$r['count'];
}
$group_list = array_values($groups);

// даты для стрелок
$dt       = new DateTime($date);
$prevDate = $dt->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
$today    = date('Y-m-d');

// получаем список активных заявок (из corrugation_plan за последние 30 дней)
$activeOrdersStmt = $pdo->prepare("
    SELECT DISTINCT order_number 
    FROM corrugation_plan 
    WHERE plan_date >= DATE_SUB(?, INTERVAL 30 DAY)
    ORDER BY order_number DESC
");
$activeOrdersStmt->execute([$date]);
$active_orders = $activeOrdersStmt->fetchAll(PDO::FETCH_COLUMN);

// получаем список всех уникальных фильтров для автодополнения
$filtersStmt = $pdo->prepare("
    SELECT DISTINCT filter_label 
    FROM corrugation_plan 
    WHERE filter_label IS NOT NULL AND filter_label != ''
    ORDER BY filter_label
");
$filtersStmt->execute();
$all_filters = $filtersStmt->fetchAll(PDO::FETCH_COLUMN);

// получаем выпущенные гофропакеты за день
$manufacturedStmt = $pdo->prepare("
    SELECT id, order_number, filter_label, count, timestamp
    FROM manufactured_corrugated_packages
    WHERE date_of_production = ?
    ORDER BY timestamp DESC, order_number, filter_label
");
$manufacturedStmt->execute([$date]);
$manufactured_packages = $manufacturedStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Задания гофромашины</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color:rgb(13, 209, 147);
            --secondary-dark:rgb(37, 216, 165);
            --accent-color: #dc2626;
            --accent-dark: #b91c1c;
            --success-color:rgb(33, 236, 108);
            --success-dark:rgb(31, 32, 31);
            --warning-color: #d97706;
            --warning-dark: #b45309;
            --info-color: #0891b2;
            --info-dark: #0e7490;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --border-radius: 6px;
            --border-radius-sm: 4px;
            --border-radius-lg: 8px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --transition: all 0.15s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
            color: var(--gray-800);
            line-height: 1.5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 12px;
            color: var(--gray-800);
            font-size: 1.25rem;
            font-weight: 600;
        }

        .section {
            background: white;
            padding: 12px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            border: 1px solid var(--gray-200);
        }

        /* NAV */
        .nav {
            max-width: 900px;
            margin: 0 auto 16px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            background: white;
            padding: 12px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .nav a, .nav button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav a:hover, .nav button:hover {
            background: var(--primary-dark);
        }

        .nav input[type="date"] {
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            font-weight: 400;
            background: white;
            transition: var(--transition);
        }

        .nav input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
            background: white;
            border: 1px solid var(--gray-200);
        }

        th, td {
            border: 1px solid var(--gray-200);
            padding: 6px 8px;
            text-align: center;
        }

        thead th {
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 12px;
        }

        tbody tr:nth-child(even) {
            background: var(--gray-50);
        }

        tbody tr:hover {
            background: var(--gray-100);
        }


        /* >>> tiny save & qty */
        /* узкое поле количества */
        input[type="number"].qty {
            width: 80px;
            padding: 8px 12px;
            text-align: center;
            font-variant-numeric: tabular-nums;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            background: white;
        }

        input[type="number"].qty:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* убираем стрелки у number */
        input[type="number"].qty::-webkit-outer-spin-button,
        input[type="number"].qty::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"].qty {
            -moz-appearance: textfield;
        }

        /* крошечная кнопка ✓ */
        button.save {
            padding: 6px 10px;
            font-size: 13px;
            line-height: 1;
            cursor: pointer;
            border: none;
            background: var(--success-color);
            color: white;
            border-radius: var(--border-radius-sm);
            min-width: 36px;
            font-weight: 500;
            transition: var(--transition);
        }

        button.save:hover {
            background: var(--success-dark);
        }

        /* <<< tiny save & qty */

        /* Кнопка удаления последней позиции */
        button.delete-last-btn:hover {
            background: var(--accent-dark);
        }

        button.delete-last-btn:active {
            transform: scale(0.98);
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
            font-size: 16px;
            font-weight: 400;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 24px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            box-shadow: var(--shadow-lg);
            position: relative;
            border: 1px solid var(--gray-200);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--gray-200);
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .close {
            color: var(--gray-400);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius-sm);
        }

        .close:hover {
            color: var(--danger-color);
            background-color: var(--gray-100);
        }

        .no-results {
            text-align: center;
            padding: 30px 20px;
            color: var(--gray-500);
            font-size: 14px;
        }

        #filterInput:focus, #countInput:focus, #orderSelect:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Стили для выпадающего списка фильтров */
        #filterSuggestions {
            font-size: 14px;
        }

        .filter-suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
            color: var(--gray-800);
        }

        .filter-suggestion-item:last-child {
            border-bottom: none;
        }

        .filter-suggestion-item:hover,
        .filter-suggestion-item.highlighted {
            background: var(--primary-color);
            color: white;
        }

        .filter-suggestion-item:active {
            background: var(--primary-dark);
        }

        #addProductionForm button:hover {
            background: var(--primary-dark);
        }

        #addProductionForm button:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
        }

        .production-section {
            max-width: 100%;
        }

        @media (min-width: 769px) {
            .production-section {
                max-width: 1200px;
                margin: 0 auto;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            h2 {
                font-size: 1.25rem;
                margin-bottom: 16px;
            }

            .section {
                padding: 16px;
            }

            .nav {
                gap: 6px;
                padding: 12px;
                margin-bottom: 20px;
            }

            .nav a, .nav button {
                padding: 8px 12px;
                font-size: 12px;
            }

            table {
                font-size: 13px;
            }

            th, td {
                padding: 6px 4px;
            }

            input[type="number"].qty {
                width: 60px;
                padding: 6px 8px;
                font-size: 13px;
            }

            button.save {
                padding: 6px 10px;
                font-size: 13px;
                min-width: 35px;
            }
        }

        @media (max-width: 600px) {
            /* растягиваем таблицу на всю ширину */
            .section {
                padding: 12px;
                margin: 0 -10px 20px -10px;
                border-radius: 0;
            }

            /* блок изготовленных гофропакетов - одинаковые отступы слева и справа */
            .section.production-section {
                margin-left: 10px;
                margin-right: 10px;
            }

            table {
                width: 100%;
                table-layout: auto;
                font-size: 12px;
            }

            th, td {
                padding: 5px 3px;
                word-wrap: break-word;
            }

            /* колонка "Заявка" - минимальная ширина */
            thead th:nth-child(1),
            tbody td:nth-child(1) {
                width: 15%;
                min-width: 60px;
            }


            /* колонка "Фильтр" - максимальная ширина */
            thead th:nth-child(2),
            tbody td:nth-child(2) {
                width: 45%;
                text-align: left;
                padding-left: 8px;
            }

            /* колонка "План" - средняя ширина */
            thead th:nth-child(3),
            tbody td:nth-child(3) {
                width: 15%;
                min-width: 50px;
            }

            /* форма внесения продукции */
            #addProductionForm {
                max-width: 100%;
            }

            #addProductionForm input {
                font-size: 13px;
                padding: 6px 10px;
            }

            #addProductionForm button {
                padding: 8px 16px;
                font-size: 13px;
            }

            /* выпадающий список фильтров на мобильных */
            #filterSuggestions {
                max-height: 150px;
                font-size: 13px;
            }

            .filter-suggestion-item {
                padding: 12px;
                font-size: 14px;
            }

            /* таблица выпущенных гофропакетов */
            .production-section table {
                font-size: 12px;
            }

            .production-section th,
            .production-section td {
                padding: 5px 4px;
            }

            /* отступы для блока изготовленных гофропакетов на мобильных */
            .production-section {
                margin-left: 10px;
                margin-right: 10px;
            }
        }
    </style>
    <script>
        // навигация по датам
        function setDateAndReload(dStr){
            if(!dStr) return;
            const url = new URL(window.location.href);
            url.searchParams.set('date', dStr);
            window.location.href = url.toString();
        }
        function shiftDate(delta){
            const inp = document.getElementById('date-input');
            if(!inp.value) return;
            const d = new Date(inp.value + 'T00:00:00');
            d.setDate(d.getDate() + delta);
            const y = d.getFullYear();
            const m = String(d.getMonth()+1).padStart(2,'0');
            const day = String(d.getDate()).padStart(2,'0');
            setDateAndReload(`${y}-${m}-${day}`);
        }
        // автоперезагрузка при смене даты
        function onDateChange(e){ setDateAndReload(e.target.value); }
        // стрелки с клавиатуры ← →
        document.addEventListener('keydown', (e)=>{
            const tag = (e.target && e.target.tagName || '').toLowerCase();
            if(tag === 'input' || tag === 'textarea') return;
            if(e.key === 'ArrowLeft'){ shiftDate(-1); }
            if(e.key === 'ArrowRight'){ shiftDate(1); }
        });


        async function saveGroup(idsCsv, itemsJson, inputId, plan){
            const inp = document.getElementById(inputId);
            const val = Number((inp.value||'').trim());
            if(isNaN(val) || val < 0){ alert('Введите корректное число'); return; }

            const items = JSON.parse(itemsJson);

            // распределение общего факта по строкам
            let rest = val, dist = [];
            for (const it of items){
                if (rest <= 0){ dist.push({id:it.id,fact:0}); continue; }
                const take = rest; // берем весь остаток (без ограничения планом)
                dist.push({id:it.id,fact:take}); rest -= take;
            }

            // сохраняем по каждой строке
            for (const d of dist){
                const resp = await fetch('save_corr_fact.php',{
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'id='+d.id+'&fact='+d.fact
                }).then(r=>r.json()).catch(()=>null);
                if (!resp || !resp.success){
                    alert('Ошибка сохранения факта по строкам группы.');
                    return;
                }
            }

            alert('Сохранено');
        }

        // Enter в поле «Факт» = сохранить
        function onQtyKey(e, idsCsv, itemsJson, inputId, plan){
            if (e.key === 'Enter') {
                e.preventDefault();
                saveGroup(idsCsv, itemsJson, inputId, plan);
            }
        }

        // навешиваем обработчик на date input после загрузки
        document.addEventListener('DOMContentLoaded', ()=>{
            const di = document.getElementById('date-input');
            if (di) di.addEventListener('change', onDateChange);
        });

    </script>
</head>
<body>
    <div class="container">
<h2>Задания гофромашины на <?= htmlspecialchars($date) ?></h2>

<div class="nav">
            <a href="?date=<?= htmlspecialchars($prevDate) ?>" title="День назад">
                ⬅️ 
            </a>
    <input id="date-input" type="date" value="<?= htmlspecialchars($date) ?>" />
            <a href="?date=<?= htmlspecialchars($nextDate) ?>" title="День вперёд">
                ➡️
            </a>
            <a href="?date=<?= htmlspecialchars($today) ?>" title="Сегодня">
                Сегодня
            </a>
</div>

<div class="section">
    <?php if ($group_list): ?>
        <table>
            <thead>
            <tr>
                <th>Заявка</th>
                <th>Фильтр</th>
                <th>План</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($group_list as $g):
                $idsCsv   = implode(',', $g['ids']);
                $rowId    = 'grow-'.str_replace(',', '-', $idsCsv);
                ?>
                <tr id="<?= $rowId ?>">
                    <td>
                        <strong><?= htmlspecialchars($g['order_number']) ?></strong>
                    </td>
                    <td>
                        <?= htmlspecialchars($g['filter_label']) ?>
                    </td>
                    <td>
                        <span style="font-weight: 600; color: var(--primary-color);">
                            <?= (int)$g['plan_sum'] ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
                <div class="no-data">
                    Заданий на эту дату нет
                </div>
    <?php endif; ?>
        </div>
</div>

<div class="section production-section">
    <h3 style="margin-bottom: 16px; font-size: 1.1rem; font-weight: 600; color: var(--gray-800);">Изготовленные гофропакеты</h3>
    
    <?php if (!empty($manufactured_packages)): ?>
        <div style="margin-bottom: 24px;">
            <table style="border-collapse: collapse; width: 100%; font-size: 13px; background: white; border: 1px solid var(--gray-200);">
                <thead>
                    <tr>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Заявка</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Фильтр</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Количество</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Время</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px; width: 1%; white-space: nowrap;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_count = 0;
                    $is_first = true;
                    foreach ($manufactured_packages as $item): 
                        $total_count += (int)$item['count'];
                        $time = $item['timestamp'] ? date('H:i', strtotime($item['timestamp'])) : '-';
                    ?>
                        <tr style="border-bottom: 1px solid var(--gray-200);">
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center;">
                                <strong><?= htmlspecialchars($item['order_number'] ?: '-') ?></strong>
                            </td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: left; padding-left: 12px;">
                                <?= htmlspecialchars($item['filter_label']) ?>
                            </td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; font-weight: 600; color: var(--primary-color);">
                                <?= (int)$item['count'] ?>
                            </td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; color: var(--gray-600); font-size: 12px;">
                                <?= htmlspecialchars($time) ?>
                            </td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; width: 1%; white-space: nowrap;">
                                <?php if ($is_first): ?>
                                    <button class="delete-last-btn" 
                                            onclick="deleteLastPackage('<?= htmlspecialchars($date) ?>')"
                                            style="background: var(--accent-color); color: white; border: none; padding: 6px 10px; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 14px; font-weight: 500; transition: var(--transition); width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;"
                                            title="Удалить последнюю внесенную позицию">
                                        ✕
                                    </button>
                                <?php else: ?>
                                    <span style="color: var(--gray-400); font-size: 11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php 
                        $is_first = false;
                    endforeach; ?>
                    <tr style="background: var(--gray-50); font-weight: 600;">
                        <td colspan="2" style="border: 1px solid var(--gray-200); padding: 8px 12px; text-align: right;">
                            Итого:
                        </td>
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px; text-align: center; color: var(--primary-color);">
                            <?= $total_count ?>
                        </td>
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px;"></td>
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px; width: 1%;"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="margin-bottom: 24px; text-align: center; color: var(--gray-500); font-size: 14px; padding: 20px;">
            Выпущенных гофропакетов за этот день нет
        </div>
    <?php endif; ?>

    <div style="padding-top: 24px; border-top: 1px solid var(--gray-200);">
        <form id="addProductionForm" style="display: flex; flex-direction: column; gap: 12px; max-width: 100%;">
            <div style="position: relative;">
                <label for="filterInput" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Имя фильтра:</label>
                <input type="text" id="filterInput" name="filter" required 
                       style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition);"
                       placeholder="Введите имя фильтра" autocomplete="off">
                <div id="filterSuggestions" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--gray-300); border-top: none; border-radius: 0 0 var(--border-radius-sm) var(--border-radius-sm); max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: var(--shadow-md); margin-top: -1px;"></div>
            </div>
            <div>
                <label for="orderSelect" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Заявка:</label>
                <select id="orderSelect" name="order" required 
                        style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition); background: white;">
                    <option value="">Выберите заявку</option>
                </select>
            </div>
            <div>
                <label for="countInput" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Количество:</label>
                <input type="number" id="countInput" name="count" required min="1" 
                       style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition);"
                       placeholder="Введите количество">
            </div>
            <button type="submit" 
                    style="background: var(--primary-color); color: white; border: none; padding: 10px 20px; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 14px; font-weight: 500; transition: var(--transition); margin-top: 4px;">
                Внести
            </button>
        </form>
    </div>
</div>

    <script>
        // Список всех фильтров из PHP
        const allFilters = <?= json_encode($all_filters, JSON_UNESCAPED_UNICODE) ?>;
        
        let currentFilterOrders = [];
        let loadOrdersTimeout = null;
        let filterSuggestionsTimeout = null;
        let currentHighlightIndex = -1;
        const filterInput = document.getElementById('filterInput');
        const orderSelect = document.getElementById('orderSelect');
        const filterSuggestions = document.getElementById('filterSuggestions');
        const submitButton = document.querySelector('#addProductionForm button[type="submit"]');

        // Функция поиска фильтров
        function searchFilters(query) {
            const trimmedQuery = query.trim().toLowerCase();
            
            if (trimmedQuery.length < 1) {
                hideFilterSuggestions();
                return;
            }

            // Фильтруем список фильтров
            const matched = allFilters.filter(filter => 
                filter.toLowerCase().includes(trimmedQuery)
            ).slice(0, 10); // Ограничиваем до 10 результатов

            if (matched.length > 0) {
                showFilterSuggestions(matched);
            } else {
                hideFilterSuggestions();
            }
        }

        // Показать выпадающий список
        function showFilterSuggestions(filters) {
            filterSuggestions.innerHTML = '';
            
            filters.forEach((filter, index) => {
                const item = document.createElement('div');
                item.className = 'filter-suggestion-item';
                item.textContent = filter;
                item.onclick = () => selectFilter(filter);
                item.onmouseover = () => highlightSuggestion(index);
                item.ontouchstart = () => highlightSuggestion(index);
                filterSuggestions.appendChild(item);
            });
            
            filterSuggestions.style.display = 'block';
            currentHighlightIndex = -1;
        }

        // Скрыть выпадающий список
        function hideFilterSuggestions() {
            setTimeout(() => {
                filterSuggestions.style.display = 'none';
            }, 200);
        }

        // Выделить элемент в списке
        function highlightSuggestion(index) {
            const items = filterSuggestions.querySelectorAll('.filter-suggestion-item');
            items.forEach((item, i) => {
                item.classList.toggle('highlighted', i === index);
            });
            currentHighlightIndex = index;
        }

        // Выбрать фильтр
        function selectFilter(filterName) {
            filterInput.value = filterName;
            hideFilterSuggestions();
            // Загружаем заявки для выбранного фильтра
            loadOrdersForFilter(filterName);
        }

        // Обработка ввода в поле фильтра
        filterInput.addEventListener('input', function() {
            const value = this.value;
            
            // Очищаем предыдущий таймаут
            if (filterSuggestionsTimeout) {
                clearTimeout(filterSuggestionsTimeout);
            }
            
            // Поиск с небольшой задержкой (debounce)
            filterSuggestionsTimeout = setTimeout(() => {
                searchFilters(value);
            }, 150);
        });

        // Обработка фокуса
        filterInput.addEventListener('focus', function() {
            if (this.value.trim().length > 0) {
                searchFilters(this.value);
            }
        });

        // Скрываем список при клике вне поля
        document.addEventListener('click', function(e) {
            const target = e.target;
            if (target !== filterInput && 
                !filterInput.contains(target) && 
                target !== filterSuggestions && 
                !filterSuggestions.contains(target)) {
                hideFilterSuggestions();
            }
        });

        // Обработка клавиш в поле ввода
        filterInput.addEventListener('keydown', function(e) {
            const items = filterSuggestions.querySelectorAll('.filter-suggestion-item');
            
            if (filterSuggestions.style.display === 'block' && items.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1);
                    highlightSuggestion(currentHighlightIndex);
                    items[currentHighlightIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentHighlightIndex = Math.max(currentHighlightIndex - 1, -1);
                    if (currentHighlightIndex === -1) {
                        items.forEach(item => item.classList.remove('highlighted'));
                    } else {
                        highlightSuggestion(currentHighlightIndex);
                        items[currentHighlightIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                        selectFilter(items[currentHighlightIndex].textContent);
                    } else if (items.length > 0) {
                        selectFilter(items[0].textContent);
                    }
                } else if (e.key === 'Escape') {
                    hideFilterSuggestions();
                }
            }
        });

        // Функция загрузки заявок для фильтра
        async function loadOrdersForFilter(filter) {
            const trimmedFilter = filter.trim();
            orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
            orderSelect.value = '';
            
            if (!trimmedFilter) {
                currentFilterOrders = [];
                return;
            }

            try {
                const response = await fetch(`get_orders_for_filter.php?filter=${encodeURIComponent(trimmedFilter)}`);
                const data = await response.json();
                
                if (data.success && data.orders && data.orders.length > 0) {
                    currentFilterOrders = data.orders;
                    data.orders.forEach(order => {
                        const option = document.createElement('option');
                        option.value = order;
                        option.textContent = order;
                        orderSelect.appendChild(option);
                    });
                } else {
                    currentFilterOrders = [];
                }
            } catch (error) {
                console.error('Ошибка загрузки заявок:', error);
                currentFilterOrders = [];
            }
        }

        // Загрузка заявок при потере фокуса (если фильтр введен вручную)
        filterInput.addEventListener('blur', async function() {
            if (loadOrdersTimeout) {
                clearTimeout(loadOrdersTimeout);
            }
            // Небольшая задержка, чтобы дать время событию клика по списку сработать
            setTimeout(async () => {
                const value = this.value.trim();
                if (value) {
                    await loadOrdersForFilter(value);
                }
            }, 300);
        });

        // Валидация заявки при выборе
        orderSelect.addEventListener('change', function() {
            const order = this.value.trim();
            const filter = filterInput.value.trim();
            
            if (!order || !filter) {
                return;
            }

            // Проверяем, есть ли заявка в списке для выбранного фильтра
            const orderExists = currentFilterOrders.some(o => o === order);
            
            if (!orderExists && currentFilterOrders.length > 0) {
                alert('Эта заявка не найдена для выбранного фильтра. Выберите заявку из списка.');
                this.focus();
            }
        });

        // Функция удаления последней внесенной позиции
        async function deleteLastPackage(date) {
            if (!confirm('Вы уверены, что хотите удалить последнюю внесенную позицию?')) {
                return;
            }

            try {
                const response = await fetch('delete_last_manufactured_package.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        date_of_production: date
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Последняя позиция успешно удалена');
                    // Перезагружаем страницу для обновления таблицы
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка:', error);
                alert('Ошибка при удалении записи');
            }
        }

        // Обработка формы внесения изготовленной продукции
        document.getElementById('addProductionForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const order = orderSelect.value.trim();
            const filter = filterInput.value.trim();
            const count = parseInt(document.getElementById('countInput').value);
            const date = '<?= htmlspecialchars($date) ?>';
            
            if (!order || !filter || !count || count <= 0) {
                alert('Заполните все поля корректно');
                return;
            }

            // Проверяем, что заявка есть в списке для выбранного фильтра
            if (currentFilterOrders.length > 0) {
                const orderExists = currentFilterOrders.some(o => o === order);
                if (!orderExists) {
                    alert('Эта заявка не найдена для выбранного фильтра. Выберите заявку из списка.');
                    orderSelect.focus();
                    return;
                }
            }
            
            try {
                const response = await fetch('save_manufactured_corrugated_packages.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        date_of_production: date,
                        order_number: order,
                        filter_label: filter,
                        count: count
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Продукция внесена успешно');
                    filterInput.value = '';
                    document.getElementById('countInput').value = '';
                    orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
                    orderSelect.value = '';
                    currentFilterOrders = [];
                    filterInput.focus();
                    // Перезагружаем страницу для обновления таблицы
                    window.location.reload();
                } else {
                    alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка:', error);
                alert('Ошибка при сохранении данных');
            }
        });
    </script>
</body>
</html>
