<?php
require_once('tools/tools.php');
require_once('settings.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Добавление нового панельного фильтра в БД</title>
    <style>
        article, aside, details, figcaption, figure, footer,header,
        hgroup, menu, nav, section { display: block; }
    </style>
</head>
<body>


<?php

echo " <H3><b>Добавление нового фильтра в БД</b></H3>";


if (isset($_POST['filter_name'])){
    $filter_name = $_POST['filter_name'];
} else {
    $filter_name = '';
}

if (isset($_POST['analog_filter']) AND ($_POST['analog_filter'] != '')){
    $analog_filter = $_POST['analog_filter'];
    /** Если аналог установлен то загружаем всю информацию в поля о аналоге */
    echo "<p>ANALOG_FILTER=".$analog_filter;
    // массив для записи всех значений аналога
    $analog_data = get_filter_data($analog_filter);
   //var_dump(get_filter_data($analog_filter));

}else{
    echo "<p> Аналог не определен";
    $analog_data = array();
    $analog_data['paper_package_name'] ='';
    $analog_data['paper_package_height'] ='';
    $analog_data['paper_package_diameter'] ='';
    $analog_data['paper_package_ext_wireframe_name'] ='';
    $analog_data['paper_package_ext_wireframe_material'] ='';
    $analog_data['paper_package_int_wireframe_name'] ='';
    $analog_data['paper_package_int_wireframe_material'] ='';
    $analog_data['paper_package_paper_width'] ='';
    $analog_data['paper_package_fold_height'] ='';
    $analog_data['paper_package_fold_count'] ='';
    $analog_data['paper_package_remark'] ='';
    $analog_data['prefilter_name'] ='';
    $analog_data['up_cap'] ='';
    $analog_data['down_cap'] ='';
    $analog_data['pp_insertion'] ='';
    $analog_data['comment'] ='';

    $analog_data['Diametr_outer']='';
    $analog_data['Diametr_inner_1']='';
    $analog_data['Diametr_inner_2']='';
    $analog_data['Height']='';


}

?>

<form action="processing_add_round_filter_into_db.php" method="post" >
    <label><b>Наименование фильтра</b>
    <input type="text" name="filter_name" size="40" value="<?php echo $filter_name?>"><p>
    </label>
    <div id="mark"></div>
    <label>Категория
    <select name="category">
        <option>Круглый</option>
    </select>
    </label><p>
        <img src="pictures/filter1.jpg" height="300"><p>
    <label>D1: <input type="text" size="5" name="Diametr_outer" value="<?php echo $analog_data['Diametr_outer']?>"></label>
    <label>d1: <input type="text" size="5" name="Diametr_inner_1" value="<?php echo $analog_data['Diametr_inner_1']?>"></label>
    <label>d2: <input type="text" size="5" name="Diametr_inner_2" value="<?php echo $analog_data['Diametr_inner_2']?>"></label>
    <label>H: <input type="text" size="5" name="Height" value="<?php echo $analog_data['Height']?>"></label>
    <hr>
    <label><b>Гофропакет:</b></label><p>


        <label>Наружный каркас: <select name="p_p_ext_wireframe"  ><option></option>
                <option <?php if($analog_data['paper_package_ext_wireframe_material'] == 'ОЦ 0,45'){echo 'selected';}?>>ОЦ 0,45</option>
                <option <?php if($analog_data['paper_package_ext_wireframe_material'] == 'БЖ 0,22'){echo 'selected';}?>>БЖ 0,22</option></select></label><p>

        <label>Внутренний каркас: <select name="p_p_int_wireframe"  ><option></option>
                <option <?php if($analog_data['paper_package_int_wireframe_material'] == 'ОЦ 0,45'){echo 'selected';}?>>ОЦ 0,45</option>
                <option <?php if($analog_data['paper_package_int_wireframe_material'] == 'БЖ 0,22'){echo 'selected';}?>>БЖ 0,22</option></select></label><p>

        <label>Ширина бумаги: <input type="text" size="5" name="p_p_paper_width" value="<?php echo $analog_data['paper_package_paper_width']?>"></label>
        <label>Высота ребра бумаги: <input type="text" size="2" name="p_p_fold_height" value="<?php echo $analog_data['paper_package_fold_height']?>"></label>
        <label>Количество ребер бумаги: <input type="text" size="2" name="p_p_fold_count" value="<?php echo $analog_data['paper_package_fold_count']?>"></label>
        <p>
        <label>Комментарий: <input type="text" size="50" name="p_p_remark" value="<?php echo $analog_data['paper_package_remark']?>"></label><br>

        <hr>
    <table ><tr>
            <td style='border: 1px solid black; border-collapse: collapse;border-color: #ababab'>    <label><b>Крышки металлические:</b></label><p>

                    <label>Верхняя: <?php load_caps("up_cap") ?></label><label style="background-color: red; color: white"><?php echo $analog_data['up_cap']?></label><p>
                    <label>Нижняя: <?php load_caps("down_cap") ?></label><label style="background-color: red; color: white"><?php echo $analog_data['down_cap']?></label></td>

            <td style='border: 1px solid black; border-collapse: collapse; border-color: #ababab'>    <label><b>Крышки полиуретановые:</b></label><p>

                    <label>Верхняя:<input type="text" value="<?php if(isset($analog_data['up_cap_PU'])){ echo $analog_data['up_cap_PU'];}?>" name="up_cap_PU" size="10"></label><label style="background-color: red; color: white"></label><p>
                    <label>Нижняя: <input type="text" value="<?php if(isset( $analog_data['down_cap_PU'])){echo $analog_data['down_cap_PU'];}?>" name="down_cap_PU" size="10"></label><label style="background-color: red; color: white"></label></td>
            </tr>
    </table>


        <hr>

    <label><b>Предфильтр</b></label><p></p>
        <label>Наличие:<select name="prefilter"><option></option>
                                                    <option <?php if($analog_data['prefilter_name'] ==! ''){echo 'selected';} ?>>Есть</option>
                    </select> </label></label><label style="background-color: red; color: white"><?php echo $analog_data['prefilter_name']?></label>

    <hr>
    <label><b>Вставка PP</b></label><p>

        <?php load_insertions() ?>
        <label style="background-color: red; color: white"><?php echo $analog_data['pp_insertion']?></label>

    <hr>
    <label><b>Групповая упаковка</b></label><p>
        <label> <input type="text" size="5" name="packing" value=""></label>
    <hr>
    <label><b>Примечание</b>
        <input type="text" size="100" name="remark" value="<?php echo $analog_data['comment']?>">
    </label><p>
    <hr>




    <input type="submit" value="Сохранить фильтр">

</form>
<p></p>
</label><label style="background-color: red; color: white">ЗНАЧЕНИЯ, ОТМЕЧЕННЫЕ КРАСНЫМ МАРКЕРОМ НЕОБХОДИМО ВНЕСТИ ВРУЧНУЮ</label>

</body>
</html>



