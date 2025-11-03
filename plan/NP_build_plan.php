<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_GET['order'] ?? '';
$days = intval($_GET['days'] ?? 9);
$start = $_GET['start'] ?? date('Y-m-d');
$fills_per_day = intval($_GET['fills_per_day'] ?? 50);

$start_date = new DateTime($start);
$dates = [];
for ($i = 0; $i < $days; $i++) {
    $dates[] = $start_date->format('Y-m-d');
    $start_date->modify('+1 day');
}

// Вычисляем конечную дату диапазона
$end_date = (new DateTime($start))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');

// Получение позиций из гофроплана только в пределах выбранного диапазона дат
$stmt = $pdo->prepare("SELECT id, plan_date, filter_label, count FROM corrugation_plan WHERE order_number = ? AND plan_date >= ? AND plan_date <= ?");
$stmt->execute([$order, $start, $end_date]);
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$by_date = [];
foreach ($positions as $p) {
    $tooltip = "{$p['filter_label']} | Кол-во гофропакетов: {$p['count']}";
    $by_date[$p['plan_date']][] = [
        'id'      => $p['id'],
        'label'   => $p['filter_label'],
        'tooltip' => $tooltip,
        'count'   => $p['count']
    ];
}

// Загрузка существующего плана сборки
$stmt = $pdo->prepare("SELECT assign_date, place, filter_label, count, corrugation_plan_id FROM build_plan WHERE order_number = ? ORDER BY assign_date, place");
$stmt->execute([$order]);
$existing_plan = $stmt->fetchAll(PDO::FETCH_ASSOC);
$plan_data = [];
foreach ($existing_plan as $row) {
    if (!isset($plan_data[$row['assign_date']])) {
        $plan_data[$row['assign_date']] = [];
    }
    if (!isset($plan_data[$row['assign_date']][$row['place']])) {
        $plan_data[$row['assign_date']][$row['place']] = [];
    }
    $plan_data[$row['assign_date']][$row['place']][] = [
        'filter' => $row['filter_label'],
        'count' => $row['count'],
        'corrugation_plan_id' => $row['corrugation_plan_id']
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Планирование сборки</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; padding: 0; margin: 0; background: #f0f0f0; }
        
        /* Фиксированная шапка */
        .fixed-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #f0f0f0;
            z-index: 100;
            padding: 15px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Контент с отступом сверху */
        .content {
            margin-top: 150px;
            padding: 0 20px 20px 20px;
        }
        
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { font-size: 10px; border: 1px solid #ccc; padding: 2px; vertical-align: top; white-space: normal; background: #fff; }
        th { background: #fafafa; }
        .position-cell { display: block; margin-bottom: 2px; cursor: pointer; padding: 2px; font-size: 11px; border-bottom: 1px dotted #ccc; }
        .used { background-color: #ccc; color: #666; cursor: not-allowed; }
        .assigned-item {
            background: #d2f5a3;
            margin-bottom: 1px;
            padding: 1px 3px;
            cursor: pointer;
            border-radius: 3px;
            display: block;
            box-sizing: border-box;
            width: 100%;
            font-size: 10px;
            line-height: 1.2;
        }
        .half-width { width: 50%; float: left; box-sizing: border-box; }
        .drop-target { min-height: 16px; min-width: 60px; position: relative; }
        .date-col { min-width: 60px; } /* компактная ширина для дат */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 20px; border-radius: 5px; width: 400px; }
        .modal h3 { margin-top: 0; }
        .modal button { margin-top: 10px; }
        .date-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; max-height: 300px; overflow-y: auto; }
        .places { margin-top: 10px; }
        .places-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; margin-top: 10px; }
        .places-grid button { padding: 5px; font-size: 12px; cursor: pointer; }
        .summary { font-size: 11px; font-weight: bold; padding-top: 4px; }
        .hover-highlight { background-color: #ffe780 !important; transition: background-color 0.2s; }
        .highlight-col { background-color: #ffe6b3 !important; }
        .highlight-row { background-color: #fff3cd !important; }

        /* --- Sticky колонки для нижней таблицы --- */
        .table-wrap { position: relative; overflow: auto; border: 1px solid #ddd; background: #fff; }
        #bottom-table { width: max(100%, 1200px); } /* чтобы был горизонтальный скролл при множестве дней */
        .sticky-left, .sticky-right {
            position: sticky;
            z-index: 3;
            background: #fff; /* не прозрачно над содержимым */
        }
        th.sticky-left, td.sticky-left {
            left: 0;
            z-index: 4; /* поверх обычных ячеек */
            box-shadow: 2px 0 0 rgba(0,0,0,0.06);
            min-width: 30px;
            width: 30px;
            text-align: center;
            padding: 2px 4px;
        }
        th.sticky-right, td.sticky-right {
            right: 0;
            z-index: 4;
            box-shadow: -2px 0 0 rgba(0,0,0,0.06);
            min-width: 30px;
            width: 30px;
            text-align: center;
            padding: 2px 4px;
        }
        
        /* Плавающее окно для нижней таблицы */
        .floating-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 45%;
            max-width: 700px;
            height: auto;
            max-height: 90vh;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .floating-panel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
            user-select: none;
        }
        
        .floating-panel-title {
            font-weight: 600;
            font-size: 13px;
        }
        
        .floating-panel-controls {
            display: flex;
            gap: 4px;
        }
        
        .floating-panel-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            line-height: 1;
        }
        
        .floating-panel-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .floating-panel-content {
            overflow: hidden;
            padding: 5px;
        }
        
        .floating-panel .table-wrap {
            border-radius: 8px;
            max-height: calc(90vh - 40px);
        }
    </style>
</head>
<body>
<div class="fixed-header">
    <h2 style="margin: 10px 0;">Планирование сборки для заявки <?= htmlspecialchars($order) ?></h2>
<form method="get" style="display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">
    Дата начала: <input type="date" name="start" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d')) ?>">
    Дней: <input type="number" name="days" value="<?= $days ?>" min="1" max="90">
    Заливок в смену: <input type="number" name="fills_per_day" id="fills_per_day" value="<?= $fills_per_day ?>" min="1" style="width:60px;">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <button type="submit">Построить таблицу</button>
    <button type="button" onclick="addDay()">Добавить день</button>
    <button type="button" onclick="removeDay()">Убрать день</button>
    <button type="button" onclick="reloadPlan()" style="background:#16a34a; color:#fff; padding:5px 10px; border:1px solid #16a34a; border-radius:4px; cursor:pointer;">Загрузить план</button>
    <button type="button" onclick="savePlan()" style="background:#2563eb; color:#fff; padding:5px 10px; border:1px solid #2563eb; border-radius:4px; cursor:pointer;">Сохранить план</button>
    <button type="button" onclick="completePlanning()" style="background:#059669; color:#fff; padding:5px 10px; border:1px solid #059669; border-radius:4px; cursor:pointer; font-weight:600;">Завершить</button>
    <button type="button" onclick="clearPage()" style="background:#dc2626; color:#fff; padding:5px 10px; border:1px solid #dc2626; border-radius:4px; cursor:pointer;">Очистить страницу</button>
</form>
</div>

<div class="content">
<h3>Доступные позиции из гофроплана</h3>
<table id="top-table">
    <tr>
        <?php foreach ($dates as $d): ?>
            <th class="date-col"><?= $d ?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($dates as $d): ?>
            <td>
                <?php foreach ($by_date[$d] ?? [] as $item): ?>
                    <?php
                    $short = preg_replace('/\[\d+]\s+\d+(\.\d+)?$/', '', $item['label']);
                    $uniqueId = uniqid('pos_');
                    ?>
                    <div class="position-cell"
                         data-id="<?= $uniqueId ?>"
                         data-corr-id="<?= $item['id'] ?>"
                         data-label="<?= htmlspecialchars($item['label']) ?>"
                         data-count="<?= $item['count'] ?>"
                         title="<?= htmlspecialchars($item['tooltip']) ?>"
                         data-cut-date="<?= $d ?>">
                        <?= htmlspecialchars($short) ?>
                    </div>
                <?php endforeach; ?>
            </td>
        <?php endforeach; ?>
    </tr>
</table>

<form method="post" action="NP/save_build_plan.php" id="save-form">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <input type="hidden" name="plan_data" id="plan_data">
</form>

<div class="floating-panel" id="floating-panel">
    <div class="floating-panel-header" id="panel-header">
        <div class="floating-panel-title">📋 Планирование сборки</div>
        <div class="floating-panel-controls">
            <button class="floating-panel-btn" onclick="minimizePanel()">−</button>
        </div>
    </div>
    <div class="floating-panel-content">
        <div class="table-wrap">
            <table id="bottom-table">
                <thead>
                <tr>
                    <th class="sticky-left">Место</th>
                    <?php foreach ($dates as $d): ?>
                        <th class="date-col"><?= $d ?></th>
                    <?php endforeach; ?>
                    <th class="sticky-right" id="right-sticky-header">Место</th>
                </tr>
                </thead>
                <tbody>
                <?php for ($place = 1; $place <= 17; $place++): ?>
                    <tr>
                        <td class="sticky-left"><?= $place ?></td>
                        <?php foreach ($dates as $d): ?>
                            <td class="drop-target date-col" data-date="<?= $d ?>" data-place="<?= $place ?>"></td>
                        <?php endforeach; ?>
                        <td class="sticky-right"><?= $place ?></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal" id="modal">
    <div class="modal-content">
        <h3>Выберите дату</h3>
        <div id="modal-dates" class="date-grid"></div>
        <div class="places">
            <h4>Выберите место:</h4>
            <div id="modal-places" class="places-grid"></div>
        </div>
        <button onclick="closeModal()">Отмена</button>
    </div>
</div>

<script>
    let selectedLabel = '';
    let selectedCutDate = '';
    let selectedId = '';
    let selectedDate = '';

    function closeModal() {
        document.getElementById("modal").style.display = "none";
        selectedLabel = '';
        selectedCutDate = '';
        selectedId = '';
        selectedDate = '';
        document.getElementById("modal-places").innerHTML = "";
    }

    // Функция проверки, есть ли еще элементы с этим data-id в нижней таблице
    function checkIfPositionFullyRemoved(posId) {
        const remainingItems = document.querySelectorAll(`.assigned-item[data-id='${posId}']`);
        if (remainingItems.length === 0) {
            // Если больше нет элементов с этим data-id, убираем "used" из верхней таблицы
            const upperCell = document.querySelector(`.position-cell[data-id='${posId}']`);
            if (upperCell) {
                upperCell.classList.remove('used');
            }
        }
    }

    function attachRemoveHandlers() {
        document.querySelectorAll('.assigned-item').forEach(div => {
            div.onmouseenter = () => highlightByLabel(div.dataset.label);
            div.onmouseleave = removeHoverHighlight;
            div.onclick = () => {
                const posId = div.getAttribute('data-id');
                // Удаляем только этот конкретный элемент
                div.remove();
                // Проверяем, остались ли еще элементы с этим data-id
                checkIfPositionFullyRemoved(posId);
            };
        });
    }

    document.querySelectorAll('.position-cell').forEach(cell => {
        cell.onmouseenter = () => highlightByLabel(cell.dataset.label);
        cell.onmouseleave = removeHoverHighlight;
        cell.addEventListener('click', () => {
            if (cell.classList.contains('used')) return;
            selectedLabel = cell.dataset.label;
            selectedCutDate = cell.dataset.cutDate;
            selectedId = cell.dataset.id;
            const modalDates = document.getElementById("modal-dates");
            modalDates.innerHTML = "";
            // собираем список дат из заголовков нижней таблицы, исключая левый и правый sticky
            const ths = Array.from(document.querySelectorAll('#bottom-table thead th'));
            ths.forEach((th, i) => {
                if (i > 0 && i < ths.length - 1) {
                    const dateStr = th.innerText.trim();
                    if (dateStr >= selectedCutDate) {
                        const btn = document.createElement("button");
                        btn.innerText = dateStr;
                        btn.onclick = () => {
                            selectedDate = dateStr;
                            renderPlacesForDate(selectedDate);
                        };
                        modalDates.appendChild(btn);
                    }
                }
            });
            document.getElementById("modal").style.display = "flex";
        });
    });

    function highlightByLabel(label) {
        const match = label.match(/\d{4}/);
        if (!match) return;
        const digits = match[0];
        document.querySelectorAll('.position-cell, .assigned-item').forEach(el => {
            const elMatch = el.dataset.label.match(/\d{4}/);
            if (elMatch && elMatch[0] === digits) el.classList.add('hover-highlight');
        });
    }

    function removeHoverHighlight() {
        document.querySelectorAll('.hover-highlight').forEach(el => el.classList.remove('hover-highlight'));
    }

    function renderPlacesForDate(date) {
        const modalPlaces = document.getElementById("modal-places");
        const fillsPerDay = parseInt(document.getElementById("fills_per_day").value || "50");
        modalPlaces.innerHTML = "";
        
        for (let i = 1; i <= 17; i++) {
            const btn = document.createElement("button");
            const td = document.querySelector(`.drop-target[data-date='${date}'][data-place='${i}']`);
            
            // Подсчитываем общее количество запланированных фильтров на это место
            let totalPlanned = 0;
            td.querySelectorAll('.assigned-item').forEach(item => {
                const count = parseInt(item.dataset.count || 0);
                totalPlanned += count;
            });
            
            // Проверяем, заполнено ли место
            const isFull = totalPlanned >= fillsPerDay;
            
            btn.innerText = `Место ${i}`;
            if (totalPlanned > 0) {
                btn.innerText += ` (${totalPlanned}/${fillsPerDay})`;
            }
            
            if (isFull) {
                btn.disabled = true;
                btn.style.opacity = "0.5";
                btn.style.backgroundColor = "#e5e7eb";
                btn.style.cursor = "not-allowed";
            } else {
                btn.onclick = () => {
                    distributeToBuildPlan(date, i);
                    const cell = document.querySelector('.position-cell[data-id="' + selectedId + '"]');
                    if (cell) cell.classList.add('used');
                    closeModal();
                };
            }
            modalPlaces.appendChild(btn);
        }
    }

    function distributeToBuildPlan(startDate, place) {
        const selectedCell = document.querySelector(`.position-cell[data-id="${selectedId}"]`);
        let total = parseInt(selectedCell.dataset.count);
        const selectedCorrId = selectedCell.dataset.corrId; // Получаем corrugation_plan_id
        const fillsPerDay = parseInt(document.getElementById("fills_per_day").value || "50");
        const dateHeaders = Array.from(document.querySelectorAll('#bottom-table thead th'));
        const dateList = dateHeaders.slice(1, dateHeaders.length - 1).map(th => th.innerText).filter(d => d >= startDate);

        let dateIndex = 0;
        while (total > 0 && dateIndex < dateList.length) {
            const td = document.querySelector(`.drop-target[data-date='${dateList[dateIndex]}'][data-place='${place}']`);
            if (td) {
                let alreadyInCell = 0;
                td.querySelectorAll('.assigned-item').forEach(item => {
                    alreadyInCell += parseInt(item.dataset.count || 0);
                });
                // Вычисляем свободное место: fillsPerDay минус уже занятое
                let freeSpace = fillsPerDay - alreadyInCell;
                if (freeSpace <= 0) { 
                    dateIndex++; 
                    continue; 
                }
                // Добавляем ровно столько, чтобы заполнить до fillsPerDay (или весь остаток, если он меньше)
                const batch = Math.min(total, freeSpace);
                const div = document.createElement('div');
                // Определяем отображаемое название
                let displayName = '';
                if (selectedLabel.startsWith('AF')) {
                    // Для AF показываем AF + цифры + буквы (например AF2012s)
                    const filterMatch = selectedLabel.match(/AF\s*\d{4}[a-zA-Z]*/);
                    displayName = filterMatch ? filterMatch[0].replace(/\s+/g, '') : selectedLabel.split('[')[0].trim();
                } else {
                    // Для других брендов показываем все до символа [
                    displayName = selectedLabel.split('[')[0].trim();
                }
                div.innerText = displayName;
                // В tooltip показываем полную информацию
                div.title = `${selectedLabel}\nКоличество: ${batch}`;
                div.classList.add('assigned-item');
                div.setAttribute('data-label', selectedLabel);
                div.setAttribute('data-count', batch);
                div.setAttribute('data-corr-id', selectedCorrId); // Добавляем corrugation_plan_id
                if (td.querySelector('.assigned-item')) {
                    div.classList.add('half-width');
                    td.querySelector('.assigned-item').classList.add('half-width');
                }
                div.setAttribute('data-id', selectedId);
                td.appendChild(div);
                total -= batch;
            }
            dateIndex++;
        }
        attachRemoveHandlers();
    }

    function preparePlan() {
        const data = {};
        document.querySelectorAll('.drop-target').forEach(td => {
            const date = td.getAttribute('data-date');
            const place = td.getAttribute('data-place');
            const items = Array.from(td.querySelectorAll('div')).map(d => ({
                label: d.dataset.label,
                count: d.dataset.count ? parseInt(d.dataset.count) : 0,
                corrugation_plan_id: d.dataset.corrId ? parseInt(d.dataset.corrId) : null
            }));
            if (items.length > 0) {
                if (!data[date]) data[date] = {};
                data[date][place] = items;
            }
        });
        document.getElementById('plan_data').value = JSON.stringify(data);
    }

    function addDay() {
        const topTable = document.getElementById('top-table');
        const bottomTable = document.getElementById('bottom-table');

        // последняя дата = предпоследний th (перед правым sticky)
        const ths = bottomTable.querySelectorAll('thead th');
        const lastDateTh = ths[ths.length - 2];
        const lastDate = new Date(lastDateTh.innerText);
        lastDate.setDate(lastDate.getDate() + 1);
        const newDateStr = lastDate.toISOString().split('T')[0];

        // Верхняя таблица
        const topHeaderRow = topTable.querySelector('tr:first-child');
        const newTopTh = document.createElement('th');
        newTopTh.className = 'date-col';
        newTopTh.innerText = newDateStr;
        topHeaderRow.appendChild(newTopTh);

        const topSecondRow = topTable.querySelector('tr:nth-child(2)');
        const newTopTd = document.createElement('td');
        topSecondRow.appendChild(newTopTd);

        // Нижняя таблица: вставляем ПЕРЕД правым sticky
        const bottomHeaderRow = bottomTable.querySelector('thead tr');
        const rightStickyHeader = document.getElementById('right-sticky-header');
        const newBottomTh = document.createElement('th');
        newBottomTh.className = 'date-col';
        newBottomTh.innerText = newDateStr;
        bottomHeaderRow.insertBefore(newBottomTh, rightStickyHeader);

        const bottomRows = bottomTable.querySelectorAll('tbody tr');
        bottomRows.forEach(row => {
            const place = row.querySelector('td.sticky-left').innerText;
            const newTd = document.createElement('td');
            newTd.classList.add('drop-target', 'date-col');
            newTd.setAttribute('data-date', newDateStr);
            newTd.setAttribute('data-place', place);
            const rightSticky = row.querySelector('td.sticky-right');
            row.insertBefore(newTd, rightSticky);
        });

        addTableHoverEffect();
    }

    function removeDay() {
        const topTable = document.getElementById('top-table');
        const bottomTable = document.getElementById('bottom-table');

        // Удаляем последнюю дату ПЕРЕД правым sticky
        const ths = bottomTable.querySelectorAll('thead th');
        if (ths.length <= 3) return; // минимум: левый, один день, правый
        const lastDateTh = ths[ths.length - 2];
        const dateStr = lastDateTh.innerText;
        lastDateTh.remove();

        // Верх: убираем последний столбец
        const topHeaders = topTable.querySelectorAll('tr:first-child th');
        if (topHeaders.length > 0) topHeaders[topHeaders.length - 1].remove();
        const topRows = topTable.querySelectorAll('tr:nth-child(2) td');
        if (topRows.length > 0) topRows[topRows.length - 1].remove();

        // Низ: убрать ячейки с этой датой
        const bottomRows = bottomTable.querySelectorAll('tbody tr');
        bottomRows.forEach(row => {
            const cells = Array.from(row.querySelectorAll('td.drop-target'));
            const cellToRemove = cells.find(td => td.getAttribute('data-date') === dateStr);
            if (cellToRemove) cellToRemove.remove();
        });
    }

    function addTableHoverEffect() {
        const bottomTable = document.getElementById('bottom-table');
        const rows = bottomTable.querySelectorAll('tbody tr');
        const ths = bottomTable.querySelectorAll('thead th');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td.drop-target');
            cells.forEach((cell) => {
                cell.addEventListener('mouseenter', () => {
                    // Подсветим всю строку
                    row.querySelectorAll('td').forEach(td => td.classList.add('highlight-row'));
                    // Подсветим соответствующий столбец: находим индекс th по дате
                    const date = cell.getAttribute('data-date');
                    let colIndex = -1;
                    ths.forEach((th, idx) => { if (th.innerText.trim() === date) colIndex = idx; });
                    if (colIndex > -1) {
                        // Подсветить этот th
                        ths[colIndex].classList.add('highlight-col');
                        // И каждую ячейку в этом столбце (пробегаем строки)
                        bottomTable.querySelectorAll('tbody tr').forEach(r => {
                            const tds = r.querySelectorAll('td');
                            if (tds[colIndex]) tds[colIndex].classList.add('highlight-col');
                        });
                    }
                });
                cell.addEventListener('mouseleave', () => {
                    bottomTable.querySelectorAll('td, th').forEach(c => {
                        c.classList.remove('highlight-col');
                        c.classList.remove('highlight-row');
                    });
                });
            });
        });
    }

    addTableHoverEffect();
    window.addDay = addDay;
    window.removeDay = removeDay;

    // Загрузка существующего плана
    function loadExistingPlan() {
        const planData = <?= json_encode($plan_data) ?>;
        const corrPlanData = <?= json_encode($by_date) ?>;
        
        // Создаем маппинг filter -> full label из corrugation_plan
        const filterToLabel = {};
        Object.values(corrPlanData).forEach(dateItems => {
            dateItems.forEach(item => {
                const filterName = item.label.split('[')[0].trim();
                filterToLabel[filterName] = item.label;
            });
        });
        
        // ШАГ 1: Собираем уникальные corrugation_plan_id из build_plan
        const usedCorrIds = new Set();
        
        Object.keys(planData).forEach(date => {
            Object.keys(planData[date]).forEach(place => {
                planData[date][place].forEach(item => {
                    if (item.corrugation_plan_id) {
                        usedCorrIds.add(parseInt(item.corrugation_plan_id));
                    }
                });
            });
        });
        
        console.log(`Найдено ${usedCorrIds.size} уникальных позиций для затенения`);
        
        // ШАГ 2: Закрашиваем позиции по corrugation_plan_id
        usedCorrIds.forEach(corrId => {
            const posCell = document.querySelector(`.position-cell[data-corr-id="${corrId}"]`);
            if (posCell) {
                posCell.classList.add('used');
                console.log(`✓ Закрашена позиция с id=${corrId}: ${posCell.dataset.label}`);
            } else {
                console.warn(`⚠ Позиция с corrugation_plan_id=${corrId} не найдена в верхней таблице`);
            }
        });
        
        // ШАГ 3: Отрисовываем элементы в нижней таблице
        Object.keys(planData).forEach(date => {
            Object.keys(planData[date]).forEach(place => {
                const td = document.querySelector(`.drop-target[data-date='${date}'][data-place='${place}']`);
                if (!td) return;
                
                planData[date][place].forEach(item => {
                    const filterName = item.filter;
                    const count = item.count;
                    const fullLabel = filterToLabel[filterName] || filterName;
                    
                    // Находим соответствующую позицию в верхней таблице для связи data-id
                    const posCell = Array.from(document.querySelectorAll('.position-cell')).find(cell => 
                        cell.dataset.label === fullLabel && cell.classList.contains('used')
                    );
                    
                    // Если позиции нет в верхней таблице - пропускаем
                    if (!posCell) {
                        console.warn(`Позиция "${fullLabel}" из build_plan не найдена в верхней таблице - пропускаем`);
                        return;
                    }
                    
                    const div = document.createElement('div');
                    // Определяем отображаемое название
                    let displayName = '';
                    if (fullLabel.startsWith('AF')) {
                        // Для AF показываем AF + цифры + буквы (например AF2012s)
                        const filterMatch = fullLabel.match(/AF\s*\d{4}[a-zA-Z]*/);
                        displayName = filterMatch ? filterMatch[0].replace(/\s+/g, '') : filterName;
                    } else {
                        // Для других брендов показываем все до символа [
                        displayName = fullLabel.split('[')[0].trim();
                    }
                    div.innerText = displayName;
                    // В tooltip показываем полную информацию
                    div.title = `${fullLabel}\nКоличество: ${count}`;
                    div.classList.add('assigned-item');
                    div.setAttribute('data-label', fullLabel);
                    div.setAttribute('data-count', count);
                    div.setAttribute('data-corr-id', item.corrugation_plan_id || ''); // Добавляем corrugation_plan_id
                    // Используем data-id из верхней таблицы
                    div.setAttribute('data-id', posCell.dataset.id);
                    
                    if (td.querySelector('.assigned-item')) {
                        div.classList.add('half-width');
                        td.querySelector('.assigned-item').classList.add('half-width');
                    }
                    
                    td.appendChild(div);
                });
            });
        });
        
        attachRemoveHandlers();
    }
    
    // Загружаем план при загрузке страницы (только если нет параметра nocache)
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('nocache') && Object.keys(<?= json_encode($plan_data) ?>).length > 0) {
        loadExistingPlan();
    }
    
    // Функция для перезагрузки плана (очистить и загрузить заново)
    function reloadPlan() {
        // Очищаем все назначенные элементы
        document.querySelectorAll('.assigned-item').forEach(item => item.remove());
        
        // Убираем пометки "used" с верхней таблицы
        document.querySelectorAll('.position-cell.used').forEach(cell => cell.classList.remove('used'));
        
        // Загружаем план заново
        if (Object.keys(<?= json_encode($plan_data) ?>).length > 0) {
            loadExistingPlan();
            alert('План загружен из базы данных');
        } else {
            alert('Сохраненный план не найден');
        }
    }
    
    window.reloadPlan = reloadPlan;
    
    // Функция для сохранения плана (остаёмся на странице)
    async function savePlan() {
        preparePlan();
        const formData = new FormData(document.getElementById('save-form'));
        
        try {
            const response = await fetch('NP/save_build_plan.php?stay=1', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                alert('✓ План успешно сохранён!');
            } else {
                alert('✗ Ошибка при сохранении плана');
            }
        } catch (error) {
            alert('✗ Ошибка: ' + error.message);
        }
    }
    
    // Функция для завершения планирования (сохранить и перейти на главную)
    function completePlanning() {
        if (!confirm('Завершить планирование?\nПлан будет сохранён и вы вернётесь на главную страницу.')) return;
        preparePlan();
        document.getElementById('save-form').submit();
    }
    
    window.savePlan = savePlan;
    window.completePlanning = completePlanning;
    
    // Функция для очистки страницы (перезагрузка без загрузки плана)
    function clearPage() {
        if (!confirm('Очистить страницу? Несохраненные изменения будут потеряны.')) return;
        
        // Получаем текущие параметры
        const params = new URLSearchParams(window.location.search);
        params.set('nocache', '1'); // Добавляем параметр, чтобы не загружать план
        
        // Перезагружаем страницу с новыми параметрами
        window.location.href = window.location.pathname + '?' + params.toString();
    }
    
    window.clearPage = clearPage;
    
    // Функционал плавающей панели
    let isDragging = false;
    let currentX, currentY, initialX, initialY;
    let isMinimized = false;
    let savedHeight = 'auto';
    
    const panel = document.getElementById('floating-panel');
    const panelHeader = document.getElementById('panel-header');
    
    panelHeader.addEventListener('mousedown', dragStart);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', dragEnd);
    
    function dragStart(e) {
        if (e.target === panelHeader || e.target.classList.contains('floating-panel-title')) {
            isDragging = true;
            const rect = panel.getBoundingClientRect();
            initialX = e.clientX - rect.left;
            initialY = e.clientY - rect.top;
        }
    }
    
    function drag(e) {
        if (isDragging) {
            e.preventDefault();
            currentX = e.clientX - initialX;
            currentY = e.clientY - initialY;
            panel.style.left = currentX + 'px';
            panel.style.top = currentY + 'px';
            panel.style.transform = 'none';
        }
    }
    
    function dragEnd() {
        isDragging = false;
    }
    
    function minimizePanel() {
        const content = document.querySelector('.floating-panel-content');
        if (isMinimized) {
            content.style.display = 'block';
            panel.style.height = savedHeight;
            isMinimized = false;
        } else {
            savedHeight = panel.style.height || 'auto';
            content.style.display = 'none';
            panel.style.height = 'auto';
            isMinimized = true;
        }
    }
    
    window.minimizePanel = minimizePanel;
</script>
</div>
</body>
</html>
