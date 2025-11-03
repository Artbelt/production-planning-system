<?php
/* NP_corrugation_plan.php — план гофрирования бумаги (синхрон с планом сборки)
   Верх: план сборки (read-only) + пропорциональная заливка обеспеченности (по всей ячейке).
   Низ:  план гофрирования (редактируемый ввод числа в ячейке).
   Скролл по двум таблицам синхронный (X/Y).
*/

$dsn='mysql:host=127.0.0.1;dbname=plan_U3;charset=utf8mb4';
$user='root'; $pass='';

$action = $_GET['action'] ?? '';

/* === AJAX ============================================================== */
if (in_array($action, [
    'load_assembly','load_corr','save_corr','load_left_rows',
    'plan_bounds','load_meta'
], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $pdo = new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);

        // Таблицы
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS build_plans(
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number VARCHAR(64) NOT NULL,
                filter VARCHAR(128) NOT NULL,
                day_date DATE NOT NULL,
                shift ENUM('D','N') NOT NULL,
                qty INT NOT NULL,
                saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY idx_order_date (order_number, day_date),
                KEY idx_order_filter (order_number, filter)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS corrugation_plans(
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number VARCHAR(64) NOT NULL,
                filter VARCHAR(128) NOT NULL,
                day_date DATE NOT NULL,
                qty INT NOT NULL,
                saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY idx_order_date (order_number, day_date),
                KEY idx_order_filter (order_number, filter)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $raw = file_get_contents('php://input');
        $payload = $raw ? json_decode($raw, true) : [];

        /* load_assembly --------------------------------------------------- */
        if ($action==='load_assembly'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            $start = (string)($payload['start'] ?? ($_GET['start'] ?? ''));
            $days  = (int)($payload['days']  ?? ($_GET['days'] ?? 14));
            if ($order==='' || $start==='' || $days<=0){
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad params']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');

            $st = $pdo->prepare("
                SELECT filter, day_date, qty
                FROM build_plans
                WHERE order_number=? AND shift='D' AND day_date BETWEEN ? AND ?
                ORDER BY filter, day_date
            ");
            $st->execute([$order, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')]);
            echo json_encode(['ok'=>true,'items'=>$st->fetchAll()]); exit;
        }

        /* load_corr -------------------------------------------------------- */
        if ($action==='load_corr'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            $start = (string)($payload['start'] ?? ($_GET['start'] ?? ''));
            $days  = (int)($payload['days']  ?? ($_GET['days'] ?? 14));
            if ($order==='' || $start==='' || $days<=0){
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad params']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');

            $st = $pdo->prepare("
                SELECT filter, day_date, qty
                FROM corrugation_plans
                WHERE order_number=? AND day_date BETWEEN ? AND ?
                ORDER BY filter, day_date
            ");
            $st->execute([$order, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')]);
            echo json_encode(['ok'=>true,'items'=>$st->fetchAll()]); exit;
        }

        /* save_corr -------------------------------------------------------- */
        if ($action==='save_corr'){
            $order = (string)($payload['order'] ?? '');
            $start = (string)($payload['start'] ?? '');
            $days  = (int)($payload['days'] ?? 14);
            $items = $payload['items'] ?? [];
            if ($order==='' || $start==='' || $days<=0 || !is_array($items)) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad payload']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM corrugation_plans WHERE order_number=? AND day_date BETWEEN ? AND ?")
                ->execute([$order, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')]);

            $ins = $pdo->prepare("INSERT INTO corrugation_plans(order_number, filter, day_date, qty) VALUES(?,?,?,?)");
            $saved = 0;
            foreach ($items as $it){
                $f = trim((string)($it['filter'] ?? ''));
                $d = (string)($it['date'] ?? '');
                $q = (int)($it['qty'] ?? 0);
                if ($f==='' || $q<=0) continue;
                $dd = DateTime::createFromFormat('Y-m-d', $d); if(!$dd) continue;
                if ($dd < $dtStart || $dd > $dtEnd) continue;
                $ins->execute([$order, $f, $dd->format('Y-m-d'), $q]);
                $saved++;
            }
            $pdo->commit();
            echo json_encode(['ok'=>true,'saved'=>$saved]); exit;
        }

        /* load_left_rows --------------------------------------------------- */
        if ($action==='load_left_rows'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            if ($order===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }
            $st = $pdo->prepare("
                SELECT o.filter, SUM(o.count) AS ordered_qty
                FROM orders o
                WHERE o.order_number=?
                GROUP BY o.filter
                ORDER BY o.filter
            ");
            $st->execute([$order]);
            echo json_encode(['ok'=>true,'items'=>$st->fetchAll()]); exit;
        }

        /* plan_bounds (по сборке) ------------------------------------------ */
        if ($action==='plan_bounds'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            if ($order===''){ echo json_encode(['ok'=>true,'min'=>null,'max'=>null,'days'=>0]); exit; }
            $st = $pdo->prepare("
                SELECT MIN(day_date) AS dmin, MAX(day_date) AS dmax
                FROM build_plans WHERE shift='D' AND order_number=?
            ");
            $st->execute([$order]); $row=$st->fetch();
            $min = $row && $row['dmin'] ? $row['dmin'] : null;
            $max = $row && $row['dmax'] ? $row['dmax'] : null;
            $days=0; if($min && $max){ $d1=new DateTime($min); $d2=new DateTime($max); $days=$d1->diff($d2)->days+1; }
            echo json_encode(['ok'=>true,'min'=>$min,'max'=>$max,'days'=>$days]); exit;
        }

        /* load_meta (валы/бумага) ------------------------------------------ */
        if ($action==='load_meta'){
            $filtersIn = $payload['filters'] ?? [];
            if (!is_array($filtersIn) || empty($filtersIn)) { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
            $in  = implode(',', array_fill(0, count($filtersIn), '?'));
            $res = []; foreach ($filtersIn as $f){ $res[$f]=['val_height_mm'=>null,'paper_width_mm'=>null]; }
            $sql = "
                SELECT rfs.filter AS filter,
                       ppr.p_p_fold_height  AS val_height_mm,
                       ppr.p_p_paper_width  AS paper_width_mm
                FROM round_filter_structure rfs
                LEFT JOIN paper_package_round ppr
                  ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                WHERE rfs.filter IN ($in)
            ";
            $st=$pdo->prepare($sql); $st->execute($filtersIn);
            foreach($st as $row){
                $f=(string)$row['filter']; if(!array_key_exists($f,$res)) continue;
                $res[$f]['val_height_mm']  = $row['val_height_mm']!==null?(float)$row['val_height_mm']:null;
                $res[$f]['paper_width_mm'] = $row['paper_width_mm']!==null?(float)$row['paper_width_mm']:null;
            }
            echo json_encode(['ok'=>true,'items'=>$res]); exit;
        }

        echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;

    }catch(Throwable $e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* === PAGE ============================================================== */

$orderNumber = $_GET['order_number'] ?? '';
if ($orderNumber===''){ http_response_code(400); exit('Укажите ?order_number=...'); }

try{
    $pdo=new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $st = $pdo->prepare("
        SELECT o.filter, SUM(o.count) AS ordered_qty
        FROM orders o WHERE o.order_number=:order GROUP BY o.filter ORDER BY o.filter
    "); $st->execute([':order'=>$orderNumber]); $filters = $st->fetchAll();
}catch(Throwable $e){ http_response_code(500); echo 'Ошибка: '.$e->getMessage(); exit; }
?>
<!doctype html><meta charset="utf-8">
<title>План гофрирования — заявка #<?=htmlspecialchars($orderNumber)?></title>
<style>
    :root{
        --bg:#f6f7fb; --card:#fff; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280;
        --accent:#2563eb; --accent-2:#10b981; --danger:#ef4444; --warning:#f59e0b;
        --wFilter:260px; --wOrd:70px; --wPlan:64px; --wDay:56px; /* в 2 раза уже */
        --radius:12px; --shadow:0 6px 20px rgba(0,0,0,.06); --weekend:#f3f4f6;
    }
    *{box-sizing:border-box}
    body{font:13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Arial,Helvetica,sans-serif;margin:0;background:var(--bg);color:var(--text)}
    .topbar{position:sticky;top:0;z-index:20;backdrop-filter:saturate(140%) blur(6px);background:rgba(246,247,251,.8);border-bottom:1px solid var(--border)}
    .topbar-inner{max-width:1400px;margin:0 auto;padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between}
    .title{font:700 16px/1.2 system-ui;margin:0}

    .toolbar-wrap{max-width:1400px;margin:10px auto 0;padding:0 16px}
    .toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)}
    .toolbar label{display:flex;align-items:center;gap:6px;color:var(--muted)}
    .toolbar input{padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;outline:none}
    .btn{padding:7px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;font-weight:600}
    .btn:hover{transform:translateY(-1px); box-shadow:0 4px 16px rgba(0,0,0,.08)}
    .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
    .help{color:var(--muted);font-size:12px}

    .panel-wrap{max-width:1400px;margin:10px auto 20px;padding:0 16px;display:flex;flex-direction:column;gap:12px}
    .block{border:1px solid var(--border);border-radius:var(--radius);background:var(--card);box-shadow:var(--shadow);overflow:hidden}
    .block h3{margin:0;padding:8px 12px;border-bottom:1px solid var(--border);font:600 14px/1.2 system-ui;background:#f8fafc}
    .scroller{height:42vh;overflow:auto}
    .scroller.small{height:38vh;}
    /* sync table styles */
    table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;table-layout:fixed}
    th,td{border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:6px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#fff;font-weight:400}
    thead th{background:#f8fafc;position:sticky;top:0;z-index:3}
    .sticky{position:sticky;z-index:4;background:#fff}

    .col-filter{left:0;width:var(--wFilter)}
    .col-ord{left:var(--wFilter);width:var(--wOrd);text-align:right;color:var(--muted)}
    .col-plan{left:calc(var(--wFilter) + var(--wOrd));width:var(--wPlan);text-align:right}

    .dayHead{width:var(--wDay);min-width:var(--wDay);max-width:var(--wDay);text-align:center}
    .dayHead.weekend{background:var(--weekend)!important}
    .dayHead .d{display:block;font-weight:400}
    .dayHead .sum{display:block;color:var(--muted);font-size:11px}

    .dayCell{width:var(--wDay);min-width:var(--wDay);max-width:var(--wDay);text-align:center; position:relative;}
    .dayCell.weekend{background:var(--weekend)!important}
    .qty{display:block;padding:2px 0;margin:0;border-radius:0;transition:background .12s; position:relative; z-index:1;}
    .qty.zero{color:#cbd5e1; opacity:.65}

    /* единый стиль названия фильтра (верх+низ одинаково) */
    .filterTitle{font-weight:600}

    /* заполнение обеспеченности — ПО ВСЕЙ ЯЧЕЙКЕ (под числом) */
    .fillBar{
        position:absolute; left:0; top:0; bottom:0;
        width:0%;
        background: rgba(59,130,246,.28);
        pointer-events:none; z-index:0;
    }

    /* ======== нижняя таблица: ввод ======== */
    .editable{cursor:text}
    .qtyInput{
        width:100%; height:24px; line-height:24px;
        border:0; outline:none; text-align:center; background:transparent;
        font:inherit; color:inherit; padding:0;
    }
    .qtyInput:focus{background:rgba(37,99,235,.06)}
    .dayCell input::-webkit-outer-spin-button,
    .dayCell input::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }
    .dayCell input[type=number]{ -moz-appearance:textfield; }

    /* ——— подсветка строки (обычная и синхронная) ——— */
    tbody tr:hover td{background:#eef6ff}
    tbody tr:hover td.sticky{background:#eef6ff!important}
    tbody tr:hover td.dayCell.weekend{background:var(--weekend)!important}

    /* синхронная подсветка из второй таблицы */
    tbody tr.rowHover td{background:#eef6ff}
    tbody tr.rowHover td.sticky{background:#eef6ff!important}
    tbody tr.rowHover td.dayCell.weekend{background:var(--weekend)!important}
</style>

<div class="topbar">
    <div class="topbar-inner">
        <h1 class="title">План гофрирования — заявка #<span id="titleOrder"><?=htmlspecialchars($orderNumber)?></span></h1>
        <div class="help">Низ: впишите количество в ячейку. Верх обновится автоматически.</div>
    </div>
</div>

<div class="toolbar-wrap">
    <div class="toolbar">
        <label>Старт: <input type="date" id="startDate"></label>
        <label>Дней: <input type="number" id="days" min="1" step="1" value="7" style="width:92px"></label>
        <button class="btn" id="btnRebuild">Построить</button>
        <button class="btn" id="btnLoad">Загрузить</button>
        <button class="btn" id="btnSave">Сохранить гофрирование</button>
        <span class="help">Диапазон автоматически подхватывается по плану сборки.</span>
    </div>
</div>

<div class="panel-wrap">
    <div class="block">
        <h3>Сборка (обеспеченность гофрой)</h3>
        <div id="asmScroller" class="scroller">
            <div id="asmTable"></div>
        </div>
    </div>

    <div class="block">
        <h3>Гофрирование (редактируемо)</h3>
        <div id="corrScroller" class="scroller small">
            <div id="corrTable"></div>
        </div>
    </div>
</div>

<script>
    let ORDER = <?=json_encode($orderNumber)?>;
    let LEFT_ROWS = [
        <?php foreach($filters as $r): ?>
        { filter: <?=json_encode($r['filter'])?>, ord: <?= (int)$r['ordered_qty']?> },
        <?php endforeach; ?>
    ];

    let startDateISO, days=7, dates=[];
    let ASM = new Map();     // f|d -> qty сборки
    let CORR = new Map();    // f|d -> qty гофры
    let COV  = new Map();    // f|d -> покрыто
    let META = {};

    const el=id=>document.getElementById(id);
    const addDays=(iso,k)=>{const dt=new Date(iso); dt.setDate(dt.getDate()+k); return dt.toISOString().slice(0,10);};
    const key=(f,d)=>`${f}|${d}`;
    const isWeekend=iso=>{ const d=new Date(iso).getDay(); return d===0||d===6; };
    const fmtDM=iso=>{const dt=new Date(iso); const dd=String(dt.getDate()).padStart(2,'0'); const mm=String(dt.getMonth()+1).padStart(2,'0'); const w=['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][dt.getDay()]; return `${dd}.${mm}<br><small>${w}</small>`;};
    const debounce=(fn,ms)=>{ let t=null; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; };

    function rebuildDates(){ dates=[]; for(let i=0;i<days;i++) dates.push(addDays(startDateISO, i)); }

    /* ===== AJAX ==== */
    async function loadAssembly(){ const body=JSON.stringify({order:ORDER,start:startDateISO,days}); const res=await fetch(location.pathname+'?action=load_assembly',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); if(!data.ok) throw new Error(data.error||'asm'); ASM.clear(); (data.items||[]).forEach(r=>{ ASM.set(key(r.filter,r.day_date), parseInt(r.qty,10)||0); }); }
    async function loadCorr(){ const body=JSON.stringify({order:ORDER,start:startDateISO,days}); const res=await fetch(location.pathname+'?action=load_corr',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); if(!data.ok) throw new Error(data.error||'corr'); CORR.clear(); (data.items||[]).forEach(r=>{ CORR.set(key(r.filter,r.day_date), parseInt(r.qty,10)||0); }); }
    async function saveCorr(){ const items=[]; for(const [k,q] of CORR.entries()){ if(!q) continue; const [f,d]=k.split('|'); items.push({filter:f,date:d,qty:q}); } const body=JSON.stringify({order:ORDER,start:startDateISO,days,items}); const res=await fetch(location.pathname+'?action=save_corr',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); if(!data.ok) throw new Error(data.error||'save'); }
    async function loadLeftRows(order){ const body=JSON.stringify({order}); const res=await fetch(location.pathname+'?action=load_left_rows',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); if(!data.ok) throw new Error(data.error||'left'); return data.items||[]; }
    async function planBounds(order){ const body=JSON.stringify({order}); const res=await fetch(location.pathname+'?action=plan_bounds',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); if(!data.ok) throw new Error(data.error||'bounds'); return data; }
    async function loadMeta(){ const filters=LEFT_ROWS.map(r=>r.filter); if(!filters.length){ META={}; return; } const body=JSON.stringify({filters}); const res=await fetch(location.pathname+'?action=load_meta',{method:'POST',headers:{'Content-Type':'application/json'},body}); const data=await res.json(); META=data.items||{}; }

    /* ===== COVERAGE ========================================================= */
    function recomputeCoverage(){
        COV.clear();
        const ds=[...dates];
        for(const row of LEFT_ROWS){
            const f=row.filter;
            const need = ds.map(d=>({d, need:(ASM.get(key(f,d))||0), covered:0 }));
            for(const d of ds){
                let remain = CORR.get(key(f,d)) || 0;
                if(remain<=0) continue;
                for(let i=ds.indexOf(d); i<need.length && remain>0; i++){
                    const lack = Math.max(0, need[i].need - need[i].covered);
                    if(lack<=0) continue;
                    const give = Math.min(lack, remain);
                    need[i].covered += give;
                    remain -= give;
                }
            }
            for(const it of need){
                if(it.covered>0) COV.set(key(f,it.d), it.covered);
            }
        }
    }
    const recomputeCoverageDebounced = debounce(()=>{ recomputeCoverage(); buildAsmCoverageOnly(); }, 60);

    /* ===== RENDER =========================================================== */
    function buildTables(){
        // верхняя (сборка)
        let htmlA='<table><thead><tr>';
        htmlA+='<th class="sticky col-filter">Фильтр</th>';
        htmlA+='<th class="sticky col-ord">Заказано</th>';
        htmlA+='<th class="sticky col-plan">Σ сборки</th>';
        for(const d of dates){
            const wk=isWeekend(d)?' weekend':'';
            htmlA+=`<th class="dayHead${wk}" title="Сборка"><span class="d">${fmtDM(d)}</span></th>`;
        }
        htmlA+='</tr></thead><tbody>';
        for(const r of LEFT_ROWS){
            const meta=META[r.filter]||{};
            const vh = meta.val_height_mm!=null?Math.round(meta.val_height_mm):null;
            const pw = meta.paper_width_mm!=null?Math.round(meta.paper_width_mm):null;
            const showW = pw!=null && pw>450;
            let sum=0; for(const d of dates){ sum += (ASM.get(key(r.filter,d))||0); }
            htmlA+=`<tr data-filter="${r.filter}">
      <td class="sticky col-filter"><span class="filterTitle">${r.filter}</span>${vh!=null?` <span class="help">[${vh}]</span>`:''}${showW?` <span class="help">[600]</span>`:''}</td>
      <td class="sticky col-ord">${r.ord||0}</td>
      <td class="sticky col-plan">${sum}</td>`;
            for(const d of dates){
                const req = ASM.get(key(r.filter,d))||0;
                const cov = Math.min(req, COV.get(key(r.filter,d))||0);
                const wk=isWeekend(d)?' weekend':'';
                const zeroCls = req===0?' zero':'';
                const ratio = req>0?Math.round((cov/req)*100):0;
                htmlA+=`<td class="dayCell${wk}">
          <div class="fillBar" style="width:${ratio}%;"></div>
          <span class="qty${zeroCls}" title="${req>0?`Покрыто ${cov} из ${req} (${ratio}%)`:'Нет сборки'}">${req}</span>
        </td>`;
            }
            htmlA+='</tr>';
        }
        htmlA+='</tbody></table>';
        el('asmTable').innerHTML=htmlA;

        // предварительно считаем суточные суммы для НИЖНЕЙ таблицы
        const corrDayTotals = new Map(); dates.forEach(d=>corrDayTotals.set(d,0));
        for(const r of LEFT_ROWS){ for(const d of dates){ corrDayTotals.set(d, (corrDayTotals.get(d)||0) + (CORR.get(key(r.filter,d))||0)); } }

        // нижняя (гофра) — ввод
        let htmlC='<table><thead><tr>';
        htmlC+='<th class="sticky col-filter">Фильтр</th>';
        htmlC+='<th class="sticky col-ord">Заказано</th>';
        htmlC+='<th class="sticky col-plan">Σ гофры</th>';
        for(const d of dates){
            const wk=isWeekend(d)?' weekend':'';
            const sum = corrDayTotals.get(d)||0;
            htmlC+=`<th class="dayHead${wk}" title="Гофрирование">
                      <span class="d">${fmtDM(d)}</span>
                      <span class="sum"><span class="corrDaySum" data-date="${d}">${sum}</span></span>
                    </th>`;
        }
        htmlC+='</tr></thead><tbody>';
        for(const r of LEFT_ROWS){
            let sum=0; for(const d of dates){ sum += (CORR.get(key(r.filter,d))||0); }
            htmlC+=`<tr data-filter="${r.filter}">
      <td class="sticky col-filter"><span class="filterTitle">${r.filter}</span></td>
      <td class="sticky col-ord">${r.ord||0}</td>
      <td class="sticky col-plan corr-sum" data-filter="${r.filter}">${sum}</td>`;
            for(const d of dates){
                const q=CORR.get(key(r.filter,d))||0;
                const wk=isWeekend(d)?' weekend':'';
                htmlC+=`<td class="dayCell editable${wk}" data-filter="${r.filter}" data-date="${d}">
        <input class="qtyInput" type="number" min="0" step="1" value="${q?q:''}" placeholder="0" inputmode="numeric">
      </td>`;
            }
            htmlC+='</tr>';
        }
        htmlC+='</tbody></table>';
        el('corrTable').innerHTML=htmlC;

        // обработчики ввода
        el('corrTable').querySelectorAll('.qtyInput').forEach(inp=>{
            const handler = ()=>onCorrInput(inp);
            inp.addEventListener('input', handler);
            inp.addEventListener('change', handler);
            inp.addEventListener('blur', handler);

            // синхронная подсветка по фокусу (когда печатаем)
            const f = inp.closest('tr')?.dataset.filter;
            if(f){
                inp.addEventListener('focus', ()=>toggleRowHover(true, f));
                inp.addEventListener('blur',  ()=>toggleRowHover(false, f));
            }
        });

        // синхронный скролл
        setupSyncScroll();
        // синхронная подсветка строк при наведении
        setupRowHoverRows();
    }

    // быстрый апдейт только ПОКРЫТИЯ в верхней таблице
    function buildAsmCoverageOnly(){
        for(const r of LEFT_ROWS){
            const f=r.filter;
            const row = el('asmTable').querySelector(`tr[data-filter="${CSS.escape(f)}"]`);
            if(!row) continue;
            dates.forEach(d=>{
                const req = ASM.get(key(f,d))||0;
                const cov = Math.min(req, COV.get(key(f,d))||0);
                const ratio = req>0?Math.round((cov/req)*100):0;
                const cell = row.querySelector(`td.dayCell:nth-child(${3 + dates.indexOf(d) + 1})`);
                if(!cell) return;
                const bar = cell.querySelector('.fillBar');
                const span = cell.querySelector('.qty');
                if(bar) bar.style.width = ratio+'%';
                if(span) span.title = req>0?`Покрыто ${cov} из ${req} (${ratio}%)`:'Нет сборки';
            });
        }
    }

    function updateCorrDayHeader(dateISO){
        let s=0;
        for(const r of LEFT_ROWS){ s += (CORR.get(key(r.filter,dateISO))||0); }
        const h = document.querySelector(`.corrDaySum[data-date="${dateISO}"]`);
        if(h) h.textContent = String(s);
    }

    function onCorrInput(inp){
        const td = inp.closest('.dayCell'); if(!td) return;
        const f = td.dataset.filter, d = td.dataset.date;
        let v = parseInt(inp.value,10);
        if(!Number.isFinite(v) || v<0) v = 0;
        inp.value = v ? String(v) : '';  // красивее, чем "0"
        CORR.set(key(f,d), v);

        // пересчитать сумму по строке
        let sum=0; for(const dt of dates){ sum += (CORR.get(key(f,dt))||0); }
        const sumCell = el('corrTable').querySelector(`.corr-sum[data-filter="${CSS.escape(f)}"]`);
        if(sumCell) sumCell.textContent = String(sum);

        // обновить суточную сумму в шапке нижней таблицы
        updateCorrDayHeader(d);

        // покрытие
        recomputeCoverageDebounced();
    }

    /* ==== sync scroll (две области) ======================================== */
    function setupSyncScroll(){
        const a = el('asmScroller'), b = el('corrScroller');
        let lock=false;
        function sync(from, to){
            if(lock) return; lock=true;
            to.scrollLeft = from.scrollLeft; to.scrollTop = from.scrollTop;
            lock=false;
        }
        a.onscroll = ()=>sync(a,b);
        b.onscroll = ()=>sync(b,a);
    }

    /* ==== sync row hover ==================================================== */
    function toggleRowHover(on, filter){
        const r1 = document.querySelector(`#asmTable tr[data-filter="${CSS.escape(filter)}"]`);
        const r2 = document.querySelector(`#corrTable tr[data-filter="${CSS.escape(filter)}"]`);
        if(r1) r1.classList.toggle('rowHover', on);
        if(r2) r2.classList.toggle('rowHover', on);
    }
    function setupRowHoverRows(){
        const rows = document.querySelectorAll('#asmTable tr[data-filter], #corrTable tr[data-filter]');
        rows.forEach(tr=>{
            const f = tr.dataset.filter;
            tr.addEventListener('mouseenter', ()=>toggleRowHover(true, f));
            tr.addEventListener('mouseleave', ()=>toggleRowHover(false, f));
        });
    }

    /* ===== INIT ============================================================= */
    (async function init(){
        const todayISO = new Date().toISOString().slice(0,10);
        // автодиапазон по сборке
        try{
            const bounds = await planBounds(ORDER);
            if(bounds.min && bounds.days>0){ startDateISO=bounds.min; days=bounds.days; }
            else { startDateISO=todayISO; days=14; }
        }catch{ startDateISO=todayISO; days=14; }
        el('startDate').value = startDateISO; el('days').value = String(days);

        // мета (бейджи валов/ширины) опциональна
        try{ await loadMeta(); }catch{}

        rebuildDates();
        await loadAssembly();
        await loadCorr();
        recomputeCoverage();
        buildTables();

        el('btnRebuild').addEventListener('click', async ()=>{
            startDateISO = el('startDate').value || todayISO;
            days = Math.max(1, parseInt(el('days').value,10)||1);
            rebuildDates();
            await Promise.all([loadAssembly(), loadCorr()]);
            recomputeCoverage();
            buildTables();
        });

        el('btnLoad').addEventListener('click', async ()=>{
            const bounds = await planBounds(ORDER);
            if(bounds.min && bounds.days>0){ startDateISO=bounds.min; days=bounds.days; el('startDate').value=startDateISO; el('days').value=String(days); }
            rebuildDates();
            await Promise.all([loadAssembly(), loadCorr()]);
            recomputeCoverage();
            buildTables();
        });

        el('btnSave').addEventListener('click', async ()=>{
            try{ await saveCorr(); alert('План гофрирования сохранён'); }
            catch(e){ alert('Ошибка сохранения: '+e.message); }
        });
    })();
</script>
