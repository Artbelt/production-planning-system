<?php
// NP_supply_requirements.php — потребность по конкретной заявке для У3
// Печать: таблица разбивается на несколько страниц по N дат (по умолчанию 20)
// Режим: "Недельные итоги" — после каждого воскресенья добавляется столбец с суммой за неделю (ISO: пн–вс)

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U3;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

/* ===== AJAX: отрисовать только таблицы ===== */
if (isset($_GET['ajax']) && $_GET['ajax']=='1') {
    $order     = $_POST['order']  ?? '';
    $ctype     = $_POST['ctype']  ?? '';           // caps (крышки)
    $chunkSize = (int)($_POST['chunk'] ?? 20);     // сколько дат на одну «страницу»
    if ($chunkSize <= 0) $chunkSize = 20;

    if ($order==='' || $ctype==='') {
        http_response_code(400);
        echo "<p>Не указана заявка или тип комплектующих.</p>";
        exit;
    }

    // Единый запрос по выбранной заявке для У3 (верхние и нижние крышки вместе)
    $sql = "
    WITH bp AS (
      SELECT
        order_number,
        filter AS base_filter,
        filter,
        day_date,
        SUM(qty) AS qty
      FROM build_plans
      WHERE order_number = :ord
      GROUP BY order_number, filter, day_date
    ),
    p AS (
      SELECT b.order_number, b.base_filter, b.filter, b.day_date, b.qty,
             rfs.up_cap, rfs.down_cap
      FROM bp b
      JOIN round_filter_structure rfs ON rfs.filter = b.base_filter
    )
    SELECT
      'caps' AS component_type,
      p.up_cap AS component_name,
      p.day_date AS need_by_date,
      p.filter AS filter_label,
      p.base_filter,
      p.qty,
      'верхняя' AS cap_type
    FROM p
    WHERE p.up_cap IS NOT NULL AND p.up_cap <> ''
    UNION ALL
    SELECT
      'caps' AS component_type,
      p.down_cap AS component_name,
      p.day_date AS need_by_date,
      p.filter AS filter_label,
      p.base_filter,
      p.qty,
      'нижняя' AS cap_type
    FROM p
    WHERE p.down_cap IS NOT NULL AND p.down_cap <> ''
    ORDER BY need_by_date, component_name, base_filter
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':ord'=>$order]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "<p>По заявке <b>".htmlspecialchars($order)."</b> для крышек данных нет.</p>";
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

    // Получаем остатки крышек на складе
    $stockMap = [];
    if (!empty($items)) {
        $placeholders = str_repeat('?,', count($items) - 1) . '?';
        $stmtStock = $pdo->prepare("SELECT cap_name, current_quantity FROM cap_stock WHERE cap_name IN ($placeholders)");
        $stmtStock->execute($items);
        $stockRows = $stmtStock->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stockRows as $sr) {
            $stockMap[$sr['cap_name']] = (int)$sr['current_quantity'];
        }
    }

    // Предрасчёт накопленной потребности для каждой позиции по датам (для определения заливки)
    $cumulativeDemand = [];     // [item][date] = накопленная потребность до этой даты включительно
    foreach ($items as $name) {
        $cumulative = 0;
        foreach ($dates as $d) {
            $cumulative += $matrix[$name][$d] ?? 0;
            $cumulativeDemand[$name][$d] = $cumulative;
        }
    }

    $title = 'крышки';

    // Хелпер форматирования
    function fmt($x){ return rtrim(rtrim(number_format((float)$x,3,'.',''), '0'), '.'); }

    // Заголовок для печати (один раз)
    echo "<h3 class=\"subtitle\">Заявка ".htmlspecialchars($order).": потребность — ".htmlspecialchars($title)."</h3>";

    // Разбиение дат на чанки
    $dateChunks = array_chunk($dates, $chunkSize, true);

    foreach ($dateChunks as $i => $chunkDates) {
        echo '<div class="sheet">';                   // оболочка страницы
        echo '<div class="table-wrap"><table class="pivot">';
        echo '<thead><tr><th class="left">Позиция</th>';
        foreach ($chunkDates as $d) {
            $ts = strtotime($d);
            echo '<th class="nowrap vertical-date">' . date('d-m-y', $ts) . '</th>';
        }
        echo '<th class="nowrap vertical-date">В заказе</th><th class="nowrap vertical-date">На складе</th><th class="nowrap vertical-date">Дефицит</th></tr></thead><tbody>';

        // Строки с позициями
        foreach ($items as $name) {
            $rowTotal = 0;
            $stockQty = $stockMap[$name] ?? 0;
            echo '<tr><td class="left">'.htmlspecialchars($name).'</td>';
            foreach ($chunkDates as $d) {
                $ts = strtotime($d);
                $v  = $matrix[$name][$d] ?? 0;
                $rowTotal += $v;
                
                // Заливаем только дни, в которые фильтр запланирован к сборке (v > 0)
                // Проверяем, хватает ли остатка на складе для покрытия потребности до этой даты включительно
                $cellClass = '';
                if ($v > 0) {
                    $cumulative = $cumulativeDemand[$name][$d] ?? 0;
                    if ($stockQty > 0 && $cumulative <= $stockQty) {
                        // Хватает крышек
                        $cellClass = 'stock-sufficient';
                    } elseif ($cumulative > $stockQty) {
                        // Не хватает крышек
                        $cellClass = 'stock-insufficient';
                    }
                }
                
                echo '<td class="'.$cellClass.'">'.($v ? fmt($v) : '').'</td>';
            }
            // В заказе
            echo '<td class="total">'.fmt($rowTotal).'</td>';
            // На складе
            echo '<td class="total">'.fmt($stockQty).'</td>';
            // Дефицит (разница между заказом и складом, если заказ больше)
            $deficit = max(0, $rowTotal - $stockQty);
            $deficitClass = $deficit > 0 ? 'deficit' : '';
            echo '<td class="total '.$deficitClass.'">'.($deficit > 0 ? fmt($deficit) : '').'</td></tr>';
        }

        // Итоги по датам
        echo '<tr class="foot"><td class="left nowrap">Итого по дням</td>';
        $grand = 0;
        $totalStock = 0;
        foreach ($chunkDates as $d) {
            $col = 0;
            foreach ($items as $name) $col += $matrix[$name][$d] ?? 0;
            $grand += $col;
            echo '<td class="total">'.($col?fmt($col):'').'</td>';
        }
        // Итого в заказе
        echo '<td class="grand">'.fmt($grand).'</td>';
        // Итого на складе
        foreach ($items as $name) {
            $totalStock += $stockMap[$name] ?? 0;
        }
        echo '<td class="grand">'.fmt($totalStock).'</td>';
        // Итого дефицит
        $totalDeficit = max(0, $grand - $totalStock);
        echo '<td class="grand">'.($totalDeficit > 0 ? fmt($totalDeficit) : '').'</td></tr>';

        echo '</tbody></table></div>'; // table-wrap
        echo '</div>'; // sheet
    }

    exit;
}

/* ===== обычная загрузка страницы ===== */

// Список заявок
$orders = $pdo->query("SELECT DISTINCT order_number FROM build_plans ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Потребность по заявке (У3)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#f6f7fb; --card:#ffffff; --text:#111827; --muted:#6b7280;
            --line:#e5e7eb; --accent:#2563eb; --accent-soft:#eaf1ff;
            --week-h:#ffe6bf; --week:#fff6e8; --week-g:#fff0d6;
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
        table.pivot{border-collapse:collapse;width:100%;min-width:640px;font-size:12.5px;table-layout:fixed}
        table.pivot th, table.pivot td{border:1px solid #ddd;padding:5px 7px;text-align:center;vertical-align:middle}
        table.pivot thead th{background:#f0f0f0;font-weight:600}
        .left{text-align:left;white-space:normal;min-width:200px;width:200px}
        .nowrap{white-space:nowrap}
        table.pivot td.total{background:#f9fafb;font-weight:bold}
        table.pivot tr.foot td{background:#eef6ff;font-weight:bold}
        table.pivot td.grand{background:#e6ffe6;font-weight:bold}
        table.pivot td.deficit{background:#fee2e2;color:#991b1b;font-weight:bold}
        table.pivot td.stock-sufficient{background:#d1fae5 !important;color:#065f46;font-weight:500}
        table.pivot td.stock-insufficient{background:#fee2e2 !important;color:#991b1b;font-weight:500}
        tbody tr:nth-child(even){background:#fafafa}

        /* Недельные колонки */
        .weekcol-h{background:var(--week-h) !important; font-weight:600;}
        .weekcol{background:var(--week) !important; font-weight:600;}
        .weekcol-g{background:var(--week-g) !important; font-weight:700;}

        @media(max-width:700px){ select,button{width:100%} }

        /* Блок-страница для печати каждой части */
        .sheet{page-break-after:always;}
        .sheet:last-child{page-break-after:auto;}

        @media print{
            @page { size: A4 landscape; margin: 10mm; }
            body{background:#fff}
            .panel{display:none !important}
            .table-wrap{box-shadow:none;border-radius:0;padding:0;overflow:visible}
            table.pivot{font-size:11px;min-width:0 !important;width:auto}
            table.pivot th, table.pivot td{
                padding:3px 4px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            }
            .vertical-date{padding:2px !important;letter-spacing:.2px}
        }

        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }
        .close {
            color: #6b7280;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        .close:hover {
            color: #111827;
        }
        .request-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .request-table th,
        .request-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .request-table th {
            background: #f0f0f0;
            font-weight: 600;
        }
        .request-table tr:nth-child(even) {
            background: #fafafa;
        }
    </style>
</head>
<body>

<h2>Потребность комплектующих по заявке (У3)</h2>

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
        <option value="caps">Крышки</option>
    </select>

    <label>Дат на страницу:
        <select id="chunk">
            <?php foreach ([12,16,20,24,28,32] as $n): ?>
                <option value="<?= $n ?>" <?= $n==20?'selected':'' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <button class="btn-primary" onclick="loadPivot()">Показать потребность</button>
    <button class="btn-soft" onclick="window.print()">Печать</button>
    <button class="btn-soft" onclick="openCreateRequestModal()" id="createRequestBtn" style="display:none;">Создать заявку</button>
</div>

<div id="result"></div>

<script>
    function loadPivot(){
        const order    = document.getElementById('order').value;
        const ctype    = document.getElementById('ctype').value;
        const chunk    = document.getElementById('chunk').value;
        if(!order){ alert('Выберите заявку'); return; }
        if(!ctype){ alert('Выберите тип комплектующих'); return; }

        const xhr = new XMLHttpRequest();
        xhr.onreadystatechange=function(){
            if(this.readyState===4){
                if(this.status===200){
                    document.getElementById('result').innerHTML = this.responseText;
                    // Показываем кнопку создания заявки после загрузки данных
                    document.getElementById('createRequestBtn').style.display = 'inline-block';
                }else{
                    alert('Ошибка загрузки: '+this.status);
                }
            }
        };
        xhr.open('POST','?ajax=1',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.send(
            'order='+encodeURIComponent(order)+
            '&ctype='+encodeURIComponent(ctype)+
            '&chunk='+encodeURIComponent(chunk)
        );
    }

    function openCreateRequestModal() {
        // Собираем данные о дефиците из таблицы
        const resultDiv = document.getElementById('result');
        const table = resultDiv.querySelector('table.pivot');
        if (!table) {
            alert('Сначала загрузите данные');
            return;
        }

        const deficitData = [];
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length < 2) return;
            
            const position = cells[0].textContent.trim();
            const inOrderCell = cells[cells.length - 3]; // "В заказе"
            const inStockCell = cells[cells.length - 2];  // "На складе"
            const deficitCell = cells[cells.length - 1];  // "Дефицит"
            
            const inOrder = parseFloat(inOrderCell.textContent.trim()) || 0;
            const inStock = parseFloat(inStockCell.textContent.trim()) || 0;
            const deficit = parseFloat(deficitCell.textContent.trim()) || 0;
            
            if (deficit > 0) {
                deficitData.push({
                    position: position,
                    inOrder: inOrder,
                    inStock: inStock,
                    deficit: deficit
                });
            }
        });

        if (deficitData.length === 0) {
            alert('Нет дефицитных позиций для создания заявки');
            return;
        }

        // Формируем содержимое модального окна
        let tableHtml = '<table class="request-table">';
        tableHtml += '<thead><tr><th>Позиция</th><th>В заказе</th><th>На складе</th><th>Дефицит</th></tr></thead>';
        tableHtml += '<tbody>';
        
        deficitData.forEach(item => {
            tableHtml += '<tr>';
            tableHtml += '<td>' + escapeHtml(item.position) + '</td>';
            tableHtml += '<td>' + item.inOrder + '</td>';
            tableHtml += '<td>' + item.inStock + '</td>';
            tableHtml += '<td style="background:#fee2e2;font-weight:bold;">' + item.deficit + '</td>';
            tableHtml += '</tr>';
        });
        
        tableHtml += '</tbody></table>';

        const order = document.getElementById('order').value;
        document.getElementById('requestOrder').textContent = order;
        document.getElementById('requestTableBody').innerHTML = tableHtml;
        document.getElementById('createRequestModal').style.display = 'block';
    }

    function closeCreateRequestModal() {
        document.getElementById('createRequestModal').style.display = 'none';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Закрытие модального окна при клике вне его
    window.onclick = function(event) {
        const modal = document.getElementById('createRequestModal');
        if (event.target == modal) {
            closeCreateRequestModal();
        }
    }
</script>

<!-- Модальное окно создания заявки -->
<div id="createRequestModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">Черновик заявки на крышки</div>
            <span class="close" onclick="closeCreateRequestModal()">&times;</span>
        </div>
        <div>
            <p><strong>Заявка:</strong> <span id="requestOrder"></span></p>
            <p><strong>Дата создания:</strong> <?= date('d.m.Y H:i') ?></p>
            <p><strong>Позиции с дефицитом:</strong></p>
            <div id="requestTableBody"></div>
            <div style="margin-top: 20px; text-align: right;">
                <button class="btn-soft" onclick="closeCreateRequestModal()" style="margin-right: 10px;">Закрыть</button>
                <button class="btn-primary" onclick="printRequest()">Печать</button>
            </div>
        </div>
    </div>
</div>

<script>
    function printRequest() {
        const modalContent = document.getElementById('createRequestModal').querySelector('.modal-content');
        const printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Заявка на крышки</title>');
        printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:20px;}');
        printWindow.document.write('table{border-collapse:collapse;width:100%;margin-top:15px;}');
        printWindow.document.write('th,td{border:1px solid #ddd;padding:8px;text-align:left;}');
        printWindow.document.write('th{background:#f0f0f0;font-weight:600;}</style></head><body>');
        printWindow.document.write(modalContent.innerHTML);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.print();
    }
</script>
</body>
</html>

