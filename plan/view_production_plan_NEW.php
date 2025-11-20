<?php
// view_production_plan.php — план vs факт + переносы по сменам для выбранной заявки

$dsn = 'mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План и факт сборки — переносы | Заявка № <?= htmlspecialchars($order) ?></title>
    <style>
        :root{
            --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb;
            --ok:#16a34a; --warn:#d97706; --bad:#dc2626; --accent:#2563eb; --hl:#fef3c7; --hlborder:#facc15;
        }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:16px;font-size:14px}
        h1{text-align:center;margin:6px 0 12px;font-weight:700}
        .toolbar{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;align-items:center;margin-bottom:12px}
        .toolbar input{padding:8px 10px;border:1px solid var(--line);border-radius:8px;width:280px}
        .btn{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
        .btn-print{background:#eaf1ff;color:var(--accent);border-color:#cfe0ff;font-weight:600}

        .calendar{display:grid;grid-template-columns:repeat(auto-fill,150px);gap:10px;justify-content:center}
        .day{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px;min-height:140px;display:flex;flex-direction:column;gap:6px;box-shadow:0 1px 4px rgba(0,0,0,.06);width:150px}
        .date{font-weight:700;color:#16a34a;white-space:nowrap}
        .muted{color:var(--muted)}
        ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:4px}
        li{padding:4px 6px;border-radius:8px;background:#fafafa;border:1px solid var(--line); transition:background-color .15s, box-shadow .15s, border-color .15s}
        li .row{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
        li strong{cursor:pointer;font-weight:400}
        .tag{font-size:12px;padding:1px 8px;border-radius:999px;border:1px solid var(--line);background:#fff}
        .ok{color:var(--ok);border-color:#c9f2d9;background:#f1f9f4}
        .warn{color:var(--warn);border-color:#fde7c3;background:#fff9ed}
        .bad{color:var(--bad);border-color:#ffc9c9;background:#fff1f1}
        .xtra{font-size:12px;color:#334155}
        .totals{font-size:12px;color:#374151;display:flex;justify-content:space-between;gap:8px}
        .bar{height:6px;background:#eef2ff;border-radius:999px;overflow:hidden;border:1px solid #dfe3ff}
        .bar > span{display:block;height:100%;background:#60a5fa}

        /* Подсветка всех вхождений одного фильтра */
        li.highlight-same{
            background:var(--hl);
            border-color:var(--hlborder);
            box-shadow:0 0 0 2px rgba(250,204,21,.35) inset;
        }
        li.highlight-same strong{
            text-decoration:underline;
            text-underline-offset:2px;
        }

        @media(max-width:900px){.calendar{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:600px){.calendar{grid-template-columns:repeat(2,1fr)}}
        @media print{
            @page { size: landscape; margin: 10mm; }
            body{background:#fff}
            .toolbar{display:none}
            .day{break-inside:avoid;box-shadow:none}
        }
    </style>
</head>
<body>

<h1>План и факт сборки — заявка № <?= htmlspecialchars($order) ?></h1>

<div class="toolbar">
    <div style="text-align:center; margin-bottom:15px;">
        <a href="view_production_plan_light.php?order=<?= urlencode($order) ?>"
           target="_blank"
           style="padding:8px 14px; background:#4CAF50; color:white; text-decoration:none; border-radius:6px;">
            📄 Версия для печати (лайт)
        </a>
    </div>

    <input type="text" id="searchInput" placeholder="Поиск фильтра...">
</div>

<div class="calendar">
    <?php foreach ($period as $dt):
        $d = $dt->format('Y-m-d');
        $planItems = $planByDate[$d] ?? [];
        $factMapDay = $factByDate[$d] ?? [];

        $sumPlan = sumPlanForDay($planItems);
        $sumFact = sumFactForDayMap($factMapDay);
        $pct = $sumPlan > 0 ? min(100, round($sumFact / $sumPlan * 100)) : ($sumFact > 0 ? 100 : 0);

        // только фильтры из плана
        $keys = [];
        foreach ($planItems as $it) $keys[$it['base']] = true;
        ksort($keys, SORT_NATURAL|SORT_FLAG_CASE);
        ?>
        <div class="day">
            <div class="date"><?= $dt->format('d.m.Y') ?></div>

            <?php if ($planItems || $factMapDay): ?>
                <ul>
                    <?php foreach (array_keys($keys) as $base):
                        $plan = 0; foreach ($planItems as $it) if ($it['base']===$base) $plan += (int)$it['count'];
                        
                        // Пропускаем позиции без плана
                        if ($plan === 0) continue;
                        
                        // Получаем распределенный факт для этой даты и позиции
                        $fact = (int)($factDistribution[$d][$base] ?? 0);

                        $cls = ($fact >= $plan) ? 'ok' : ($fact>0 ? 'warn' : 'bad');
                        ?>
                        <li data-key="<?= htmlspecialchars(mb_strtolower($base)) ?>">
                            <div class="row">
                                <strong><?= htmlspecialchars($base) ?></strong>
                                <span class="tag <?= $cls ?>"><?= (int)$fact ?>/<?= (int)$plan ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <em class="muted">Нет задач</em>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
    // Поиск по названию фильтра
    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.day li').forEach(li => {
            li.style.display = (!q || (li.getAttribute('data-key')||'').includes(q)) ? '' : 'none';
        });
    });

    // Сквозная подсветка одинаковых фильтров при наведении на НАЗВАНИЕ (strong)
    const calendar = document.querySelector('.calendar');

    function addHighlight(key){
        if(!key) return;
        document.querySelectorAll(`.day li[data-key="${CSS.escape(key)}"]`)
            .forEach(li => li.classList.add('highlight-same'));
    }
    function removeHighlight(){
        document.querySelectorAll('.day li.highlight-same')
            .forEach(li => li.classList.remove('highlight-same'));
    }

    // Делегируем события: реагируем только на hover по <strong>
    calendar.addEventListener('mouseover', (e) => {
        const strong = e.target.closest('strong');
        if (!strong) return;
        const li = strong.closest('li');
        if (!li) return;
        const key = (li.getAttribute('data-key')||'').toLowerCase();
        removeHighlight();
        addHighlight(key);
    });
    calendar.addEventListener('mouseout', (e) => {
        // Снимаем подсветку, когда курсор уходит с имени
        const related = e.relatedTarget;
        // Если ушли на другой strong того же ключа — подсветка обновится по mouseover
        if (!e.target.closest('strong')) return;
        // Если ушли куда-то ещё — убираем подсветку
        if (!related || !related.closest || !related.closest('strong')) {
            removeHighlight();
        }
    });
</script>

</body>
</html>
