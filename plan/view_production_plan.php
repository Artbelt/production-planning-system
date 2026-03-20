<?php
// view_production_plan.php — план vs факт + переносы по сменам для выбранной заявки
error_reporting(E_ALL);
ini_set('display_errors', 1);
if(function_exists('opcache_reset')) opcache_reset(); // Сброс OpCache

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');

$order = $_GET['order'] ?? '';
if (!$order) die("Не указан номер заявки.");

/* ---------- ПЛАН (build_plan) ---------- */
$stmt = $pdo->prepare("
    SELECT assign_date, filter_label, `count`
    FROM build_plan
    WHERE order_number = ?
    ORDER BY assign_date, filter_label
");
$stmt->execute([$order]);
$planRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* нормализуем названия и группируем по дате и базе */
$planByDate = [];              // [$date][] = ['base'=>..., 'count'=>int]
$planMap    = [];              // [$base][$date] = int
$allDates   = [];

foreach ($planRows as $r) {
    $date  = $r['assign_date'];
    $label = preg_replace('/\[.*$/', '', $r['filter_label']);
    $label = preg_replace('/[●◩⏃]/u', '', $label);
    $base  = trim($label);
    $cnt   = (int)$r['count'];

    $planByDate[$date][] = ['base'=>$base, 'count'=>$cnt];
    if (!isset($planMap[$base])) $planMap[$base] = [];
    if (!isset($planMap[$base][$date])) $planMap[$base][$date] = 0;
    $planMap[$base][$date] += $cnt;

    $allDates[$date] = true;
}

/* ---------- ФАКТ (manufactured_production) ---------- */
$stmt = $pdo->prepare("
    SELECT date_of_production AS prod_date,
           TRIM(SUBSTRING_INDEX(name_of_filter,' [',1)) AS base_filter,
           SUM(count_of_filters) AS fact_count
    FROM manufactured_production
    WHERE name_of_order = ?
    GROUP BY prod_date, base_filter
    ORDER BY prod_date, base_filter
");
$stmt->execute([$order]);
$factRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$factByDate = [];              // [$date][$base] = int
$factMap    = [];              // [$base][$date] = int

foreach ($factRows as $r) {
    $date = $r['prod_date'];
    $base = $r['base_filter'];
    if ($base === null || $base === '') continue;
    $cnt  = (int)$r['fact_count'];

    if (!isset($factByDate[$date])) $factByDate[$date] = [];
    if (!isset($factByDate[$date][$base])) $factByDate[$date][$base] = 0;
    $factByDate[$date][$base] += $cnt;

    if (!isset($factMap[$base])) $factMap[$base] = [];
    if (!isset($factMap[$base][$date])) $factMap[$base][$date] = 0;
    $factMap[$base][$date] += $cnt;

    $allDates[$date] = true;
}

/* ---------- Диапазон дат ---------- */
if ($allDates) {
    $dates = array_keys($allDates);
    sort($dates);
    $start = new DateTime(reset($dates));
    $end   = new DateTime(end($dates)); $end->modify('+1 day');
} else {
    $dates = [];
    $start = new DateTime();
    $end   = new DateTime();
}
$period = new DatePeriod($start, new DateInterval('P1D'), $end);

/* ---------- Распределение факта по плану ---------- */
/*
   Для каждой позиции собираем весь факт и последовательно "заполняем" плановые дни
*/
$factDistribution = []; // [$date][$base] = количество факта распределенного на этот день

foreach ($planMap as $base => $datesMap) {
    // Собираем весь факт по этой позиции (сумма по всем датам производства)
    $totalFact = 0;
    if (isset($factMap[$base])) {
        foreach ($factMap[$base] as $factCount) {
            $totalFact += (int)$factCount;
        }
    }
    
    // Получаем плановые даты для этой позиции, отсортированные
    $planDates = array_keys($datesMap);
    sort($planDates);
    
    // Распределяем факт по плановым дням
    $remainingFact = $totalFact;
    foreach ($planDates as $planDate) {
        if ($remainingFact <= 0) break;
        
        $planQty = (int)$datesMap[$planDate];
        $allocatedFact = min($remainingFact, $planQty);
        
        if (!isset($factDistribution[$planDate])) {
            $factDistribution[$planDate] = [];
        }
        $factDistribution[$planDate][$base] = $allocatedFact;
        
        $remainingFact -= $allocatedFact;
    }
}

/* утилиты */
function sumPlanForDay($items){ $s=0; foreach($items as $it) $s+=(int)$it['count']; return $s; }
function sumFactForDayMap($map){ $s=0; foreach($map as $v) $s+=(int)$v; return $s; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$DOW = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План и факт сборки — переносы | Заявка № <?= htmlspecialchars($order) ?></title>
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
        .print-link {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            background: #16a34a;
            color: #fff;
            white-space: nowrap;
        }
        .search-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            min-width: 220px;
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
            transition: box-shadow .15s ease, outline-color .15s ease, background-color .15s ease;
        }
        .item-fill {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            background: rgba(34, 197, 94, 0.35);
            z-index: 0;
        }
        .item-row { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: baseline; gap: 4px; }
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
        .item.item-highlighted-fixed { background: #dbeafe; }

        .filter-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            max-width: min(90vw, 420px);
            width: 280px;
            background: #fef3c7;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            border: 1px solid #f59e0b;
            z-index: 1500;
            display: none;
        }
        .filter-panel.visible { display: block; }
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
        .filter-panel-title { color: #111827; white-space: nowrap; }
        .filter-panel-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            padding: 0 4px;
            color: #6b7280;
        }
        .filter-panel-body { padding: 10px 12px 12px; }
        .filter-panel-name { font-size: 14px; font-weight: 700; color: #111827; word-break: break-word; }

        @media print {
            body { padding-top: 0; }
            .no-print { display: none !important; }
            .days-grid { grid-template-columns: repeat(7, 150px); width: fit-content; }
            .day-column { break-inside: avoid; }
            .item.item-highlighted { outline: none !important; box-shadow: none !important; }
            .item.item-highlighted-fixed { background: transparent !important; }
        }

        @media (max-width: 768px) {
            body { padding: 60px 8px 16px; align-items: stretch; }
            .section-header { padding: 8px 4px; font-size: 14px; }
            .days-grid { grid-template-columns: 1fr; width: 100%; }
            .day-column { width: auto; }
            .no-print {
                left: 8px;
                right: 8px;
                top: 8px;
                justify-content: space-between;
                flex-wrap: wrap;
            }
            .search-input { min-width: 0; flex: 1 1 auto; }
            .filter-panel { left: 8px; right: 8px; width: auto; max-width: 100%; }
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
    <input class="search-input" type="text" id="searchInput" placeholder="Поиск фильтра...">
</div>

<div class="section-header">
    План и факт сборки — Заявка: <?= h($order) ?>
</div>

<div class="days-grid">
    <?php
    $periodDates = [];
    foreach ($period as $dtObj) $periodDates[] = clone $dtObj;

    if (!empty($periodDates)) {
        $firstTs  = strtotime($periodDates[0]->format('Y-m-d'));
        $firstDow = (int)date('w', $firstTs);
        $offset   = ($firstDow === 0) ? 6 : ($firstDow - 1);
        for ($i = 0; $i < $offset; $i++): ?>
            <div class="day-column" style="border-right:1px solid #ddd;border-bottom:1px solid #ddd;background:#fafafa;"></div>
        <?php endfor;
    }

    foreach ($periodDates as $dt):
        $d = $dt->format('Y-m-d');
        $planItems = $planByDate[$d] ?? [];
        $factMapDay = $factByDate[$d] ?? [];
        $ts = strtotime($d);
        $dow = (int)date('w', $ts);
        $isWeekend = ($dow === 0 || $dow === 6);

        // только фильтры из плана
        $keys = [];
        foreach ($planItems as $it) $keys[$it['base']] = true;
        ksort($keys, SORT_NATURAL|SORT_FLAG_CASE);
        ?>
        <div class="day-column">
            <div class="day-header<?= $isWeekend ? ' weekend' : '' ?>">
                <?= date('d.m.Y', $ts) ?> <?= $DOW[$dow] ?>
            </div>
            <div class="items-container">

            <?php if ($planItems || $factMapDay): ?>
                    <?php foreach (array_keys($keys) as $base):
                        $plan = 0; foreach ($planItems as $it) if ($it['base']===$base) $plan += (int)$it['count'];
                        
                        // Пропускаем позиции без плана
                        if ($plan === 0) continue;
                        
                        // Получаем распределенный факт для этой даты и позиции
                        $fact = (int)($factDistribution[$d][$base] ?? 0);

                        $percentage = $plan > 0 ? ($fact / $plan * 100) : 0;
                        ?>
                        <div class="item"
                             data-filter="<?= h($base) ?>"
                             data-key="<?= h(mb_strtolower($base)) ?>"
                             title="Процент: <?= round($percentage, 1) ?>%">
                            <?php if ($percentage > 0): ?>
                                <div class="item-fill" style="width:<?= round(min(100, $percentage), 1) ?>%"></div>
                            <?php endif; ?>
                            <div class="item-row">
                                <span class="item-name"><?= h($base) ?></span>
                                <span class="item-qty"><?= (int)$fact ?>/<?= (int)$plan ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#bbb;font-size:10px;text-align:center;padding:8px 0;font-style:italic;">Нет задач</div>
            <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="filter-panel" id="filterPanel">
    <div class="filter-panel-header" id="filterPanelHeader">
        <span class="filter-panel-title">Наименование фильтра</span>
        <button type="button" class="filter-panel-close" id="filterPanelClose" title="Закрыть">×</button>
    </div>
    <div class="filter-panel-body">
        <div class="filter-panel-name" id="filterPanelName"></div>
    </div>
</div>

<script>
    // Поиск по названию фильтра
    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.item').forEach(li => {
            li.style.display = (!q || (li.getAttribute('data-key')||'').includes(q)) ? '' : 'none';
        });
    });

    // Сквозная подсветка одинаковых фильтров
    const calendar = document.querySelector('.days-grid');
    const items = document.querySelectorAll('.item[data-filter]');
    const panel  = document.getElementById('filterPanel');
    const header = document.getElementById('filterPanelHeader');
    const nameEl = document.getElementById('filterPanelName');
    const btnClose = document.getElementById('filterPanelClose');
    let currentSelectedFilter = null;

    function addHighlight(key){
        if(!key) return;
        document.querySelectorAll(`.item[data-key="${CSS.escape(key)}"]`)
            .forEach(li => li.classList.add('item-highlighted'));
    }
    function removeHighlight(){
        document.querySelectorAll('.item.item-highlighted')
            .forEach(li => li.classList.remove('item-highlighted'));
    }
    function updateFixedHighlight() {
        items.forEach(function (i) {
            const f = i.getAttribute('data-filter');
            if (f && currentSelectedFilter && f === currentSelectedFilter) {
                i.classList.add('item-highlighted', 'item-highlighted-fixed');
            } else {
                i.classList.remove('item-highlighted-fixed');
            }
        });
    }
    function openFilterPanel(filterName) {
        if (!panel || !nameEl) return;
        nameEl.textContent = filterName || '';
        panel.classList.add('visible');
        currentSelectedFilter = filterName || null;
        updateFixedHighlight();
    }
    function closeFilterPanel() {
        if (!panel) return;
        panel.classList.remove('visible');
        currentSelectedFilter = null;
        updateFixedHighlight();
    }

    // Делегируем hover для временной подсветки
    calendar.addEventListener('mouseover', (e) => {
        const li = e.target.closest('.item');
        if (!li) return;
        const key = (li.getAttribute('data-key')||'').toLowerCase();
        removeHighlight();
        addHighlight(key);
        updateFixedHighlight();
    });
    calendar.addEventListener('mouseout', (e) => {
        if (!e.target.closest('.item')) return;
        if (!e.relatedTarget || !e.relatedTarget.closest || !e.relatedTarget.closest('.item')) {
            removeHighlight();
            updateFixedHighlight();
        }
    });

    items.forEach(function (el) {
        el.addEventListener('click', function () {
            const f = el.getAttribute('data-filter') || '';
            if (f) openFilterPanel(f);
        });
    });

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
        if (e.key === 'Escape') closeFilterPanel();
    });
</script>

</body>
</html>
