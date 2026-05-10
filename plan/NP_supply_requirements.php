<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');

/* ===== AJAX: отрисовать только таблицы ===== */
if (isset($_GET['ajax']) && $_GET['ajax']=='1') {
    $order     = $_POST['order']  ?? '';
    $ctype     = $_POST['ctype']  ?? '';           // wireframe | prefilter | box | g_box

    if ($order==='' || $ctype==='') {
        http_response_code(400);
        echo "<p>Не указана заявка или тип комплектующих.</p>";
        exit;
    }

    // Единый запрос по выбранной заявке
    // Плейсхолдеры :ord1/:ord2 и :ctype1…:ctype10 — уникальные имена: при PDO::ATTR_EMULATE_PREPARES=false
    // драйвер MySQL не допускает повторного использования одного именованного параметра.
    $sql = "
    WITH bp AS (
      SELECT
        order_number,
        TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
        filter_label,
        assign_date,
        `count`
      FROM build_plan
      WHERE order_number = :ord1
    ),
    p AS (
      SELECT b.order_number, b.base_filter, b.filter_label, b.assign_date, b.`count`,
             pfs.wireframe, pfs.prefilter, pfs.box, pfs.g_box
      FROM bp b
      JOIN panel_filter_structure pfs ON pfs.filter = b.base_filter
    ),
    o AS (
      SELECT order_number, COALESCE(packaging_rate,1) AS packaging_rate
      FROM orders WHERE order_number = :ord2
    )
    SELECT
      :ctype1 AS component_type,
      CASE 
        WHEN :ctype2 = 'wireframe' THEN p.wireframe
        WHEN :ctype3 = 'prefilter' THEN p.prefilter
        WHEN :ctype4 = 'box'       THEN p.box
        WHEN :ctype5 = 'g_box'     THEN p.g_box
      END AS component_name,
      p.assign_date AS need_by_date,
      p.filter_label,
      p.base_filter,
      CASE 
        WHEN :ctype6 = 'g_box' 
             THEN CEIL(p.`count` / NULLIF((SELECT packaging_rate FROM o LIMIT 1),0))
        ELSE p.`count`
      END AS qty
    FROM p
    WHERE
      ( :ctype7 <> 'wireframe' OR (p.wireframe IS NOT NULL AND p.wireframe <> '') )
      AND ( :ctype8 <> 'prefilter' OR (p.prefilter IS NOT NULL AND p.prefilter <> '') )
      AND ( :ctype9 <> 'box'       OR (p.box IS NOT NULL AND p.box <> '') )
      AND ( :ctype10 <> 'g_box'     OR (p.g_box IS NOT NULL AND p.g_box <> '') )
    ORDER BY need_by_date, component_name, base_filter
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':ord1' => $order,
        ':ord2' => $order,
        ':ctype1' => $ctype,
        ':ctype2' => $ctype,
        ':ctype3' => $ctype,
        ':ctype4' => $ctype,
        ':ctype5' => $ctype,
        ':ctype6' => $ctype,
        ':ctype7' => $ctype,
        ':ctype8' => $ctype,
        ':ctype9' => $ctype,
        ':ctype10' => $ctype,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "<p>По заявке <b>".htmlspecialchars($order)."</b> для типа «".htmlspecialchars($ctype)."» данных нет.</p>";
        exit;
    }

    // Пивот-структура
    $dates  = [];      // список дат
    $items  = [];      // строки (компоненты)
    $matrix = [];      // matrix[item][date] = qty
    foreach ($rows as $r) {
        $d = $r['need_by_date'];
        $name = $r['component_name'];
        if ($name === null || $name === '') continue;

        $dates[$d] = true;
        $items[$name] = true;

        if (!isset($matrix[$name])) $matrix[$name] = [];
        if (!isset($matrix[$name][$d])) $matrix[$name][$d] = 0;
        $matrix[$name][$d] += (float)$r['qty'];
    }
    $dates = array_keys($dates);
    sort($dates);
    $items = array_keys($items);
    sort($items, SORT_NATURAL|SORT_FLAG_CASE);

    $titleMap = ['wireframe'=>'каркас','prefilter'=>'предфильтр','box'=>'индивидуальная коробка','g_box'=>'групповая коробка'];
    $title = $titleMap[$ctype] ?? $ctype;

    // Хелпер форматирования
    function fmt($x){ return rtrim(rtrim(number_format((float)$x,3,'.',''), '0'), '.'); }

    // Заголовок для печати (один раз)
    echo "<h3 class=\"subtitle\">Заявка ".htmlspecialchars($order).": потребность — ".htmlspecialchars($title)."</h3>";

    echo '<div class="table-wrap"><table class="pivot">';
    echo '<thead><tr><th class="left">Позиция</th>';
    foreach ($dates as $d) {
        $ts = strtotime($d);
        echo '<th class="nowrap vertical-date">' . date('d-m-y', $ts) . '</th>';
    }
    echo '<th class="nowrap">Итого</th></tr></thead><tbody>';

    foreach ($items as $name) {
        $rowTotal = 0;
        echo '<tr><td class="left">'.htmlspecialchars($name).'</td>';
        foreach ($dates as $d) {
            $v  = $matrix[$name][$d] ?? 0;
            $rowTotal += $v;
            echo '<td>'.($v ? fmt($v) : '').'</td>';
        }
        echo '<td class="total">'.fmt($rowTotal).'</td></tr>';
    }

    echo '<tr class="foot"><td class="left nowrap">Итого по дням</td>';
    $grand = 0;
    foreach ($dates as $d) {
        $col = 0;
        foreach ($items as $name) {
            $col += $matrix[$name][$d] ?? 0;
        }
        $grand += $col;
        echo '<td class="total">'.($col ? fmt($col) : '').'</td>';
    }
    echo '<td class="grand">'.fmt($grand).'</td></tr>';

    echo '</tbody></table></div>';

    exit;
}

/* ===== экспорт в XLSX ===== */
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $order = $_GET['order'] ?? '';
    $ctype = $_GET['ctype'] ?? '';

    if ($order === '' || $ctype === '') {
        http_response_code(400);
        echo "Не указана заявка или тип комплектующих.";
        exit;
    }

    $sql = "
    SELECT
      :ctype1 AS component_type,
      CASE
        WHEN :ctype2 = 'wireframe' THEN pfs.wireframe
        WHEN :ctype3 = 'prefilter' THEN pfs.prefilter
        WHEN :ctype4 = 'box'       THEN pfs.box
        WHEN :ctype5 = 'g_box'     THEN pfs.g_box
      END AS component_name,
      bp.assign_date AS need_by_date,
      bp.filter_label,
      TRIM(SUBSTRING_INDEX(bp.filter_label,' [',1)) AS base_filter,
      CASE
        WHEN :ctype6 = 'g_box'
             THEN CEIL(
                 bp.`count` / NULLIF(
                     (
                         SELECT COALESCE(o2.packaging_rate, 1)
                         FROM orders o2
                         WHERE o2.order_number = :ord2
                         LIMIT 1
                     ),
                     0
                 )
             )
        ELSE bp.`count`
      END AS qty
    FROM build_plan bp
    JOIN panel_filter_structure pfs
      ON pfs.filter = TRIM(SUBSTRING_INDEX(bp.filter_label,' [',1))
    WHERE bp.order_number = :ord1
      AND ( :ctype7 <> 'wireframe' OR (pfs.wireframe IS NOT NULL AND pfs.wireframe <> '') )
      AND ( :ctype8 <> 'prefilter' OR (pfs.prefilter IS NOT NULL AND pfs.prefilter <> '') )
      AND ( :ctype9 <> 'box'       OR (pfs.box IS NOT NULL AND pfs.box <> '') )
      AND ( :ctype10 <> 'g_box'    OR (pfs.g_box IS NOT NULL AND pfs.g_box <> '') )
    ORDER BY need_by_date, component_name, base_filter
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ord1' => $order,
            ':ord2' => $order,
            ':ctype1' => $ctype,
            ':ctype2' => $ctype,
            ':ctype3' => $ctype,
            ':ctype4' => $ctype,
            ':ctype5' => $ctype,
            ':ctype6' => $ctype,
            ':ctype7' => $ctype,
            ':ctype8' => $ctype,
            ':ctype9' => $ctype,
            ':ctype10' => $ctype,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        http_response_code(500);
        echo "Ошибка загрузки данных для экспорта: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }

    if (!$rows) {
        http_response_code(404);
        echo "По заявке {$order} для типа «{$ctype}» данных нет.";
        exit;
    }

    $dates = [];
    $items = [];
    $matrix = [];
    foreach ($rows as $r) {
        $d = $r['need_by_date'];
        $name = $r['component_name'];
        if ($name === null || $name === '') {
            continue;
        }
        $dates[$d] = true;
        $items[$name] = true;
        if (!isset($matrix[$name])) {
            $matrix[$name] = [];
        }
        if (!isset($matrix[$name][$d])) {
            $matrix[$name][$d] = 0;
        }
        $matrix[$name][$d] += (float)$r['qty'];
    }

    $dates = array_keys($dates);
    sort($dates);
    $items = array_keys($items);
    sort($items, SORT_NATURAL | SORT_FLAG_CASE);

    $titleMap = [
        'wireframe' => 'каркас',
        'prefilter' => 'предфильтр',
        'box'       => 'индивидуальная коробка',
        'g_box'     => 'групповая коробка',
    ];
    $title = 'Заявка ' . $order . ': потребность — ' . ($titleMap[$ctype] ?? $ctype);
    $safeOrder = preg_replace('/[^0-9A-Za-z._-]/', '_', (string)$order);
    $filename = 'potrebnost_' . ($safeOrder ?: 'order') . '_' . date('Y-m-d') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellspacing="0" cellpadding="4">';
    echo '<tr><th colspan="' . (count($dates) + 2) . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</th></tr>';
    echo '<tr><th>Позиция</th>';
    foreach ($dates as $d) {
        echo '<th>' . htmlspecialchars(date('d-m-y', strtotime($d)), ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '<th>Итого</th></tr>';

    foreach ($items as $name) {
        $rowTotal = 0.0;
        echo '<tr><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
        foreach ($dates as $d) {
            $v = (float)($matrix[$name][$d] ?? 0);
            $rowTotal += $v;
            echo '<td>' . ($v != 0.0 ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : '') . '</td>';
        }
        echo '<td><strong>' . rtrim(rtrim(number_format($rowTotal, 3, '.', ''), '0'), '.') . '</strong></td></tr>';
    }

    $grand = 0.0;
    echo '<tr><td><strong>Итого по дням</strong></td>';
    foreach ($dates as $d) {
        $colTotal = 0.0;
        foreach ($items as $name) {
            $colTotal += (float)($matrix[$name][$d] ?? 0);
        }
        $grand += $colTotal;
        echo '<td><strong>' . ($colTotal != 0.0 ? rtrim(rtrim(number_format($colTotal, 3, '.', ''), '0'), '.') : '') . '</strong></td>';
    }
    echo '<td><strong>' . rtrim(rtrim(number_format($grand, 3, '.', ''), '0'), '.') . '</strong></td></tr>';
    echo '</table></body></html>';
    exit;
}

/* ===== обычная загрузка страницы ===== */

// Список заявок
$orders = $pdo->query("SELECT DISTINCT order_number FROM build_plan ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Потребность по заявке</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#f6f7fb; --card:#ffffff; --text:#111827; --muted:#6b7280;
            --line:#e5e7eb; --accent:#2563eb; --accent-soft:#eaf1ff;
        }
        *{box-sizing:border-box}
        body{
            font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
            background:var(--bg); color:var(--text);
            margin:0; padding:10px; font-size:13px;
        }
        h2{margin:6px 0 12px;text-align:center}
        .panel{
            max-width:1100px;margin:0 auto 12px;background:#fff;border-radius:10px;
            padding:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);
            display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center
        }
        .vertical-date{
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            padding: 4px;
        }
        label{white-space:nowrap; display:flex; align-items:center; gap:6px}
        select,button{padding:7px 10px;font-size:13px;border:1px solid var(--line);border-radius:8px;background:#fff}
        input[type="checkbox"]{transform:translateY(1px)}
        button{cursor:pointer;font-weight:600}
        .btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
        .btn-soft{background:var(--accent-soft);color:var(--accent);border-color:#cfe0ff}
        #result{max-width:1100px;margin:0 auto}

        .subtitle{margin:6px 0 8px}

        .table-wrap{overflow-x:auto;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:10px;margin-bottom:14px}
        table.pivot{
            border-collapse:collapse;
            width:max-content;      /* не сжимаем таблицу до ширины контейнера */
            min-width:640px;
            font-size:12.5px;
            table-layout:auto;
        }
        table.pivot th, table.pivot td{
            border:1px solid #ddd;
            padding:5px 7px;
            text-align:center;
            vertical-align:middle;
            white-space:nowrap;     /* сохраняем читаемость чисел и дат */
        }
        table.pivot thead th{background:#f0f0f0;font-weight:600}
        .left{text-align:left;white-space:normal}
        .nowrap{white-space:nowrap}
        table.pivot td.total{background:#f9fafb;font-weight:bold}
        table.pivot tr.foot td{background:#eef6ff;font-weight:bold}
        table.pivot td.grand{background:#e6ffe6;font-weight:bold}
        tbody tr:nth-child(even){background:#fafafa}

        @media(max-width:700px){ select,button{width:100%} }

        @media print{
            @page { size: A4 landscape; margin: 10mm; }
            body{background:#fff}
            .panel{display:none !important}
            .table-wrap{box-shadow:none;border-radius:0;padding:0;overflow:visible}
            table.pivot{font-size:11px;min-width:0 !important;width:max-content}
            table.pivot th, table.pivot td{
                padding:3px 4px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            }
            .vertical-date{padding:2px !important;letter-spacing:.2px}
        }
    </style>
</head>
<body>

<h2>Потребность комплектующих по заявке</h2>

<div class="panel">
    <label>Заявка:</label>
    <select id="order">
        <option value="">— выберите —</option>
        <?php foreach ($orders as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Тип комплектующих:</label>
    <select id="ctype">
        <option value="">— выберите —</option>
        <option value="prefilter">Предфильтр</option>
        <option value="wireframe">Каркас</option>
        <option value="box">Коробка индивидуальная</option>
        <option value="g_box">Коробка групповая</option>
    </select>

    <button class="btn-primary" onclick="loadPivot()">Показать потребность</button>
    <button class="btn-soft" onclick="exportToExcel()">Экспорт Excel</button>
</div>

<div id="result"></div>

<script>
    function loadPivot(){
        const order    = document.getElementById('order').value;
        const ctype    = document.getElementById('ctype').value;
        if(!order){ alert('Выберите заявку'); return; }
        if(!ctype){ alert('Выберите тип комплектующих'); return; }

        const xhr = new XMLHttpRequest();
        xhr.onreadystatechange=function(){
            if(this.readyState===4){
                if(this.status===200){
                    document.getElementById('result').innerHTML = this.responseText;
                }else{
                    alert('Ошибка загрузки: '+this.status);
                }
            }
        };
        xhr.open('POST','?ajax=1',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.send(
            'order='+encodeURIComponent(order)+
            '&ctype='+encodeURIComponent(ctype)
        );
    }

    function exportToExcel(){
        const order = document.getElementById('order').value;
        const ctype = document.getElementById('ctype').value;
        if(!order){ alert('Выберите заявку'); return; }
        if(!ctype){ alert('Выберите тип комплектующих'); return; }
        window.location.href = '?export=xlsx&order=' + encodeURIComponent(order) + '&ctype=' + encodeURIComponent(ctype);
    }
</script>
</body>
</html>
