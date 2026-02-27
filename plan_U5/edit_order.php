<?php
// edit_order.php — редактирование существующей заявки
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit_logger.php';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
$auditLogger = new AuditLogger($mysqli);

// API: сохранить изменения заявки
if (($_GET['action'] ?? '') === 'save_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Пустое тело']); exit; }

    $order_number = trim((string)($payload['order_number'] ?? ''));
    $items = $payload['items'] ?? [];
    $deleted_items = $payload['deleted_items'] ?? [];

    if ($order_number==='') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Укажите имя заявки']); exit; }

    $pdo->beginTransaction();
    try {
        // Удаляем удаленные позиции
        if (!empty($deleted_items)) {
            foreach ($deleted_items as $del_item) {
                $filter = trim((string)($del_item['filter'] ?? ''));
                $count = (int)($del_item['count'] ?? 0);
                $recId = $order_number . '|' . ($filter ?: '(пусто)') . '|' . $count;
                $oldVals = ['order_number' => $order_number, 'filter' => $filter ?: null, 'count' => $count];

                if ($filter !== '') {
                    $delStmt = $pdo->prepare("DELETE FROM orders WHERE order_number = ? AND `filter` = ? AND `count` = ? LIMIT 1");
                    $delStmt->execute([$order_number, $filter, $count]);
                } else {
                    $delStmt = $pdo->prepare("DELETE FROM orders WHERE order_number = ? AND (`filter` IS NULL OR `filter` = '') AND `count` = ? LIMIT 1");
                    $delStmt->execute([$order_number, $count]);
                }
                $auditLogger->logDelete('orders', $recId, $oldVals, 'edit_order: удалена позиция заявки', null);
            }
        }

        // Обновляем/добавляем позиции
        foreach ($items as $it) {
            $filter = trim((string)($it['filter'] ?? ''));
            $count  = (int)($it['count'] ?? 0);
            if ($filter==='' || $count<=0) continue;

            $marking            = trim((string)($it['marking'] ?? '')) ?: null;
            $personal_packaging = trim((string)($it['personal_packaging'] ?? '')) ?: null;
            $personal_label     = trim((string)($it['personal_label'] ?? '')) ?: null;
            $group_packaging    = trim((string)($it['group_packaging'] ?? '')) ?: null;
            $packaging_rate     = isset($it['packaging_rate']) && $it['packaging_rate']!=='' ? (int)$it['packaging_rate'] : null;
            $group_label        = trim((string)($it['group_label'] ?? '')) ?: null;
            $remark             = trim((string)($it['remark'] ?? '')) ?: null;
            $is_new             = (bool)($it['is_new'] ?? false);

            if ($is_new) {
                // Добавляем новую позицию
                $workshopStmt = $pdo->prepare("SELECT workshop FROM orders WHERE order_number = ? LIMIT 1");
                $workshopStmt->execute([$order_number]);
                $workshop = $workshopStmt->fetchColumn() ?: 'U5';

                $ins = $pdo->prepare("INSERT INTO orders (
                    order_number, workshop, `filter`, `count`, marking,
                    personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark,
                    hide, cut_ready, cut_confirmed, plan_ready, corr_ready, build_ready
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    0,0,0,0,0,0
                )");
                $ins->execute([
                    $order_number, $workshop, $filter, $count, $marking,
                    $personal_packaging, $personal_label, $group_packaging, $packaging_rate, $group_label, $remark
                ]);
                $recId = $order_number . '|' . $filter . '|' . $count;
                $newVals = ['order_number' => $order_number, 'workshop' => $workshop, 'filter' => $filter, 'count' => $count, 'marking' => $marking, 'personal_packaging' => $personal_packaging, 'personal_label' => $personal_label, 'group_packaging' => $group_packaging, 'packaging_rate' => $packaging_rate, 'group_label' => $group_label, 'remark' => $remark];
                $auditLogger->logInsert('orders', $recId, $newVals, 'edit_order: добавлена позиция заявки', null);
            } else {
                // Обновляем существующую позицию (только если есть реальные изменения)
                $old_filter = trim((string)($it['old_filter'] ?? $filter));
                $old_count  = (int)($it['old_count'] ?? $count);

                // Читаем актуальное состояние строки из БД
                $selectStmt = $pdo->prepare("
                    SELECT `filter`, `count`, marking,
                           personal_packaging, personal_label, group_packaging,
                           packaging_rate, group_label, remark
                    FROM orders
                    WHERE order_number = ? AND `filter` = ? AND `count` = ?
                    LIMIT 1
                ");
                $selectStmt->execute([$order_number, $old_filter, $old_count]);
                $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    // Нет такой строки — ничего не обновляем и не логируем, чтобы не плодить мусор
                    continue;
                }

                $oldVals = [
                    'filter'             => $existing['filter'],
                    'count'              => (int)$existing['count'],
                    'marking'            => $existing['marking'],
                    'personal_packaging' => $existing['personal_packaging'],
                    'personal_label'     => $existing['personal_label'],
                    'group_packaging'    => $existing['group_packaging'],
                    'packaging_rate'     => $existing['packaging_rate'] === null ? null : (int)$existing['packaging_rate'],
                    'group_label'        => $existing['group_label'],
                    'remark'             => $existing['remark'],
                ];

                $newVals = [
                    'filter'             => $filter,
                    'count'              => $count,
                    'marking'            => $marking,
                    'personal_packaging' => $personal_packaging,
                    'personal_label'     => $personal_label,
                    'group_packaging'    => $group_packaging,
                    'packaging_rate'     => $packaging_rate,
                    'group_label'        => $group_label,
                    'remark'             => $remark,
                ];

                $changed = [];
                foreach (['filter','count','marking','personal_packaging','personal_label','group_packaging','packaging_rate','group_label','remark'] as $f) {
                    if (($oldVals[$f] ?? null) !== ($newVals[$f] ?? null)) {
                        $changed[] = $f;
                    }
                }

                // Если ничего не изменилось — пропускаем UPDATE и лог
                if (!$changed) {
                    continue;
                }

                $upd = $pdo->prepare("UPDATE orders SET 
                    `filter` = ?, `count` = ?, marking = ?,
                    personal_packaging = ?, personal_label = ?, group_packaging = ?, 
                    packaging_rate = ?, group_label = ?, remark = ?
                    WHERE order_number = ? AND `filter` = ? AND `count` = ?
                    LIMIT 1
                ");
                $upd->execute([
                    $filter, $count, $marking,
                    $personal_packaging, $personal_label, $group_packaging,
                    $packaging_rate, $group_label, $remark,
                    $order_number, $old_filter, $old_count
                ]);

                $recId = $order_number . '|' . $old_filter . '|' . $old_count;
                $auditLogger->logUpdate('orders', $recId, $oldVals, $newVals, $changed, 'edit_order: обновлена позиция заявки', null);
            }
        }
        $pdo->commit();
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// Получаем номер заявки из параметров
$order_number = $_GET['order'] ?? '';

// Если номер заявки не указан, показываем список заявок для выбора
if ($order_number === '') {
    $orders = $pdo->query("
        SELECT DISTINCT order_number 
        FROM orders 
        WHERE COALESCE(hide, 0) != 1 
        ORDER BY order_number
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Выбор заявки для редактирования</title>
        <style>
            :root{
                --bg:#f6f7f9;
                --panel:#ffffff;
                --ink:#1e293b;
                --muted:#64748b;
                --border:#e2e8f0;
                --accent:#667eea;
                --radius:14px;
                --shadow:0 10px 25px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.06);
            }
            body{
                margin:0; background:var(--bg); color:var(--ink);
                font: 16px/1.6 "Inter","Segoe UI", Arial, sans-serif;
                padding:20px;
            }
            .container{ max-width:800px; margin:0 auto; }
            .panel{
                background:var(--panel);
                border:1px solid var(--border);
                border-radius:var(--radius);
                box-shadow:var(--shadow);
                padding:24px;
                margin-bottom:16px;
            }
            h1{ margin:0 0 20px; font-size:24px; font-weight:600; }
            .order-list{ display:flex; flex-direction:column; gap:8px; }
            .order-item{
                display:flex; align-items:center; gap:12px;
                padding:12px 16px;
                border:1px solid var(--border);
                border-radius:8px;
                background:white;
                cursor:pointer;
                transition:all 0.2s;
            }
            .order-item:hover{
                background:#f8fafc;
                border-color:var(--accent);
                transform:translateY(-1px);
                box-shadow:0 4px 12px rgba(102,126,234,0.1);
            }
            .order-item a{
                flex:1;
                text-decoration:none;
                color:var(--ink);
                font-weight:500;
            }
            .btn-back{
                display:inline-block;
                padding:8px 16px;
                background:var(--muted);
                color:white;
                text-decoration:none;
                border-radius:8px;
                margin-bottom:16px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <a href="main.php" class="btn-back">← Назад</a>
            <div class="panel">
                <h1>Выберите заявку для редактирования</h1>
                <?php if (empty($orders)): ?>
                    <p>Нет доступных заявок</p>
                <?php else: ?>
                    <div class="order-list">
                        <?php foreach ($orders as $order): ?>
                            <div class="order-item">
                                <a href="?order=<?= urlencode($order) ?>"><?= htmlspecialchars($order) ?></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Загружаем данные заявки
$workshopStmt = $pdo->prepare("SELECT DISTINCT workshop FROM orders WHERE order_number = ? LIMIT 1");
$workshopStmt->execute([$order_number]);
$workshop = $workshopStmt->fetchColumn() ?: 'U5';

$positionsStmt = $pdo->prepare("
    SELECT `filter`, `count`, marking, personal_packaging, personal_label, 
           group_packaging, packaging_rate, group_label, remark
    FROM orders 
    WHERE order_number = ? 
    ORDER BY `filter`, `count`
");
$positionsStmt->execute([$order_number]);
$positions = $positionsStmt->fetchAll();

// список фильтров (для datalist)
$filters = $pdo->query("SELECT DISTINCT TRIM(`filter`) AS f FROM salon_filter_structure WHERE TRIM(`filter`)<>'' ORDER BY f")->fetchAll();
$filtersList = array_map(fn($r)=>$r['f'],$filters);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Редактирование заявки <?= htmlspecialchars($order_number) ?></title>
    <style>
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1e293b;
            --muted:#64748b;
            --border:#e2e8f0;
            --accent:#667eea;
            --danger:#dc2626;
            --radius:14px;
            --shadow:0 10px 25px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.06);
            --shadow-soft:0 2px 8px rgba(0,0,0,0.08);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg); color:var(--ink);
            font: 16px/1.6 "Inter","Segoe UI", Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
        }

        .container{ max-width:1700px; margin:0 auto; padding:12px; }
        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:12px;
            margin-bottom:12px;
        }
        .section-title{
            font-size:14px; font-weight:600; color:#111827;
            margin:0 0 8px; padding-bottom:4px; border-bottom:1px solid var(--border);
        }

        button, input[type="submit"]{
            appearance:none;
            border:1px solid transparent;
            cursor:pointer;
            background:var(--accent);
            color:#fff;
            padding:6px 12px;
            border-radius:6px;
            font-weight:600;
            font-size:13px;
            transition:background .2s, box-shadow .2s, transform .04s;
            box-shadow:0 2px 4px rgba(0,0,0,0.1);
        }
        button:hover, input[type="submit"]:hover{ background:#5568d3; transform:translateY(-1px); }
        button:active, input[type="submit"]:active{ transform:translateY(0); }
        button.btn-danger{ background:var(--danger); }
        button.btn-danger:hover{ background:#b91c1c; }
        button.btn-link{
            background:transparent;
            color:var(--accent);
            border:1px solid var(--border);
            box-shadow:none;
        }
        button.btn-link:hover{
            background:#f8fafc;
            border-color:var(--accent);
        }
        button.btn-link-secondary{
            background:transparent;
            color:var(--muted);
            border:1px solid var(--border);
            box-shadow:none;
        }
        button.btn-link-secondary:hover{
            background:#f8fafc;
            border-color:var(--muted);
            color:var(--ink);
        }

        input[type="text"], input[type="number"], select{
            width:100%; padding:5px 8px;
            border:1px solid var(--border); border-radius:6px;
            background:#fff; color:var(--ink); outline:none;
            transition:border-color .2s, box-shadow .2s;
            box-sizing:border-box;
            font-size:13px;
        }
        input:focus, select:focus{
            border-color:#c7d2fe; box-shadow:0 0 0 3px #e0e7ff;
        }

        .row{
            display:grid;
            grid-template-columns:minmax(200px,1fr) minmax(100px,120px) minmax(150px,1fr) minmax(120px,1fr) minmax(120px,1fr) minmax(150px,1fr) minmax(100px,120px) minmax(120px,1fr) minmax(150px,1fr) 44px;
            gap:6px;
            align-items:center;
            padding:4px 4px;
            border-bottom:1px solid var(--border);
            transition:background-color 0.2s ease;
            border-radius:4px;
            background-color:transparent;
        }
        .row.header{
            font-weight:600; 
            background:var(--panel) !important; 
            border-radius:var(--radius); 
            padding:8px 4px; 
            margin-bottom:6px; 
            box-shadow:var(--shadow-soft);
            font-size:13px;
        }
        .row:not(.header):hover{
            background-color:#e2e8f0 !important;
        }
        .row:last-child{border-bottom:none}
        .filter-cell{display:flex;align-items:center;gap:6px}
        .icon-btn{
            padding:4px 8px; border:1px solid var(--border); background:var(--panel); 
            border-radius:6px; cursor:pointer; font-size:11px; color:var(--muted);
            transition:all 0.2s;
        }
        .icon-btn:hover{background:#f8fafc; transform:translateY(-1px);}

        .actions{margin-top:12px;display:flex;gap:10px;align-items:center}
        .status{margin-left:auto; color:var(--muted); font-size:13px;}

        @media (max-width:1400px){
            .row{grid-template-columns:minmax(180px,1fr) minmax(80px,100px) minmax(120px,1fr) minmax(100px,1fr) minmax(100px,1fr) minmax(120px,1fr) minmax(80px,100px) minmax(100px,1fr) minmax(120px,1fr) 40px;gap:6px;}
        }
        @media (max-width:1200px){
            .row{grid-template-columns:minmax(160px,1fr) minmax(70px,90px) minmax(100px,1fr) minmax(90px,1fr) minmax(90px,1fr) minmax(100px,1fr) minmax(70px,90px) minmax(90px,1fr) minmax(100px,1fr) 36px;gap:4px;}
        }
        @media (max-width:1100px){
            .row{grid-template-columns:1fr; gap:4px; padding:12px; border:1px solid var(--border); border-radius:var(--radius); margin-bottom:8px; background:transparent;}
            .row:not(.header):hover{background-color:#e2e8f0 !important;}
            .row.header{display:none}
            .row > div{display:flex; align-items:center; gap:8px}
            .row > div::before{content:attr(data-label)': '; font-weight:600; min-width:120px; flex-shrink:0}
        }
    </style>
</head>
<body>
<div class="container">
    <div class="panel">
        <div class="section-title">Редактирование заявки: <?= htmlspecialchars($order_number) ?></div>
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px;flex-wrap:wrap; font-size:13px;">
            <div style="font-weight:600;min-width:100px">Цех: <?= htmlspecialchars($workshop) ?></div>
            <button onclick="window.location.href='edit_order.php'" class="btn-link">← Выбрать другую заявку</button>
            <button onclick="window.location.href='main.php'" class="btn-link-secondary">← На главную</button>
        </div>
    </div>

    <div class="panel">
        <div class="section-title">Позиции заявки</div>
        <div class="row header">
            <div>Фильтр</div>
            <div>Количество, шт</div>
            <div>Маркировка</div>
            <div>Упаковка инд.</div>
            <div>Этикетка инд.</div>
            <div>Упаковка групп.</div>
            <div>Норма упаковки</div>
            <div>Этикетка групп.</div>
            <div>Примечание</div>
            <div></div>
        </div>
        <div id="rows"></div>
    </div>

    <div class="panel">
        <div class="actions">
            <button onclick="addRow()">+ Добавить позицию</button>
            <button onclick="saveOrder()">💾 Сохранить изменения</button>
            <span id="status" class="status"></span>
        </div>
    </div>
</div>

<datalist id="filters_datalist">
    <?php foreach ($filtersList as $f): ?>
        <option value="<?= htmlspecialchars($f) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
    const orderNumber = <?= json_encode($order_number, JSON_UNESCAPED_UNICODE) ?>;
    const initialPositions = <?= json_encode($positions, JSON_UNESCAPED_UNICODE) ?>;
    let deletedItems = [];

    function makeFilterInput(value=''){
        const wrap=document.createElement('div');
        wrap.className='filter-cell';
        const inp=document.createElement('input');
        inp.type='text';
        inp.name='filter';
        inp.setAttribute('list','filters_datalist');
        inp.placeholder='начните вводить название…';
        inp.value=value||'';
        const info=document.createElement('button');
        info.type='button'; info.className='icon-btn'; info.textContent='i'; info.title='Инфо (можно привязать позже)';
        wrap.append(inp,info);
        return wrap;
    }

    function addRow(prefill={}, isNew=false){
        const r=document.createElement('div');
        r.className='row';
        if (isNew) r.setAttribute('data-is-new', 'true');

        const colFilter=document.createElement('div');
        colFilter.setAttribute('data-label','Фильтр');
        colFilter.appendChild(makeFilterInput(prefill.filter||''));

        const colCount=document.createElement('div');
        colCount.setAttribute('data-label','Количество, шт');
        const inCount=document.createElement('input'); 
        inCount.type='number'; 
        inCount.min='1'; 
        inCount.placeholder='шт'; 
        inCount.value=prefill.count||''; 
        inCount.name='count'; 
        colCount.appendChild(inCount);

        const colMark=document.createElement('div');
        colMark.setAttribute('data-label','Маркировка');
        const inMark=document.createElement('input'); 
        inMark.type='text'; 
        inMark.name='marking'; 
        inMark.placeholder='маркировка'; 
        inMark.value=(prefill.marking??'стандарт'); 
        colMark.appendChild(inMark);

        const colPPack=document.createElement('div');
        colPPack.setAttribute('data-label','Упаковка инд.');
        const inPPack=document.createElement('input'); 
        inPPack.type='text'; 
        inPPack.name='personal_packaging'; 
        inPPack.placeholder='инд. упаковка'; 
        inPPack.value=(prefill.personal_packaging??'стандарт'); 
        colPPack.appendChild(inPPack);

        const colPLabel=document.createElement('div');
        colPLabel.setAttribute('data-label','Этикетка инд.');
        const inPLabel=document.createElement('input'); 
        inPLabel.type='text'; 
        inPLabel.name='personal_label'; 
        inPLabel.placeholder='инд. этикетка'; 
        inPLabel.value=(prefill.personal_label??'стандарт'); 
        colPLabel.appendChild(inPLabel);

        const colGPack=document.createElement('div');
        colGPack.setAttribute('data-label','Упаковка групп.');
        const inGPack=document.createElement('input'); 
        inGPack.type='text'; 
        inGPack.name='group_packaging'; 
        inGPack.placeholder='групп. упаковка'; 
        inGPack.value=(prefill.group_packaging??'стандарт'); 
        colGPack.appendChild(inGPack);

        const colRate=document.createElement('div');
        colRate.setAttribute('data-label','Норма упаковки');
        const inRate=document.createElement('input'); 
        inRate.type='number'; 
        inRate.name='packaging_rate'; 
        inRate.placeholder='шт в коробке'; 
        inRate.min='0'; 
        inRate.step='1'; 
        inRate.value=(prefill.packaging_rate??10); 
        colRate.appendChild(inRate);

        const colGLabel=document.createElement('div');
        colGLabel.setAttribute('data-label','Этикетка групп.');
        const inGLabel=document.createElement('input'); 
        inGLabel.type='text'; 
        inGLabel.name='group_label'; 
        inGLabel.placeholder='этикетка групп.'; 
        inGLabel.value=(prefill.group_label??'стандарт'); 
        colGLabel.appendChild(inGLabel);

        const colRemark=document.createElement('div');
        colRemark.setAttribute('data-label','Примечание');
        const inRem=document.createElement('input'); 
        inRem.type='text'; 
        inRem.name='remark'; 
        inRem.placeholder='примечание'; 
        inRem.value=prefill.remark||''; 
        colRemark.appendChild(inRem);

        const colDel=document.createElement('div');
        colDel.setAttribute('data-label','Действие');
        const btnX=document.createElement('button'); 
        btnX.type='button'; 
        btnX.textContent='✕'; 
        btnX.title='Удалить'; 
        btnX.className='icon-btn btn-danger';
        btnX.onclick=()=>{
            if (!r.hasAttribute('data-is-new')) {
                const filter = r.querySelector('[name="filter"]').value;
                const count = r.querySelector('[name="count"]').value;
                deletedItems.push({filter: filter, count: parseInt(count) || 0});
            }
            r.remove();
        }; 
        colDel.appendChild(btnX);

        // Сохраняем старые значения для обновления
        if (!isNew && prefill.filter !== undefined) {
            r.setAttribute('data-old-filter', prefill.filter);
            r.setAttribute('data-old-count', prefill.count || 0);
        }

        r.append(colFilter,colCount,colMark,colPPack,colPLabel,colGPack,colRate,colGLabel,colRemark,colDel);
        document.getElementById('rows').appendChild(r);
    }

    function buildPayload(){
        const rows=[...document.querySelectorAll('#rows .row')];
        const items=rows.map(row=>{
            const q=(name)=> (row.querySelector(`[name="${name}"]`)?.value ?? '').trim();
            const isNew = row.hasAttribute('data-is-new');
            const item = {
                filter:q('filter'),
                count:Number(q('count')||0),
                marking:q('marking'),
                personal_packaging:q('personal_packaging'),
                personal_label:q('personal_label'),
                group_packaging:q('group_packaging'),
                packaging_rate:q('packaging_rate')===''? '': Number(q('packaging_rate')),
                group_label:q('group_label'),
                remark:q('remark'),
                is_new: isNew
            };
            if (!isNew) {
                item.old_filter = row.getAttribute('data-old-filter') || q('filter');
                item.old_count = parseInt(row.getAttribute('data-old-count')) || Number(q('count')||0);
            }
            return item;
        });
        return {order_number: orderNumber, items: items, deleted_items: deletedItems};
    }

    async function saveOrder(){
        const status=document.getElementById('status');
        const payload=buildPayload();
        if(!payload.items.length && !payload.deleted_items.length){ 
            alert('Нет изменений для сохранения'); 
            return; 
        }
        try{
            status.textContent='Сохраняем…';
            const res=await fetch(location.pathname+'?action=save_order',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            const data=await res.json();
            if(!data.ok) throw new Error(data.error||'Ошибка сохранения');
            status.textContent='';
            alert('Заявка успешно сохранена');
            deletedItems = [];
            location.reload();
        }catch(e){ 
            status.textContent=''; 
            alert('Не удалось сохранить: '+e.message); 
        }
    }

    // Загружаем существующие позиции
    initialPositions.forEach(pos => addRow(pos, false));
</script>
</body>
</html>

