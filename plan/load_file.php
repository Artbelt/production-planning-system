<?php
// Подавляем deprecated warnings от PHPExcel
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once __DIR__ . '/PHPExcel/Classes/PHPExcel.php';

// Скрипт ожидает файл, отправленный формой POST (name="userfile").
// При обычном GET-запросе без файла возможны фатальные ошибки (PHP 8+ -> 500).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['userfile'])) {
    http_response_code(400);
    exit('Нет загруженного файла (ожидается POST multipart/form-data с input name="userfile").');
}

if (!is_array($_FILES['userfile']) || !isset($_FILES['userfile']['tmp_name'], $_FILES['userfile']['name'], $_FILES['userfile']['error'])) {
    http_response_code(400);
    exit('Некорректные данные загрузки файла.');
}

if ($_FILES['userfile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Ошибка загрузки файла. Код: ' . (int)$_FILES['userfile']['error']);
}

$uploaddir = __DIR__ . '/uploads/';
if (!is_dir($uploaddir)) {
    http_response_code(500);
    exit('На сервере отсутствует папка для загрузок: ' . htmlspecialchars($uploaddir, ENT_QUOTES, 'UTF-8'));
}

$uploadfile = $uploaddir . basename($_FILES['userfile']['name']);
if (!move_uploaded_file($_FILES['userfile']['tmp_name'], $uploadfile)) {
    http_response_code(500);
    exit('Не удалось сохранить загруженный файл на сервере.');
}

set_time_limit(0);
date_default_timezone_set('Europe/London');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Загрузка заявки</title>
    
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
        
        /* Import modern font */
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
// PHPExcel ожидает структуру, где IOFactory лежит в PHPExcel/IOFactory.php
// относительно директории PHPExcel/Classes/.
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . '/PHPExcel/Classes/');

/** PHPExcel_IOFactory */
require_once 'PHPExcel/IOFactory.php';

//$inputFileName = './upload/'.$_FILES['userfile']['name'];
$inputFileName = $uploadfile;

echo '<div class="panel">';
echo '<div class="section-title">Заявка загружена</div>';
echo '<p>Загружен файл ' . pathinfo($inputFileName,PATHINFO_BASENAME) . '</p>';
try {
    $objPHPExcel = PHPExcel_IOFactory::load($inputFileName);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Ошибка при чтении Excel-файла: ' . $e->getMessage());
}

@$sheetData = $objPHPExcel->getActiveSheet()->toArray(null,true,true,true);

/**Вывод заявки на экран */
$propusk = true;/** маркер пропуска начальной части файла и заголовков*/
$order = [];/**массив - заявка без лишних элементов, заголовков etc.*/
$workshop = 'U'.$sheetData['1']['C'];
echo '<p>для участка №'.$sheetData['1']['C'] . '<br>на период ' . $sheetData['1']['E'] . ' = ' . $sheetData['1']['F'] . '</p>';
echo '<table>';
echo '<tr><td><b>Фильтр</b></td><td><b>Кол-во</b></td><td><b>Маркировка</b></td><td><b>Инд.упак.</b>'
    .'</td><td><b>Этик.инд.</b></td><td><b>групп.упак.<b></td><td><b>Hорма упак.</b></td><td><b>этик.групп.</b>'
    .'</td><td><b>Примечание</b></td></tr>';
foreach ($sheetData as $arr){
    if($arr['B']=='Марка фильтра') {$propusk = false; continue;}
    if(($propusk == false) && ($arr['B']!='')){/**Убираем пустые ячейки*/
        array_push($order, $arr);
        echo '<tr><td>' . $arr['B'] . '</td><td>' . $arr['C'] . '</td><td>' . $arr['D'] . '</td><td>' . $arr['E'] . '</td><td>' . $arr['F']
            . '</td><td>' . $arr['G'] . '</td><td>' . $arr['H'] . '</td><td>' . $arr['I'] . '</td><td>' . $arr['J'] . '</td></tr>';
    }
}
$propusk = true;
echo '</table>';
echo '</div>'; // закрываем panel

/** Переменная для сериализации и передачи массива в следующий скрипт */
$order_str = serialize($order);
$order_str_b64 = base64_encode($order_str); // base64 надежно переносит байты через HTML

echo '<div class="form-group">';
echo '<form action="save_order_into_DB.php" method="post">';
echo '<label for="order_name">Присвоить номер заявке:</label><br><br>';
echo '<input name="order_name" type="text" placeholder="№X-X" id="order_name" style="width:200px; margin-right:10px;"/>';
echo "<input type='hidden' name='order_str' value='" . htmlspecialchars($order_str_b64, ENT_QUOTES, 'UTF-8') . "'/>";
echo "<input type='hidden' name='workshop' value='$workshop'/>";
echo "<input type='submit' value=' и сохранить в БД'/>";
echo "</form>";
echo '</div>';
?>
    </div>
</body>
</html>