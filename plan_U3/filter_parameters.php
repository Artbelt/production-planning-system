<?php /** filter_parameters.php  файл отображает инофрмацию про конструктив фильтра */

require_once('tools/tools.php');
require_once('settings.php');

/** @var  $filter  содержит наименование фильтра параметры которого мы запрашиваем*/
$filter = $_POST['filter_name'];

/** Если наименование фильтра не передано сценарию - просто прекращаем работу сценария */
if (!$filter) {exit();}

?>
    <div id="header" style="background-color: #5450ff; height: 50px; width: 100%; font-family: Calibri; font-size: 20px">
        <p style="color: white"><?php echo $filter; ?>:</p>
    </div>

<?php



/** Создаем подключение к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$stmt = $pdo->prepare("SELECT * FROM panel_filter_structure WHERE filter = ?");
$stmt->execute([$filter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $filter_data) {

    $type_of_filter = $filter_data['category'];
    $paper_package = $filter_data['paper_package'];
    $wireframe = $filter_data['wireframe'];
    $prefilter = $filter_data['prefilter'];
    $box = $filter_data['box'];
    $g_box = $filter_data['g_box'];
    $comment = $filter_data['comment'];
}

$stmt_paper = $pdo->prepare("SELECT * FROM paper_package_panel WHERE p_p_name = ?");
$stmt_paper->execute([$paper_package]);
$result_paper = $stmt_paper->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result_paper as $paper_package_data) {

        $p_p_length = $paper_package_data['p_p_length'];
        $p_p_height = $paper_package_data['p_p_height'];
        $p_p_width = $paper_package_data['p_p_width'];
        $p_p_pleats_count = $paper_package_data['p_p_pleats_count'];
        $p_p_amplifier = $paper_package_data['p_p_amplifier'];
        $p_p_remark = $paper_package_data['p_p_remark'];
    }

$stmt_wf = $pdo->prepare("SELECT * FROM wireframe_panel WHERE w_name = ?");
$stmt_wf->execute([$wireframe]);
foreach ($stmt_wf->fetchAll(PDO::FETCH_ASSOC) as $wireframe_data) {
    $w_length = $wireframe_data['w_length'];
    $w_width = $wireframe_data['w_width'];
    $w_material = $wireframe_data['w_material'];
}

$stmt_pf = $pdo->prepare("SELECT * FROM prefilter_panel WHERE p_name = ?");
$stmt_pf->execute([$prefilter]);
foreach ($stmt_pf->fetchAll(PDO::FETCH_ASSOC) as $prefilter_data) {
    $p_length = $prefilter_data['p_length'];
    $p_width = $prefilter_data['p_width'];
    $p_material = $prefilter_data['p_material'];
    $p_remark = $prefilter_data['p_remark'];
}

/** Выводим данные */

    echo "Тип фильтра: ".$type_of_filter."<br>";
    echo "<p>";
    echo "<table style='border: 1px solid black; border-collapse: collapse; font-size: 14px;'>";
    echo "<tr><td  style=' border: 1px solid black'>Гофропакет: </td><td  style=' border: 1px solid black'>".$paper_package."</td></tr>";
    echo "<tr><td  style=' border: 1px solid black'>Длина : </td><td  style=' border: 1px solid black'> ".$p_p_length."</td></tr>";
    echo "<tr><td  style=' border: 1px solid black'>Высота : </td><td  style=' border: 1px solid black'> ".$p_p_height."</td></tr>";
    echo "<tr><td  style=' border: 1px solid black'>Ширина : </td><td  style=' border: 1px solid black'> ".$p_p_width."</td></tr>>";
    echo "<tr><td  style=' border: 1px solid black'>Количество ребер:  </td><td  style=' border: 1px solid black'>".$p_p_pleats_count."</td></tr>";
    echo "<tr><td  style=' border: 1px solid black'>Усилитель:  </td><td  style=' border: 1px solid black'>".$p_p_amplifier."</td></tr>";
    echo "<tr><td  style=' border: 1px solid black'>Комментарий:  </td><td  style=' border: 1px solid black'>".$p_p_remark."</td></tr>";
    echo "</table>";
    echo "<p>";
    if ($wireframe == ''){echo "Каркаса нет<br>";}
    else {echo "".$wireframe."<br>";
          echo "Длина :".$w_length."<br>";
          echo "Ширина :".$w_width."<br>";
          echo "Материал :".$w_material."<br>";
    }
    echo "<p>";
    if ($prefilter == ''){echo "Предфильтра нет<br>";}
    else {echo " ".$prefilter."<br>";
        echo "Длина :".$p_length."<br>";
        echo "Ширина :".$p_width."<br>";
        echo "Материал :".$p_material."<br>";
        echo "Комментарий :".$p_remark."<br>";
    }
    echo "<p>";
    echo "Упаковка: <br>";
    echo "индивидуальная №: ".$box."<br>";
    echo "групповая №: ".$g_box."<br><br>";
    echo "Комментарий: ".$comment."<br>";




echo '</form>';
