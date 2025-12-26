<?php
// NP_supply_requirements.php — потребность по конкретной заявке для У3
// Все данные отображаются в одной таблице без разбиения на страницы

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U3;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

/* ===== AJAX: экспорт в Excel ===== */
if (isset($_GET['export']) && $_GET['export']=='excel') {
    $order = $_GET['order'] ?? '';
    $ctype = $_GET['ctype'] ?? '';
    
    if ($order==='' || $ctype==='') {
        http_response_code(400);
        echo "Не указана заявка или тип комплектующих.";
        exit;
    }
    
    // Подключаем PHPExcel
    require_once __DIR__ . '/PHPExcel.php';
    
    // Получаем данные (та же логика, что и для отображения)
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
        http_response_code(404);
        echo "По заявке ".htmlspecialchars($order)." для крышек данных нет.";
        exit;
    }
    
    // Пивот-структура
    $dates  = [];
    $items  = [];
    $matrix = [];
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
    
    // Предрасчёт накопленной потребности
    $cumulativeDemand = [];
    foreach ($items as $name) {
        $cumulative = 0;
        foreach ($dates as $d) {
            $cumulative += $matrix[$name][$d] ?? 0;
            $cumulativeDemand[$name][$d] = $cumulative;
        }
    }
    
    // Создаем Excel файл
    $objPHPExcel = new PHPExcel();
    $objPHPExcel->setActiveSheetIndex(0);
    $sheet = $objPHPExcel->getActiveSheet();
    $sheet->setTitle('Потребность');
    
    // Заголовок
    $lastCol = PHPExcel_Cell::stringFromColumnIndex(count($dates) + 3);
    $sheet->setCellValue('A1', 'Заявка ' . $order . ': потребность — крышки');
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
    
    // Заголовки столбцов
    $col = 1;
    $sheet->setCellValueByColumnAndRow($col, 2, 'Позиция');
    $col++;
    
    foreach ($dates as $d) {
        $ts = strtotime($d);
        $sheet->setCellValueByColumnAndRow($col, 2, date('d-m-y', $ts));
        $col++;
    }
    
    $sheet->setCellValueByColumnAndRow($col, 2, 'В заказе');
    $col++;
    $sheet->setCellValueByColumnAndRow($col, 2, 'На складе');
    $col++;
    $sheet->setCellValueByColumnAndRow($col, 2, 'Дефицит');
    
    // Стили для заголовков
    $headerRange = 'A2:' . PHPExcel_Cell::stringFromColumnIndex($col - 1) . '2';
    $sheet->getStyle($headerRange)->getFont()->setBold(true);
    $sheet->getStyle($headerRange)->getFill()
        ->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
        ->getStartColor()->setRGB('F0F0F0');
    $sheet->getStyle($headerRange)->getAlignment()
        ->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER)
        ->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
    $sheet->getStyle($headerRange)->getBorders()->getAllBorders()
        ->setBorderStyle(PHPExcel_Style_Border::BORDER_THIN);
    
    // Данные
    $row = 3;
    foreach ($items as $name) {
        $col = 1;
        $rowTotal = 0;
        $stockQty = $stockMap[$name] ?? 0;
        
        $sheet->setCellValueByColumnAndRow($col, $row, $name);
        $col++;
        
        foreach ($dates as $d) {
            $v = (float)($matrix[$name][$d] ?? 0);
            $rowTotal += $v;
            
            if ($v > 0) {
                $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
                $sheet->setCellValue($cellAddress, (string)$v);
                $cumulative = (float)($cumulativeDemand[$name][$d] ?? 0);
                if ($stockQty > 0 && $cumulative <= $stockQty) {
                    $sheet->getStyle($cellAddress)->getFill()
                        ->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('D1FAE5');
                } elseif ($cumulative > $stockQty) {
                    $sheet->getStyle($cellAddress)->getFill()
                        ->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FEE2E2');
                }
            }
            $col++;
        }
        
        // В заказе
        $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
        $sheet->setCellValue($cellAddress, (string)$rowTotal);
        $sheet->getStyle($cellAddress)->getFont()->setBold(true);
        $col++;
        
        // На складе
        $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
        $sheet->setCellValue($cellAddress, (string)$stockQty);
        $sheet->getStyle($cellAddress)->getFont()->setBold(true);
        $col++;
        
        // Дефицит
        $deficit = max(0, $rowTotal - $stockQty);
        if ($deficit > 0) {
            $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
            $sheet->setCellValue($cellAddress, (string)$deficit);
            $sheet->getStyle($cellAddress)->getFont()->setBold(true);
            $sheet->getStyle($cellAddress)->getFill()
                ->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FEE2E2');
        }
        
        $row++;
    }
    
    // Итоги
    $col = 1;
    $sheet->setCellValueByColumnAndRow($col, $row, 'Итого по дням');
    $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
    $sheet->getStyle($cellAddress)->getFont()->setBold(true);
    $col++;
    
    $grand = 0;
    $totalStock = 0;
    foreach ($dates as $d) {
        $colTotal = 0;
        foreach ($items as $name) $colTotal += (float)($matrix[$name][$d] ?? 0);
        $grand += $colTotal;
        if ($colTotal > 0) {
            $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
            $sheet->setCellValue($cellAddress, (string)$colTotal);
        }
        $col++;
    }
    
    $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
    $sheet->setCellValue($cellAddress, (string)$grand);
    $sheet->getStyle($cellAddress)->getFont()->setBold(true);
    $col++;
    
    foreach ($items as $name) {
        $totalStock += (int)($stockMap[$name] ?? 0);
    }
    $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
    $sheet->setCellValue($cellAddress, (string)$totalStock);
    $sheet->getStyle($cellAddress)->getFont()->setBold(true);
    $col++;
    
    $totalDeficit = max(0, $grand - $totalStock);
    if ($totalDeficit > 0) {
        $cellAddress = PHPExcel_Cell::stringFromColumnIndex($col - 1) . $row;
        $sheet->setCellValue($cellAddress, (string)$totalDeficit);
        $sheet->getStyle($cellAddress)->getFont()->setBold(true);
    }
    
    // Границы для всех ячеек
    $lastCol = PHPExcel_Cell::stringFromColumnIndex($col - 1);
    $dataRange = 'A2:' . $lastCol . $row;
    $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
        ->setBorderStyle(PHPExcel_Style_Border::BORDER_THIN);
    
    // Автоширина столбцов
    foreach (range(0, $col - 1) as $colNum) {
        $colLetter = PHPExcel_Cell::stringFromColumnIndex($colNum);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }
    
    // Настройки печати
    $sheet->getPageSetup()->setOrientation(PHPExcel_Worksheet_PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(PHPExcel_Worksheet_PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageSetup()->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.5);
    $sheet->getPageMargins()->setRight(0.5);
    $sheet->getPageMargins()->setLeft(0.5);
    $sheet->getPageMargins()->setBottom(0.5);
    
    // Повторение заголовков на каждой странице
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 2);
    
    // Отправка файла
    $filename = 'Потребность_' . $order . '_' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
    $objWriter->save('php://output');
    exit;
}

/* ===== AJAX: отрисовать только таблицы ===== */
if (isset($_GET['ajax']) && $_GET['ajax']=='1') {
    $order     = $_POST['order']  ?? '';
    $ctype     = $_POST['ctype']  ?? '';           // caps (крышки)

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


    // Создаем одну таблицу со всеми датами
        echo '<div class="table-wrap"><table class="pivot">';
        echo '<thead><tr><th class="left">Позиция</th>';
    foreach ($dates as $d) {
            $ts = strtotime($d);
            echo '<th class="nowrap vertical-date">' . date('d-m-y', $ts) . '</th>';
        }
        echo '<th class="nowrap vertical-date">В заказе</th><th class="nowrap vertical-date">На складе</th><th class="nowrap vertical-date">Дефицит</th></tr></thead><tbody>';

        // Строки с позициями
        foreach ($items as $name) {
            $rowTotal = 0;
            $stockQty = $stockMap[$name] ?? 0;
            echo '<tr><td class="left">'.htmlspecialchars($name).'</td>';
        foreach ($dates as $d) {
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
    foreach ($dates as $d) {
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
            max-width:1200px;margin:0 auto 12px;background:#fff;border-radius:10px;
            padding:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);
            display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center
        }
        .vertical-date{
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            padding: 6px 4px;
            width: 26px;
            min-width: 26px;
            max-width: 26px;
            font-size: 11.5px;
            line-height: 1.3;
            box-sizing: border-box;
        }
        label{white-space:nowrap; display:flex; align-items:center; gap:6px}
        select,button{padding:7px 10px;font-size:13px;border:1px solid var(--line);border-radius:8px;background:#fff}
        input[type="checkbox"]{transform:translateY(1px)}
        button{cursor:pointer;font-weight:600}
        .btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
        .btn-soft{background:var(--accent-soft);color:var(--accent);border-color:#cfe0ff}
        #result{width:100%;margin:0 auto}

        .subtitle{margin:6px 0 8px}

        .table-wrap{overflow-x:auto;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:6px;margin-bottom:14px;width:100%}
        table.pivot{border-collapse:collapse;width:100%;min-width:640px;font-size:11px;table-layout:fixed}
        table.pivot th, table.pivot td{border:1px solid #ddd;padding:3px 4px;text-align:center;vertical-align:middle;line-height:1.2}
        table.pivot thead th{background:#f0f0f0;font-weight:600}
        .left{text-align:left;white-space:normal;min-width:140px;width:140px;max-width:140px;font-size:10.5px}
        .nowrap{white-space:nowrap}
        table.pivot td.total{background:#f9fafb;font-weight:bold;min-width:100px;width:100px}
        table.pivot tr.foot td{background:#eef6ff;font-weight:bold}
        table.pivot td.grand{background:#e6ffe6;font-weight:bold;min-width:100px;width:100px}
        table.pivot td.deficit{background:#fee2e2;color:#991b1b;font-weight:bold;min-width:100px;width:100px}
        table.pivot th.vertical-date:last-child,
        table.pivot th.vertical-date:nth-last-child(2){
            min-width:44px;
            width:44px;
            max-width:44px;
        }
        table.pivot th.vertical-date:nth-last-child(3){
            min-width:44px;
            width:44px;
            max-width:44px;
        }
        table.pivot td.stock-sufficient{background:#d1fae5 !important;color:#065f46;font-weight:500}
        table.pivot td.stock-insufficient{background:#fee2e2 !important;color:#991b1b;font-weight:500}
        tbody tr:nth-child(even){background:#fafafa}

        /* Недельные колонки */
        .weekcol-h{background:var(--week-h) !important; font-weight:600;}
        .weekcol{background:var(--week) !important; font-weight:600;}
        .weekcol-g{background:var(--week-g) !important; font-weight:700;}

        @media(max-width:700px){ select,button{width:100%} }


        @media print{
            @page { 
                size: A4 landscape; 
                margin: 8mm 5mm;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            body{
                background:#fff !important;
                margin:0;
                padding:0;
                font-size:10px;
            }
            h2, h3{
                margin:0 0 8px 0;
                page-break-after:avoid;
            }
            .panel{
                display:none !important;
            }
            .subtitle{
                margin:0 0 6px 0;
                font-size:12px;
            }
            .table-wrap{
                box-shadow:none !important;
                border-radius:0 !important;
                padding:0 !important;
                margin:0 !important;
                overflow:visible !important;
                width:100% !important;
                page-break-inside:avoid;
            }
            table.pivot{
                font-size:9px !important;
                min-width:100% !important;
                width:100% !important;
                table-layout:fixed !important;
                border-collapse:collapse !important;
                page-break-inside:auto;
            }
            table.pivot thead{
                display:table-header-group;
            }
            table.pivot thead th{
                background:#f0f0f0 !important;
                font-weight:600 !important;
                padding:4px 3px !important;
                border:1px solid #000 !important;
                page-break-after:avoid;
            }
            table.pivot tbody tr{
                page-break-inside:avoid;
                page-break-after:auto;
            }
            table.pivot tbody td{
                padding:3px 2px !important;
                border:1px solid #000 !important;
                white-space:nowrap !important;
                overflow:visible !important;
                text-overflow:clip !important;
            }
            .vertical-date{
                padding:3px 1px !important;
                font-size:8px !important;
                letter-spacing:0 !important;
                width:18px !important;
                min-width:18px !important;
                max-width:18px !important;
            }
            .left{
                min-width:120px !important;
                width:120px !important;
                max-width:120px !important;
                font-size:9px !important;
                white-space:normal !important;
            }
            table.pivot td.total{
                min-width:80px !important;
                width:80px !important;
                font-weight:bold !important;
            }
            table.pivot td.grand,
            table.pivot td.deficit{
                min-width:80px !important;
                width:80px !important;
                font-weight:bold !important;
            }
            table.pivot th.vertical-date:last-child,
            table.pivot th.vertical-date:nth-last-child(2){
                min-width:44px !important;
                width:44px !important;
                max-width:44px !important;
            }
            table.pivot th.vertical-date:nth-last-child(3){
                min-width:44px !important;
                width:44px !important;
                max-width:44px !important;
            }
            #result{
                width:100% !important;
                max-width:100% !important;
                margin:0 !important;
            }
            #createRequestModal{
                display:none !important;
            }
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



<div class="panel">

    <label>Потребность комплектующих по заявке:</label>
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

    <button class="btn-primary" onclick="loadPivot()">Показать потребность</button>

    <button class="btn-soft" onclick="exportToExcel()" id="exportExcelBtn" style="display:none;">Экспорт в Excel</button>
    <button class="btn-soft" onclick="openCreateRequestModal()" id="createRequestBtn" style="display:none;">Создать заявку</button>
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
                    // Показываем кнопки после загрузки данных
                    document.getElementById('createRequestBtn').style.display = 'inline-block';
                    document.getElementById('exportExcelBtn').style.display = 'inline-block';
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

    function openCreateRequestModal() {
        // Собираем данные о дефиците из таблицы
        const resultDiv = document.getElementById('result');
        const table = resultDiv.querySelector('table.pivot');
        if (!table) {
            alert('Сначала загрузите данные');
            return;
        }

        // Получаем заголовки с датами
        const headerRow = table.querySelector('thead tr');
        const dateHeaders = Array.from(headerRow.querySelectorAll('th.vertical-date'));
        const dateHeadersText = dateHeaders.map(th => th.textContent.trim()).slice(0, -3); // Исключаем последние 3 столбца
        
        // Собираем данные по плану сборки и остаткам на складе для каждой позиции
        const planData = {}; // {position: {date: qty}}
        const stockData = {}; // {position: stockQty}
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            // Пропускаем строку "Итого по дням"
            if (row.classList.contains('foot')) return;
            
            const cells = row.querySelectorAll('td');
            if (cells.length < 2) return;
            
            const position = cells[0].textContent.trim();
            if (position === 'Итого по дням') return;
            
            // Получаем остаток на складе (предпоследний столбец)
            const inStockCell = cells[cells.length - 2];
            const stockQty = parseFloat(inStockCell.textContent.trim()) || 0;
            stockData[position] = stockQty;
            
            if (!planData[position]) {
                planData[position] = {};
            }
            
            // Собираем данные по датам (исключаем последние 3 столбца)
            const dateCells = Array.from(cells).slice(1, -3);
            dateCells.forEach((cell, index) => {
                if (index < dateHeadersText.length) {
                    const date = dateHeadersText[index];
                    const qty = parseFloat(cell.textContent.trim()) || 0;
                    if (qty > 0) {
                        if (!planData[position][date]) {
                            planData[position][date] = 0;
                        }
                        planData[position][date] += qty;
                    }
                }
            });
        });
        
        // Определяем партии для каждой позиции и проверяем дефицит
        const batches = [];
        
        Object.keys(planData).forEach(position => {
            const stockQty = stockData[position] || 0;
            
            // Собираем даты и сортируем их правильно (по дате, а не по строке)
            const dates = Object.keys(planData[position])
                .filter(d => planData[position][d] > 0)
                .sort((a, b) => {
                    // Преобразуем даты для корректного сравнения
                    const dateA = convertDateToInput(a);
                    const dateB = convertDateToInput(b);
                    if (dateA < dateB) return -1;
                    if (dateA > dateB) return 1;
                    return 0;
                });
            
            if (dates.length === 0) return;
            
            // Рассчитываем накопленную потребность по датам
            let cumulativeBefore = 0; // Накопленная потребность до текущей даты
            
            // Группируем даты в партии по непрерывности
            let currentBatch = {
                position: position,
                startDate: dates[0],
                dates: [dates[0]],
                qty: planData[position][dates[0]],
                cumulativeBefore: 0 // Накопленная потребность до начала партии
            };
            
            // Обновляем накопленную потребность после первой даты
            cumulativeBefore = planData[position][dates[0]];
            
            for (let i = 1; i < dates.length; i++) {
                // Преобразуем даты из формата "d-m-y" в формат для сравнения
                const prevDateStr = convertDateToInput(dates[i - 1]);
                const currDateStr = convertDateToInput(dates[i]);
                const prevDate = new Date(prevDateStr + 'T00:00:00');
                const currDate = new Date(currDateStr + 'T00:00:00');
                const daysDiff = (currDate - prevDate) / (1000 * 60 * 60 * 24);
                
                // Если пропущена смена (больше 1 дня) - начинается новая партия
                if (daysDiff > 1) {
                    // Проверяем, есть ли дефицит в текущей партии
                    // Накопленная потребность на конец партии = накопленная до начала + размер партии
                    const batchEndCumulative = currentBatch.cumulativeBefore + currentBatch.qty;
                    
                    // Если накопленная потребность на конец партии превышает остаток - есть дефицит
                    if (batchEndCumulative > stockQty) {
                        // Количество дефицита = сколько не хватает на конец партии
                        const batchDeficit = batchEndCumulative - stockQty;
                        
                        // Но нужно учесть, что если остаток покрывает начало партии, 
                        // то дефицит только на часть партии
                        const deficitAtStart = Math.max(0, currentBatch.cumulativeBefore - stockQty);
                        const actualDeficit = deficitAtStart > 0 ? currentBatch.qty : batchDeficit;
                        
                        batches.push({
                            position: currentBatch.position,
                            qty: actualDeficit,
                            date: convertDateToInput(currentBatch.startDate)
                        });
                    }
                    
                    // Обновляем накопленную потребность (на конец предыдущей партии)
                    cumulativeBefore = batchEndCumulative;
                    
                    // Начинаем новую партию
                    currentBatch = {
                        position: position,
                        startDate: dates[i],
                        dates: [dates[i]],
                        qty: planData[position][dates[i]],
                        cumulativeBefore: cumulativeBefore
                    };
                    
                    // Обновляем накопленную потребность после добавления новой даты
                    cumulativeBefore += planData[position][dates[i]];
                } else {
                    // Продолжаем текущую партию
                    currentBatch.dates.push(dates[i]);
                    currentBatch.qty += planData[position][dates[i]];
                    
                    // Обновляем накопленную потребность
                    cumulativeBefore += planData[position][dates[i]];
                }
            }
            
            // Проверяем последнюю партию на дефицит
            const batchEndCumulative = currentBatch.cumulativeBefore + currentBatch.qty;
            
            // Если накопленная потребность на конец партии превышает остаток - есть дефицит
            if (batchEndCumulative > stockQty) {
                const batchDeficit = batchEndCumulative - stockQty;
                
                // Учитываем, покрывает ли остаток начало партии
                const deficitAtStart = Math.max(0, currentBatch.cumulativeBefore - stockQty);
                const actualDeficit = deficitAtStart > 0 ? currentBatch.qty : batchDeficit;
                
                batches.push({
                    position: currentBatch.position,
                    qty: actualDeficit,
                    date: convertDateToInput(currentBatch.startDate)
                });
            }
        });
        
        if (batches.length === 0) {
            alert('Нет данных для создания заявки');
            return;
        }

        // Сортируем партии по дате, затем по позиции
        batches.sort((a, b) => {
            if (a.date < b.date) return -1;
            if (a.date > b.date) return 1;
            if (a.position < b.position) return -1;
            if (a.position > b.position) return 1;
            return 0;
        });

        // Формируем содержимое модального окна
        let tableHtml = '<table class="request-table">';
        tableHtml += '<thead><tr><th>Крышка</th><th>Количество</th><th>Дата</th></tr></thead>';
        tableHtml += '<tbody>';
        
        batches.forEach((batch, index) => {
            tableHtml += '<tr data-index="' + index + '">';
            tableHtml += '<td>' + escapeHtml(batch.position) + '</td>';
            tableHtml += '<td style="font-weight:bold;">' + Math.round(batch.qty) + '</td>';
            tableHtml += '<td><input type="date" class="batch-date-input" value="' + batch.date + '" data-index="' + index + '" style="width:100%;padding:4px;border:1px solid #ddd;border-radius:4px;"></td>';
            tableHtml += '</tr>';
        });
        
        tableHtml += '</tbody></table>';

        const order = document.getElementById('order').value;
        document.getElementById('requestOrder').textContent = order;
        document.getElementById('requestTableBody').innerHTML = tableHtml;
        document.getElementById('createRequestModal').style.display = 'block';
        
        // Сохраняем данные партий
        window.batchesArray = batches;
    }

    function closeCreateRequestModal() {
        document.getElementById('createRequestModal').style.display = 'none';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function exportToExcel() {
        const order = document.getElementById('order').value;
        const ctype = document.getElementById('ctype').value;
        if(!order){ alert('Выберите заявку'); return; }
        if(!ctype){ alert('Выберите тип комплектующих'); return; }
        
        window.location.href = '?export=excel&order=' + encodeURIComponent(order) + '&ctype=' + encodeURIComponent(ctype);
    }

    function convertDateToInput(dateStr) {
        // Преобразуем формат dd-mm-yy в yyyy-mm-dd для input[type="date"]
        // dateStr в формате "25-12-25" (dd-mm-yy)
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return '';
        const day = parts[0].padStart(2, '0');
        const month = parts[1].padStart(2, '0');
        const year = '20' + parts[2]; // Преобразуем yy в yyyy
        return year + '-' + month + '-' + day;
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

