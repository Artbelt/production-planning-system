<?php
require_once('tools/tools.php');

$filter_name =  $_POST['filter_name'];
$category = $_POST['category'];
/** ГОФРОПАКЕТ */
$p_p_name = "гофропакет ".$filter_name;
$p_p_height = $_POST['p_p_fold_height'];
$p_p_ext_wireframe = $_POST['p_p_ext_wireframe'];//material
$p_p_int_wireframe = $_POST['p_p_int_wireframe'];//material
$p_p_paper_width = $_POST['p_p_paper_width'];
$p_p_fold_height = $_POST['p_p_fold_height'];
$p_p_fold_count = $_POST['p_p_fold_count'];
$p_p_remark = $_POST['p_p_remark'];
/** КАРКАС НАРУЖНыЙ */
if ($p_p_ext_wireframe != ''){
    $ext_wf_name = $filter_name." наружный каркас";
}/** КАРКАС внутренний */
if ($p_p_int_wireframe != ''){
    $int_wf_name = $filter_name." внутренний каркас";
}
/** КРЫШКИ */
// Обработка верхней крышки
$up_cap_type = $_POST['up_cap_type'] ?? 'metal';
$up_cup = '';
$up_cap_PU = '';

if ($up_cap_type == 'metal') {
    // Металлическая крышка
    $up_cap_select = $_POST['up_cap_select'] ?? '';
    $up_cap_new = $_POST['up_cap_new'] ?? '';
    
    if ($up_cap_select == '__NEW__' && $up_cap_new != '') {
        // Добавляем новую крышку в ассортимент
        $up_cup = trim($up_cap_new);
        // Проверяем, нет ли уже такой крышки в БД
        global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
        $check_sql = "SELECT COUNT(*) as cnt FROM cap_stock WHERE cap_name = '".$mysqli->real_escape_string($up_cup)."'";
        $check_result = $mysqli->query($check_sql);
        $check_row = $check_result->fetch_assoc();
        if ($check_row['cnt'] == 0) {
            // Добавляем новую крышку в таблицу cap_stock
            $insert_sql = "INSERT INTO cap_stock(cap_name) VALUES('".$mysqli->real_escape_string($up_cup)."')";
            $mysqli->query($insert_sql);
        }
        $mysqli->close();
    } elseif ($up_cap_select != '' && $up_cap_select != '__NEW__') {
        $up_cup = trim($up_cap_select);
    } else {
        // Для обратной совместимости - используем старое поле, если оно есть
        $up_cup = isset($_POST['up_cap']) ? trim($_POST['up_cap']) : '';
    }
} else {
    // Полиуретановая крышка
    $up_cap_PU = isset($_POST['up_cap_PU']) ? trim($_POST['up_cap_PU']) : '';
}

// Обработка нижней крышки
$down_cap_type = $_POST['down_cap_type'] ?? 'metal';
$down_cup = '';
$down_cap_PU = '';

if ($down_cap_type == 'metal') {
    // Металлическая крышка
    $down_cap_select = $_POST['down_cap_select'] ?? '';
    $down_cap_new = $_POST['down_cap_new'] ?? '';
    
    if ($down_cap_select == '__NEW__' && $down_cap_new != '') {
        // Добавляем новую крышку в ассортимент
        $down_cup = trim($down_cap_new);
        // Проверяем, нет ли уже такой крышки в БД
        global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
        $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
        $check_sql = "SELECT COUNT(*) as cnt FROM cap_stock WHERE cap_name = '".$mysqli->real_escape_string($down_cup)."'";
        $check_result = $mysqli->query($check_sql);
        $check_row = $check_result->fetch_assoc();
        if ($check_row['cnt'] == 0) {
            // Добавляем новую крышку в таблицу cap_stock
            $insert_sql = "INSERT INTO cap_stock(cap_name) VALUES('".$mysqli->real_escape_string($down_cup)."')";
            $mysqli->query($insert_sql);
        }
        $mysqli->close();
    } elseif ($down_cap_select != '' && $down_cap_select != '__NEW__') {
        $down_cup = trim($down_cap_select);
    } else {
        // Для обратной совместимости - используем старое поле, если оно есть
        $down_cup = isset($_POST['down_cap']) ? trim($_POST['down_cap']) : '';
    }
} else {
    // Полиуретановая крышка
    $down_cap_PU = isset($_POST['down_cap_PU']) ? trim($_POST['down_cap_PU']) : '';
}

/** ПРЕДФИЛЬТР */

$pf_presence = $_POST['prefilter'];
if ($pf_presence != '') {
        $pf_name = "предфильтр ".$filter_name;
} else {
    $pf_name = "";
}
/** ВСТАВКА ПП */

$pi_presence = $_POST['pp_insertion'];
if ($pi_presence != '') {
    $pi_name = $pi_presence;
} else {
    $pi_name = "";

}


/** Packing */
$pckg_presence = $_POST['packing'];
if ($pckg_presence != '') {
    $pckg_name = "г/к ящик ".$filter_name;
} else {
    $pckg_name = "";
}

/** @var $a ПРоверка наличия фильтра в БД  */
$a = check_filter($_POST['filter_name']);

/** Если фильтр уже есть в БД -> выход */
if ($a > 0){
    echo "Фильтр {$filter_name} уже есть в БД";
    exit();
}

$remark =  $_POST['remark'];

$diametr_outer = $_POST['Diametr_outer'];
$diametr_inner_1 = $_POST['Diametr_inner_1'];
$diametr_inner_2 = $_POST['Diametr_inner_2'];
$height = $_POST['Height'];
// $up_cap_PU и $down_cap_PU уже определены выше




/** Если фильтра в БД такого нет -> начинаем запись */

/** Запись информации о фильтре в БД */
$sql = "INSERT INTO round_filter_structure(filter, category, filter_package, up_cap, down_cap, prefilter, plastic_insertion, packing, comment, Diametr_outer, Diametr_inner_1, Diametr_inner_2, Height, PU_up_cap, PU_down_cap) 
        VALUES ('$filter_name','$category','$p_p_name','$up_cup','$down_cup','$pf_name','$pi_name','$pckg_name','$remark', '$diametr_outer', '$diametr_inner_1', '$diametr_inner_2', '$height', '$up_cap_PU','$down_cap_PU');";
$result = mysql_execute($sql);

/** Запись информации о гофропакете в БД */
$sql = "INSERT INTO paper_package_round(p_p_name, p_p_height, p_p_ext_wireframe, p_p_int_wireframe, p_p_paper_width, p_p_fold_height, p_p_fold_count, comment) 
        VALUES('$p_p_name','$p_p_paper_width','$ext_wf_name','$int_wf_name','$p_p_paper_width','$p_p_fold_height','$p_p_fold_count','$p_p_remark');";
$result = mysql_execute($sql);

/** Запись информации о каркасax в БД если каркас указан */
if ($ext_wf_name != ''){
    $sql = "INSERT INTO wireframe_round(w_name, w_material) 
        VALUES('$ext_wf_name','$p_p_ext_wireframe');";
    $result = mysql_execute($sql);
}
if ($int_wf_name != ''){
    $sql = "INSERT INTO wireframe_round(w_name, w_material) 
        VALUES('$int_wf_name','$p_p_int_wireframe');";
    $result = mysql_execute($sql);
}

/** Запись информации о предфильтре в БД если предфильтр указан*/
if ($pf_name != ''){
    $sql = "INSERT INTO prefilter_round(pf_name)
       
        VALUES('$pf_name')";
    $result = mysql_execute($sql);
}

/** Запись информации о PP-вставке в БД если вставка указана*/ /** НАДО ДОДЕЛІВАТЬ -!!!!!!!!!!------------------------------------------------------ */
if ($pi_name != ''){

    $sql = "INSERT INTO insertions(i_name)
       
        VALUES('$pi_name')";
    $result = mysql_execute($sql);
}


echo "Фильтр {$filter_name} успешно добавлен в БД";
?>
<button onclick="window.close();">Закрыть окно</button>
