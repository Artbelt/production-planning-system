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
$ext_wf_name = '';
if ($p_p_ext_wireframe != ''){
    $ext_wf_name = $filter_name." наружный каркас";
}
/** КАРКАС внутренний */
$int_wf_name = '';
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

/** Проверяем режим работы */
$mode = isset($_POST['mode']) ? $_POST['mode'] : 'insert';

$remark =  $_POST['remark'];
// Аналог берем из поля analog (которое равно analog_filter - прототипу)
$analog = isset($_POST['analog']) ? trim($_POST['analog']) : '';
// Для обратной совместимости: если analog пустой, пытаемся извлечь из remark
if (empty($analog) && !empty($remark) && preg_match('/ANALOG_FILTER=([^\s]+)/i', $remark, $matches)) {
    $analog = trim($matches[1]);
    // Удаляем ANALOG_FILTER=... из remark
    $remark = preg_replace('/\s*ANALOG_FILTER=[^\s]+/i', '', $remark);
    $remark = trim($remark);
}

/** @var $a ПРоверка наличия фильтра в БД  */
$a = check_filter($_POST['filter_name']);

/** Если режим update - обновляем существующий фильтр */
if ($mode == 'update') {
    if ($a == 0) {
        echo "Фильтр {$filter_name} не найден в БД для обновления";
        exit();
    }
    
    // Обновляем информацию о фильтре в БД
    global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;
    $mysqli = new mysqli($mysql_host,$mysql_user,$mysql_user_pass,$mysql_database);
    
    // Экранируем значения для безопасности
    $filter_name_escaped = $mysqli->real_escape_string($filter_name);
    $category_escaped = $mysqli->real_escape_string($category);
    $p_p_name_escaped = $mysqli->real_escape_string($p_p_name);
    $up_cup_escaped = $mysqli->real_escape_string($up_cup);
    $down_cup_escaped = $mysqli->real_escape_string($down_cup);
    $pf_name_escaped = $mysqli->real_escape_string($pf_name);
    $pi_name_escaped = $mysqli->real_escape_string($pi_name);
    $pckg_name_escaped = $mysqli->real_escape_string($pckg_name);
    $remark_escaped = $mysqli->real_escape_string($remark);
    $analog_escaped = $mysqli->real_escape_string($analog);
    $diametr_outer_escaped = $mysqli->real_escape_string($diametr_outer);
    $diametr_inner_1_escaped = $mysqli->real_escape_string($diametr_inner_1);
    $diametr_inner_2_escaped = $mysqli->real_escape_string($diametr_inner_2);
    $height_escaped = $mysqli->real_escape_string($height);
    $up_cap_PU_escaped = $mysqli->real_escape_string($up_cap_PU);
    $down_cap_PU_escaped = $mysqli->real_escape_string($down_cap_PU);
    $productivity_escaped = $mysqli->real_escape_string($productivity);
    // Для press: если значение '1', сохраняем '1', иначе NULL
    $press_sql = ($press === '1') ? "'1'" : 'NULL';
    // Для analog: если значение пустое, сохраняем NULL, иначе значение
    $analog_sql = empty($analog) ? 'NULL' : "'$analog_escaped'";
    
    $sql = "UPDATE round_filter_structure SET 
            category = '$category_escaped',
            filter_package = '$p_p_name_escaped',
            up_cap = '$up_cup_escaped',
            down_cap = '$down_cup_escaped',
            prefilter = '$pf_name_escaped',
            plastic_insertion = '$pi_name_escaped',
            packing = '$pckg_name_escaped',
            comment = '$remark_escaped',
            analog = $analog_sql,
            Diametr_outer = '$diametr_outer_escaped',
            Diametr_inner_1 = '$diametr_inner_1_escaped',
            Diametr_inner_2 = '$diametr_inner_2_escaped',
            Height = '$height_escaped',
            PU_up_cap = '$up_cap_PU_escaped',
            PU_down_cap = '$down_cap_PU_escaped',
            productivity = '$productivity_escaped',
            press = $press_sql
            WHERE filter = '$filter_name_escaped'";
    $result = $mysqli->query($sql);
    
    if (!$result) {
        echo "Ошибка обновления фильтра: " . $mysqli->error;
        $mysqli->close();
        exit();
    }
    
    // Обновляем информацию о гофропакете
    $p_p_paper_width_escaped = $mysqli->real_escape_string($p_p_paper_width);
    $p_p_fold_height_escaped = $mysqli->real_escape_string($p_p_fold_height);
    $p_p_fold_count_escaped = $mysqli->real_escape_string($p_p_fold_count);
    $p_p_remark_escaped = $mysqli->real_escape_string($p_p_remark);
    $ext_wf_name_escaped = isset($ext_wf_name) ? $mysqli->real_escape_string($ext_wf_name) : '';
    $int_wf_name_escaped = isset($int_wf_name) ? $mysqli->real_escape_string($int_wf_name) : '';
    
    $sql = "UPDATE paper_package_round SET 
            p_p_height = '$p_p_paper_width_escaped',
            p_p_ext_wireframe = '$ext_wf_name_escaped',
            p_p_int_wireframe = '$int_wf_name_escaped',
            p_p_paper_width = '$p_p_paper_width_escaped',
            p_p_fold_height = '$p_p_fold_height_escaped',
            p_p_fold_count = '$p_p_fold_count_escaped',
            comment = '$p_p_remark_escaped'
            WHERE p_p_name = '$p_p_name_escaped'";
    $result = $mysqli->query($sql);
    
    $mysqli->close();
    
    echo "Фильтр {$filter_name} успешно обновлен в БД";
    ?>
    <button onclick="window.close();">Закрыть окно</button>
    <?php
    exit();
}

/** Если режим insert и фильтр уже есть в БД -> выход */
if ($mode == 'insert' && $a > 0){
    echo "Фильтр {$filter_name} уже есть в БД. Используйте режим редактирования для изменения параметров.";
    exit();
}

$diametr_outer = $_POST['Diametr_outer'];
$diametr_inner_1 = $_POST['Diametr_inner_1'];
$diametr_inner_2 = $_POST['Diametr_inner_2'];
$height = $_POST['Height'];
$productivity = isset($_POST['productivity']) ? $_POST['productivity'] : '';
// В БД: 1 или NULL (пусто). Если не выбрано или пусто - сохраняем как NULL
$press = (isset($_POST['press']) && $_POST['press'] === '1') ? '1' : '';
// $up_cap_PU и $down_cap_PU уже определены выше




/** Если фильтра в БД такого нет -> начинаем запись */

/** Запись информации о фильтре в БД */
// Для press: если значение '1', сохраняем '1', иначе NULL
$press_for_insert = ($press === '1') ? "'1'" : 'NULL';
// Для analog: если значение пустое, сохраняем NULL, иначе значение
$analog_escaped = empty($analog) ? 'NULL' : "'".addslashes($analog)."'";
$sql = "INSERT INTO round_filter_structure(filter, category, filter_package, up_cap, down_cap, prefilter, plastic_insertion, packing, comment, analog, Diametr_outer, Diametr_inner_1, Diametr_inner_2, Height, PU_up_cap, PU_down_cap, productivity, press) 
        VALUES ('$filter_name','$category','$p_p_name','$up_cup','$down_cup','$pf_name','$pi_name','$pckg_name','$remark', $analog_escaped, '$diametr_outer', '$diametr_inner_1', '$diametr_inner_2', '$height', '$up_cap_PU','$down_cap_PU', '$productivity', $press_for_insert);";
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
