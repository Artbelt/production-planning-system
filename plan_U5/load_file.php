<?php
// Подавляем deprecated warnings от PHPExcel
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

if(isset($_FILES['userfile'])) {
    $uploaddir = 'uploads/';
    $uploadfile = $uploaddir . basename($_FILES['userfile']['name']);

    $copied = copy($_FILES['userfile']['tmp_name'], $uploadfile);

    if ($copied)
    {
        echo "Файл корректен и был успешно загружен.\n";
    } else {
        echo "Неудача";
        die();
    }
}
$info = new SplFileInfo($uploadfile);
@rename ($uploadfile, "/upload/1.$info->getExtension();");
set_time_limit(0);
date_default_timezone_set('Europe/London');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Загрузка заявки U5</title>
    
    <style>
        /* ===== Modern Pro UI Design ===== */
        :root{
            --bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
            --shadow-soft: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            --shadow-hover: 0 20px 40px rgba(0,0,0,0.15), 0 8px 16px rgba(0,0,0,0.1);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg-solid); color:var(--ink);
            font: 16px/1.6 "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
            font-weight: 400;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        .container{ 
            max-width:1200px; 
            margin:0 auto; 
            padding:24px; 
            min-height: 100vh;
        }
        
        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:24px;
            margin-bottom:20px;
        }
        
        .section-title{
            font-size:24px; 
            font-weight:600; 
            color:var(--ink);
            margin:0 0 20px; 
            padding-bottom:12px; 
            border-bottom:2px solid var(--border);
        }
        
        table{
            width:100%;
            border-collapse:collapse;
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            box-shadow:var(--shadow-soft);
            overflow:hidden;
            margin:20px 0;
        }
        
        table td,table th{
            padding:12px;border-bottom:1px solid var(--border);vertical-align:top;
            text-align:left;
        }
        
        table th{
            background:#f8fafc;
            font-weight:600;
            color:var(--ink);
        }
        
        table tr:hover{
            background:#f8fafc;
        }
        
        table tr.deleted-row{
            display:none;
        }
        
        .delete-btn{
            background:#dc3545;
            color:white;
            border:none;
            border-radius:50%;
            width:24px;
            height:24px;
            cursor:pointer;
            font-size:14px;
            font-weight:bold;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            transition:all 0.2s;
            padding:0;
        }
        
        .delete-btn:hover{
            background:#c82333;
            transform:scale(1.1);
        }
        
        input[type="text"], input[type="submit"]{
            padding:10px 16px;
            border:1px solid var(--border); 
            border-radius:var(--radius-sm);
            background:#fff; 
            color:var(--ink); 
            outline:none;
            transition:all 0.2s;
            font-size:14px;
        }
        
        input[type="submit"]{
            background:var(--accent-solid);
            color:var(--accent-ink);
            border:none;
            cursor:pointer;
            font-weight:500;
        }
        
        input[type="submit"]:hover{
            opacity:0.9;
            transform:translateY(-1px);
        }
        
        .form-group{
            margin:20px 0;
            padding:20px;
            background:#f8fafc;
            border-radius:var(--radius-sm);
        }
        
        hr{
            border:none;
            border-top:2px solid var(--border);
            margin:20px 0;
        }
    </style>
</head>
<body>
    <div class="container">

<?php

/** Include path **/
set_include_path(get_include_path() . PATH_SEPARATOR . '../../../Classes/');

/** PHPExcel_IOFactory */
@include 'PHPExcel/IOFactory.php';

//$inputFileName = './upload/'.$_FILES['userfile']['name'];
@$inputFileName = $uploadfile;

echo '<div class="panel">';
echo '<div class="section-title">Заявка загружена</div>';
echo '<p>Загружен файл ' . pathinfo($inputFileName,PATHINFO_BASENAME) . '</p>';
@$objPHPExcel = PHPExcel_IOFactory::load($inputFileName);

@$sheetData = $objPHPExcel->getActiveSheet()->toArray(null,true,true,true);

/**Вывод заявки на экран */
$propusk = true;/** маркер пропуска начальной части файла и заголовков*/
$order = [];/**массив - заявка без лишних элементов, заголовков etc.*/
$workshop = 'U'.$sheetData['1']['C'];
echo '<p>для участка №'.$sheetData['1']['C'] . '<br>на период ' . $sheetData['1']['E'] . ' = ' . $sheetData['1']['F'] . '</p>';
echo '<table id="orderTable">';
echo '<tr><td><b>Фильтр</b></td><td><b>Кол-во</b></td><td><b>Маркировка</b></td><td><b>Инд.упак.</b>'
    .'</td><td><b>Этик.инд.</b></td><td><b>групп.упак.<b></td><td><b>Hорма упак.</b></td><td><b>этик.групп.</b>'
    .'</td><td><b>Примечание</b></td><td><b>Действие</b></td></tr>';

$rowIndex = 0;
foreach ($sheetData as $arr){
    if($arr['B']=='Марка фильтра') {$propusk = false; continue;}
    if(($propusk == false) && ($arr['B']!='')){/**Убираем пустые ячейки*/

        $arr = array_map(function($item) {
            if ($item !== null) {
                return str_replace(["\r", "\n"], '', $item);
            }
            return $item;
        }, $arr);

        $rowId = 'row-' . $rowIndex;
        array_push($order, $arr);
        echo '<tr id="' . $rowId . '" class="order-row" data-row-index="' . $rowIndex . '">';
        echo '<td>' . htmlspecialchars($arr['B']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['C']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['D']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['E']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['F']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['G']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['H']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['I']) . '</td>';
        echo '<td>' . htmlspecialchars($arr['J']) . '</td>';
        echo '<td><button type="button" class="delete-btn" onclick="deleteRow(' . $rowIndex . ')" title="Удалить строку">×</button></td>';
        echo '</tr>';
        $rowIndex++;
    }
}
$propusk = true;
echo '</table>';
echo '</div>'; // закрываем panel

/** Переменная для сериализации и передачи массива в следующий скрипт */
$order_json = json_encode($order, JSON_UNESCAPED_UNICODE);
$order_str = serialize($order);

echo '<div class="form-group">';
echo '<form action="save_order_into_DB.php" method="post" id="saveOrderForm">';
echo '<label for="order_name">Присвоить номер заявке:</label><br><br>';
echo '<input name="order_name" type="text" placeholder="№X-X" id="order_name" style="width:200px; margin-right:10px;"/>';
echo "<input type='hidden' name='order_str' id='order_str' value='" . htmlspecialchars($order_str, ENT_QUOTES, 'UTF-8') . "'/>";
echo "<input type='hidden' name='workshop' value='$workshop'/>";
echo "<input type='submit' value=' и сохранить в БД'/>";
echo "</form>";
echo '</div>';

echo "<script>";
echo "var originalOrderData = " . str_replace('</script>', '<\\/script>', $order_json) . ";";
echo "var deletedRows = [];";
echo "</script>";
?>

<script>
function deleteRow(rowIndex) {
    if (confirm('Вы уверены, что хотите удалить эту строку?')) {
        var row = document.getElementById('row-' + rowIndex);
        if (row) {
            row.classList.add('deleted-row');
            deletedRows.push(rowIndex);
            updateOrderData();
        }
    }
}

function updateOrderData() {
    var filteredOrder = [];
    for (var i = 0; i < originalOrderData.length; i++) {
        if (deletedRows.indexOf(i) === -1) {
            filteredOrder.push(originalOrderData[i]);
        }
    }
    
    var formData = new FormData();
    formData.append('action', 'serialize_order');
    formData.append('order_data', JSON.stringify(filteredOrder));
    
    fetch('serialize_order.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(serializedData => {
        document.getElementById('order_str').value = serializedData;
        var visibleRows = document.querySelectorAll('#orderTable tr.order-row:not(.deleted-row)').length;
        console.log('Осталось строк: ' + visibleRows);
    })
    .catch(error => {
        console.error('Ошибка сериализации:', error);
        alert('Ошибка при обновлении данных. Пожалуйста, обновите страницу.');
    });
}

document.getElementById('saveOrderForm').addEventListener('submit', function(e) {
    var visibleRows = document.querySelectorAll('#orderTable tr.order-row:not(.deleted-row)').length;
    if (visibleRows === 0) {
        e.preventDefault();
        alert('Нельзя сохранить заявку без строк! Пожалуйста, оставьте хотя бы одну строку.');
        return false;
    }
    
    if (deletedRows.length > 0) {
        e.preventDefault();
        var form = this;
        
        var formData = new FormData();
        formData.append('action', 'serialize_order');
        var filteredOrder = [];
        for (var i = 0; i < originalOrderData.length; i++) {
            if (deletedRows.indexOf(i) === -1) {
                filteredOrder.push(originalOrderData[i]);
            }
        }
        formData.append('order_data', JSON.stringify(filteredOrder));
        
        fetch('serialize_order.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(serializedData => {
            document.getElementById('order_str').value = serializedData;
            form.submit();
        })
        .catch(error => {
            console.error('Ошибка сериализации:', error);
            alert('Ошибка при обновлении данных. Пожалуйста, обновите страницу и попробуйте снова.');
        });
        
        return false;
    }
});
</script>

    </div>
</body>
</html>