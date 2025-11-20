<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "");
$date = $_GET['date'] ?? date('Y-m-d');

// грузим сырые строки
$stmt = $pdo->prepare("
    SELECT id, order_number, plan_date, filter_label, `count`, fact_count
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
            'fact_sum'     => 0,
        ];
    }
    $groups[$key]['ids'][] = (int)$r['id'];
    $groups[$key]['items'][] = [
        'id'         => (int)$r['id'],
        'count'      => (int)$r['count'],
        'fact_count' => (int)$r['fact_count'],
    ];
    $groups[$key]['plan_sum'] += (int)$r['count'];
    $groups[$key]['fact_sum'] += (int)$r['fact_count'];
}
$group_list = array_values($groups);

// даты для стрелок
$dt       = new DateTime($date);
$prevDate = $dt->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
$today    = date('Y-m-d');

// Начальная заливка по плану/факту (зелёная шкала 80–100%+)
function greenShadeStyle(int $plan, int $fact): string {
    if ($plan <= 0) return '';
    $ratio = $fact / $plan;

    $h = 120;     // оттенок зелёного
    $s = 60;      // насыщенность
    $L_dark  = 65; // тёмный (при >=100%)
    $L_light = 85; // светлый (при 80%)

    if ($ratio >= 1) {
        $L = $L_dark;
    } elseif ($ratio >= 0.8) {
        $def = 1 - $ratio;         // 0..0.2
        $t   = $def / 0.2;         // 0..1
        $L   = $L_dark + ($L_light - $L_dark) * $t;
    } else {
        return '';
    }
    $L = max(0, min(100, $L));
    return "style=\"background-color: hsl($h, {$s}%, {$L}%);\"";
}
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
            margin-bottom: 20px;
            color: var(--gray-800);
            font-size: 1.5rem;
            font-weight: 600;
        }

        .section {
            background: white;
            padding: 24px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border: 1px solid var(--gray-200);
        }

        /* NAV */
        .nav {
            max-width: 900px;
            margin: 0 auto 30px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            background: white;
            padding: 16px;
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
            font-size: 14px;
            background: white;
            border: 1px solid var(--gray-200);
        }

        th, td {
            border: 1px solid var(--gray-200);
            padding: 12px 8px;
            text-align: center;
        }

        thead th {
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 13px;
        }

        tbody tr:nth-child(even) {
            background: var(--gray-50);
        }

        tbody tr:hover {
            background: var(--gray-100);
        }

        /* выполнено — только оформление текста; фон задаём инлайном */
        .is-done td {
            text-decoration: line-through;
            color: var(--success-dark);
            font-weight: 600;
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

        .search-form {
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .search-results {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-200);
            border-radius: var(--border-radius-sm);
        }

        .search-result-item {
            padding: 12px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: var(--transition);
        }

        .search-result-item:hover {
            background-color: var(--gray-50);
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .result-date {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 14px;
        }

        .result-details {
            margin-top: 4px;
            color: var(--gray-600);
            font-size: 13px;
        }

        .result-order-number {
            background: var(--primary-color);
            color: white;
            padding: 4px 8px;
            border-radius: var(--border-radius-sm);
            font-weight: 700;
            font-size: 14px;
            display: inline-block;
            margin-right: 8px;
            box-shadow: var(--shadow-sm);
        }

        .result-plan {
            color: var(--success-color);
            font-weight: 500;
        }

        .result-fact {
            color: var(--warning-color);
            font-weight: 500;
        }

        .no-results {
            text-align: center;
            padding: 30px 20px;
            color: var(--gray-500);
            font-size: 14px;
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
                padding: 10px 6px;
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

            table {
                width: 100%;
                table-layout: auto;
                font-size: 12px;
            }

            th, td {
                padding: 8px 4px;
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

            /* колонка "Факт" - фиксированная ширина */
            thead th:nth-child(4),
            tbody td:nth-child(4) {
                width: 25%;
                min-width: 100px;
            }

            /* внутри "Факт": одна строка, без переносов */
            tbody td:nth-child(4) {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                white-space: nowrap;
            }

            /* поле количества */
            input[type="number"].qty {
                width: 45px;
                padding: 4px 6px;
                font-size: 12px;
            }

            /* кнопка сохранения */
            button.save {
                padding: 4px 8px;
                font-size: 11px;
                min-width: 30px;
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

        // заливка после сохранения
        function applyShade(rowEl, plan, fact){
            if (!rowEl || plan <= 0) return;
            const ratio = fact / plan;

            const H = 120, S = 60;
            const L_dark = 35, L_light = 85;

            rowEl.style.backgroundColor = '';
            if (ratio >= 1){
                rowEl.classList.add('is-done');
                rowEl.style.backgroundColor = `hsl(${H}, ${S}%, ${L_dark}%)`;
            } else if (ratio >= 0.8){
                rowEl.classList.remove('is-done');
                const def = 1 - ratio; // 0..0.2
                const t   = def / 0.2; // 0..1
                const L   = L_dark + (L_light - L_dark) * t;
                rowEl.style.backgroundColor = `hsl(${H}, ${S}%, ${L}%)`;
            } else {
                rowEl.classList.remove('is-done');
            }
        }

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

            // применим подсветку
            const row = document.getElementById('grow-'+idsCsv.split(',').join('-'));
            applyShade(row, Number(plan), val);

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

        // Функции для модального окна поиска
        function openFilterSearch() {
            document.getElementById('filterSearchModal').style.display = 'block';
            document.getElementById('filterSearchInput').focus();
        }

        function closeFilterSearch() {
            document.getElementById('filterSearchModal').style.display = 'none';
            document.getElementById('filterSearchInput').value = '';
            document.getElementById('searchResults').innerHTML = '<div class="no-results">Введите название фильтра для поиска</div>';
        }

        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('filterSearchModal');
            if (event.target === modal) {
                closeFilterSearch();
            }
        }

        // Поиск фильтров
        async function searchFilters() {
            const searchTerm = document.getElementById('filterSearchInput').value.trim();
            const resultsDiv = document.getElementById('searchResults');
            
            if (searchTerm.length < 2) {
                resultsDiv.innerHTML = '<div class="no-results">Введите минимум 2 символа для поиска</div>';
                return;
            }

            try {
                const response = await fetch('search_filter_positions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'filter_name=' + encodeURIComponent(searchTerm)
                });

                const data = await response.json();
                
                if (data.success && data.results.length > 0) {
                    displaySearchResults(data.results);
                } else {
                    resultsDiv.innerHTML = '<div class="no-results">Позиции с таким фильтром не найдены</div>';
                }
            } catch (error) {
                console.error('Ошибка поиска:', error);
                resultsDiv.innerHTML = '<div class="no-results">Ошибка при поиске. Попробуйте еще раз.</div>';
            }
        }

        // Отображение результатов поиска
        function displaySearchResults(results) {
            const resultsDiv = document.getElementById('searchResults');
            let html = '';

            results.forEach(result => {
                const planSum = result.plan_sum || 0;
                const factSum = result.fact_sum || 0;
                const ratio = planSum > 0 ? (factSum / planSum) : 0;
                const ratioPercent = (ratio * 100).toFixed(1);
                
                // Затенение для выполнения 90%+
                let bgStyle = '';
                if (ratio >= 0.9) {
                    const H = 120;
                    const L = 85; // светлость одинаковая
                    // насыщенность от 20% (при 90%) до 60% (при 100%+)
                    const S_min = 20, S_max = 60;
                    let S;
                    if (ratio >= 1) {
                        S = S_max;
                    } else {
                        // ratio от 0.9 до 1.0
                        const t = (ratio - 0.9) / 0.1; // 0..1
                        S = S_min + (S_max - S_min) * t;
                    }
                    bgStyle = `background: hsl(${H}, ${S}%, ${L}%);`;
                }
                
                html += `
                    <div class="search-result-item" onclick="goToDate('${result.plan_date}')" style="${bgStyle}">
                        <div class="result-date">${result.plan_date}</div>
                        <div class="result-details">
                            <span class="result-order-number">${result.order_number}</span>${result.filter_label}<br>
                            План: <span class="result-plan">${planSum} шт</span> | 
                            Факт: <span class="result-fact">${factSum} шт</span> | 
                            Выполнено: ${ratioPercent}%
                        </div>
                    </div>
                `;
            });

            resultsDiv.innerHTML = html;
        }

        // Переход к найденной дате
        function goToDate(date) {
            closeFilterSearch();
            setDateAndReload(date);
        }
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
            <button onclick="openFilterSearch()" title="Найти позицию по фильтру">
                Найти позицию
            </button>
</div>

<div class="section">
    <?php if ($group_list): ?>
        <table>
            <thead>
            <tr>
                <th>Заявка</th>
                <th>Фильтр</th>
                <th>План</th>
                <th>Факт</th>
                <th>История</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($group_list as $g):
                $idsCsv   = implode(',', $g['ids']);
                $rowId    = 'grow-'.str_replace(',', '-', $idsCsv);
                $inputId  = 'gfact-'.str_replace(',', '-', $idsCsv);
                $itemsArr = array_map(fn($it)=>['id'=>$it['id'],'count'=>$it['count']], $g['items']);
                $itemsJson = htmlspecialchars(json_encode($itemsArr), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $ratio  = ($g['plan_sum']>0) ? $g['fact_sum']/$g['plan_sum'] : 0;
                $isDone = ($ratio >= 1);
                $style  = greenShadeStyle((int)$g['plan_sum'], (int)$g['fact_sum']); // начальный фон
                
                // Берем первый ID для отображения истории группы
                $firstId = $g['ids'][0];
                ?>
                <tr id="<?= $rowId ?>" class="<?= $isDone ? 'is-done' : '' ?>" <?= $style ?>>
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
                    <td>
                        <input
                            type="number" class="qty" id="<?= $inputId ?>"
                            value="<?= (int)$g['fact_sum']  ?>" min="0" max="<?= (int)$g['plan_sum'] ?>"
                            onkeydown="onQtyKey(event,'<?= $idsCsv ?>','<?= $itemsJson ?>','<?= $inputId ?>',<?= (int)$g['plan_sum'] ?>)"
                        >
                        <button class="save" type="button" title="Сохранить"
                                onclick="saveGroup('<?= $idsCsv ?>','<?= $itemsJson ?>','<?= $inputId ?>',<?= (int)$g['plan_sum'] ?>)">
                            ✓
                        </button>
                    </td>
                    <td>
                        <button class="save" type="button" title="История изготовления"
                                onclick="showHistory(<?= $firstId ?>, '<?= $idsCsv ?>')"
                                style="background: var(--info-color);">
                            📋
                        </button>
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

    <!-- Modal для поиска позиций по фильтру -->
    <div id="filterSearchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Поиск позиций по фильтру</h2>
                <span class="close" onclick="closeFilterSearch()">&times;</span>
            </div>
            <div class="search-form">
                <input 
                    type="text" 
                    id="filterSearchInput" 
                    class="search-input" 
                    placeholder="Введите название фильтра для поиска..."
                    onkeyup="searchFilters()"
                >
            </div>
            <div id="searchResults" class="search-results">
                <div class="no-results">
                    Введите название фильтра для поиска
                </div>
            </div>
        </div>
    </div>

    <!-- Modal для просмотра истории изготовления -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">История изготовления позиции</h2>
                <span class="close" onclick="closeHistory()">&times;</span>
            </div>
            <div id="historyContent" style="padding: 10px;">
                <div class="no-results">Загрузка...</div>
            </div>
        </div>
    </div>

    <script>
        // Функции для модального окна истории
        function showHistory(id, ids) {
            const modal = document.getElementById('historyModal');
            const content = document.getElementById('historyContent');
            
            modal.style.display = 'block';
            content.innerHTML = '<div class="no-results">Загрузка...</div>';
            
            // Загружаем историю для первого ID (для группы)
            fetch('get_corr_history.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayHistory(data.data);
                    } else {
                        content.innerHTML = '<div class="no-results">Ошибка: ' + (data.message || 'Неизвестная ошибка') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Ошибка загрузки истории:', error);
                    content.innerHTML = '<div class="no-results">Ошибка загрузки данных</div>';
                });
        }

        function displayHistory(data) {
            const content = document.getElementById('historyContent');
            
            let html = '<div style="margin-bottom: 20px;">';
            html += '<p><strong>Заявка:</strong> ' + data.order_number + '</p>';
            html += '<p><strong>Фильтр:</strong> ' + data.filter_label + '</p>';
            html += '<p><strong>План:</strong> <span style="color: var(--primary-color); font-weight: 600;">' + data.plan_count + ' шт</span></p>';
            html += '<p><strong>Факт (общий):</strong> <span style="color: var(--success-color); font-weight: 600;">' + data.fact_count + ' шт</span></p>';
            html += '</div>';
            
            if (data.history && data.history.length > 0) {
                html += '<h3 style="margin-bottom: 15px; font-size: 1.1rem;">История изготовления:</h3>';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr style="background: var(--gray-100);">';
                html += '<th style="padding: 10px; border: 1px solid var(--gray-200);">Дата</th>';
                html += '<th style="padding: 10px; border: 1px solid var(--gray-200);">Количество</th>';
                html += '<th style="padding: 10px; border: 1px solid var(--gray-200);">Время</th>';
                html += '</tr></thead><tbody>';
                
                data.history.forEach(entry => {
                    html += '<tr>';
                    html += '<td style="padding: 10px; border: 1px solid var(--gray-200); text-align: center;"><strong>' + entry.date + '</strong></td>';
                    html += '<td style="padding: 10px; border: 1px solid var(--gray-200); text-align: center; font-weight: 600; color: var(--success-color);">' + entry.quantity + ' шт</td>';
                    html += '<td style="padding: 10px; border: 1px solid var(--gray-200); text-align: center;">' + (entry.timestamp || '-') + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                
                html += '<div style="margin-top: 20px; padding: 15px; background: var(--gray-50); border-radius: var(--border-radius);">';
                html += '<p><strong>Итого из истории:</strong> <span style="color: var(--info-color); font-weight: 600;">' + data.stats.total_from_history + ' шт</span></p>';
                html += '<p><strong>Дней изготовления:</strong> ' + data.stats.production_days + '</p>';
                
                if (data.stats.is_match) {
                    html += '<p style="color: var(--success-color); font-weight: 600;">✓ История совпадает с фактом</p>';
                } else {
                    html += '<p style="color: var(--warning-color); font-weight: 600;">⚠ История не совпадает с фактом</p>';
                }
                html += '</div>';
            } else {
                html += '<div class="no-results">История изготовления пока пуста</div>';
            }
            
            content.innerHTML = html;
        }

        function closeHistory() {
            document.getElementById('historyModal').style.display = 'none';
        }

        // Обновляем закрытие модальных окон при клике вне их
        const existingClickHandler = window.onclick;
        window.onclick = function(event) {
            const historyModal = document.getElementById('historyModal');
            const filterModal = document.getElementById('filterSearchModal');
            
            if (event.target === historyModal) {
                closeHistory();
            }
            if (event.target === filterModal) {
                closeFilterSearch();
            }
        };
    </script>
</body>
</html>
