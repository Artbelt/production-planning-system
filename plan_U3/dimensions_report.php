<?php
/** подключение фалйа настроек */
require_once('settings.php') ;
require_once('tools/tools.php') ;


//print_r($_POST);

/**------------------------------------ ШАпка таблицы склад-------------------------*/
echo "<table border='1'; style='border-collapse: collapse; font-family: Calibri'>";
echo "<caption>Параметры фильтров<p></caption>";
echo "<tr align='center'><td>Фильтр</td><td width='50'>Диаметр наружный</td><td  width='60'>Диаметр внутренний верх</td>
<td  width='50'>Диаметр внутренний низ</td><td>Высота</td><td  width='50'>Верхняя крышка</td><td  width='50'>Нижняя крышка</td>
<td>РР вставка</td><td>Предфильтр</td><td>Упаковка</td><td>Примечание</td></tr>";

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$sql = "SELECT * FROM list_of_caps order by name_of_cap ASC;";

$sql= "SELECT 
round_filter_structure.filter, 
round_filter_structure.Diametr_outer, 
round_filter_structure.Diametr_inner_1, 
round_filter_structure.Diametr_inner_2, 
round_filter_structure.Height,
round_filter_structure.up_cap,
round_filter_structure.down_cap,
round_filter_structure.PU_up_cap,
round_filter_structure.PU_down_cap,
round_filter_structure.plastic_insertion,
round_filter_structure.prefilter,
round_filter_structure.comment,
round_filter_structure.packing
FROM round_filter_structure  WHERE filter NOT LIKE '%pe%' order by filter ASC";
//FROM round_filter_structure  WHERE filter LIKE '%AF%' AND filter NOT LIKE '%pe%' order by filter ASC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);


foreach ($rows as $filter_params) {
    $filter = $filter_params['filter'];
    $diameter_out = $filter_params['Diametr_outer'];
    $diameter_inner_1 = $filter_params['Diametr_inner_1'];
    $diameter_inner_2 = $filter_params['Diametr_inner_2'];
    $height = $filter_params['Height'];
    $up_cap_metal = $filter_params['up_cap'];
    $down_cap_metal = $filter_params['down_cap'];
    $PU_up_cap = $filter_params['PU_up_cap'];
    $PU_down_cap = $filter_params['PU_down_cap'];
    $plastic_insertion = $filter_params['plastic_insertion'];
    $prefilter = $filter_params['prefilter'];
    $packing = $filter_params['packing'];
    $comment = $filter_params['comment'];

    /** Проверка верхней крышки */
    if ($up_cap_metal != '') {
        $up_cap = $up_cap_metal. ' [M]';
    } else {
        $up_cap = $PU_up_cap. ' [PU]';
    }

    /** проверка нижней крышки*/
    if ($down_cap_metal != ''){
            $down_cap= $down_cap_metal . ' [M]';
    } else {
            $down_cap = $PU_down_cap . ' [PU]' ;
    }

    if ($diameter_inner_2 < 1) {
        $diameter_inner_2 = '';
    }

    echo "<tr align='center'>";
    echo "<td>".$filter."</td>";
    echo "<td>".$diameter_out."</td>";
    echo "<td>".$diameter_inner_1."</td>";
    echo "<td>".$diameter_inner_2."</td>";
    echo "<td>".$height."</td>";
    echo "<td>".$up_cap."</td>";
    echo "<td>".$down_cap."</td>";
    echo "<td>".$plastic_insertion."</td>";
    echo "<td>".$prefilter."</td>";
    echo "<td>".$packing."</td>";
    echo "<td>".$comment."</td>";
    echo "</tr>";
}
echo "</table>";

?>