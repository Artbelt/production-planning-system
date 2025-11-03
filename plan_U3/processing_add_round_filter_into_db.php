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
$up_cup = $_POST['up_cap'];
$down_cup = $_POST['down_cap'];

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
$up_cap_PU = $_POST['up_cap_PU'];
$down_cap_PU = $_POST['down_cap_PU'];




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
