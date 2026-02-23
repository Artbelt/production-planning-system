<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

const HALF_BALE_LEN = 600;   // м (0.5 бухты)
const FULL_BALE_LEN = 1200;  // м (1 бухта)

$action = $_GET['action'] ?? '';

    /* ===== AJAX: сохранить/загрузить остатки на участке ===== */
if ($action==='save_stocks' || $action==='load_stocks') {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $pdo = getPdo('plan_u3');
        
        // Создаем таблицу если её нет
        $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_stocks (
            order_number VARCHAR(50) NOT NULL,
            filter VARCHAR(255) NOT NULL,
            stock_qty INT DEFAULT 0,
            PRIMARY KEY (order_number, filter),
            INDEX idx_order_filter (order_number, filter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($action==='save_stocks'){
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!$payload || empty($payload['order']) || !isset($payload['stocks']) || !is_array($payload['stocks'])) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad payload']); exit;
            }
            $order = (string)$payload['order'];
            
            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO workshop_stocks (order_number, filter, stock_qty)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE stock_qty = VALUES(stock_qty)
            ");
            
            foreach ($payload['stocks'] as $stock) {
                $filter = (string)($stock['filter'] ?? '');
                $qty = max(0, (int)($stock['qty'] ?? 0));
                if ($filter !== '') {
                    $ins->execute([$order, $filter, $qty]);
                }
            }
            $pdo->commit();
            echo json_encode(['ok'=>true]); exit;
        }
        
        if ($action==='load_stocks'){
            $ord = $_GET['order'] ?? '';
            if ($ord==='') {
                $raw = file_get_contents('php://input');
                if ($raw) { $tmp = json_decode($raw, true); if (!empty($tmp['order'])) $ord = (string)$tmp['order']; }
            }
            if ($ord===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }
            
            $st = $pdo->prepare("SELECT filter, stock_qty FROM workshop_stocks WHERE order_number=?");
            $st->execute([$ord]);
            $stocks = [];
            foreach ($st->fetchAll() as $r) {
                $stocks[$r['filter']] = (int)$r['stock_qty'];
            }
            echo json_encode(['ok'=>true,'stocks'=>$stocks]); exit;
        }
    } catch(Throwable $e){
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* ===== AJAX: сохранить/загрузить раскрой ===== */
if ($action==='save_cut' || $action==='load_cut') {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $pdo = getPdo('plan_u3');

        /* --- SAVE: кладём полосы построчно в cut_plans --- */
        if ($action==='save_cut'){
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!$payload || empty($payload['order']) || !isset($payload['bales']) || !is_array($payload['bales'])) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad payload']); exit;
            }
            $order  = (string)$payload['order'];
            $defaultFormat = isset($payload['format']) ? (int)$payload['format'] : 1200;

            // чистим прошлую версию и вставляем заново
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM cut_plans WHERE order_number=?")->execute([$order]);

            $ins = $pdo->prepare("
                INSERT INTO cut_plans
                  (order_number, bale_id, strip_no, material, filter, width, height, length, format, source)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");

            $saved = 0;
            foreach ($payload['bales'] as $bi => $bale) {
                $baleId = $bi + 1;
                $si = 0;
                $fmt = isset($bale['format']) ? (int)$bale['format'] : $defaultFormat;

                foreach (($bale['strips'] ?? []) as $s) {
                    $filter = (string)($s['filter'] ?? '');
                    $w = (float)($s['w'] ?? 0);
                    $h = (float)($s['h'] ?? 0);
                    if ($filter==='' || $w<=0) continue;

                    $si++;
                    $len = (float)HALF_BALE_LEN; // 0.5 бухты = 600 м
                    $src = (!empty($s['source'])) ? $s['source'] : (!empty($s['rowEl']) ? 'order' : 'assort');
                    $mat = 'Simple';

                    $ins->execute([$order, $baleId, $si, $mat, $filter, $w, $h, $len, $fmt, $src]);
                    $saved++;
                }
            }
            $pdo->commit();


            $pdo->beginTransaction();

            // статусы заявки: раскрой готов/не готов, подтверждение сбрасываем
            $stUpd = $pdo->prepare("UPDATE orders SET cut_ready=?, cut_confirmed=? WHERE order_number=?");
            $stUpd->execute([$saved > 0 ? 1 : 0, 0, $order]);

            $pdo->commit();

            echo json_encode(['ok'=>true,'saved'=>$saved]); exit;
        }

        /* --- LOAD: берём из cut_plans, собираем обратно по бухтам --- */
        if ($action==='load_cut'){
            $ord = $_GET['order'] ?? '';
            if ($ord==='') {
                $raw = file_get_contents('php://input');
                if ($raw) { $tmp = json_decode($raw, true); if (!empty($tmp['order'])) $ord = (string)$tmp['order']; }
            }
            if ($ord===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }

            $st = $pdo->prepare("
                SELECT bale_id, strip_no, filter, width, height, length, format, source
                FROM cut_plans
                WHERE order_number=?
                ORDER BY bale_id, strip_no
            ");
            $st->execute([$ord]);
            $rows = $st->fetchAll();

            $bales = []; $curId=null; $cur=[]; $curFmt=null;
            $push = function() use (&$bales,&$cur,&$curFmt){
                if(!$cur) return;
                $sumW = 0.0; foreach($cur as $s){ $sumW += (float)$s['w']; }
                $bales[] = ['w'=>$sumW, 'half'=>count($cur), 'format'=>(int)($curFmt?:1200), 'strips'=>$cur];
                $cur = []; $curFmt=null;
            };

            foreach ($rows as $r){
                $bid = (int)$r['bale_id'];
                if ($curId===null) $curId=$bid;
                if ($curId!==$bid){ $push(); $curId=$bid; }
                $f=(string)$r['filter']; $w=(float)$r['width']; $h=(float)$r['height'];
                $curFmt = $curFmt ?? (int)$r['format'];
                $cur[] = [
                    'filter'=>$f, 'w'=>$w, 'h'=>$h,
                    'rowKey'=>$f.'|'.$w.'|'.$h,
                    'source'=>$r['source'] ?? 'order'
                ];
            }
            $push();

            echo json_encode(['ok'=>true,'bales'=>$bales]); exit;
        }

    } catch(Throwable $e){
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}


/* === обычная страница: требуем ?order_number === */
$orderNumber = $_GET['order_number'] ?? '';
if ($orderNumber===''){ http_response_code(400); exit('Укажите ?order_number=...'); }

/* === обычная страница === */
try{
    $pdo = getPdo('plan_u3');

    // позиции заявки (считаем потребность в полубухтах)
    $sql="
    SELECT
      o.order_number,
      o.filter,
      ppr.p_p_material                 AS material,
      ppr.p_p_height                   AS strip_width_mm,
      ppr.p_p_fold_height              AS pleat_height_mm,
      SUM(o.count)                     AS strips_qty,
      ROUND(((ppr.p_p_fold_height*2*ppr.p_p_fold_count)*SUM(o.count))/1000, 3) AS total_length_m
    FROM orders o
    JOIN round_filter_structure rfs ON rfs.filter=o.filter
    JOIN paper_package_round ppr ON ppr.p_p_name=rfs.filter_package
    WHERE o.order_number=:order_number
    GROUP BY o.order_number,o.filter,ppr.p_p_material,ppr.p_p_height,ppr.p_p_fold_height,ppr.p_p_fold_count";
    $st=$pdo->prepare($sql); $st->execute([':order_number'=>$orderNumber]); $rows=$st->fetchAll();

    // Загружаем остатки на участке
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS workshop_stocks (
            order_number VARCHAR(50) NOT NULL,
            filter VARCHAR(255) NOT NULL,
            stock_qty INT DEFAULT 0,
            PRIMARY KEY (order_number, filter),
            INDEX idx_order_filter (order_number, filter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Таблица уже существует или ошибка - игнорируем
    }
    
    $stStocks = $pdo->prepare("SELECT filter, stock_qty FROM workshop_stocks WHERE order_number=?");
    $stStocks->execute([$orderNumber]);
    $stocksMap = [];
    foreach ($stStocks->fetchAll() as $s) {
        $stocksMap[$s['filter']] = (int)$s['stock_qty'];
    }

    $rowsAll=[]; $totalMeters=0.0;
    foreach($rows as $r){
        if (strtoupper((string)$r['material'])==='CARBON') continue;
        $tm=(float)$r['total_length_m'];
        $r['need_units'] = (int)ceil($tm / HALF_BALE_LEN); // «шт» = полубухты
        $r['workshop_stock'] = $stocksMap[$r['filter']] ?? 0; // остатки на участке
        $rowsAll[]=$r;
        $totalMeters += $tm;
    }
    // сортировка по ширине (убывание)
    usort($rowsAll, function($a,$b){
        $aw=(float)$a['strip_width_mm']; $bw=(float)$b['strip_width_mm'];
        if ($aw === $bw) return strcmp((string)$a['filter'], (string)$b['filter']);
        return $bw <=> $aw;
    });

    // ассортимент - сначала пробуем с JOIN
    $sqlAssort="
    SELECT
      rfs.filter,
      ppr.p_p_material  AS material,
      ppr.p_p_height    AS strip_width_mm,
      ppr.p_p_fold_height AS pleat_height_mm
    FROM round_filter_structure rfs
    LEFT JOIN paper_package_round ppr ON ppr.p_p_name = rfs.filter_package
    WHERE UPPER(COALESCE(ppr.p_p_material, '')) <> 'CARBON'
    GROUP BY rfs.filter
    ORDER BY rfs.filter";
    
    try {
        $assort = $pdo->query($sqlAssort)->fetchAll();
    } catch (Exception $e) {
        $assort = [];
    }
    
    // Если ассортимент пустой или ошибка, берем все фильтры из справочника
    if (empty($assort)) {
        $sqlAssort="
        SELECT DISTINCT filter
        FROM round_filter_structure
        ORDER BY filter";
        $assort = $pdo->query($sqlAssort)->fetchAll();
    }

    /* НОВЫЕ позиции в заказе — их НЕТ в справочнике (для модалки) */
    $sqlMissing="
      SELECT o.filter AS filter, SUM(o.count) AS strips_qty
      FROM orders o
      LEFT JOIN round_filter_structure rfs ON rfs.filter = o.filter
      WHERE o.order_number = :order_number AND rfs.filter IS NULL
      GROUP BY o.filter
      ORDER BY o.filter
    ";
    $stm=$pdo->prepare($sqlMissing);
    $stm->execute([':order_number'=>$orderNumber]);
    $missing = $stm->fetchAll();

    // DEBUG: временно выводим для отладки
    // echo "<!-- DEBUG ASSORT COUNT: " . count($assort) . " -->\n";
    // echo "<!-- DEBUG MISSING COUNT: " . count($missing) . " -->\n";
    // if (!empty($assort)) echo "<!-- DEBUG FIRST ASSORT: " . print_r($assort[0], true) . " -->\n";

}catch(Throwable $e){http_response_code(500);echo 'Ошибка: '.$e->getMessage(); exit;}
?>
<!doctype html><meta charset="utf-8">
<title>Раскрой по заявке #<?=htmlspecialchars($orderNumber)?></title>
<style>
    :root{ --gap:12px; --left:700px; --mid:560px; --right:380px; }
    *{box-sizing:border-box}
    body{font:12px/1.25 Arial;margin:10px;height:100vh;overflow:hidden;display:flex;flex-direction:column;align-items:center}
    h2{margin:0 0 8px;font:600 16px/1.2 Arial}
    .wrap{display:grid;grid-template-columns:var(--left) var(--mid) var(--right);gap:var(--gap);height:calc(100vh - 38px);max-width:calc(var(--left) + var(--mid) + var(--right) + var(--gap)*2);width:100%}

    .left{overflow-y:auto;overflow-x:auto;border:1px solid #ddd;border-radius:8px;padding:8px;font-size:11px;background:#fff}
    .section{margin-bottom:10px}
    .section h3{margin:0 0 6px;font:600 13px/1.2 Arial}

    .panel{border:1px solid #ccc;border-radius:8px;padding:10px;height:100%;overflow:auto;background:#fff}
    .panel h3{margin:0 0 6px;font:600 14px/1.2 Arial}
    .meta{margin:6px 0 8px}
    .meta span{display:inline-block;margin-right:10px}
    
    /* правый блок: фиксированные кнопки, прокручиваемый список */
    #balesPanel{display:flex !important;flex-direction:column !important;height:100% !important;overflow:hidden !important}
    #balesPanel .panelHead{flex-shrink:0;padding-bottom:10px}
    #balesPanel #bales{flex:1;overflow-y:auto !important;overflow-x:hidden;min-height:0;padding-top:0}

    #currentBalePanel{max-height:600px;overflow-y:auto}
    
    /* блок ассортимента: фиксированный заголовок, прокручиваемая таблица */
    #assortPanel{display:flex !important;flex-direction:column !important;overflow:hidden !important}
    #assortPanel h3{flex-shrink:0;margin:0 0 6px}
    #assortPanel > div{flex:1;overflow-y:auto !important;overflow-x:hidden;min-height:0}
    #assortPanel #assortTable thead{position:sticky;top:0;z-index:5;background:#f6f6f6}

    table{border-collapse:collapse;table-layout:fixed;width:100%;min-width:100%}
    th,td{border:1px solid #ccc;padding:3px 5px;white-space:nowrap}
    th{overflow:visible;text-overflow:clip}
    td{overflow:hidden;text-overflow:ellipsis}
    th{background:#f6f6f6}
    .right{text-align:right}

    /* компактные ширины колонок */
    .col-filter{width:150px}.col-w{width:55px}.col-h{width:42px}
    .col-need{width:80px}.col-stock{width:80px}.col-cut{width:80px}.col-rest{width:80px}
    
    /* стили для input остатков */
    .stock-input{width:100%;padding:2px 4px;border:1px solid #ccc;border-radius:4px;text-align:right;font-size:11px}
    .stock-input:focus{outline:none;border-color:#2563eb;background:#f0f9ff}

    /* без hover/selection */
    .posTable tr:hover td{background:transparent}
    .posTable tr.sel td{background:transparent}
    /* Готово (всё раскроено) — зелёная подсветка */
    .posTable tr.done td{ background:#dff7c7; }

    /* подсветка кандидатов по ширине */
    .posTable tr.width-cand td{ position:relative; }
    .posTable tr.width-cand td::after{ content:""; position:absolute; inset:0; background: var(--wbg, transparent); pointer-events:none; }

    /* подсветка кандидатов по ширине в таблице ассортимента */
    #assortTable tr.width-cand-assort td{ position:relative; }
    #assortTable tr.width-cand-assort td::after{ content:""; position:absolute; inset:0; background: var(--wbg-assort, transparent); pointer-events:none; }

    .baleTbl{table-layout:fixed;margin-top:6px}
    .bcol-pos{width:220px}.bcol-w{width:80px}.bcol-h{width:70px}.bcol-l{width:150px}
    .delBtn{border:1px solid #d66;background:#fee;border-radius:6px;padding:2px 8px;cursor:pointer}
    .delBtn:hover{background:#fdd}

    .balesList .card{border:1px dashed #bbb;border-radius:8px;padding:8px;margin-bottom:8px;background:#fff}
    .cardHead{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;gap:8px}
    .delBaleBtn{border:1px solid #d66;background:#fee;border-radius:6px;padding:3px 10px;cursor:pointer}
    .delBaleBtn:hover{background:#fdd}
    .panelHead{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;gap:8px}
    .panelHead .btn{margin-left:6px}


    #balesPanel {
        --fs-table: 11px;      /* шрифт таблицы */
        --fs-head: 11px;       /* шрифт заголовка карточки */
        --pad-card: 6px;       /* паддинг карточки */
        --gap-card: 6px;       /* расстояние между карточками */
        --pad-cell: 2px 6px;   /* паддинг ячеек */
        --lh: 1.15;            /* line-height в таблице */
    }

    /* карточка бухты */
    #balesPanel .balesList .card{
        padding: var(--pad-card);
        margin-bottom: var(--gap-card);
        border-radius: 6px;
    }

    /* шапка карточки */
    #balesPanel .cardHead{
        margin-bottom: 4px;
        font-size: var(--fs-head);
        line-height: 1.2;
    }
    #balesPanel .delBaleBtn{ padding: 2px 6px; }

    /* сама таблица */
    #balesPanel .baleTbl{
        table-layout: fixed;
        width: 100%;
        font-size: var(--fs-table);
    }
    #balesPanel .baleTbl th,
    #balesPanel .baleTbl td{
        padding: var(--pad-cell);
        line-height: var(--lh);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* чуть уже колонки */
    #balesPanel .bcol-pos{ width: 90px; }  /* было 220px */
    #balesPanel .bcol-w  { width: 40px;  }  /* было 80px  */
    #balesPanel .bcol-h  { width: 40px;  }  /* было 70px  */
    #balesPanel .bcol-l  { width: 40px;  }  /* было 150px */


    /* --- ПЕЧАТЬ: исправления, чтобы ничего не обрезалось --- */
    @media print {
        @page { size: A4 portrait; margin: 10mm; }

        /* общий режим печати */
        html, body { height:auto !important; overflow:visible !important; overflow-y:hidden !important; background:#fff !important; }
        .left, .mid, .panelHead .btn { display:none !important; }
        .wrap { display:block !important; height:auto !important; overflow:visible !important; overflow-y:hidden !important; }
        #balesPanel { border:none; box-shadow:none; background:#fff; height:auto !important; overflow:visible !important; overflow-y:hidden !important; }
        #balesPanel #bales { overflow:visible !important; overflow-y:hidden !important; }

        /* ДВЕ карточки в строке, без вылазаний */
        #balesPanel .balesList{
            display:grid !important;
            gap:5mm; /* при необходимости уменьшай/увеличивай */
            grid-template-columns: repeat(2, minmax(0, calc((100% - 5mm)/2)));
            justify-content:start;
            align-items:start;
        }

        /* Карточка не шире своей колонки и не рвётся */
        #balesPanel .card{
            break-inside:avoid;
            page-break-inside:avoid;
            border:1px solid #000;
            margin:0;
            padding:3.5mm;
            box-sizing:border-box;
            width:100%;
            max-width:100%;
        }
        #balesPanel .cardHead{ margin-bottom:2mm; font-size:11pt; }

        /* Таблица внутри — компактная, не раздвигает колонку */
        #balesPanel .baleTbl{
            width:100%;
            table-layout:fixed;
            font-size:9.5pt;
        }
        #balesPanel .baleTbl th,
        #balesPanel .baleTbl td{
            padding:1.2mm 1.8mm;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        #balesPanel .baleTbl th{ background:#fff; }

        /* убрать все кнопки */
        .btn, .delBaleBtn, .delBtn { display:none !important; }
    }

    /* === МОДАЛЬНОЕ ОКНО для новых позиций === */
    .modalBack{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9998}
    .modal{display:none;position:fixed;inset:0;align-items:center;justify-content:center;z-index:9999;pointer-events:none}
    .modal .win{pointer-events:all;width:min(920px, 92vw);max-height:80vh;overflow:auto;background:#fff;border-radius:12px;border:1px solid #e5e7eb;box-shadow:0 20px 60px rgba(0,0,0,.25);padding:14px}
    .modal h3{margin:0 0 8px;font:700 16px/1.2 system-ui, Arial}
    .missingTbl{width:100%;border-collapse:collapse;table-layout:auto}
    .missingTbl th, .missingTbl td{border:1px solid #e5e7eb;padding:6px 8px;vertical-align:middle}
    .missingTbl th{background:#f9fafb}
    .missFilter{font-weight:700}
    .assortSelect{width:100%;max-width:420px}
    .modal .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
    .modal .closeBtn{border:1px solid #aaa;background:#fafafa;border-radius:8px;padding:6px 10px;cursor:pointer}
    .addBtn{border:1px solid #2563eb;background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:6px 10px;cursor:pointer;white-space:nowrap}
    .addBtn:hover{background:#dbeafe;border-color:#1d4ed8}
    .muted{color:#6b7280;font-size:12px;margin:4px 0}

</style>

<h2>Раскрой по заявке #<?=htmlspecialchars($orderNumber)?></h2>
<div class="wrap">
    <!-- ЛЕВАЯ ТАБЛИЦА -->
    <div class="left">
        <div class="section">
            <h3>Позиции</h3>
            <table id="tblAll" class="posTable">
                <colgroup>
                    <col class="col-filter"><col class="col-w"><col class="col-h">
                    <col class="col-need"><col class="col-stock"><col class="col-cut"><col class="col-rest">
                </colgroup>
                <tr>
                    <th>Фильтр</th><th>Шир, мм</th><th>H, мм</th>
                    <th class="right">Нужно, шт</th><th class="right" title="Остаток на участке">Ост. уч., шт</th><th class="right">В раскр., шт</th><th class="right">Ост., шт</th>
                </tr>
                <?php foreach($rowsAll as $i=>$r): ?>
                    <?php 
                    $need=(int)$r['need_units']; 
                    $stock=(int)($r['workshop_stock'] ?? 0);
                    $rest = max(0, $need - $stock);
                    ?>
                    <tr data-i="a<?=$i?>"
                        data-filter="<?=htmlspecialchars($r['filter'])?>"
                        data-w="<?=$r['strip_width_mm']?>"
                        data-h="<?=$r['pleat_height_mm']?>"
                        data-need="<?=$need?>"
                        data-stock="<?=$stock?>"
                        data-cutn="0">
                        <td><?=htmlspecialchars($r['filter'])?></td>
                        <td><?=$r['strip_width_mm']?></td>
                        <td><?=$r['pleat_height_mm']?></td>
                        <td class="right needn"><?=number_format($need,0,'.',' ')?></td>
                        <td class="right">
                            <input type="number" class="stock-input" 
                                   data-filter="<?=htmlspecialchars($r['filter'])?>"
                                   value="<?=$stock?>" 
                                   min="0" 
                                   step="1"
                                   title="Остатки на участке">
                        </td>
                        <td class="right cutn">0</td>
                        <td class="right restn"><?=number_format($rest,0,'.',' ')?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="quiet">Резка кратно 0.5 бухты (<?=HALF_BALE_LEN?> м). «шт» = полубухты.</p>
        </div>
    </div>

    <!-- СЕРЕДИНА -->
    <div class="mid" style="display:flex;flex-direction:column;gap:12px;overflow:auto">
        <div class="panel" id="currentBalePanel">
            <h3>Текущая бухта</h3>
            <div class="meta">
                <span>Выбрано: <b id="selName" class="quiet">—</b></span>
                <span>Формат:
                    <select id="fmtSel" style="padding:2px 6px;border:1px solid #bbb;border-radius:6px">
                        <option value="1200" selected>1200 мм</option>
                        <option value="222">222 мм</option>
                    </select>
                </span>
                <span>Ширина бухты: <b id="bw">0.0</b> / <b id="bwTotal">1200.0</b> мм</span>
                <span>Остаток: <b id="rest">1200.0</b> мм</span>
            </div>
            <div class="ctrls">
                <button class="btn" id="btnSave" disabled>Сохранить бухту</button>
                <button class="btn" id="btnClear" disabled>Очистить</button>
            </div>
            <div id="baleList" class="quiet">Пусто</div>
        </div>

        <div class="panel" id="assortPanel">
            <h3>Ассортимент (добавить не из заявки)</h3>
            <div style="flex:1;overflow-y:auto;overflow-x:hidden;min-height:0">
                <table id="assortTable">
                    <colgroup>
                        <col class="acol-mat"><col class="acol-filter"><col class="acol-w"><col class="acol-h"><col class="acol-act">
                    </colgroup>
                    <thead>
                        <tr><th>Материал</th><th>Фильтр</th><th>Ширина</th><th>H</th><th>Действия</th></tr>
                    </thead>
                    <tbody>
                <?php foreach($assort as $i=>$a): ?>
                    <tr data-filter="<?=htmlspecialchars($a['filter'])?>"
                        data-w="<?=$a['strip_width_mm']?>"
                        data-h="<?=$a['pleat_height_mm']?>">
                        <td>Simple</td>
                        <td><?=htmlspecialchars($a['filter'])?></td>
                        <td><?=$a['strip_width_mm']?> мм</td>
                        <td><?=$a['pleat_height_mm']?> мм</td>
                        <td>
                            <button class="assortBtn btnAdd1">+1 шт</button>
                            <button class="assortBtn btnAuto">Авто</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ПРАВО -->
    <div class="panel" id="balesPanel">
        <div class="panelHead">
            <h3 style="margin:0">Собранные бухты</h3>
            <div>
                <button class="btn" id="btnLoadDB">Load</button>
                <button class="btn" id="btnSaveDB">Save</button>
                <button class="btn" id="btnPrint">Печать</button>
            </div>
        </div>
        <div id="bales" class="balesList quiet">Пока нет</div>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО для новых позиций -->
<div class="modalBack" id="missBack"></div>
<div class="modal" id="missModal" role="dialog" aria-modal="true" aria-labelledby="missTitle">
    <div class="win">
        <div class="topbar">
            <h3 id="missTitle">Новые позиции в заявке — нет в справочнике</h3>
            <button class="closeBtn" id="missClose">Закрыть</button>
        </div>
        <p class="muted" id="missIntro"></p>
        <table class="missingTbl" id="missTbl">
            <tr>
                <th>Позиция из заявки</th>
                <th>Подобрать из ассортимента</th>
                <th>Добавить в БД</th>
            </tr>
            <!-- строки генерируются JS -->
        </table>
    </div>
</div>

<script>
    const ORDER = <?=json_encode($orderNumber)?>;

    // ===== данные для модалки =====
    const MISSING = <?=json_encode($missing, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
    const ASSORT  = <?=json_encode($assort,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;

    // Отладка - выводим в консоль
    console.log('MISSING:', MISSING);
    console.log('ASSORT:', ASSORT);
    console.log('ASSORT length:', ASSORT ? ASSORT.length : 0);

    // путь к странице добавления/редактирования позиции
    const ADD_FILTER_URL = 'edit_filter_properties.php';
    const WORKSHOP_CODE = 'U3';
    const ASSORT_SELECT_POST_NAME = 'analog_filter';

    // === глобальные параметры (объявляем один раз) ===
    let BALE_WIDTH = 1200.0;                  // текущий формат бухты
    const eps = 1e-9, HALF_LEN = <?=HALF_BALE_LEN?>;

    // селектор формата
    const fmtSel = document.getElementById('fmtSel');
    fmtSel.value = String(BALE_WIDTH);
    fmtSel.addEventListener('change', () => {
        const v = parseFloat(fmtSel.value);
        if (baleWidth - eps > v) {
            alert('Ширина уже добавленных полос ('+fmt1(baleWidth)+' мм) больше нового формата ('+v+' мм). Очистите или уменьшите набор.');
            fmtSel.value = String(BALE_WIDTH);
            return;
        }
        BALE_WIDTH = v;
        document.getElementById('bwTotal').textContent = fmt1(BALE_WIDTH);
        updBaleUI();
        highlightWidthMatches();
    });

    /* подсветка подходящих ширин: (free - w) ∈ [5 .. 35] мм, ближе к 5 — насыщеннее */
    function highlightWidthMatches(){
        const RANGE_MIN = 5, RANGE_MAX = 35;
        const free = Math.max(0, BALE_WIDTH - baleWidth);
        
        // Подсветка для левой таблицы (позиции из заявки)
        document.querySelectorAll('#tblAll tr[data-i]').forEach(tr=>{
            tr.classList.remove('width-cand'); tr.style.removeProperty('--wbg');
            const w = parseFloat(tr.dataset.w || '0');
            const delta = free - w;
            if (delta + eps < RANGE_MIN || delta - eps > RANGE_MAX) return;
            const t = (RANGE_MAX - delta) / (RANGE_MAX - RANGE_MIN);
            const light=[210,235,255], dark=[0,102,204];
            const r=Math.round(light[0] + (dark[0]-light[0])*t);
            const g=Math.round(light[1] + (dark[1]-light[1])*t);
            const b=Math.round(light[2] + (dark[2]-light[2])*t);
            tr.classList.add('width-cand');
            tr.style.setProperty('--wbg', `rgba(${r},${g},${b},0.40)`);
        });
        
        // Подсветка для таблицы ассортимента
        document.querySelectorAll('#assortTable tr[data-filter]').forEach(tr=>{
            tr.classList.remove('width-cand-assort'); tr.style.removeProperty('--wbg-assort');
            const w = parseFloat(tr.dataset.w || '0');
            if (w <= 0) return; // пропускаем строки без ширины
            const delta = free - w;
            if (delta + eps < RANGE_MIN || delta - eps > RANGE_MAX) return;
            const t = (RANGE_MAX - delta) / (RANGE_MAX - RANGE_MIN);
            const light=[210,235,255], dark=[0,102,204];
            const r=Math.round(light[0] + (dark[0]-light[0])*t);
            const g=Math.round(light[1] + (dark[1]-light[1])*t);
            const b=Math.round(light[2] + (dark[2]-light[2])*t);
            tr.classList.add('width-cand-assort');
            tr.style.setProperty('--wbg-assort', `rgba(${r},${g},${b},0.40)`);
        });
    }

    let curRow=null, baleStrips=[], baleWidth=0.0, bales=[];
    const el=(id)=>document.getElementById(id);
    const fmt1=(x)=>(Math.round(x*10)/10).toFixed(1);

    function restUnitsOf(tr){
        const need = parseInt(tr.dataset.need||'0',10);
        const stock = parseInt(tr.dataset.stock||'0',10);
        const cut  = parseInt(tr.dataset.cutn||'0',10);
        return Math.max(0, need - stock - cut);
    }

    function updateRowUnits(tr, deltaUnits){
        const need = parseInt(tr.dataset.need||'0',10);
        const stock = parseInt(tr.dataset.stock||'0',10);
        const prev = parseInt(tr.dataset.cutn||'0',10);
        let now = prev + deltaUnits;
        if (now < 0) now = 0; 
        // Максимум - это то, что нужно за вычетом остатков на участке
        const maxCut = Math.max(0, need - stock);
        if (now > maxCut) now = maxCut;
        tr.dataset.cutn = String(now);
        const rest = Math.max(0, need - stock - now);
        tr.querySelector('.cutn').textContent  = String(now);
        tr.querySelector('.restn').textContent = String(rest);
        tr.classList.toggle('done', rest===0); // зелёный, если закрыто
        return now - prev;
    }
    
    function updateStock(tr, newStock){
        const stock = Math.max(0, parseInt(newStock, 10) || 0);
        tr.dataset.stock = String(stock);
        const need = parseInt(tr.dataset.need||'0',10);
        const cut = parseInt(tr.dataset.cutn||'0',10);
        const rest = Math.max(0, need - stock - cut);
        tr.querySelector('.restn').textContent = String(rest);
        tr.classList.toggle('done', rest===0);
        
        // Предупреждаем, если в раскрое больше чем нужно с учетом остатков
        const maxCut = Math.max(0, need - stock);
        if (cut > maxCut) {
            const excess = cut - maxCut;
            console.warn(`Внимание: для ${tr.dataset.filter} в раскрое ${excess} шт больше, чем нужно (с учетом остатков на участке)`);
        }
    }

    function setSelection(tr){
        curRow=tr||null;
        el('selName').textContent = tr ? (tr.dataset.filter + ` | ${tr.dataset.w}×${tr.dataset.h}`) : '—';
        if(!tr){ el('selName').classList.add('quiet'); } else { el('selName').classList.remove('quiet'); }
    }

    // клик по строке — добавить 1 шт (но не на input остатков)
    document.getElementById('tblAll').addEventListener('click', e=>{
        if (e.target.classList.contains('stock-input')) return; // не обрабатываем клик по input
        const tr=e.target.closest('tr[data-i]'); if(!tr) return;
        setSelection(tr); addFromRow(tr, 1);
    });
    
    // обработчик изменения остатков на участке
    document.getElementById('tblAll').addEventListener('change', e=>{
        if (!e.target.classList.contains('stock-input')) return;
        const tr = e.target.closest('tr[data-i]');
        if (!tr) return;
        const newStock = parseInt(e.target.value, 10) || 0;
        updateStock(tr, newStock);
        saveStocksToDB(); // автосохранение
    });
    
    // обработчик blur для остатков (сохранение при потере фокуса)
    document.getElementById('tblAll').addEventListener('blur', e=>{
        if (e.target.classList.contains('stock-input')) {
            saveStocksToDB();
        }
    }, true);

    function addFromRow(tr, take){
        if(!tr) return;
        const w = parseFloat(tr.dataset.w), h = parseFloat(tr.dataset.h);
        const availUnits = restUnitsOf(tr);
        if (availUnits <= 0){ alert('По этой позиции остаток 0 шт.'); return; }
        if (take > availUnits) take = availUnits;

        const free=Math.max(0, BALE_WIDTH-baleWidth);
        const needW=w*take;
        if(needW > free+eps){ alert('Не помещается по ширине.'); return; }

        for(let i=0;i<take;i++)
            baleStrips.push({filter:tr.dataset.filter,w:w,h:h,rowKey:keyOf(tr),rowEl:tr, source:'order'});

        baleWidth = Math.round((baleWidth + needW) * 10) / 10;
        updateRowUnits(tr, take);
        updBaleUI();
    }

    // ассортимент — не влияет на левую таблицу
    document.getElementById('assortTable').addEventListener('click', (e)=>{
        const tr = e.target.closest('tr[data-filter]'); if(!tr) return;
        const filter = tr.dataset.filter, w = parseFloat(tr.dataset.w), h = parseFloat(tr.dataset.h);
        const free = Math.max(0, BALE_WIDTH - baleWidth);
        if(e.target.classList.contains('btnAdd1')){
            if(w > free+eps){ alert('Не помещается по ширине.'); return; }
            baleStrips.push({filter,w,h,rowKey:`${filter}|${w}|${h}`,rowEl:null, source:'assort'});
            baleWidth = Math.round((baleWidth + w) * 10) / 10; updBaleUI();
        } else if(e.target.classList.contains('btnAuto')){
            let take = Math.floor((free + eps)/w);
            if(take<=0){ alert('Не помещается по ширине.'); return; }
            for(let i=0;i<take;i++) baleStrips.push({filter,w,h,rowKey:`${filter}|${w}|${h}`,rowEl:null, source:'assort'});
            baleWidth = Math.round((baleWidth + w*take) * 10) / 10; updBaleUI();
        }
    });

    function updBaleUI(){
        el('bw').textContent=fmt1(baleWidth);
        el('bwTotal').textContent=fmt1(BALE_WIDTH);
        el('rest').textContent=fmt1(Math.max(0,BALE_WIDTH-baleWidth));

        const box=el('baleList');
        if(!baleStrips.length){box.textContent='Пусто';box.classList.add('quiet');toggleCtrls();return;}
        box.classList.remove('quiet');

        let html='<table class="baleTbl"><colgroup><col class="bcol-pos"><col class="bcol-w"><col class="bcol-h"><col class="bcol-l"></colgroup>';
        html+='<tr><th>Позиция</th><th>Ширина</th><th>H</th><th>Длина</th></tr>';
        html+=baleStrips.map((s,idx)=>`
          <tr>
            <td>${s.filter} <button class="delBtn" title="Убрать полосу" data-idx="${idx}" style="margin-left:6px">×</button></td>
            <td>${fmt1(s.w)} мм</td>
            <td>${s.h} мм</td>
            <td><?=HALF_BALE_LEN?> м </td>
          </tr>`).join('');
        html+='</table>';
        box.innerHTML=html;

        box.querySelectorAll('.delBtn').forEach(btn=>{
            btn.addEventListener('click', e=>{
                const i = parseInt(e.currentTarget.dataset.idx,10);
                removeStrip(i);
            });
        });
        highlightWidthMatches(); toggleCtrls();
    }

    function toggleCtrls(){ const hasBale=baleStrips.length>0; el('btnSave').disabled=!hasBale; el('btnClear').disabled=!hasBale; }

    function removeStrip(idx){
        const s = baleStrips[idx]; if(!s) return;
        if(s.rowEl) updateRowUnits(s.rowEl, -1);
        baleWidth = Math.max(0, Math.round((baleWidth - s.w) * 10) / 10);
        baleStrips.splice(idx,1); updBaleUI();
    }

    function clearBale(){
        if(!baleStrips.length){ updBaleUI(); return; }
        const counts = new Map();
        for(const s of baleStrips){ if(s.rowEl) counts.set(s.rowEl, (counts.get(s.rowEl)||0) + 1); }
        for (const [tr, cnt] of counts.entries()){ updateRowUnits(tr, -cnt); }
        baleStrips=[]; baleWidth=0; updBaleUI(); highlightWidthMatches();
    }
    document.getElementById('btnClear').addEventListener('click', clearBale);

    function saveBale(){
        if(!baleStrips.length) return;
        const halfCount = baleStrips.length;
        bales.push({w:baleWidth, strips:[...baleStrips], half:halfCount, format: BALE_WIDTH});
        renderBales(); baleStrips=[]; baleWidth=0; updBaleUI(); highlightWidthMatches();
    }
    document.getElementById('btnSave').addEventListener('click', saveBale);

    function deleteBale(idx){
        const b = bales[idx]; if(!b) return;
        const counts = new Map();
        for(const s of b.strips){ if(s.rowEl) counts.set(s.rowEl, (counts.get(s.rowEl)||0) + 1); }
        for (const [tr, cnt] of counts.entries()){ updateRowUnits(tr, -cnt); }
        bales.splice(idx,1); renderBales();
    }

    function renderBales(){
        const box=el('bales');
        if(!bales.length){box.textContent='Пока нет'; box.classList.add('quiet'); return;}
        box.classList.remove('quiet');

        let html = bales.map((b,idx)=>{
            const fmt = b.format || 1200;
            const leftover = Math.max(0, Math.round(fmt - b.w));
            const rows=b.strips.map(s=>`<tr><td>${s.filter}</td><td>${fmt1(s.w)} мм</td><td>${s.h} мм</td><td><?=HALF_BALE_LEN?> м </td></tr>`).join('');
            return `<div class="card">
          <div class="cardHead">
            <div><b>Бухта #${idx+1}</b> · Остаток: <b>${leftover} мм</b> · Формат: <b>${fmt} мм</b></div>
            <div><button class="delBaleBtn" data-idx="${idx}" title="Удалить бухту">×</button></div>
          </div>
          <table class="baleTbl"><colgroup><col class="bcol-pos"><col class="bcol-w"><col class="bcol-h"><col class="bcol-l"></colgroup>
            <tr><th>Позиция</th><th>Ширина</th><th>H</th><th>Длина</th></tr>${rows}
          </table>
        </div>`;
        }).join('');
        box.innerHTML=html;

        box.querySelectorAll('.delBaleBtn').forEach(btn=>{
            btn.addEventListener('click', e=>{
                const idx = parseInt(e.currentTarget.dataset.idx,10);
                deleteBale(idx);
            });
        });
    }

    function printBales(){ if(!bales.length){ alert('Нет сохранённых бухт для печати.'); return; } window.print(); }
    document.getElementById('btnPrint').addEventListener('click', printBales);

    /* === Сохранение/загрузка всех бухт в БД === */
    function keyOf(tr){ return `${tr.dataset.filter}|${tr.dataset.w}|${tr.dataset.h}`; }
    function findRowByKey(key){
        if(!key) return null;
        const [f,w,h] = key.split('|');
        return [...document.querySelectorAll('#tblAll tr[data-i]')].find(tr =>
            tr.dataset.filter===f && String(tr.dataset.w)===String(w) && String(tr.dataset.h)===String(h)
        ) || null;
    }
    function serializeBales(){
        return bales.map(b=>({
            format: b.format || BALE_WIDTH,
            strips: b.strips.map(s=>({
                filter: s.filter,
                w: s.w,
                h: s.h,
                rowKey: s.rowKey || `${s.filter}|${s.w}|${s.h}`,
                source: s.source || (s.rowEl ? 'order' : 'assort')
            }))
        }));
    }

    async function saveAllToDB(){
        try{
            const body = JSON.stringify({ order: ORDER, format: BALE_WIDTH, bales: serializeBales() });
            const res = await fetch(location.pathname+'?action=save_cut', { method:'POST', headers:{'Content-Type':'application/json'}, body });
            const data = await res.json();
            if(!data.ok) throw new Error(data.error||'Ошибка сохранения');
            alert('Раскрой сохранён в БД.');
        }catch(e){ alert('Не удалось сохранить: '+e.message); }
    }
    document.getElementById('btnSaveDB').addEventListener('click', saveAllToDB);

    function resetLeftTable(){
        document.querySelectorAll('#tblAll tr[data-i]').forEach(tr=>{
            tr.dataset.cutn='0';
            tr.querySelector('.cutn').textContent='0';
            const need = parseInt(tr.dataset.need||'0',10);
            const stock = parseInt(tr.dataset.stock||'0',10);
            const rest = Math.max(0, need - stock);
            tr.querySelector('.restn').textContent=String(rest);
            tr.classList.remove('done');
        });
    }
    
    // Сохранение остатков на участке в БД
    async function saveStocksToDB(){
        try{
            const stocks = [];
            document.querySelectorAll('#tblAll tr[data-i]').forEach(tr=>{
                const filter = tr.dataset.filter;
                const stock = parseInt(tr.dataset.stock||'0',10);
                stocks.push({filter, qty: stock});
            });
            const body = JSON.stringify({ order: ORDER, stocks });
            const res = await fetch(location.pathname+'?action=save_stocks', { 
                method:'POST', 
                headers:{'Content-Type':'application/json'}, 
                body 
            });
            const data = await res.json();
            if(!data.ok) throw new Error(data.error||'Ошибка сохранения остатков');
        }catch(e){ 
            console.error('Не удалось сохранить остатки:', e.message); 
        }
    }
    
    // Загрузка остатков на участке из БД
    async function loadStocksFromDB(){
        try{
            const res = await fetch(location.pathname + '?action=load_stocks', {
                method:'POST', 
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({order: ORDER})
            });
            const text = await res.text();
            let data; 
            try { data = JSON.parse(text); } 
            catch { throw new Error('Backend вернул не JSON: ' + text.slice(0,200)); }
            if(!data.ok) throw new Error(data.error||'Ошибка загрузки остатков');
            
            const stocks = data.stocks || {};
            document.querySelectorAll('#tblAll tr[data-i]').forEach(tr=>{
                const filter = tr.dataset.filter;
                const stock = stocks[filter] || 0;
                tr.dataset.stock = String(stock);
                const input = tr.querySelector('.stock-input');
                if (input) input.value = stock;
                // Пересчитываем остатки с учетом остатков на участке
                const need = parseInt(tr.dataset.need||'0',10);
                const cut = parseInt(tr.dataset.cutn||'0',10);
                const rest = Math.max(0, need - stock - cut);
                tr.querySelector('.restn').textContent = String(rest);
                tr.classList.toggle('done', rest===0);
            });
        }catch(e){ 
            console.error('Не удалось загрузить остатки:', e.message); 
        }
    }

    async function loadFromDB(){
        try{
            // Сначала загружаем остатки на участке
            await loadStocksFromDB();
            
            const res = await fetch(location.pathname + '?action=load_cut', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({order: ORDER})
            });
            const text = await res.text();
            let data; try { data = JSON.parse(text); } catch { throw new Error('Backend вернул не JSON: ' + text.slice(0,200)); }
            if(!data.ok) throw new Error(data.error||'Ошибка загрузки');

            bales=[]; baleStrips=[]; baleWidth=0; resetLeftTable();

            for (const b of (data.bales||[])) {
                const strips=[]; let bw=0;
                for (const s of (b.strips||[])) {
                    const tr = findRowByKey(s.rowKey || `${s.filter}|${s.w}|${s.h}`);
                    strips.push({filter:s.filter,w:parseFloat(s.w),h:parseFloat(s.h),rowKey:(s.rowKey||`${s.filter}|${s.w}|${s.h}`),rowEl:tr, source:s.source||'order'});
                    bw += parseFloat(s.w)||0;
                    if (tr) updateRowUnits(tr, 1);
                }
                bales.push({w:bw, strips, half:strips.length, format: parseInt(b.format||1200,10)});
            }
            renderBales();

            // подстроим формат под первую бухту из БД
            if (bales.length) {
                BALE_WIDTH = bales[0].format || 1200;
                fmtSel.value = String(BALE_WIDTH);
                document.getElementById('bwTotal').textContent = fmt1(BALE_WIDTH);
                updBaleUI();
            }

            highlightWidthMatches();
            alert('Раскрой загружен из БД.');
        }catch(e){ alert('Не удалось загрузить: ' + e.message); }
    }
    document.getElementById('btnLoadDB').addEventListener('click', loadFromDB);

    // === ФУНКЦИИ ДЛЯ МОДАЛКИ ===
    function buildAssortSelect(id) {
        const sel = document.createElement('select');
        sel.id = id;
        sel.className = 'assortSelect';

        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = '— Выберите аналог —';
        sel.appendChild(opt0);

        console.log('Building select', id, 'ASSORT:', ASSORT);

        if (!Array.isArray(ASSORT) || ASSORT.length === 0) {
            const optErr = document.createElement('option');
            optErr.value = '';
            optErr.textContent = '❌ Ассортимент не загружен';
            sel.appendChild(optErr);
            sel.disabled = true;
            console.error('ASSORT пустой или не массив:', ASSORT);
            return sel;
        }

        const uniqueNames = new Set(ASSORT.map(a => a.filter || a));
        console.log('Unique names:', uniqueNames);

        for (const name of uniqueNames) {
            if (name && name !== '') {
                const o = document.createElement('option');
                o.value = name;
                o.textContent = name;
                sel.appendChild(o);
            }
        }
        return sel;
    }

    function renderMissingModal(){
        if(!Array.isArray(MISSING) || !MISSING.length) return;

        el('missIntro').textContent = `В заявке #${ORDER} найдены позиции, которых нет в справочнике. Подберите аналог из ассортимента или добавьте позицию в БД.`;

        const tbody = el('missTbl');
        for (const [idx, m] of MISSING.entries()){
            const tr = document.createElement('tr');

            // 1) Позиция из заявки
            const tdF = document.createElement('td');
            tdF.innerHTML = `<span class="missFilter">${m.filter}</span>`;
            tr.appendChild(tdF);

            // 2) Выпадающий список ассортимента
            const tdSel = document.createElement('td');
            const sel = buildAssortSelect('assSel_'+idx);
            tdSel.appendChild(sel);
            tr.appendChild(tdSel);

            // 3) Форма "Добавить в БД"
            const tdAdd = document.createElement('td');

            const form = document.createElement('form');
            form.action = ADD_FILTER_URL;
            form.method = 'post';
            form.target = '_blank';

            // hidden: режим работы - добавление нового фильтра
            const inpWorkMode = document.createElement('input');
            inpWorkMode.type = 'hidden';
            inpWorkMode.name = 'work_mode';
            inpWorkMode.value = 'add';
            form.appendChild(inpWorkMode);

            // hidden: цех (для совместимости)
            const inpWorkshop = document.createElement('input');
            inpWorkshop.type = 'hidden';
            inpWorkshop.name = 'workshop';
            inpWorkshop.value = WORKSHOP_CODE;
            form.appendChild(inpWorkshop);

            // hidden: имя отсутствующего фильтра
            const inpFilterName = document.createElement('input');
            inpFilterName.type = 'hidden';
            inpFilterName.name = 'filter_name';
            inpFilterName.value = m.filter;
            form.appendChild(inpFilterName);

            // hidden: выбранный шаблон из выпадайки (прототип)
            const inpCopyFrom = document.createElement('input');
            inpCopyFrom.type = 'hidden';
            inpCopyFrom.name = ASSORT_SELECT_POST_NAME;
            form.appendChild(inpCopyFrom);

            // сабмит
            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.className = 'addBtn';
            btn.textContent = 'Добавить в БД';
            form.appendChild(btn);

            // при отправке перетащим выбранное из select во hidden
            form.addEventListener('submit', (ev) => {
                const selEl = document.getElementById('assSel_' + idx);
                inpCopyFrom.value = selEl.value;
            });

            tdAdd.appendChild(form);
            tr.appendChild(tdAdd);

            tbody.appendChild(tr);
        }

        // показать модалку
        el('missBack').style.display='block';
        el('missModal').style.display='flex';

        // закрытие
        const close = ()=>{ el('missBack').style.display='none'; el('missModal').style.display='none'; };
        el('missBack').onclick = close;
        el('missClose').onclick = close;
    }

    // init
    el('btnSave').disabled=true; el('btnClear').disabled=true;
    highlightWidthMatches();
    
    // Загружаем остатки на участке при загрузке страницы
    loadStocksFromDB();

    // показываем модалку, если есть новые позиции
    if (Array.isArray(MISSING) && MISSING.length){ renderMissingModal(); }
</script>
