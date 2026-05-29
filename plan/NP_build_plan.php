<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
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
    // Убираем из tooltip ширину/высоту бумаги, чтобы они не “светились” в интерфейсе
    $tooltipLabel = preg_replace('/\[\s*h?\s*\d+(?:\.\d+)?\s*]\s*\d+(?:\.\d+)?/u', '', (string)$p['filter_label']);
    $tooltipLabel = preg_replace('/\[\s*h?\s*\d+(?:\.\d+)?\s*]/u', '', (string)$tooltipLabel);
    $tooltipLabel = trim(preg_replace('/\s{2,}/u', ' ', $tooltipLabel));
    $tooltip = "{$tooltipLabel} | Кол-во гофропакетов: {$p['count']}";
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
        .position-cell { display: block; margin-bottom: 2px; cursor: pointer; padding: 2px; font-size: 11px; border-bottom: 1px dotted #ccc; user-select: none; }
        .position-cell.pos-wide { background-color: #DBEAFE; border-bottom-color: #93C5FD; }
        .position-cell.used { background-color: #ccc !important; border-bottom-color: #aaa !important; }
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
            user-select: none;
        }
        .assigned-item.pos-wide { background-color: #DBEAFE !important; }
        .half-width { width: 50%; float: left; box-sizing: border-box; }
        .drop-target { min-height: 16px; min-width: 78px; position: relative; user-select: none; }
        .date-col { min-width: 78px; } /* ширина колонок дат (+30% к прежним 60px) */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 20px; border-radius: 5px; width: 400px; }
        #audit-modal.modal { z-index: 1100; }
        #audit-modal .modal-content { width: auto; max-width: 640px; max-height: 85vh; display: flex; flex-direction: column; }
        #audit-modal-body { white-space: pre-wrap; word-break: break-word; margin: 0 0 12px; flex: 1; min-height: 0; overflow: auto; font-size: 11px; line-height: 1.35; }
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
        #bottom-table { width: max(100%, 1560px); } /* горизонтальный скролл при множестве дней (база +30%) */
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

        /* Режим: выбрана позиция сверху — можно назначить кликом по ячейке плана */
        body.np-build-plan-pick-cell #bottom-table tbody td.drop-target {
            cursor: pointer;
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
    <button type="button" onclick="auditBuildPlanMarkup()" style="background:#ca8a04; color:#fff; padding:5px 10px; border:1px solid #ca8a04; border-radius:4px; cursor:pointer;" title="Разметка (серые/блоки) и количества (гофроплан vs сумма внизу)">Проверить разметку</button>
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
                    // Убираем из отображения ширину/высоту бумаги: [48] 199 / [h48] 199.5 и т.п.
                    // Не привязываемся к концу строки: после высоты могут быть значки.
                    $short = preg_replace('/\[\s*h?\s*\d+(?:\.\d+)?\s*]\s*\d+(?:\.\d+)?/u', '', (string)$item['label']);
                    $short = preg_replace('/\[\s*h?\s*\d+(?:\.\d+)?\s*]/u', '', (string)$short);
                    $short = trim(preg_replace('/\s{2,}/u', ' ', $short));
                    $paperWidth = null;
                    // Ширина бумаги обычно записана как: "[48] 199" (высота: "[h48] 199.5")
                    if (preg_match('/\[\s*\d+(?:[.,]\d+)?\s*\]\s*([0-9]+(?:[.,][0-9]+)?)/u', (string)$item['label'], $mm)) {
                        $paperWidth = (float)str_replace(',', '.', (string)$mm[1]);
                    }
                    $posWideClass = ($paperWidth !== null && $paperWidth > 230) ? ' pos-wide' : '';
                    $uniqueId = uniqid('pos_');
                    ?>
                    <div class="position-cell<?= $posWideClass ?>"
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
        <p style="font-size:11px; color:#555; margin:0 0 8px;">Можно вместо выбора здесь кликнуть по пустой ячейке в таблице «Планирование сборки» — дата и место подставятся сами. Либо по уже размещённой позиции в её <strong>последний</strong> день в плане — новая позиция добавится в этот же день и строку, если в ячейке ещё есть лимит по заливкам; иначе со следующего дня.</p>
        <div id="modal-dates" class="date-grid"></div>
        <div class="places">
            <h4>Выберите место:</h4>
            <div id="modal-places" class="places-grid"></div>
        </div>
        <button onclick="closeModal()">Отмена</button>
    </div>
</div>

<div class="modal" id="audit-modal" onclick="if (event.target === this) closeAuditModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <h3 id="audit-modal-title" style="margin-top:0;">Результат проверки</h3>
        <pre id="audit-modal-body"></pre>
        <button type="button" onclick="closeAuditModal()">Закрыть</button>
    </div>
</div>

<script>
    function normalizePlanLabel(label) {
        if (!label) return '';
        const base = String(label).split('[')[0].trim().replace(/\s+/g, '').toUpperCase();
        return base;
    }

    function stripPaperDims(label){
        if (!label) return '';
        // Убираем конструкции вида: "[48] 199" / "[h48] 199.5" (в том числе если после высоты есть значок)
        let s = String(label);
        s = s.replace(/\[\s*h?\s*\d+(?:[.,]\d+)?\s*]\s*\d+(?:[.,]\d+)?/g, '');
        s = s.replace(/\[\s*h?\s*\d+(?:[.,]\d+)?\s*]/g, '');
        s = s.replace(/\s{2,}/g, ' ').trim();
        return s;
    }

    function parsePaperWidthMm(label){
        if (!label) return null;
        const m = String(label).match(/\[\s*\d+(?:[.,]\d+)?\s*\]\s*([0-9]+(?:[.,][0-9]+)?)/u);
        if (!m) return null;
        const n = parseFloat(String(m[1]).replace(',', '.'));
        return isFinite(n) ? n : null;
    }

    let selectedLabel = '';
    let selectedCutDate = '';
    let selectedId = '';
    let selectedDate = '';
    // Активная позиция внизу: последняя ячейка (дата и "место"), куда было добавление.
    // Используется для Shift+клика по позиции сверху.
    let activeDate = '';
    let activePlace = null;

    function closePickerMode() {
        document.body.classList.remove('np-build-plan-pick-cell');
    }

    function openPickerMode() {
        document.body.classList.add('np-build-plan-pick-cell');
    }

    /** Позиция из верхней таблицы выбрана для назначения (модалка открыта) и ещё не «used». */
    function getPendingPositionCell() {
        if (!selectedId) return null;
        const cell = document.querySelector('.position-cell[data-id="' + selectedId + '"]');
        if (!cell || cell.classList.contains('used')) return null;
        return cell;
    }

    function addCalendarDay(dateStr) {
        const p = String(dateStr).trim().split('-').map(Number);
        if (p.length < 3 || !p[0]) return dateStr;
        const d = new Date(p[0], p[1] - 1, p[2]);
        d.setDate(d.getDate() + 1);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    /** Все фрагменты одной позиции внизу (как при удалении). */
    function getAssignedClusterItems(anchorItem) {
        const posId = anchorItem.getAttribute('data-id');
        const corrId = anchorItem.getAttribute('data-corr-id');
        const label = anchorItem.getAttribute('data-label');
        if (posId) {
            return Array.from(document.querySelectorAll('.assigned-item[data-id=\'' + posId + '\']'));
        }
        if (corrId) {
            return Array.from(document.querySelectorAll('.assigned-item[data-corr-id=\'' + corrId + '\']'));
        }
        if (label) {
            const norm = normalizePlanLabel(label);
            if (norm) {
                return Array.from(document.querySelectorAll('.assigned-item')).filter(item =>
                    normalizePlanLabel(item.dataset.label || '') === norm);
            }
        }
        return [];
    }

    /** Максимальная дата assign_date среди фрагментов кластера (последний день позиции в плане). */
    function findClusterLastDate(anchorItem) {
        const items = getAssignedClusterItems(anchorItem);
        if (!items.length) return null;
        let maxD = '';
        items.forEach(it => {
            const cell = it.closest('td.drop-target');
            if (!cell) return;
            const d = (cell.getAttribute('data-date') || '').trim();
            if (d > maxD) maxD = d;
        });
        return maxD || null;
    }

    function getBottomPlanDateRange() {
        const ths = Array.from(document.querySelectorAll('#bottom-table thead th'));
        const dates = ths.slice(1, ths.length - 1).map(th => th.innerText.trim()).filter(Boolean);
        if (!dates.length) return { min: '', max: '' };
        return { min: dates[0], max: dates[dates.length - 1] };
    }

    function closeModal() {
        document.getElementById("modal").style.display = "none";
        closePickerMode();
        selectedLabel = '';
        selectedCutDate = '';
        selectedId = '';
        selectedDate = '';
        document.getElementById("modal-places").innerHTML = "";
    }

    // Снимаем "used" у верхних позиций, когда внизу больше не осталось соответствующих assigned-item.
    // Важно: при загрузке сохраненного плана data-id у assigned-item может не совпадать с data-id верхней ячейки,
    // поэтому проверяем в приоритетном порядке: data-id -> data-corr-id -> нормализованный label.
    function checkIfPositionFullyRemoved(posId, corrId, label) {
        // 1) По data-id (самый точный вариант)
        if (posId) {
            const remainingItems = document.querySelectorAll(`.assigned-item[data-id='${posId}']`);
            if (remainingItems.length === 0) {
                const upperCell = document.querySelector(`.position-cell[data-id='${posId}']`);
                if (upperCell) upperCell.classList.remove('used');
            }
        }

        // 2) По data-corr-id (когда data-id не совпал)
        if (corrId) {
            const remainingCorrItems = document.querySelectorAll(`.assigned-item[data-corr-id='${corrId}']`);
            if (remainingCorrItems.length === 0) {
                document.querySelectorAll(`.position-cell[data-corr-id='${corrId}']`).forEach(upper => {
                    upper.classList.remove('used');
                });
            }
        }

        // 3) По нормализованному label (fallback на случай несовпадений форматирования/матчинга)
        if (label) {
            const norm = normalizePlanLabel(label);
            if (norm) {
                const remainingNormItems = Array.from(document.querySelectorAll('.assigned-item')).filter(item => {
                    return normalizePlanLabel(item.dataset.label || '') === norm;
                });
                if (remainingNormItems.length === 0) {
                    document.querySelectorAll('.position-cell').forEach(upper => {
                        if (normalizePlanLabel(upper.dataset.label || '') === norm) {
                            upper.classList.remove('used');
                        }
                    });
                }
            }
        }
    }

    function attachRemoveHandlers() {
        document.querySelectorAll('.assigned-item').forEach(div => {
            div.onmouseenter = () => highlightByLabel(div.dataset.label);
            div.onmouseleave = removeHoverHighlight;
            div.onclick = () => {
                const posId = div.getAttribute('data-id');
                const corrId = div.getAttribute('data-corr-id');
                const label = div.getAttribute('data-label');
                // Логируем и определяем, какие фрагменты нужно удалить:
                // по вашему требованию: удаляем не только кликнутый фрагмент, а ВСЕ фрагменты с тем же ID.
                let itemsToRemove = [];
                if (posId) {
                    itemsToRemove = Array.from(document.querySelectorAll(`.assigned-item[data-id='${posId}']`));
                } else if (corrId) {
                    itemsToRemove = Array.from(document.querySelectorAll(`.assigned-item[data-corr-id='${corrId}']`));
                } else if (label) {
                    const norm = normalizePlanLabel(label);
                    if (norm) {
                        itemsToRemove = Array.from(document.querySelectorAll('.assigned-item')).filter(item => {
                            return normalizePlanLabel(item.dataset.label || '') === norm;
                        });
                    }
                }

                // Удаляем все фрагменты кластера
                itemsToRemove.forEach(it => it.remove());

                // Проверяем, что позиция действительно освободилась
                checkIfPositionFullyRemoved(posId, corrId, label);
                // Частичное удаление: если внизу осталось меньше, чем count в гофроплане — снова активна сверху
                document.querySelectorAll('#top-table .position-cell.used').forEach(upper => {
                    if (!isCorrugationRowFullyPlaced(upper)) upper.classList.remove('used');
                });
            };
        });
    }

    // Проверяем: для даты все 17 мест заполнены по лимиту fillsPerDay
    function isDateFullyBooked(dateStr, fillsPerDay) {
        for (let i = 1; i <= 17; i++) {
            const td = document.querySelector(`.drop-target[data-date='${dateStr}'][data-place='${i}']`);
            if (!td) return false; // если вдруг ячейка отсутствует — не считаем дату полностью занятой

            let totalPlanned = 0;
            td.querySelectorAll('.assigned-item').forEach(item => {
                totalPlanned += parseInt(item.dataset.count || 0);
            });

            if (totalPlanned < fillsPerDay) return false;
        }
        return true;
    }

    document.querySelectorAll('.position-cell').forEach(cell => {
        cell.onmouseenter = () => highlightByLabel(cell.dataset.label);
        cell.onmouseleave = removeHoverHighlight;
        cell.addEventListener('click', (e) => {
            if (e.shiftKey) {
                // Shift+клик иногда триггерит выделение текста в браузере — убираем,
                // чтобы Shift+клик работал как действие, а не как выделение.
                e.preventDefault();
                e.stopPropagation();
                const sel = window.getSelection && window.getSelection();
                if (sel && sel.removeAllRanges) sel.removeAllRanges();
            }
            if (cell.classList.contains('used')) return;
            selectedLabel = cell.dataset.label;
            selectedCutDate = cell.dataset.cutDate;
            selectedId = cell.dataset.id;

            // Shift+клик: добавляем сразу начиная с активной даты/места,
            // продолжая распределение дальше по дням.
            if (e.shiftKey && activeDate && activePlace !== null && activePlace !== undefined) {
                // Нельзя добавлять раньше cutDate позиции.
                const startDate = (selectedCutDate && activeDate < selectedCutDate) ? selectedCutDate : activeDate;
                distributeToBuildPlan(startDate, activePlace);
                markTopCellUsedIfFullyPlaced();
                closeModal();
                return;
            }

            const modalDates = document.getElementById("modal-dates");
            modalDates.innerHTML = "";
            const fillsPerDay = parseInt(document.getElementById("fills_per_day").value || "50");
            // собираем список дат из заголовков нижней таблицы, исключая левый и правый sticky
            const ths = Array.from(document.querySelectorAll('#bottom-table thead th'));
            ths.forEach((th, i) => {
                if (i > 0 && i < ths.length - 1) {
                    const dateStr = th.innerText.trim();
                    if (dateStr >= selectedCutDate) {
                        const btn = document.createElement("button");
                        btn.innerText = dateStr;
                        const isFullDate = isDateFullyBooked(dateStr, fillsPerDay);
                        if (isFullDate) {
                            btn.disabled = true;
                            btn.style.opacity = "0.5";
                            btn.style.backgroundColor = "#e5e7eb";
                            btn.style.cursor = "not-allowed";
                        } else {
                            btn.onclick = () => {
                                selectedDate = dateStr;
                                renderPlacesForDate(selectedDate);
                            };
                        }
                        modalDates.appendChild(btn);
                    }
                }
            });
            document.getElementById("modal").style.display = "flex";
            openPickerMode();
        });
    });

    // Назначение в ячейку плана кликом по панели «Планирование сборки» (capture: перехват клика по блоку, чтобы не сработало удаление)
    document.getElementById('bottom-table').addEventListener('click', function (e) {
        const posCell = getPendingPositionCell();
        if (!posCell) return;

        const assignTouch = e.target.closest('.assigned-item');
        if (assignTouch) {
            const anchorTd = assignTouch.closest('td.drop-target');
            if (!anchorTd || !anchorTd.classList.contains('drop-target')) return;

            const clickedDate = (anchorTd.getAttribute('data-date') || '').trim();
            const place = parseInt(anchorTd.getAttribute('data-place'), 10);
            const clusterLast = findClusterLastDate(assignTouch);

            if (!clusterLast || clickedDate !== clusterLast) {
                e.preventDefault();
                e.stopPropagation();
                alert('Чтобы поставить новую позицию сразу после этой, кликните по блоку этой позиции в её последний день в плане (последняя колонка, где она ещё отображается для этой строки «место»).');
                return;
            }

            const fillsPerDay = parseInt(document.getElementById('fills_per_day').value || '50', 10);
            let alreadyInLastDayCell = 0;
            anchorTd.querySelectorAll('.assigned-item').forEach(item => {
                alreadyInLastDayCell += parseInt(item.dataset.count || 0, 10);
            });
            // В последний день кластера: сначала этот же день/место, если под лимит заливок ещё есть место; иначе со следующего дня
            const startDate = (alreadyInLastDayCell < fillsPerDay) ? clusterLast : addCalendarDay(clusterLast);

            const cutDate = selectedCutDate || posCell.dataset.cutDate || '';
            if (cutDate && startDate < cutDate) {
                e.preventDefault();
                e.stopPropagation();
                alert('Нельзя начать раньше даты из гофроплана для новой позиции (' + cutDate + ').');
                return;
            }

            const range = getBottomPlanDateRange();
            if (range.max && startDate > range.max) {
                e.preventDefault();
                e.stopPropagation();
                alert('Размещение с выбранной даты (' + startDate + ') выходит за правый край таблицы. Нажмите «Добавить день» или выберите другой способ.');
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            distributeToBuildPlan(startDate, place);
            markTopCellUsedIfFullyPlaced();
            closeModal();
            return;
        }

        const td = e.target.closest('td.drop-target');
        if (!td || !td.classList.contains('drop-target')) return;

        const date = (td.getAttribute('data-date') || '').trim();
        const place = parseInt(td.getAttribute('data-place'), 10);
        const cutDate = selectedCutDate || posCell.dataset.cutDate || '';
        if (!date || !place) return;

        if (cutDate && date < cutDate) {
            alert('Нельзя назначить раньше даты из гофроплана для этой позиции (' + cutDate + ').');
            return;
        }

        const fillsPerDay = parseInt(document.getElementById('fills_per_day').value || '50', 10);
        let totalPlanned = 0;
        td.querySelectorAll('.assigned-item').forEach(item => {
            totalPlanned += parseInt(item.dataset.count || 0, 10);
        });
        if (totalPlanned >= fillsPerDay) {
            alert('На этом месте в выбранный день уже достигнут лимит заливок (' + fillsPerDay + ').');
            return;
        }

        distributeToBuildPlan(date, place);
        markTopCellUsedIfFullyPlaced();
        closeModal();
    }, true);

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
                    markTopCellUsedIfFullyPlaced();
                    closeModal();
                };
            }
            modalPlaces.appendChild(btn);
        }
    }

    function getPlacedCountForTopCell(topCell) {
        if (!topCell) return 0;
        const needed = parseInt(topCell.dataset.count || '0', 10);
        if (!needed) return 0;
        const cid = String(topCell.dataset.corrId || '').trim();
        let placed = 0;
        if (cid) {
            document.querySelectorAll('#bottom-table .assigned-item[data-corr-id="' + cid + '"]').forEach(item => {
                placed += parseInt(item.dataset.count || 0, 10);
            });
        } else {
            const posId = topCell.getAttribute('data-id');
            if (posId) {
                document.querySelectorAll('#bottom-table .assigned-item[data-id="' + posId + '"]').forEach(item => {
                    placed += parseInt(item.dataset.count || 0, 10);
                });
            }
        }
        return placed;
    }

    function isCorrugationRowFullyPlaced(topCell) {
        if (!topCell) return false;
        const needed = parseInt(topCell.dataset.count || '0', 10);
        if (!needed) return true;
        return getPlacedCountForTopCell(topCell) >= needed;
    }

    /** Серая подсветка только если внизу размещён весь объём гофропакетов по этой строке. */
    function markTopCellUsedIfFullyPlaced() {
        const topCell = document.querySelector('.position-cell[data-id="' + selectedId + '"]');
        if (topCell && isCorrugationRowFullyPlaced(topCell)) {
            topCell.classList.add('used');
        }
    }

    function distributeToBuildPlan(startDate, place) {
        const selectedCell = document.querySelector(`.position-cell[data-id="${selectedId}"]`);
        const initialTotal = parseInt(selectedCell.dataset.count, 10) || 0;
        let total = initialTotal;
        const selectedCorrId = selectedCell.dataset.corrId; // Получаем corrugation_plan_id
        const fillsPerDay = parseInt(document.getElementById("fills_per_day").value || "50");
        const dateHeaders = Array.from(document.querySelectorAll('#bottom-table thead th'));
        const dateList = dateHeaders.slice(1, dateHeaders.length - 1)
            .map(th => th.innerText.trim())
            .filter(d => d >= startDate);

        let dateIndex = 0;
        let lastAssignedDate = null;
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
                div.title = `${stripPaperDims(selectedLabel)}\nКоличество: ${batch}`;
                div.classList.add('assigned-item');
                const wMm = parsePaperWidthMm(selectedLabel);
                if (wMm != null && wMm > 230) div.classList.add('pos-wide');
                div.setAttribute('data-label', selectedLabel);
                div.setAttribute('data-count', batch);
                div.setAttribute('data-corr-id', selectedCorrId); // Добавляем corrugation_plan_id
                if (td.querySelector('.assigned-item')) {
                    div.classList.add('half-width');
                    td.querySelector('.assigned-item').classList.add('half-width');
                }
                div.setAttribute('data-id', selectedId);
                td.appendChild(div);
                lastAssignedDate = dateList[dateIndex];
                total -= batch;
            }
            dateIndex++;
        }
        attachRemoveHandlers();
        // Обновляем активную позицию для следующего Shift+клика.
        if (lastAssignedDate !== null) {
            activeDate = lastAssignedDate;
            activePlace = place;
        }

        const remaining = total;
        const placed = initialTotal - remaining;
        if (remaining > 0) {
            alert(
                'Размещено ' + placed + ' из ' + initialTotal + ' гофропакетов (строка «место ' + place + '»).\n\n' +
                'Не хватает ' + remaining + ' шт. — закончились свободные дни в правой части таблицы или лимит заливок в смену на этой строке.\n\n' +
                'Нажмите «Добавить день», затем снова разместите эту позицию (или Shift+клик — продолжит с последней ячейки). Позиция сверху останется активной, пока не разложите всё количество.'
            );
        }
        return { placed, remaining, initialTotal, lastAssignedDate };
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

    /**
     * Единственный источник «серой» подсветки сверху — блоки в таблице сборки.
     * Снимает ложные used и выставляет их только по data-corr-id (с починкой устаревших id в блоках).
     */
    function syncUsedFromBuildPlanFragments() {
        document.querySelectorAll('#top-table .position-cell.used').forEach(c => c.classList.remove('used'));

        document.querySelectorAll('#bottom-table .assigned-item').forEach(item => {
            let cid = String(item.dataset.corrId || '').trim();
            const itemFull = item.dataset.label || '';

            let top = cid
                ? document.querySelector('#top-table .position-cell[data-corr-id="' + cid + '"]')
                : null;

            if (!top && itemFull) {
                top = Array.from(document.querySelectorAll('#top-table .position-cell')).find(
                    c => !c.classList.contains('used') && (c.dataset.label || '') === itemFull
                ) || null;
            }

            if (!top && itemFull) {
                const norm = normalizePlanLabel(itemFull);
                if (norm) {
                    const candidates = Array.from(document.querySelectorAll('#top-table .position-cell')).filter(
                        c => !c.classList.contains('used') && normalizePlanLabel(c.dataset.label || '') === norm
                    );
                    top = candidates.find(c => (c.dataset.label || '') === itemFull) || candidates[0] || null;
                }
            }

            if (!top) return;

            const topCid = String(top.dataset.corrId || '').trim();
            if (topCid && cid !== topCid) {
                item.setAttribute('data-corr-id', topCid);
            }
            if (isCorrugationRowFullyPlaced(top)) {
                top.classList.add('used');
            }
        });
    }

    window.syncUsedFromBuildPlanFragments = syncUsedFromBuildPlanFragments;

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

        const claimedTopCorrIds = new Set();

        // Не помечаем «used» по всему build_plan из PHP заранее: в плане могут быть даты вне текущего
        // диапазона столбцов — ячейки внизу не рисуются, а верх помечался бы серым без парных блоков
        // (ложные срабатывания проверки разметки). Затенение — только при отрисовке фрагментов ниже и в финальной сверке.

        // Отрисовываем элементы в нижней таблице
        Object.keys(planData).forEach(date => {
            Object.keys(planData[date]).forEach(place => {
                const td = document.querySelector(`.drop-target[data-date='${date}'][data-place='${place}']`);
                if (!td) return;
                
                planData[date][place].forEach(item => {
                    const filterName = item.filter;
                    const count = item.count;
                    const fullLabel = filterToLabel[filterName] || filterName;

                    // Находим соответствующую позицию в верхней таблице для связи data-id.
                    // Важно: матчим в первую очередь по corrugation_plan_id, т.к. fullLabel может не совпадать 1-в-1
                    // из-за текущего диапазона дат/форматирования в верхней таблице.
                    const rawCid = item.corrugation_plan_id;
                    const corrId = (rawCid !== null && rawCid !== undefined && String(rawCid).trim() !== '')
                        ? String(rawCid).trim()
                        : '';
                    let matchedByCorrId = false;
                    let posCell = corrId
                        ? document.querySelector(`.position-cell[data-corr-id="${corrId}"]`)
                        : null;
                    matchedByCorrId = !!(corrId && posCell);

                    // fallback по полному label (устаревший id в БД или пустой corrugation_plan_id)
                    if (!posCell && fullLabel) {
                        const allByLabel = Array.from(document.querySelectorAll('.position-cell')).filter(
                            cell => (cell.dataset.label || '') === fullLabel
                        );
                        posCell = allByLabel.find(cell => {
                            const id = String(cell.dataset.corrId || '').trim();
                            return id && !claimedTopCorrIds.has(id);
                        }) || null;
                    }

                    let corrForDiv = '';
                    if (matchedByCorrId && corrId) {
                        corrForDiv = corrId;
                    } else if (posCell) {
                        corrForDiv = String(posCell.dataset.corrId || '').trim();
                    } else if (corrId) {
                        corrForDiv = corrId;
                    }
                    if (posCell) {
                        const claimId = String(posCell.dataset.corrId || '').trim();
                        if (claimId) claimedTopCorrIds.add(claimId);
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
                    div.title = `${stripPaperDims(fullLabel)}\nКоличество: ${count}`;
                    div.classList.add('assigned-item');
                    const wMm = parsePaperWidthMm(fullLabel);
                    if (wMm != null && wMm > 230) div.classList.add('pos-wide');
                    div.setAttribute('data-label', fullLabel);
                    div.setAttribute('data-count', count);
                    div.setAttribute('data-corr-id', corrForDiv);

                    // Используем data-id из верхней таблицы, если она есть.
                    // Если верхней клетки нет (например, позиция вне текущего диапазона дат),
                    // всё равно рисуем элемент, но оставляем data-id пустым/синтетическим.
                    const assignedId = posCell ? posCell.dataset.id : (corrForDiv ? `corr_${corrForDiv}` : '');
                    div.setAttribute('data-id', assignedId);
                    
                    if (td.querySelector('.assigned-item')) {
                        div.classList.add('half-width');
                        td.querySelector('.assigned-item').classList.add('half-width');
                    }
                    
                    td.appendChild(div);
                });
            });
        });

        syncUsedFromBuildPlanFragments();
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

    /** Сумма размещённого количества в видимой таблице сборки по corrugation_plan_id. */
    function getPlacedCountByCorrId(corrId) {
        let placed = 0;
        if (!corrId) return 0;
        document.querySelectorAll('#bottom-table .assigned-item[data-corr-id="' + corrId + '"]').forEach((it) => {
            placed += parseInt(it.dataset.count || 0, 10) || 0;
        });
        return placed;
    }

    /** Позиции, у которых внизу (в текущей таблице) размещено меньше, чем count в гофроплане. */
    function auditBuildPlanQuantities() {
        const incomplete = [];
        const overPlaced = [];
        const notStarted = [];

        document.querySelectorAll('#top-table .position-cell').forEach((cell) => {
            const needed = parseInt(cell.dataset.count || '0', 10) || 0;
            if (needed <= 0) return;
            const cid = String(cell.dataset.corrId || '').trim();
            if (!cid) return;

            const placed = getPlacedCountByCorrId(cid);
            const row = {
                corrugation_plan_id: cid,
                label: cell.dataset.label || '',
                cutDate: cell.dataset.cutDate || '',
                needed,
                placed,
                missing: needed - placed
            };

            if (placed === 0) {
                if (cell.classList.contains('used')) {
                    row.type = 'Помечена серой, но в таблице сборки 0 шт.';
                    incomplete.push(row);
                } else {
                    notStarted.push(row);
                }
            } else if (placed < needed) {
                row.type = 'Размещено меньше, чем в гофроплане (возможна «потеря» при нехватке дней)';
                incomplete.push(row);
            } else if (placed > needed) {
                row.type = 'В плане сборки больше, чем в гофроплане';
                overPlaced.push(row);
            }
        });

        return { incomplete, overPlaced, notStarted };
    }

    /** Проверка разметки и количеств (гофроплан vs план сборки на экране). */
    function auditBuildPlanMarkup() {
        syncUsedFromBuildPlanFragments();

        const problems = [];
        const warnings = [];
        const qty = auditBuildPlanQuantities();

        document.querySelectorAll('#top-table .position-cell.used').forEach((cell) => {
            const cid = String(cell.dataset.corrId || '').trim();
            if (cid) {
                const n = document.querySelectorAll('#bottom-table .assigned-item[data-corr-id="' + cid + '"]').length;
                if (n === 0) {
                    problems.push({
                        type: 'Серая позиция без блоков в плане сборки с тем же corrugation_plan_id',
                        corrugation_plan_id: cid,
                        label: cell.dataset.label || '',
                        cutDate: cell.dataset.cutDate || ''
                    });
                }
            } else {
                const lbl = cell.dataset.label || '';
                const norm = normalizePlanLabel(lbl);
                const hasAssign = norm && Array.from(document.querySelectorAll('#bottom-table .assigned-item')).some((it) => {
                    return normalizePlanLabel(it.dataset.label || '') === norm;
                });
                if (!hasAssign) {
                    problems.push({
                        type: 'Серая позиция без data-corr-id и без совпадения по названию в таблице сборки',
                        label: lbl
                    });
                }
            }
        });

        document.querySelectorAll('#bottom-table .assigned-item').forEach((it) => {
            const cid = String(it.dataset.corrId || '').trim();
            if (!cid) return;
            const top = document.querySelector('#top-table .position-cell[data-corr-id="' + cid + '"]');
            if (top && !top.classList.contains('used')) {
                warnings.push({
                    type: 'В плане сборки есть блок с id, а соответствующая строка гофроплана не отмечена серой (или не в диапазоне дат)',
                    corrugation_plan_id: cid,
                    label: it.dataset.label || ''
                });
            }
        });

        const qtyProblems = qty.incomplete.length + qty.overPlaced.length;
        const markupIssues = problems.length + warnings.length;
        const totalIssues = markupIssues + qtyProblems;

        const titleEl = document.getElementById('audit-modal-title');
        const bodyEl = document.getElementById('audit-modal-body');
        if (totalIssues === 0) {
            titleEl.textContent = 'Проверка разметки и количеств';
            bodyEl.textContent =
                'Замечаний не найдено.\n\n' +
                'Перед проверкой выполнена синхронизация подсветки с таблицей сборки.\n\n' +
                'Разметка:\n' +
                '• У каждой серой позиции сверху (с id) внизу есть блок с тем же data-corr-id.\n' +
                '• У каждого блока внизу с id соответствующая строка гофроплана (если видна) отмечена серой.\n\n' +
                'Количества (по видимой таблице сборки):\n' +
                '• У всех строк гофроплана с id сумма внизу совпадает с количеством в гофроплане (или позиция ещё не начата).\n' +
                '• Не начато позиций: ' + qty.notStarted.length + '.\n\n' +
                'Если часть назначений на даты правее последнего столбца — расширьте диапазон «Дней» и нажмите «Построить таблицу», затем проверку снова.';
        } else {
            titleEl.textContent = 'Проверка: замечаний — ' + totalIssues +
                ' (разметка: ' + markupIssues + ', количества: ' + qtyProblems + ')';
            let text = '';
            if (problems.length > 0) {
                text += 'РАЗМЕТКА — ПРОБЛЕМЫ (' + problems.length + '):\n' + JSON.stringify(problems, null, 2) + '\n\n';
            }
            if (warnings.length > 0) {
                text += 'РАЗМЕТКА — ПРЕДУПРЕЖДЕНИЯ (' + warnings.length + '):\n' + JSON.stringify(warnings, null, 2) + '\n\n';
            }
            if (qty.incomplete.length > 0) {
                text += 'КОЛИЧЕСТВА — НЕДОРАЗМЕЩЕНО (' + qty.incomplete.length + '):\n' +
                    '(нужно дозаполнить: «Добавить день» + снова назначить позицию)\n' +
                    JSON.stringify(qty.incomplete, null, 2) + '\n\n';
            }
            if (qty.overPlaced.length > 0) {
                text += 'КОЛИЧЕСТВА — ПРЕВЫШЕНИЕ (' + qty.overPlaced.length + '):\n' +
                    JSON.stringify(qty.overPlaced, null, 2) + '\n\n';
            }
            text += 'Детали в консоли (F12 → Console).';
            bodyEl.textContent = text;
            console.warn('[Проверка плана сборки] разметка — проблемы:', problems);
            console.warn('[Проверка плана сборки] разметка — предупреждения:', warnings);
            console.warn('[Проверка плана сборки] количества — недоразмещено:', qty.incomplete);
            console.warn('[Проверка плана сборки] количества — превышение:', qty.overPlaced);
        }
        document.getElementById('audit-modal').style.display = 'flex';
    }

    function closeAuditModal() {
        document.getElementById('audit-modal').style.display = 'none';
    }

    window.auditBuildPlanMarkup = auditBuildPlanMarkup;
    window.closeAuditModal = closeAuditModal;
    
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
