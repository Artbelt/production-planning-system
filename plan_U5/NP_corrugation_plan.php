<?php
// NP_corrugation_plan.php — верх: ПОЛОСЫ из бухт (с расчётом количества фильтров), низ: план на гофру с диапазоном дней + сохранение/загрузка
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);

$order = $_GET['order'] ?? '';
if ($order==='') { http_response_code(400); exit('Укажите ?order=...'); }

/*
 * Верхняя таблица = полосы, полученные при раскрое (по датам раскроя).
 */
$sql = "
SELECT
  rp.work_date,
  rp.bale_id,
  cps.strip_no,
  cps.filter,
  cps.height,
  cps.width,
  cps.fact_length,
  pps.p_p_pleats_count AS pleats
FROM roll_plans rp
JOIN cut_plans cps
  ON cps.order_number = rp.order_number
 AND cps.bale_id      = rp.bale_id
JOIN salon_filter_structure sfs
  ON sfs.filter = cps.filter
JOIN paper_package_salon pps
  ON pps.p_p_name = sfs.paper_package
WHERE rp.order_number = ?
ORDER BY rp.work_date, rp.bale_id, cps.strip_no
";
$st = $pdo->prepare($sql);
$st->execute([$order]);
$rows = $st->fetchAll();

function trim_num($x, $dec=1){
    $s = number_format((float)$x, $dec, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/* Получаем информацию о выполненных операциях (fact_count > 0) */
$factData = [];
$stFact = $pdo->prepare("
    SELECT plan_date, filter_label, bale_id, strip_no, count, fact_count 
    FROM corrugation_plan 
    WHERE order_number = ? AND fact_count > 0
");
$stFact->execute([$order]);
while ($row = $stFact->fetch()) {
    $key = $row['bale_id'] . ':' . $row['strip_no'];
    $factData[$key] = [
        'plan_count' => (int)$row['count'],
        'fact_count' => (int)$row['fact_count'],
        'plan_date' => $row['plan_date']
    ];
}

$dates = [];
$pool  = [];
foreach($rows as $r){
    $d = $r['work_date'];
    $dates[$d]=true;

    $H = (float)$r['height'];
    $W = (float)$r['width'];
    $Z = (int)$r['pleats'];
    $L = $r['fact_length'] !== null ? (int)round((float)$r['fact_length']) : null; // м

    // длина одного фильтра (м)
    $L_one = ($H * 2 * max(0,$Z)) / 1000.0;
    $cnt   = ($L !== null && $L_one > 0) ? (int)floor($L / $L_one) : 0;

    // видимая часть: имя + [h..] + [N шт]
    $label_visible = sprintf('%s [h%s] [%d шт]', $r['filter'], trim_num($H, 1), $cnt);

    // tooltip (скрытые поля): [z..][w..][L..]
    $tooltip = sprintf('[z%d] [w%s]%s', $Z, trim_num($W, 1), $L !== null ? (' [L'.(int)$L.']') : '');

    // Включаем filter в ключ для уникальности (на случай дубликатов bale_id:strip_no с разными filter)
    // Заменяем двоеточия в filter на подчеркивания, чтобы не конфликтовать с разделителями ключа
    $filterSafe = str_replace(':', '_', $r['filter']);
    $key = $r['bale_id'].':'.$r['strip_no'].':'.$filterSafe;
    $pool[$d][] = [
        'key'      => $key,
        'bale_id'  => (int)$r['bale_id'],
        'strip_no' => (int)$r['strip_no'],
        'filter'   => (string)$r['filter'], // чистое имя (для БД)
        'label'    => $label_visible,
        'tip'      => $tooltip,
        'packs'    => $cnt,
        'fact_count' => isset($factData[$r['bale_id'].':'.$r['strip_no']]) ? $factData[$r['bale_id'].':'.$r['strip_no']]['fact_count'] : 0,
        'plan_count' => isset($factData[$r['bale_id'].':'.$r['strip_no']]) ? $factData[$r['bale_id'].':'.$r['strip_no']]['plan_count'] : 0,
    ];
}
$dates = array_values(array_keys($dates));
sort($dates);
?>
<!doctype html>
<meta charset="utf-8">
<title>Гофроплан (полосы): <?=htmlspecialchars($order)?></title>
<style>
    :root{ --line:#e5e7eb; --bg:#f7f9fc; --card:#fff; --muted:#6b7280; --accent:#2563eb; }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);font:11px system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;overflow-x:auto;overflow-y:auto;padding-top:100px}
    .wrap{width:max-content;min-width:100vw;margin:0;padding:0 6px}
    .panel{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:6px;margin:6px 0}
    .head{display:flex;align-items:center;justify-content:space-between;margin:1px 0 6px;gap:6px;flex-wrap:wrap}
    .btn{background:var(--accent);color:#fff;border:1px solid var(--accent);border-radius:6px;padding:4px 8px;cursor:pointer;font-size:10px}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .muted{color:var(--muted);font-size:10px}
    .sub{font-size:10px;color:var(--muted)}

    .gridTop{display:flex;gap:6px;padding-bottom:6px}
    .gridBot{display:grid;gap:6px}
    .col{border-left:1px solid var(--line);padding-left:6px;min-height:120px;flex-shrink:0}
    .gridTop .col{width:180px}
    .col h4{margin:0 0 4px;font-weight:600;font-size:12px}
    
    /* Sticky заголовки дат в плавающей панели */
    .floating-panel .col h4 {
        position: sticky;
        top: 0;
        background: white;
        z-index: 10;
        padding: 6px 8px;
        margin: 0 0 4px 0;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border-radius: 6px 6px 0 0;
        transition: all 0.2s;
    }
    .floating-panel .col.active-day h4 {
        background: #fef3c7;
        border: 2px solid #f59e0b;
        border-bottom: 2px solid #f59e0b;
        box-shadow: 0 2px 6px rgba(245, 158, 11, 0.2);
    }
    .floating-panel .col.active-day h4 .day-date {
        color: #92400e;
        font-weight: 700;
    }
    .floating-panel .col h4 .day-date {
        font-size: 12px;
        font-weight: 600;
    }
    .floating-panel .col h4 .day-count {
        font-size: 10px;
        color: #6b7280;
        font-weight: 500;
    }

    .pill{display:flex;align-items:center;justify-content:space-between;gap:4px;border:1px solid #dbe3f0;background:#eef6ff;border-radius:6px;padding:3px 6px;margin:2px 0;cursor:pointer;font-size:10px;position:relative;flex-wrap:wrap}
    .pill-date{color:#666;font-size:9px;margin-left:auto}
    .day-separator{background:#e5e7eb;color:#6b7280;padding:2px 6px;margin:4px 0;border-radius:4px;font-size:9px;font-weight:600;text-align:center}
    .pill:hover{background:#e6f1ff}
    .pill-disabled{opacity:.45;filter:grayscale(.15);pointer-events:none}

    /* Выполненные полосы */
    .pill-done::after{content:"✓";position:absolute;right:4px;top:50%;transform:translateY(-50%);color:#10b981;font-weight:bold;font-size:12px}
    
    /* Частично выполненные полосы */
    .pill-partial::after{content:"◐";position:absolute;right:4px;top:50%;transform:translateY(-50%);color:#f59e0b;font-weight:bold;font-size:12px}

    .dropzone{min-height:28px;border:1px dashed var(--line);border-radius:4px;padding:4px;transition:background 0.2s ease}
    .dropzone.drag-over{background:#e0f2fe;border-color:#0ea5e9}
    .rowItem{display:flex;align-items:center;justify-content:space-between;background:#dff7c7;border:1px solid #bddda2;border-radius:6px;padding:3px 4px;margin:2px 0;font-size:10px;cursor:grab;transition:opacity 0.2s ease;max-width:200px}
    .rowItem.dragging{opacity:0.5;cursor:grabbing}
    .row-content{flex:1;overflow:hidden;white-space:nowrap}
    .rowItem .rm{border:none;background:#fff;border:1px solid #ccc;border-radius:3px;padding:1px 3px;cursor:pointer;font-size:8px;min-width:16px;width:16px;height:16px;display:flex;align-items:center;justify-content:center}
    .dayTotal{margin-top:4px;font-size:10px}
    /* керування всередині картки низу */
    .rowItem .controls{display:flex;align-items:center;gap:3px}

    .tools{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .tools label{font-size:10px;color:#333}
    .tools input[type=date], .tools input[type=number]{padding:2px 6px;border:1px solid #dcdfe5;border-radius:6px;font-size:10px}
    /* запрет выделения текста по всей странице */
        html, body, .wrap, .panel, .grid, .col, .pill, .rowItem, button {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* но внутри полей ввода и редактируемых областей разрешаем */
        input, textarea, [contenteditable], .allow-select {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }

    .modalWrap{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.35);z-index:1000}
    .modal{background:#fff;border-radius:10px;border:1px solid var(--line);min-width:320px;max-width:500px;max-height:70vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,.2)}
    .modalHeader{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line)}
    .modalTitle{font-weight:600;font-size:15px}
    .modalClose{border:1px solid #ccc;background:#f8f8f8;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:14px}
    .modalBody{padding:10px;overflow:auto}
    .daysGrid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
    .dayBtn{display:flex;flex-direction:column;gap:4px;padding:10px;border:1px solid #d9e2f1;border-radius:8px;background:#f4f8ff;cursor:pointer;text-align:left;font-size:13px;transition:all 0.2s}
    .dayBtn:hover{background:#ecf4ff;border-color:#93c5fd}
    .dayHead{font-weight:600;font-size:13px}
    .daySub{font-size:11px;color:#6b7280}
    .dayBtn:disabled{
        opacity:.5;
        cursor:not-allowed;
    }
    .topCol h4{display:flex;align-items:center;justify-content:space-between}


    @media (max-width:560px){ .daysGrid{grid-template-columns:1fr;} .modal{min-width:240px;max-width:90vw;} }
    .height-buttons {
        display: flex;
        gap: 2px;
        flex-wrap: wrap;
    }
    .height-btn {
        font-size: 8px;
        padding: 1px 4px;
        border: 1px solid #d97706;
        border-radius: 3px;
        background: white;
        color: #92400e;
        cursor: pointer;
        min-width: 20px;
        transition: all 0.2s ease;
    }
    .height-btn:hover {
        background: #fef3c7;
    }
    .height-btn.active {
        background: #f59e0b;
        color: white;
        border-color: #d97706;
    }
    .btn-small {
        font-size: 8px;
        padding: 2px 6px;
        border-radius: 4px;
        min-width: auto;
    }
    .pill.highlighted {
        background: #dbeafe !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
    }
    .rowItem.highlighted {
        background: #dbeafe !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
    }

    /* Плавающая панель для плана гофрирования */
    .floating-panel {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 60%;
        max-width: 900px;
        height: auto;
        max-height: 42vh;
        background: #fef3c7;
        border: 2px solid #f59e0b;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
        z-index: 1000;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: box-shadow 0.2s ease;
    }
    .floating-panel:hover {
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }
    .floating-panel-header {
        background: #fef3c7;
        color: #92400e;
        padding: 8px 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: move;
        user-select: none;
        border-bottom: 1px solid #fcd34d;
    }
    .floating-panel-title {
        font-weight: 700;
        font-size: 13px;
        color: #92400e;
    }
    .floating-panel-btn {
        background: white;
        color: #92400e;
        border: 1px solid #f59e0b;
        border-radius: 4px;
        padding: 2px 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 700;
        transition: all 0.2s;
        min-width: 24px;
    }
    .floating-panel-btn:hover {
        background: #fef3c7;
        border-color: #d97706;
    }
    .floating-panel-content {
        overflow-y: auto;
        padding: 10px;
        flex: 1;
        background: white;
    }
    .floating-panel.minimized .floating-panel-content {
        display: none;
    }
    .floating-panel.minimized {
        max-height: 40px;
    }
</style>

<div class="wrap">
    <!-- Фиксированный заголовок с управлением -->
    <div style="position:fixed;top:0;left:0;right:0;background:white;border-bottom:2px solid #e5e7eb;z-index:50;box-shadow:0 2px 4px rgba(0,0,0,0.1);padding:10px 16px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
            <h2 style="margin:0;font-size:18px;font-weight:700">Гофроплан — <?=htmlspecialchars($order)?></h2>
            
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <button class="btn" id="btnLoad" style="padding:8px 14px;font-size:13px">Загрузить</button>
                <button class="btn" id="btnSave" disabled style="padding:8px 14px;font-size:13px">Сохранить</button>
                <button class="btn" onclick="window.location.href='NP_cut_index.php'" style="padding:8px 14px;font-size:13px">Вернуться</button>
                
                <div style="border-left:2px solid #e5e7eb;height:32px;margin:0 6px"></div>
                
                <label style="font-size:13px;display:flex;gap:6px;align-items:center;font-weight:500">
                    Начало: <input type="date" id="rngStart" class="control-input" style="font-size:13px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;width:140px">
                </label>
                <label style="font-size:13px;display:flex;gap:6px;align-items:center;font-weight:500">
                    Дней: <input type="number" id="rngDays" value="7" min="1" class="control-input" style="width:70px;font-size:13px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px">
                </label>
                <button class="btn" id="btnBuildDays" style="padding:8px 14px;font-size:13px">Построить</button>
                <button class="btn" id="btnAddDay" title="Добавить день" style="padding:8px 12px;font-size:14px;font-weight:700">+</button>
                
                <div style="border-left:2px solid #e5e7eb;height:32px;margin:0 6px"></div>
                
                <span style="font-size:13px;color:#6b7280;font-weight:500">Высоты:</span>
                <div class="height-buttons" id="heightButtons" style="display:flex;gap:4px;flex-wrap:wrap">
                    <?php
                    // Собираем уникальные высоты из данных
                    $heights = [];
                    foreach($pool as $list) {
                        foreach($list as $p) {
                            if(preg_match('/\[h(\d+)\]/', $p['label'], $m)) {
                                $heights[] = (int)$m[1];
                            }
                        }
                    }
                    $heights = array_unique($heights);
                    sort($heights);
                    
                    foreach($heights as $h):
                    ?>
                        <button class="height-btn" data-height="h<?=$h?>" style="font-size:12px;padding:4px 8px;min-width:40px">h<?=$h?></button>
                    <?php endforeach; ?>
                </div>
                <button class="btn" id="btnClearFilter" title="Очистить фильтр" style="padding:8px 12px;font-size:13px">✕</button>
            </div>
        </div>
    </div>
    

    <div class="panel" id="topPanel">
        <div class="head">
            <div><b>Полосы из раскроя</b> <span class="sub">клик → дата внизу (Shift+клик → последний день)</span></div>
            <div class="muted">
                <?php $cnt=0; foreach($pool as $list) $cnt+=count($list); echo $cnt; ?> полос
            </div>
        </div>
        <div class="gridTop" id="gridTop">
            <?php 
            // Группируем позиции по дням в столбцах (максимум 30 позиций на столбец)
            $maxItemsPerColumn = 30;
            $columns = [];
            $currentColumn = [];
            $currentColumnItems = 0;
            $currentDay = null;
            
            foreach($dates as $d): 
                if(empty($pool[$d])) continue;
                
                foreach($pool[$d] as $p): 
                    // Если текущий столбец заполнен, создаем новый
                    if($currentColumnItems >= $maxItemsPerColumn) {
                        $columns[] = $currentColumn;
                        $currentColumn = [];
                        $currentColumnItems = 0;
                        $currentDay = null;
                    }
                    
                    // Если день изменился, добавляем разделитель
                    if($currentDay !== $d) {
                        if($currentDay !== null && $currentColumnItems > 0) {
                            // Добавляем разделитель только если в столбце уже есть позиции
                            $currentColumn[] = [
                                'type' => 'separator',
                                'date' => $d
                            ];
                            $currentColumnItems++;
                        }
                        $currentDay = $d;
                    }
                    
                    // Добавляем позицию в текущий столбец
                    $currentColumn[] = [
                        'type' => 'pill',
                        'date' => $d,
                        'data' => $p
                    ];
                    $currentColumnItems++;
                endforeach;
            endforeach;
            
            // Добавляем последний столбец, если в нем есть данные
            if(!empty($currentColumn)) {
                $columns[] = $currentColumn;
            }
            
            // Если нет данных, создаем один пустой столбец
            if(empty($columns)) {
                $columns[] = [];
            }
            
            // Выводим столбцы
            foreach($columns as $columnIndex => $column): 
                // Находим первую дату в столбце
                $firstDate = null;
                if(!empty($column)) {
                    foreach($column as $item) {
                        if($item['type'] === 'pill' || $item['type'] === 'separator') {
                            $firstDate = $item['date'];
                            break;
                        }
                    }
                }
                ?>
                <div class="col topCol" data-column="<?=$columnIndex?>">
                    <h4>
                        <span><?= $firstDate ?: 'Пустой' ?></span>
                    </h4>

                    <?php if(empty($column)): ?>
                        <div class="muted">нет</div>
                    <?php else: 
                        $daysInColumn = [];
                        foreach($column as $item): 
                            if($item['type'] === 'separator'): 
                                if(!in_array($item['date'], $daysInColumn)) {
                                    $daysInColumn[] = $item['date'];
                                }
                                echo '<div class="day-separator">' . $item['date'] . '</div>';
                            else:
                                $d = $item['date'];
                                $p = $item['data'];
                                
                                // Собираем дни в столбце для заголовка
                                if(!in_array($d, $daysInColumn)) {
                                    $daysInColumn[] = $d;
                                }
                                
                                // Определяем статус выполнения
                                $factCount = $p['fact_count'] ?? 0;
                                $planCount = $p['plan_count'] ?? 0;
                                $pillClass = 'pill';
                                $tooltipExtra = '';
                                
                                if ($factCount > 0) {
                                    if ($factCount >= $planCount && $planCount > 0) {
                                        $pillClass .= ' pill-done';
                                        $tooltipExtra = ' · ✓ Выполнено: ' . $factCount . ' шт';
                                    } else {
                                        $pillClass .= ' pill-partial';
                                        $tooltipExtra = ' · ◐ Выполнено: ' . $factCount . ' из ' . $planCount . ' шт';
                                    }
                                }
                                
                                echo '<div class="' . $pillClass . '"';
                                echo ' title="' . htmlspecialchars($d . ' · Бухта #'.$p['bale_id'].' · Полоса №'.$p['strip_no'].' · '.($p['tip'] ?? '') . $tooltipExtra) . '"';
                                echo ' data-key="' . htmlspecialchars($p['key']) . '"';
                                echo ' data-cut-date="' . $d . '"';
                                echo ' data-bale-id="' . $p['bale_id'] . '"';
                                echo ' data-strip-no="' . $p['strip_no'] . '"';
                                echo ' data-filter-name="' . htmlspecialchars($p['filter']) . '"';
                                echo ' data-packs="' . (int)$p['packs'] . '">';
                                echo '<span>' . htmlspecialchars($p['label'] ?? '') . '</span>';
                                echo '</div>';
                            endif;
                        endforeach;
                    endif; ?>
                </div>
            <?php endforeach; ?>
        </div>


    </div>

    <!-- Плавающая панель с планом гофрирования -->
    <div class="floating-panel" id="planPanel">
        <div class="floating-panel-header" id="panelHeader">
            <div class="floating-panel-title">📋 План гофрирования</div>
            <div style="display:flex;gap:8px">
                <button class="floating-panel-btn" onclick="minimizePanel()">−</button>
            </div>
        </div>
        <div class="floating-panel-content">
            <div class="gridBot" id="planGrid"></div>
            <div class="sub" style="margin-top:6px;font-size:11px;color:#6b7280">
                Полоса добавляется один раз. Удалите внизу → вернется вверху.
            </div>
        </div>
    </div>
</div>

<div class="modalWrap" id="datePicker">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dpTitle">
        <div class="modalHeader">
            <div class="modalTitle" id="dpTitle">Выберите дату</div>
            <button class="modalClose" id="dpClose" title="Закрыть">×</button>
        </div>
        <div class="modalBody">
            <div class="daysGrid" id="dpDays"></div>
        </div>
    </div>
</div>

<script>
    const orderNumber = <?= json_encode($order) ?>;

    const plan = new Map();          // Map<date, Set<key>>
    const assigned = new Set();      // Set<key>
    const planGrid = document.getElementById('planGrid');
    const saveBtn  = document.getElementById('btnSave');
    const loadBtn  = document.getElementById('btnLoad');

    // Локальний ISO без UTC-зсуву
    const iso = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const parseISO = s => { const [y,m,da] = s.split('-').map(Number); return new Date(y, m-1, da); };
    const topGrid = document.querySelector('#topPanel .gridTop');
    const nextISO = ds => { const d = parseISO(ds); d.setDate(d.getDate()+1); return iso(d); };
    const previousISO = ds => { const d = parseISO(ds); d.setDate(d.getDate()-1); return iso(d); };

    function topEnsureDayCol(ds){
        // Ищем столбец, который содержит этот день
        let col = null;
        const allCols = topGrid.querySelectorAll('.topCol');
        
        for(let c of allCols) {
            const pills = c.querySelectorAll('.pill[data-cut-date="' + ds + '"]');
            if(pills.length > 0) {
                col = c;
                break;
            }
        }
        
        if (col) return col;

        // Если столбец не найден, создаем новый
        const colCount = topGrid.querySelectorAll('.topCol').length;
        col = document.createElement('div');
        col.className = 'col topCol';
        col.dataset.column = colCount;
        col.innerHTML = `
    <h4><span>Новый столбец</span></h4>
    <div class="muted">нет</div>
  `;
        topGrid.appendChild(col);
        return col;
    }

    function topSetEmptyState(col){
        const hasPill = !!col.querySelector('.pill');
        const ph = col.querySelector('.muted');
        if (!hasPill && !ph){
            const m = document.createElement('div'); m.className='muted'; m.textContent='нет'; col.appendChild(m);
        } else if (hasPill && ph){ ph.remove(); }
    }




    const cutDateByKey = new Map(); // key => 'YYYY-MM-DD'

    let lastPickedDay = null;

    const initialDays = <?= json_encode($dates, JSON_UNESCAPED_UNICODE) ?>;

    // Функция для обновления информации об активном дне
    function updateActiveDayInfo() {
        // Обновляем подсветку активного дня
        updateActiveDayHighlight();
    }

    function ensureDay(ds){ if(!plan.has(ds)) plan.set(ds, new Set()); }
    
    // Функция для добавления дня в визуальную таблицу плана
    function addDayToPlanGrid(dayStr) {
        // Проверяем, есть ли уже такой день
        if (planGrid.querySelector(`.col[data-day="${dayStr}"]`)) {
            return; // День уже существует
        }
        
        // Создаем новую колонку дня
        const col = document.createElement('div');
        col.className = 'col';
        col.dataset.day = dayStr;
        col.innerHTML = `
            <h4>
                <span class="day-date">${dayStr}</span>
                <span class="day-count" style="font-size:11px;color:#6b7280;font-weight:500">0 шт</span>
            </h4>
            <div class="dropzone"></div>
        `;
        
        // Добавляем в конец таблицы
        planGrid.appendChild(col);
        
        // Обновляем ширину грида
        const totalCols = planGrid.querySelectorAll('.col').length;
        planGrid.style.gridTemplateColumns = `repeat(${Math.max(1, totalCols)}, minmax(153px, 1fr))`;
        
        // Убеждаемся, что день есть в плане данных
        ensureDay(dayStr);
        
        // Инициализируем drag-and-drop только для новой dropzone
        const newDropzone = col.querySelector('.dropzone');
        if (newDropzone) {
            // Небольшая задержка для гарантии, что элемент полностью добавлен в DOM
            setTimeout(() => {
                initSingleDropzone(newDropzone);
                console.log('Initialized dropzone for day:', dayStr);
            }, 10);
        }
        
        // Также убеждаемся, что делегирование событий работает
        ensureEventDelegation();
        
        // Показываем уведомление
        showNotification(`День ${dayStr} добавлен в план`);
        console.log(`День ${dayStr} добавлен в план`);
    }
    // Функция для показа уведомления
    function showNotification(message) {
        // Создаем элемент уведомления
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        `;
        notification.textContent = message;
        
        // Добавляем в DOM
        document.body.appendChild(notification);
        
        // Анимация появления
        setTimeout(() => {
            notification.style.opacity = '1';
            notification.style.transform = 'translateX(0)';
        }, 10);
        
        // Автоматически убираем через 3 секунды
        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 3000);
    }

    function refreshSaveState(){
        let has=false; plan.forEach(set=>{ if(set.size) has=true; });
        saveBtn.disabled = !has;
    }
    function setPillDisabledByKey(key, disabled){
        // Ключ может быть в формате bale_id:strip_no:hash или bale_id:strip_no
        // Ищем все плашки, которые начинаются с bale_id:strip_no
        const parts = key.split(':');
        if (parts.length >= 2) {
            const baseKey = parts[0] + ':' + parts[1];
            document.querySelectorAll(`.pill[data-key^="${baseKey}:"]`).forEach(el=>{
                el.classList.toggle('pill-disabled', !!disabled);
            });
            // Также проверяем старый формат для обратной совместимости
            document.querySelectorAll(`.pill[data-key="${baseKey}"]`).forEach(el=>{
                el.classList.toggle('pill-disabled', !!disabled);
            });
        } else {
            // Старый формат без hash
            document.querySelectorAll(`.pill[data-key="${key}"]`).forEach(el=>{
                el.classList.toggle('pill-disabled', !!disabled);
            });
        }
    }
    function getAllDays(){
        return [...planGrid.querySelectorAll('.col[data-day]')].map(c=>c.dataset.day);
    }

    // Функция для получения всех дней между первым и последним днем заявки
    function getAllDaysInRange(){
        if (initialDays.length === 0) return [];
        
        // Получаем все дни из заявки
        const firstDay = initialDays[0];
        const lastDay = initialDays[initialDays.length - 1];
        
        // Получаем все дни, которые уже добавлены в план
        const existingDays = getAllDays();
        
        // Создаем массив всех дней между первым и последним днем заявки
        const allDays = [];
        const startDate = parseISO(firstDay);
        const endDate = parseISO(lastDay);
        
        for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
            allDays.push(iso(d));
        }
        
        // Добавляем все дни, которые были добавлены вручную и выходят за рамки заявки
        existingDays.forEach(day => {
            if (!allDays.includes(day)) {
                allDays.push(day);
            }
        });
        
        // Сортируем по дате
        allDays.sort();
        
        return allDays;
    }
    function dayCount(ds){ return plan.has(ds) ? plan.get(ds).size : 0; }


    function dayPacks(ds){
        const col = getPlanCol(ds);
        if (!col) return 0;
        let sum = 0;
        col.querySelectorAll('.dropzone .rowItem').forEach(r=>{
            const pk = parseInt(r.dataset.packs||'0',10);
            if (!isNaN(pk)) sum += pk;
        });
        return sum;
    }
    
    // Обновление счетчика в заголовке дня
    function updateDayCount(ds) {
        const col = getPlanCol(ds);
        if (!col) {
            console.log('updateDayCount: col not found for', ds);
            return;
        }
        const countEl = col.querySelector('.day-count');
        if (countEl) {
            const total = dayPacks(ds);
            countEl.textContent = total + ' шт';
            console.log('updateDayCount:', ds, 'total:', total);
        } else {
            console.log('updateDayCount: .day-count element not found in col for', ds);
        }
    }
    
    // Обновление подсветки активного дня
    function updateActiveDayHighlight() {
        // Убираем подсветку со всех дней
        planGrid.querySelectorAll('.col').forEach(col => {
            col.classList.remove('active-day');
        });
        
        // Подсвечиваем активный день
        if (lastPickedDay) {
            const activeCol = getPlanCol(lastPickedDay);
            if (activeCol) {
                activeCol.classList.add('active-day');
            }
        }
    }


    function updateMoveButtons(row){
        const days = getAllDays();
        const idx  = days.indexOf(row.dataset.day);
        const leftBtn  = row.querySelector('.mv-left');
        const rightBtn = row.querySelector('.mv-right');
        if(leftBtn)  leftBtn.disabled  = (idx <= 0);
        if(rightBtn) rightBtn.disabled = (idx >= days.length - 1);
    }

    function moveRow(row, dir){
        const days = getAllDays();
        const cur  = row.dataset.day;
        const idx  = days.indexOf(cur);
        const next = idx + dir;
        if (next < 0 || next >= days.length) return;

        const newDay  = days[next];
        const key     = row.dataset.key;
        const cutDate = row.dataset.cutDate || cutDateByKey.get(key) || '';  // ← додано

        if (cutDate && newDay < cutDate) {
            alert(`Нельзя переносить раньше раскроя: ${cutDate}`);
            return;
        }

        ensureDay(newDay);
        const newSet = plan.get(newDay);
        if (newSet.has(key)) { alert('У цьому дні вже є ця полоса.'); return; }

        const oldSet = plan.get(cur);
        if (oldSet) oldSet.delete(key);
        newSet.add(key);

        const dzNew = planGrid.querySelector(`.col[data-day="${newDay}"] .dropzone`);
        if (!dzNew) return;
        dzNew.appendChild(row);
        row.dataset.day = newDay;

        recalcDayTotal(cur);
        recalcDayTotal(newDay);
        updateMoveButtons(row);
        lastPickedDay = newDay;
        updateActiveDayInfo();
        applyHeightFilter(); // Применяем фильтр после перемещения
    }



    /* фабрика створення картки рядка з кнопками ⟵ ⟶ */
    function createRow({key,targetDay,packs,filter,labelTxt,cutDate}){
        const row = document.createElement('div');
        row.className = 'rowItem';
        row.dataset.key      = key;
        row.dataset.day      = targetDay;
        row.dataset.packs    = String(packs);
        row.dataset.filter   = filter;
        row.dataset.cutDate  = cutDate || cutDateByKey.get(key) || '';  // ← зберегли

        // Сокращаем только название фильтра до 9 символов
        const filterName = filter || 'Без имени';
        const shortFilterName = filterName.length > 9 ? filterName.substring(0, 9) + '...' : filterName;
        const shortLabel = `${shortFilterName} [h${labelTxt.match(/\[h(\d+)\]/)?.[1] || '?'}] [${packs} шт]`;
        
        row.innerHTML = `
    <div class="row-content">
      <span title="${labelTxt}">${shortLabel}</span>
    </div>
    <div class="controls">
      <button class="rm" title="Убрать" aria-label="Видалити">×</button>
    </div>
  `;

        row.querySelector('.rm').onclick = ()=>{
            const set = plan.get(row.dataset.day);
            if(set) set.delete(key);
            row.remove();
            assigned.delete(key);
            setPillDisabledByKey(key,false);
            refreshSaveState();
            recalcDayTotal(row.dataset.day);
            applyHeightFilter(); // Применяем фильтр после удаления
        };

        // Делаем строку перетаскиваемой
        initRowDragging(row, key);

        return row;
    }

    function initRowDragging(row, key) {
        // Проверяем, есть ли уже обработчики
        if (row.hasAttribute('data-drag-initialized')) {
            return;
        }
        
        row.setAttribute('data-drag-initialized', 'true');
        row.draggable = true;
        
        row.addEventListener('dragstart', (e) => {
            console.log('Drag start:', key);
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', key);
        });
        
        row.addEventListener('dragend', (e) => {
            row.classList.remove('dragging');
        });
    }

    function renderPlanGrid(days){
        plan.clear(); assigned.clear();
        document.querySelectorAll('.pill').forEach(p=>p.classList.remove('pill-disabled'));
        lastPickedDay = null;
        updateActiveDayInfo();

        // Очищаем атрибуты инициализации
        document.querySelectorAll('.dropzone').forEach(dz => dz.removeAttribute('data-dropzone-initialized'));
        document.querySelectorAll('.rowItem').forEach(row => row.removeAttribute('data-drag-initialized'));
        
        planGrid.innerHTML = '';
        const frag = document.createDocumentFragment();
        days.forEach(ds=>{
            ensureDay(ds);
            const col = document.createElement('div');
            col.className = 'col';
            col.dataset.day = ds;
            col.innerHTML = `
                <h4>
                    <span class="day-date">${ds}</span>
                    <span class="day-count" style="font-size:11px;color:#6b7280;font-weight:500">0 шт</span>
                </h4>
                <div class="dropzone"></div>
            `;
            frag.appendChild(col);
        });
        planGrid.appendChild(frag);
        planGrid.style.gridTemplateColumns = `repeat(${Math.max(1, days.length)}, minmax(153px, 1fr))`;
        initDropzones(); // Инициализируем drag-and-drop
        ensureEventDelegation(); // Инициализируем делегирование событий
        refreshSaveState();
    }
    
    function initSingleDropzone(dropzone) {
        // Проверяем, есть ли уже обработчики
        if (dropzone.hasAttribute('data-dropzone-initialized')) {
            console.log('Dropzone already initialized, skipping');
            return;
        }
        
        console.log('Initializing dropzone:', dropzone);
        dropzone.setAttribute('data-dropzone-initialized', 'true');
        
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            dropzone.classList.add('drag-over');
            console.log('Dragover on:', dropzone.closest('.col').dataset.day);
        });
        
        dropzone.addEventListener('dragleave', (e) => {
            dropzone.classList.remove('drag-over');
            console.log('Dragleave from:', dropzone.closest('.col').dataset.day);
        });
        
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('drag-over');
            
            const key = e.dataTransfer.getData('text/plain');
            console.log('Drop event:', key, 'on day:', dropzone.closest('.col').dataset.day);
            const draggedRow = document.querySelector(`.rowItem[data-key="${key}"]`);
            
            if (!draggedRow) {
                console.log('Dragged row not found for key:', key);
                return;
            }
            
            const targetCol = dropzone.closest('.col');
            const newDay = targetCol.dataset.day;
            const oldDay = draggedRow.dataset.day;
            const cutDate = draggedRow.dataset.cutDate || '';
            
            // Проверка даты раскроя
            if (cutDate && newDay < cutDate) {
                alert(`Нельзя переносить раньше раскроя: ${cutDate}`);
                return;
            }
            
            // Проверяем, не переносим ли в тот же день
            if (newDay === oldDay) {
                console.log('Same day, no need to move');
                return;
            }
            
            // Проверка дубликата (только если переносим в другой день)
            const newSet = plan.get(newDay);
            if (newSet && newSet.has(key)) {
                alert('У цьому дні вже є ця полоса.');
                return;
            }
            
            // Перемещаем строку
            const oldSet = plan.get(oldDay);
            if (oldSet) oldSet.delete(key);
            newSet.add(key);
            
            dropzone.appendChild(draggedRow);
            draggedRow.dataset.day = newDay;
            
            recalcDayTotal(oldDay);
            recalcDayTotal(newDay);
            lastPickedDay = newDay;
            updateActiveDayInfo();
            applyHeightFilter();
        });
    }

    function ensureEventDelegation() {
        // Проверяем, есть ли уже делегирование событий
        if (planGrid.hasAttribute('data-delegation-initialized')) {
            return;
        }
        
        planGrid.setAttribute('data-delegation-initialized', 'true');
        
        // Делегирование событий на уровне planGrid
        planGrid.addEventListener('dragover', (e) => {
            const dropzone = e.target.closest('.dropzone');
            if (dropzone) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                dropzone.classList.add('drag-over');
                console.log('Delegated dragover on:', dropzone.closest('.col').dataset.day);
            }
        });
        
        planGrid.addEventListener('dragleave', (e) => {
            const dropzone = e.target.closest('.dropzone');
            if (dropzone && !dropzone.contains(e.relatedTarget)) {
                dropzone.classList.remove('drag-over');
                console.log('Delegated dragleave from:', dropzone.closest('.col').dataset.day);
            }
        });
        
        planGrid.addEventListener('drop', (e) => {
            const dropzone = e.target.closest('.dropzone');
            if (dropzone) {
                e.preventDefault();
                dropzone.classList.remove('drag-over');
                
                const key = e.dataTransfer.getData('text/plain');
                console.log('Delegated drop event:', key, 'on day:', dropzone.closest('.col').dataset.day);
                
                const draggedRow = document.querySelector(`.rowItem[data-key="${key}"]`);
                if (!draggedRow) {
                    console.log('Dragged row not found for key:', key);
                    return;
                }
                
                const targetCol = dropzone.closest('.col');
                const newDay = targetCol.dataset.day;
                const oldDay = draggedRow.dataset.day;
                const cutDate = draggedRow.dataset.cutDate || '';
                
                // Проверка даты раскроя
                if (cutDate && newDay < cutDate) {
                    alert(`Нельзя переносить раньше раскроя: ${cutDate}`);
                    return;
                }
                
                // Проверяем, не переносим ли в тот же день
                if (newDay === oldDay) {
                    console.log('Same day, no need to move');
                    return;
                }
                
                // Проверка дубликата (только если переносим в другой день)
                const newSet = plan.get(newDay);
                if (newSet && newSet.has(key)) {
                    alert('У цьому дні вже є ця полоса.');
                    return;
                }
                
                // Перемещаем строку
                const oldSet = plan.get(oldDay);
                if (oldSet) oldSet.delete(key);
                newSet.add(key);
                
                dropzone.appendChild(draggedRow);
                draggedRow.dataset.day = newDay;
                
                recalcDayTotal(oldDay);
                recalcDayTotal(newDay);
                lastPickedDay = newDay;
                updateActiveDayInfo();
                applyHeightFilter();
            }
        });
        
        console.log('Event delegation initialized on planGrid');
    }

    function initDropzones() {
        document.querySelectorAll('.dropzone').forEach(dropzone => {
            initSingleDropzone(dropzone);
        });
    }
    
    function getPlanCol(ds){
        return planGrid.querySelector(`.col[data-day="${ds}"]`);
    }
    function recalcDayTotal(ds){
        // Обновляем счетчик в заголовке дня
        updateDayCount(ds);
        
        // Обновляем подсветку активного дня, если это текущий активный день
        if (ds === lastPickedDay) {
            updateActiveDayInfo();
        }
    }

    function addToPlan(targetDay, pillEl){
        const key      = pillEl.dataset.key;
        const packs    = parseInt(pillEl.dataset.packs||'0',10);
        const filter   = pillEl.dataset.filterName || '';
        const labelTxt = pillEl.querySelector('span')?.textContent || pillEl.textContent;
        const cutDate  = pillEl.dataset.cutDate || cutDateByKey.get(key) || '';

        // ЗАБОРОНА: не раніше розкрою
        if (cutDate && targetDay < cutDate) {
            alert(`Нельзя назначать раньше раскроя: ${cutDate}`);
            return;
        }


        ensureDay(targetDay);
        const set = plan.get(targetDay);
        if (set.has(key)) return;

        let dz = planGrid.querySelector(`.col[data-day="${targetDay}"] .dropzone`);
        if(!dz){ 
            // Автоматически добавляем день в план
            addDayToPlanGrid(targetDay);
            dz = planGrid.querySelector(`.col[data-day="${targetDay}"] .dropzone`);
            if (!dz) return; // На всякий случай проверяем еще раз
        }

        const row = createRow({
            key,
            targetDay,
            packs,
            filter,
            labelTxt
        });
        dz.appendChild(row);


        set.add(key);
        assigned.add(key);
        setPillDisabledByKey(key,true);
        refreshSaveState();
        lastPickedDay = targetDay;
        recalcDayTotal(targetDay);
        updateActiveDayInfo();
        applyHeightFilter(); // Применяем фильтр к новой строке
    }

    // Модалка выбора даты
    const dpWrap = document.getElementById('datePicker');
    const dpDays = document.getElementById('dpDays');
    const dpClose= document.getElementById('dpClose');
    let pendingPill = null;

    function openDatePicker(pillEl){
        pendingPill = pillEl;
        dpDays.innerHTML = '';
        const days = getAllDaysInRange();
        if (!days.length){ alert('Нет дат для заявки.'); return; }

        const cutDate = pillEl.dataset.cutDate; // 'YYYY-MM-DD'

        days.forEach(ds=>{
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dayBtn';

            const lines = dayCount(ds);
            const packs = dayPacks(ds);

            btn.innerHTML = `
      <div class="dayHead">${ds}</div>
      <div class="daySub">Назначено полос: ${lines}</div>
      <div class="daySub">Гофропакетів: ${packs} шт</div>
    `;

            if (cutDate && ds < cutDate) {
                btn.disabled = true;        // раніше розкрою — забороняємо
            } else {
                btn.onclick = ()=>{ addToPlan(ds, pendingPill); closeDatePicker(); };
            }

            if (ds === lastPickedDay) btn.style.outline = '2px solid #2563eb';
            dpDays.appendChild(btn);
        });

        dpWrap.style.display = 'flex';
        setTimeout(()=>{ const first = dpDays.querySelector('.dayBtn:not(:disabled)'); if(first) first.focus(); },0);
    }





    function closeDatePicker(){ dpWrap.style.display = 'none'; pendingPill = null; }
    dpClose.addEventListener('click', closeDatePicker);
    dpWrap.addEventListener('click', (e)=>{ if(e.target===dpWrap) closeDatePicker(); });
    document.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && dpWrap.style.display==='flex') closeDatePicker(); });

    document.querySelectorAll('.pill').forEach(p=>{
        // Сохраняем cutDate по baseKey (bale_id:strip_no) для всех вариантов filter
        const key = p.dataset.key || '';
        const parts = key.split(':');
        if (parts.length >= 2) {
            const baseKey = parts[0] + ':' + parts[1];
            cutDateByKey.set(baseKey, p.dataset.cutDate);
            // Также сохраняем по полному ключу для обратной совместимости
            cutDateByKey.set(key, p.dataset.cutDate);
        } else {
            cutDateByKey.set(key, p.dataset.cutDate);
        }
        p.addEventListener('click', (e)=>{
            if (e.shiftKey && lastPickedDay){ addToPlan(lastPickedDay, p); return; }
            openDatePicker(p);
        });
    });

    // Кнопки дней
    const btnBuildDays = document.getElementById('btnBuildDays');
    const rngStart     = document.getElementById('rngStart');
    const rngDays      = document.getElementById('rngDays');
    const btnAddDay    = document.getElementById('btnAddDay');
    const heightButtons = document.querySelectorAll('.height-btn');
    const btnClearFilter = document.getElementById('btnClearFilter');

    (function initDates(){
        const today = new Date(); const ds = today.toISOString().slice(0,10);
        rngStart.value = ds;
        renderPlanGrid(initialDays.length ? initialDays : [ds]);
    })();

    btnBuildDays.addEventListener('click', ()=>{
        const start = rngStart.value;
        const n = parseInt(rngDays.value||'0',10);
        if(!start || isNaN(n) || n<=0){ alert('Укажите корректный диапазон дат.'); return; }
        const out = [];
        const d0 = parseISO(start);
        for(let i=0;i<n;i++){ const d=new Date(d0); d.setDate(d0.getDate()+i); out.push(iso(d)); }
        renderPlanGrid(out);
    });

    // Добавление одного дня
    btnAddDay.addEventListener('click', ()=>{
        // 1) Визначаємо, який день додати
        const daysNow = getAllDays();
        let newDs;
        if (daysNow.length) {
            // якщо є дні — беремо останній і додаємо +1
            const last = daysNow[daysNow.length - 1];
            const nd = parseISO(last); 
            nd.setDate(nd.getDate() + 1);
            newDs = iso(nd);
        } else {
            // якщо таблиця порожня — стартуємо з rngStart або сьогодні
            const base = (rngStart.value || iso(new Date()));
            newDs = base;
        }

        // 3) Додаємо колонку дня в кінець
        ensureDay(newDs);
        const col = document.createElement('div');
        col.className = 'col';
        col.dataset.day = newDs;
        col.innerHTML = `
    <h4>
        <span class="day-date">${newDs}</span>
        <span class="day-count" style="font-size:11px;color:#6b7280;font-weight:500">0 шт</span>
    </h4>
    <div class="dropzone"></div>
  `;
        planGrid.appendChild(col);

        // 4) Оновлюємо ширину гріда
        const total = daysNow.length + 1;
        planGrid.style.gridTemplateColumns = `repeat(${Math.max(1, total)}, minmax(153px, 1fr))`;
        
        // 5) Инициализируем dropzone для нового дня
        const newDropzone = col.querySelector('.dropzone');
        if (newDropzone) {
            setTimeout(() => {
                initSingleDropzone(newDropzone);
                console.log('Initialized dropzone for new day:', newDs);
            }, 10);
        }
        ensureEventDelegation();
    });

    // Функциональность фильтра высот
    let selectedHeights = new Set();

    function applyHeightFilter() {
        // Убираем подсветку со всех позиций (верхняя и нижняя таблицы)
        document.querySelectorAll('.pill.highlighted, .rowItem.highlighted').forEach(el => {
            el.classList.remove('highlighted');
        });

        if (selectedHeights.size === 0) {
            return; // Если ничего не выбрано, просто убираем подсветку
        }

        // Подсвечиваем позиции в верхней таблице
        document.querySelectorAll('.pill').forEach(pill => {
            const pillText = pill.textContent.toLowerCase();
            const hasSelectedHeight = Array.from(selectedHeights).some(height => 
                pillText.includes(height.toLowerCase())
            );
            
            if (hasSelectedHeight) {
                pill.classList.add('highlighted');
            }
        });

        // Подсвечиваем строки в нижней таблице
        document.querySelectorAll('.rowItem').forEach(row => {
            const rowText = row.textContent.toLowerCase();
            const hasSelectedHeight = Array.from(selectedHeights).some(height => 
                rowText.includes(height.toLowerCase())
            );
            
            if (hasSelectedHeight) {
                row.classList.add('highlighted');
            }
        });
    }

    function toggleHeightFilter(height) {
        if (selectedHeights.has(height)) {
            selectedHeights.delete(height);
        } else {
            selectedHeights.add(height);
        }
        
        // Обновляем состояние кнопки
        const button = document.querySelector(`[data-height="${height}"]`);
        button.classList.toggle('active', selectedHeights.has(height));
        
        applyHeightFilter();
    }

    function clearHeightFilter() {
        selectedHeights.clear();
        heightButtons.forEach(btn => btn.classList.remove('active'));
        applyHeightFilter();
    }

    // Обработчики событий для кнопок высот
    heightButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const height = btn.dataset.height;
            toggleHeightFilter(height);
        });
    });
    
    btnClearFilter.addEventListener('click', clearHeightFilter);


    // Сохранение
    function buildPayload(){
        const items = [];
        document.querySelectorAll('.dropzone .rowItem').forEach(row=>{
            const key    = row.dataset.key || '';
            const packs  = parseInt(row.dataset.packs||'0',10);
            const filter = row.dataset.filter || '';
            const day    = row.dataset.day || '';
            if(!key || !day) return;
            // Ключ может быть в формате bale_id:strip_no или bale_id:strip_no:hash
            // Извлекаем bale_id и strip_no (первые два элемента)
            const parts = key.split(':');
            const bale_id = parseInt(parts[0], 10);
            const strip_no = parseInt(parts[1], 10);
            if(!bale_id || !strip_no) return;
            items.push({ date: day, bale_id, strip_no, filter, count: packs });
        });
        return { order: orderNumber, items };
    }

    saveBtn.addEventListener('click', async ()=>{
        try{
            const payload = buildPayload();
            const res = await fetch('NP/save_corrugation_plan.php', { // <-- путь, если файл лежит в папке NP
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify(payload)
            });
            let data;
            try { data = await res.json(); }
            catch { const t = await res.text(); throw new Error('Backend не JSON:\n'+t.slice(0,500)); }
            if(!data.ok) throw new Error(data.error||'Ошибка сохранения');
            alert('План сохранён.');
        }catch(e){ alert('Не удалось сохранить: '+e.message); }
    });


    // Загрузка
    // Загрузка
    loadBtn.addEventListener('click', async ()=>{
        const uniqSortedDates = arr => Array.from(new Set(arr.filter(Boolean))).sort();

        try{
            const res = await fetch('NP/save_corrugation_plan.php?order='+encodeURIComponent(orderNumber));
            let data;
            try { data = await res.json(); }
            catch { const t = await res.text(); throw new Error('Backend не JSON:\n'+t.slice(0,500)); }
            if(!data.ok) throw new Error(data.error||'Ошибка загрузки');

            // 1) Зібрати всі дати з бекенда: з data.days і з самих items
            const itemDays = uniqSortedDates((data.items||[]).map(it=>it.date));
            const apiDays  = uniqSortedDates([...(data.days||[]), ...itemDays]);

            // 2) Якщо бекенд нічого не дав — fallback на initialDays
            const days = apiDays.length ? apiDays : (initialDays.length ? initialDays : []);
            renderPlanGrid(days);

            // 3) Розкласти елементи по днях
// 3) Розкласти елементи по днях
            (data.items||[]).forEach(it=>{
                // Ищем плашку по bale_id, strip_no и filter (используем атрибуты для поиска)
                const pill = Array.from(document.querySelectorAll('.pill')).find(p => 
                    p.dataset.baleId == it.bale_id && 
                    p.dataset.stripNo == it.strip_no && 
                    p.dataset.filterName === it.filter
                );

                if (pill) {
                    addToPlan(it.date, pill);
                } else {
                    ensureDay(it.date);
                    const dz = document.querySelector(`.col[data-day="${it.date}"] .dropzone`);
                    if (!dz) return;

                    const label   = (it.filter||'Без имени') + ' ['+(it.count||0)+' шт]';
                    // Используем baseKey для cutDateByKey (там хранятся старые ключи)
                    const baseKey = String(it.bale_id)+':'+String(it.strip_no);
                    const cutDate = cutDateByKey.get(baseKey) || '';
                    // Создаем ключ с filter для уникальности (заменяем двоеточия в filter)
                    const filterSafe = (it.filter || '').replace(/:/g, '_');
                    const key = baseKey + ':' + filterSafe;

                    const row = createRow({
                        key,
                        targetDay: it.date,
                        packs: (it.count||0),
                        filter: (it.filter||''),
                        labelTxt: label,
                        cutDate                          // ← передали явно
                    });
                    dz.appendChild(row);

                    const set = plan.get(it.date); set.add(key);
                    assigned.add(key);
                    setPillDisabledByKey(key,true);
                }
            });


            // 4) Підрахувати підсумки по кожному дню та розблокувати "Сохранить"
            getAllDays().forEach(ds=>recalcDayTotal(ds));
            refreshSaveState();
            applyHeightFilter(); // Применяем фильтр к загруженным данным
            alert('План загружен.');
        }catch(e){
            alert('Не удалось загрузить: '+e.message);
        }
    });




    // Инициализация
    (function init(){
        const today = new Date(); const ds = iso(today);
        document.getElementById('rngStart').value = ds;
        renderPlanGrid(initialDays.length ? initialDays : [ds]);
        updateActiveDayInfo();
    })();

    function cascadeShiftFrom(ds){
        const s = prompt(`На скільки днів зсунути всі дні ВІД ${ds} (включно)?\nДодатне число — вперед, від’ємне — назад.`, '1');
        if (s === null) return;
        const delta = parseInt(s, 10);
        if (!Number.isFinite(delta) || delta === 0) { alert('Нічого не змінено'); return; }

        fetch('NP/shift_roll_plan_days.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ order: orderNumber, start_date: ds, delta })
        })
            .then(async r => {
                let j; try { j = await r.json(); }
                catch { throw new Error('Backend не JSON'); }
                if (!j.ok) throw new Error(j.error || 'Помилка');
                alert(`Оновлено записів: ${j.affected}. Перезавантажую сторінку...`);
                location.reload();
            })
            .catch(e => alert('Не вдалося зсунути: ' + e.message));
    }

    // прив’язка до кнопок у верхній таблиці
    document.querySelectorAll('.topCascade').forEach(btn=>{
        btn.onclick = ()=> cascadeShiftFrom(btn.dataset.day);
    });

    // ========== Функционал плавающей панели ==========
    function minimizePanel() {
        const panel = document.getElementById('planPanel');
        panel.classList.toggle('minimized');
        const btn = event.target;
        btn.textContent = panel.classList.contains('minimized') ? '+' : '−';
    }

    // Перетаскивание панели
    (function() {
        const panel = document.getElementById('planPanel');
        const header = document.getElementById('panelHeader');
        let isDragging = false;
        let startX, startY, startLeft, startTop;

        header.addEventListener('mousedown', (e) => {
            if (e.target.tagName === 'BUTTON') return;
            isDragging = true;
            
            // Получаем текущую позицию с учетом transform
            const rect = panel.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            
            // Убираем центрирование при первом перетаскивании
            panel.style.transform = 'none';
            panel.style.left = startLeft + 'px';
            panel.style.top = startTop + 'px';
            panel.style.transition = 'none';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            e.preventDefault();
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            
            panel.style.left = (startLeft + dx) + 'px';
            panel.style.top = (startTop + dy) + 'px';
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                panel.style.transition = '';
            }
        });
    })();

</script>
