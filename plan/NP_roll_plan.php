<?php
/* cut_roll_plan.php — планирование раскроя (страница + API)
   - Левый столбец фиксирован (sticky)
   - Верхняя строка дат и нижняя строка «Загрузка (ч)» фиксированы по вертикали
   - Нижний горизонтальный бегунок синхронизирован с таблицей
   - Ширина колонок дат регулируется CSS-переменной --dayW
   - API: ?action=load_assignments / ?action=save_assignments (таблица roll_plan)
*/

$dsn  = "mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4";
$user = "root";
$pass = "";

$action = $_GET['action'] ?? '';

/* ============================ API ===================================== */
if (in_array($action, ['load_assignments','save_assignments'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Таблица roll_plan (как в схеме)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS roll_plan (
              id INT(11) NOT NULL AUTO_INCREMENT,
              order_number VARCHAR(50) DEFAULT NULL,
              bale_id VARCHAR(50) DEFAULT NULL,
              plan_date DATE DEFAULT NULL,
              done TINYINT(1) DEFAULT 0 COMMENT 'Выполнено: 0 или 1',
              PRIMARY KEY (id),
              UNIQUE KEY order_number (order_number, bale_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if ($action === 'load_assignments') {
            $order = $_GET['order'] ?? '';
            if ($order === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }

            $st = $pdo->prepare("SELECT plan_date, bale_id
                                 FROM roll_plan
                                 WHERE order_number=?
                                 ORDER BY plan_date, bale_id");
            $st->execute([$order]);
            $plan = [];
            foreach ($st as $r) {
                $d = $r['plan_date'];
                $b = (string)$r['bale_id'];
                if ($d === null) continue; // без даты не включаем
                $plan[$d][] = $b;
            }
            echo json_encode(['ok'=>true,'plan'=>$plan]); exit;
        }

        if ($action === 'save_assignments') {
            $raw = file_get_contents('php://input');
            $payload = $raw ? json_decode($raw, true) : [];
            $order = (string)($payload['order'] ?? '');
            $plan  = $payload['plan'] ?? [];

            if ($order === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }
            if (!is_array($plan)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad plan']); exit; }

            $pdo->beginTransaction();
            // простой способ: очистить все строки этого заказа и записать заново
            $pdo->prepare("DELETE FROM roll_plan WHERE order_number=?")->execute([$order]);
            $ins = $pdo->prepare("INSERT INTO roll_plan(order_number, plan_date, bale_id) VALUES(?,?,?)");

            foreach ($plan as $date => $bales) {
                $dd = DateTime::createFromFormat('Y-m-d', $date);
                if (!$dd || !is_array($bales)) continue;
                foreach ($bales as $bid) {
                    $b = trim((string)$bid); if ($b==='') continue;
                    $ins->execute([$order, $dd->format('Y-m-d'), $b]);
                }
            }

            // Обновляем статус plan_ready = 1 в таблице orders
            try {
                $pdo->prepare("UPDATE orders SET plan_ready = 1 WHERE order_number = ?")->execute([$order]);
            } catch(Throwable $e) {
                // Если поле plan_ready не существует, просто игнорируем ошибку
                if (strpos($e->getMessage(), 'plan_ready') === false) {
                    throw $e;
                }
            }

            $pdo->commit();
            echo json_encode(['ok'=>true]); exit;
        }

        echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;

    } catch(Throwable $e) {
        if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

/* ============================ PAGE ==================================== */

try{
    $pdo = new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
}catch(Throwable $e){
    http_response_code(500);
    exit('DB error: '.$e->getMessage());
}

$order = $_GET['order'] ?? '';

// Получаем статус заказа для проверки plan_ready
$plan_ready = false;
if ($order) {
    try {
        $status_stmt = $pdo->prepare("SELECT plan_ready FROM orders WHERE order_number = ? LIMIT 1");
        $status_stmt->execute([$order]);
        $order_status = $status_stmt->fetch();
        $plan_ready = $order_status ? (bool)$order_status['plan_ready'] : false;
    } catch(Throwable $e) {
        // Если поле plan_ready не существует, просто игнорируем ошибку
        if (strpos($e->getMessage(), 'plan_ready') === false) {
            throw $e;
        }
    }
}

$stmt = $pdo->prepare("SELECT bale_id, filter, height, width, format FROM cut_plans WHERE order_number = ? ORDER BY bale_id");
$stmt->execute([$order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bales = [];
foreach ($rows as $r) {
    $bid = (int)$r['bale_id'];
    if (!isset($bales[$bid])) {
        $bales[$bid] = [
            'bale_id' => $bid, 
            'strips' => [],
            'format' => $r['format'] ?? '1000' // Формат бухты
        ];
    }
    $bales[$bid]['strips'][] = [
        'filter' => $r['filter'],
        'height' => (float)$r['height'],
        'width'  => (float)$r['width'],
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Планирование раскроя: <?= htmlspecialchars($order) ?></title>
    <style>
        :root{ --dayW: 88px; } /* ширина колонок дат */

        *{ box-sizing: border-box; }
        body{ font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,Helvetica,sans-serif; padding:20px; background:#f7f9fc; color:#333; }
        .container{ max-width:1200px; margin:0 auto; }

        h2{ color:#2c3e50; font-size:22px; margin:0 0 4px; }
        p{ margin:0 0 16px; font-size:13px; color:#666; }

        form{
            background:#fff; padding:12px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06);
            display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:10px;
        }
        label{ font-size:13px; color:#444; }
        input[type="date"]{
            padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff; outline:none;
        }
        input[type="number"]{
            padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff; outline:none;
            width: 60px;
        }
        .btn-group{
            display: flex;
            gap: 6px;
            margin-left: auto;
        }
        .btn{
            background:#1a73e8; color:#fff; border:1px solid #1a73e8; border-radius:6px; padding:6px 12px;
            font-size:12px; cursor:pointer; transition:.15s ease; font-weight:600; white-space: nowrap;
        }
        .btn:hover{ background:#1557b0; border-color:#1557b0; }
        .btn-complete{
            background:#16a34a; color:#fff; border:1px solid #16a34a; border-radius:6px; padding:6px 12px;
            font-size:12px; cursor:pointer; transition:.15s ease; font-weight:600; white-space: nowrap;
        }
        .btn-complete:hover{ background:#15803d; border-color:#15803d; }

        #planArea{
            position:relative; overflow-x:auto; overflow-y:auto; margin-top:14px;
            border:1px solid #e5e7eb; border-radius:10px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.05); max-height:70vh; padding:0;
        }

        table{ border-collapse:separate; border-spacing:0; width:max-content; background:#fff; }
        th,td{ border:1px solid #e5e7eb; padding:6px 8px; font-size:12px; text-align:center; white-space:nowrap; height:24px; background:#fff; }

        /* липкая шапка */
        thead th{ position:sticky; top:0; z-index:6; background:#f1f5f9; }
        thead th:first-child{
            left:0; z-index:8; text-align:left; background:#e5ecf7;
            min-width:160px; max-width:360px; white-space:normal;
        }

        /* липкий левый столбец */
        tbody td:first-child{
            position:sticky; left:0; z-index:4; background:#fff; text-align:left;
            min-width:160px; max-width:360px; white-space:normal; box-shadow:2px 0 0 rgba(0,0,0,.06);
        }

        /* липкий низ (итоги) */
        tfoot td{ position:sticky; bottom:0; z-index:5; background:#f8fafc; font-weight:700; border-top:2px solid #e5e7eb; }
        tfoot td:first-child{
            left:0; z-index:7; text-align:left; background:#eef2ff;
            min-width:160px; max-width:360px; white-space:normal; box-shadow:2px 0 0 rgba(0,0,0,.06);
        }

        /* ширина колонок дат */
        thead th:not(:first-child), tbody td:not(:first-child), tfoot td:not(:first-child){
            width:var(--dayW); min-width:var(--dayW); max-width:var(--dayW);
        }

        .bale-label{ display:block; font-size:11px; color:#6b7280; margin-top:3px; line-height:1.2; white-space:normal; }
        .highlight{ background:#d1ecf1 !important; border-color:#0bb !important; }
        .overload{ background:#fde2e2 !important; }

        /* Панель висот (чіпи) */
        #heightBarWrap{margin-top:12px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.05);padding:8px 10px;}
        #heightBarTitle{font-size:12px;color:#555;margin:0 0 6px}
        #heightBar{display:flex;flex-wrap:wrap;gap:6px}
        .hchip{font-size:12px;line-height:1;border:1px solid #d1d5db;border-radius:999px;padding:6px 10px;background:#f9fafb;cursor:pointer;user-select:none;position:relative;padding-bottom:16px}
        .hchip.active{background:#e0f2fe;border-color:#38bdf8;font-weight:600}
        /* відсоток + смужка прогресу всередині чіпа */
        .hchip .hpct{font-size:10px;color:#555;margin-left:6px}
        .hchip .hbar{position:absolute;left:8px;right:8px;bottom:4px;height:4px;background:#e5e7eb;border-radius:999px;overflow:hidden}
        .hchip .hfill{height:100%;width:0;background:#60a5fa;transition:width .2s ease}

        /* тільки окремі висоти */
        .hval{padding:1px 4px;border-radius:4px;margin-right:2px;border:1px solid transparent}
        .hval.active{background:#7dd3fc;color:#052c47;font-weight:700;border-color:#0284c7;box-shadow:0 0 0 2px rgba(2,132,199,.22)}

        /* ВЫДЕЛЕНИЕ названия запланированных бухт */
        .bale-name.bale-picked{background:#fff7cc !important;color:#e65100 !important;padding:2px 6px;border-radius:4px;border:1px solid #f59e0b}

        @media (max-width:768px){
            form{ flex-direction:column; align-items:flex-start; }
            thead th:first-child, tbody td:first-child, tfoot td:first-child{ min-width:140px; }
            .btn{ width:100%; }
        }
        
        /* Плавающая панель поиска */
        .search-panel {
            position: fixed;
            top: 15px;
            right: 15px;
            width: 260px;
            background: white;
            border: 1px solid #667eea;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            z-index: 1000;
            max-height: 70vh;
            display: flex;
            flex-direction: column;
        }
        
        .search-panel__header {
            padding: 8px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 7px 7px 0 0;
            font-weight: 600;
            font-size: 12px;
            cursor: move;
            user-select: none;
        }
        
        .search-panel__input {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .search-panel__input input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            box-sizing: border-box;
        }
        
        .search-panel__input input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        .search-panel__results {
            padding: 6px;
            overflow-y: auto;
            flex: 1;
        }
        
        .search-result-item {
            padding: 6px 8px;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin-bottom: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .search-result-item:hover {
            background: #f0f4ff;
            border-color: #667eea;
            transform: translateX(-1px);
        }
        
        .search-result-item__bale {
            font-weight: 600;
            color: #333;
            margin-bottom: 2px;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .search-result-item__filters {
            font-size: 10px;
            color: #666;
        }
        
        .bale-status-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #4caf50;
            color: white;
            font-size: 10px;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .search-result-highlight {
            background: #fff59d;
            padding: 1px 2px;
            border-radius: 2px;
            font-weight: 600;
        }
        
        .no-results {
            text-align: center;
            color: #999;
            padding: 20px 10px;
            font-size: 11px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Планирование раскроя для заявки <?= htmlspecialchars($order) ?></h2>
    <p><b>Норматив:</b> 1 бухта формата 1000 = <b>40 минут</b> (0.67 ч), формата 199 = <b>30 минут</b> (0.5 ч)</p>

    <form onsubmit="event.preventDefault(); drawTable();">
        <label>Дата начала: <input type="date" id="startDate" required></label>
        <label>Дней: <input type="number" id="daysCount" min="1" value="10" required></label>
        <button type="submit" class="btn">Построить</button>
        
        <div class="btn-group">
            <button type="button" class="btn" id="btnLoad">Загрузить</button>
            <button type="button" class="btn" id="btnSave">Сохранить</button>
            <?php if ($plan_ready): ?>
                <button type="button" class="btn-complete" onclick="window.location.href='NP_cut_index.php'">
                    ✅ Завершить
                </button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($plan_ready): ?>
        <p style="font-size: 12px; color: #666; margin-top: 5px; text-align: center;">
            План сохранён. Переход к планированию гофрирования.
        </p>
    <?php endif; ?>

    <div id="heightBarWrap" style="display:none">
        <div id="heightBarTitle">Фільтр за висотами:</div>
        <div id="heightBar"></div>
    </div>

    <div id="planArea"></div>
</div>

<script>
    const ORDER  = <?= json_encode($order) ?>;
    const BALES  = <?= json_encode(array_values($bales), JSON_UNESCAPED_UNICODE) ?>;

    let selected = {}; // { "YYYY-MM-DD": ["baleId1","baleId2", ...] }

    const cssEsc = (s)=> (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/"/g,'\\"');

    const daysBetween = (isoA, isoB) => {
        const a = new Date(isoA), b = new Date(isoB);
        a.setHours(12); b.setHours(12);
        return Math.round((b - a) / 86400000);
    };

    // утиліта для id з висотою (14.5 -> "14_5")
    const hid = h => String(h).replace(/\./g, '_');

    // Множина обраних висот у фільтрі
    const selectedHeights = new Set();

    // Всі доступні висоти
    const allHeights = (() => {
        const s = new Set();
        BALES.forEach(b => b.strips.forEach(st => s.add(Number(st.height))));
        return Array.from(s).sort((a,b)=>a-b);
    })();

    // Загальна кількість смуг по кожній висоті у всьому замовленні
    const totalStripsByHeight = (() => {
        const m = new Map();
        BALES.forEach(b => b.strips.forEach(s => {
            const h = Number(s.height);
            m.set(h, (m.get(h) || 0) + 1);
        }));
        return m; // Map<height, totalCount>
    })();

    function buildHeightBar(){
        const wrap = document.getElementById('heightBarWrap');
        const bar  = document.getElementById('heightBar');
        if(!allHeights.length){ wrap.style.display='none'; return; }
        wrap.style.display='';
        bar.innerHTML='';

        // Скинути
        const reset = document.createElement('span');
        reset.className='hchip';
        reset.textContent='Скинути';
        reset.title='Очистити вибір висот';
        reset.onclick=()=>{
            selectedHeights.clear();
            bar.querySelectorAll('.hchip').forEach(c=>c.classList.remove('active'));
            updateHeightHighlights();
        };
        bar.appendChild(reset);

        // Чіпи висот з % та прогрес-баром
        allHeights.forEach(h=>{
            const id = hid(h);
            const chip = document.createElement('span');
            chip.className='hchip';
            chip.dataset.h = h;
            chip.innerHTML = `[${h}] <span class="hpct" id="hpct-${id}">0%</span>
                               <span class="hbar"><span class="hfill" id="hfill-${id}"></span></span>`;
            chip.onclick=()=>{
                const val = Number(chip.dataset.h);
                if(selectedHeights.has(val)){ selectedHeights.delete(val); chip.classList.remove('active'); }
                else{ selectedHeights.add(val); chip.classList.add('active'); }
                updateHeightHighlights();
            };
            bar.appendChild(chip);
        });
        updateHeightProgress();
    }

    function updateHeightHighlights(){
        document.querySelectorAll('.hval').forEach(span=>{
            const h = Number(span.dataset.h);
            if(selectedHeights.has(h)) span.classList.add('active'); else span.classList.remove('active');
        });
    }

    function getSelectedBaleIds(){
        const set = new Set();
        Object.values(selected).forEach(arr => (arr||[]).forEach(id => set.add(id)));
        return set;
    }

    function updateLeftMarkers(){
        const chosen = getSelectedBaleIds();
        document.querySelectorAll('.bale-name').forEach(el=>{
            const bid = el.dataset.baleId; // оставляем как строку
            el.classList.toggle('bale-picked', chosen.has(bid));
        });
    }

    // Порахувати прогрес по кожній висоті і намалювати у чіпах
    function updateHeightProgress(){
        const planned = new Map(); // Map<height, count>
        Object.values(selected).forEach(arr=>{
            (arr||[]).forEach(bid=>{
                const b = BALES.find(x=>x.bale_id===bid);
                if(!b) return;
                b.strips.forEach(s=>{
                    const h = Number(s.height);
                    planned.set(h, (planned.get(h)||0)+1);
                });
            });
        });

        allHeights.forEach(h=>{
            const id = hid(h);
            const total = totalStripsByHeight.get(h) || 0;
            const done  = planned.get(h) || 0;
            const pct   = total ? Math.round(done*100/total) : 0;

            const pctEl  = document.getElementById(`hpct-${id}`);
            const fillEl = document.getElementById(`hfill-${id}`);
            if (pctEl)  pctEl.textContent = `${pct}%`;
            if (fillEl) fillEl.style.width = `${pct}%`;

            const chip = document.querySelector(`.hchip[data-h="${h}"]`);
            if (chip) chip.title = `Розплановано: ${done} з ${total} (${pct}%)`;
        });
    }

    async function drawTable() {
        const startVal = document.getElementById('startDate').value;
        const days = parseInt(document.getElementById('daysCount').value);
        if (!startVal || isNaN(days)) return;

        const start = new Date(startVal);
        const container = document.getElementById('planArea');
        container.innerHTML = '';

        const table  = document.createElement('table');

        /* --- THEAD --- */
        const thead  = document.createElement('thead');
        const headTr = document.createElement('tr');
        headTr.innerHTML = '<th>Бухта</th>';
        const dates = [];
        for (let d = 0; d < days; d++) {
            const date = new Date(start);
            date.setDate(start.getDate() + d);
            const iso = date.toISOString().split('T')[0];
            dates.push(iso);
            headTr.innerHTML += `<th>${iso}</th>`;
        }
        thead.appendChild(headTr);
        table.appendChild(thead);

        /* --- TBODY --- */
        const tbody = document.createElement('tbody');

        BALES.forEach(b => {
            const tr = document.createElement('tr');

            const uniqHeights = Array.from(new Set(b.strips.map(s=>Number(s.height))).values());
            const tooltip = b.strips
                .map(s => `${s.filter} [${s.height}] ${s.width}мм`)
                .join('\n');

            const td0 = document.createElement('td');
            td0.className = 'left-label';
            td0.dataset.baleId = b.bale_id;
            const formatLabel = b.format ? `[${b.format}]` : '[1000]';
            td0.innerHTML = `<strong class="bale-name" data-bale-id="${b.bale_id}">Бухта ${b.bale_id} ${formatLabel}</strong><div class="bale-label">`
                + uniqHeights.map(h=>`<span class="hval" data-h="${h}">[${h}]</span>`).join(' ')
                + '</div>';
            td0.title = tooltip;
            tr.appendChild(td0);

            dates.forEach(iso=>{
                const td = document.createElement('td');
                td.dataset.date   = iso;
                td.dataset.baleId = b.bale_id;

                td.onclick = ()=>{
                    const sid = td.dataset.date;
                    const bid = td.dataset.baleId;

                    // снимаем выделение со всех ячеек этой бухты (в строке)
                    document.querySelectorAll(`td[data-bale-id="${cssEsc(bid)}"]`).forEach(c=>{
                        c.classList.remove('highlight');
                        const d0 = c.dataset.date;
                        if (selected[d0]) {
                            const idx = selected[d0].indexOf(bid);
                            if (idx>=0) selected[d0].splice(idx,1);
                            if (selected[d0].length===0) delete selected[d0];
                        }
                    });

                    // выделяем текущую
                    if (!selected[sid]) selected[sid] = [];
                    if (!selected[sid].includes(bid)) {
                        selected[sid].push(bid);
                        td.classList.add('highlight');
                    }
                    updateTotals();
                    updateHeightProgress();
                    updateLeftMarkers();
                    
                    // Обновляем результаты поиска если панель активна
                    const searchInput = document.getElementById('filterSearchInput');
                    if (searchInput && searchInput.value.trim() !== '') {
                        searchFilterInBales();
                    }
                };

                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);

        /* --- TFOOT (липкий низ) --- */
        const tfoot = document.createElement('tfoot');
        const totalRow = document.createElement('tr');
        totalRow.innerHTML = '<td><b>Загрузка (ч)</b></td>';
        dates.forEach(iso=>{
            const t = document.createElement('td');
            t.id = 'load-' + iso;
            totalRow.appendChild(t);
        });
        tfoot.appendChild(totalRow);
        table.appendChild(tfoot);

        container.appendChild(table);

        updateTotals();
        updateHeightHighlights();
        updateHeightProgress();
        updateLeftMarkers();

        // Автоподгрузка сохранённого плана для текущих параметров
        try{
            const plan = await loadSavedPlan();
            applyPlan(plan);
        }catch(e){
            console.warn('План не загружен:', e);
        }
    }

    function updateTotals() {
        const minsPerBale1000 = 40;  // Формат 1000: 40 минут = 0.67 часа
        const minsPerBale199 = 30;   // Формат 199: 30 минут = 0.5 часа
        
        const all = document.querySelectorAll('td.highlight');
        const cnt = {};
        
        all.forEach(td=>{
            const d = td.dataset.date;
            const baleId = td.dataset.baleId;
            
            // Находим бухту по ID и получаем её формат
            const bale = BALES.find(b => String(b.bale_id) === String(baleId));
            const format = bale ? (bale.format || '1000') : '1000';
            const mins = (format === '199') ? minsPerBale199 : minsPerBale1000;
            
            if (!cnt[d]) cnt[d] = { total_mins: 0, count: 0 };
            cnt[d].total_mins += mins;
            cnt[d].count += 1;
        });

        document.querySelectorAll('[id^="load-"]').forEach(td=>{
            const date = td.id.replace('load-','');
            const hours = cnt[date] ? (cnt[date].total_mins / 60) : 0;
            td.textContent = (hours>0) ? hours.toFixed(2) : '';
            td.className = (hours > 7) ? 'overload' : '';
        });
    }

    async function savePlan(){
        try{
            const res = await fetch(location.pathname + '?action=save_assignments', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ order: ORDER, plan: selected })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'save failed');
            alert('План сохранён');
            
            // Перезагружаем страницу чтобы показать кнопку "Завершить"
            location.reload();
        }catch(e){
            alert('Ошибка сохранения: ' + e.message);
        }
    }

    async function loadSavedPlan(){
        const res = await fetch(location.pathname + '?action=load_assignments&order=' + encodeURIComponent(ORDER));
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'load failed');
        return data.plan || {};
    }

    function applyPlan(plan){
        const chosen = new Map(); // bale_id -> date (берём первое попадание)
        Object.entries(plan).forEach(([date, list])=>{
            if (!Array.isArray(list)) return;
            list.forEach(bid=>{
                const b = String(bid);
                if (!chosen.has(b)) chosen.set(b, date);
            });
        });

        document.querySelectorAll('td.highlight').forEach(el => el.classList.remove('highlight'));
        selected = {};

        for (const [bid, date] of chosen.entries()){
            document.querySelectorAll(`td[data-bale-id="${cssEsc(bid)}"]`).forEach(c=>c.classList.remove('highlight'));
            const td = document.querySelector(`td[data-bale-id="${cssEsc(bid)}"][data-date="${cssEsc(date)}"]`);
            if (!td) continue;

            if (!selected[date]) selected[date] = [];
            if (!selected[date].includes(bid)) selected[date].push(bid);

            td.classList.add('highlight');
        }
        updateTotals();
        updateHeightHighlights();
        updateHeightProgress();
        updateLeftMarkers();
    }

    // Кнопки в форме
    document.getElementById('btnSave').addEventListener('click', savePlan);

    // «Загрузить сохранённый»:
    // 1) тянем план из БД
    // 2) определяем min/max даты
    // 3) подставляем их в инпуты (startDate — min, days — разница + 1)
    // 4) строим таблицу и применяем план (drawTable сам снова загрузит и применит)
    document.getElementById('btnLoad').addEventListener('click', async ()=>{
        try{
            const plan = await loadSavedPlan();
            const dates = Object.keys(plan).filter(Boolean).sort();
            if (!dates.length) { alert('Сохранённый план не найден.'); return; }

            const startISO = dates[0];
            const endISO   = dates[dates.length - 1];
            const days     = daysBetween(startISO, endISO) + 1;

            document.getElementById('startDate').value  = startISO;
            document.getElementById('daysCount').value  = Math.max(1, days);

            await drawTable(); // он сам подгрузит и применит plan
        }catch(e){
            alert('Не удалось загрузить план: ' + e.message);
        }
    });

    // стартовая дата = сегодня и инициализация фильтра высот
    (function setToday(){
        const el = document.getElementById('startDate');
        const today = new Date(); today.setHours(12);
        el.value = today.toISOString().slice(0,10);
        buildHeightBar();
    })();
    
    // ==================== ПОИСК ФИЛЬТРОВ ====================
    
    function isBalePlanned(baleId) {
        // Проверяем, есть ли бухта в selected (распланирована)
        return Object.values(selected).some(arr => arr && arr.includes(String(baleId)));
    }
    
    function searchFilterInBales() {
        const searchText = document.getElementById('filterSearchInput').value.toLowerCase().trim();
        const resultsContainer = document.getElementById('searchResults');
        
        if (searchText === '') {
            resultsContainer.innerHTML = '<div class="no-results">Введите название фильтра для поиска</div>';
            return;
        }
        
        // Ищем в данных BALES
        const results = [];
        BALES.forEach(bale => {
            const matchingFilters = [];
            bale.strips.forEach(strip => {
                if (strip.filter.toLowerCase().includes(searchText)) {
                    matchingFilters.push(strip.filter);
                }
            });
            
            if (matchingFilters.length > 0) {
                results.push({
                    bale_id: bale.bale_id,
                    format: bale.format || '1000',
                    filters: matchingFilters,
                    isPlanned: isBalePlanned(bale.bale_id)
                });
            }
        });
        
        // Отображаем результаты
        if (results.length === 0) {
            resultsContainer.innerHTML = '<div class="no-results">Ничего не найдено</div>';
            return;
        }
        
        resultsContainer.innerHTML = results.map(result => {
            const uniqueFilters = [...new Set(result.filters)];
            const filtersHtml = uniqueFilters.map(filter => {
                const highlighted = filter.replace(new RegExp(searchText, 'gi'), match => 
                    `<span class="search-result-highlight">${match}</span>`
                );
                return highlighted;
            }).join(', ');
            
            const statusIcon = result.isPlanned ? '<span class="bale-status-check">✓</span>' : '';
            
            return `
                <div class="search-result-item" onclick="scrollToBale(${result.bale_id})">
                    <div class="search-result-item__bale">
                        <span>Бухта #${result.bale_id} [${result.format}]</span>
                        ${statusIcon}
                    </div>
                    <div class="search-result-item__filters">
                        ${filtersHtml}
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function scrollToBale(baleId) {
        // Находим элемент с названием бухты
        const baleElement = document.querySelector(`.bale-name[data-bale-id="${baleId}"]`);
        
        if (baleElement) {
            // Подсвечиваем бухту
            baleElement.classList.add('bale-picked');
            
            // Скроллим к элементу
            baleElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Убираем подсветку через 3 секунды
            setTimeout(() => {
                baleElement.classList.remove('bale-picked');
            }, 3000);
        }
    }
    
    // ==================== ПЕРЕТАСКИВАНИЕ ПАНЕЛИ ====================
    
    document.addEventListener('DOMContentLoaded', function() {
        const panel = document.querySelector('.search-panel');
        const header = document.querySelector('.search-panel__header');
        
        if (!panel || !header) return;
        
        let isDragging = false;
        let currentX = 0;
        let currentY = 0;
        let initialX = 0;
        let initialY = 0;
        let xOffset = 0;
        let yOffset = 0;
        
        header.addEventListener('mousedown', dragStart);
        document.addEventListener('mousemove', drag);
        document.addEventListener('mouseup', dragEnd);
        
        function dragStart(e) {
            if (e.target === header || header.contains(e.target)) {
                isDragging = true;
                initialX = e.clientX - xOffset;
                initialY = e.clientY - yOffset;
            }
        }
        
        function drag(e) {
            if (isDragging) {
                e.preventDefault();
                
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;
                
                xOffset = currentX;
                yOffset = currentY;
                
                setTranslate(currentX, currentY, panel);
            }
        }
        
        function dragEnd(e) {
            initialX = currentX;
            initialY = currentY;
            isDragging = false;
        }
        
        function setTranslate(xPos, yPos, el) {
            el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0)`;
        }
    });
</script>

<!-- Плавающая панель поиска -->
<div class="search-panel">
    <div class="search-panel__header">
        🔍 Поиск фильтра в бухтах
    </div>
    <div class="search-panel__input">
        <input type="text" 
               id="filterSearchInput" 
               placeholder="Введите название фильтра..." 
               oninput="searchFilterInBales()">
    </div>
    <div class="search-panel__results" id="searchResults">
        <div class="no-results">Введите название фильтра для поиска</div>
    </div>
</div>

</body>
</html>
