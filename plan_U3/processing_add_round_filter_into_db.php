<?php
require_once('tools/tools.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo_filter = getPdo('plan_u3');

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
        $up_cup = trim($up_cap_new);
        $chk = $pdo_filter->prepare("SELECT COUNT(*) FROM cap_stock WHERE cap_name = ?");
        $chk->execute([$up_cup]);
        if ((int)$chk->fetchColumn() == 0) {
            $pdo_filter->prepare("INSERT INTO cap_stock(cap_name) VALUES(?)")->execute([$up_cup]);
        }
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
        $down_cup = trim($down_cap_new);
        $chk = $pdo_filter->prepare("SELECT COUNT(*) FROM cap_stock WHERE cap_name = ?");
        $chk->execute([$down_cup]);
        if ((int)$chk->fetchColumn() == 0) {
            $pdo_filter->prepare("INSERT INTO cap_stock(cap_name) VALUES(?)")->execute([$down_cup]);
        }
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
    
    $diametr_outer = $_POST['Diametr_outer'] ?? '';
    $diametr_inner_1 = $_POST['Diametr_inner_1'] ?? '';
    $diametr_inner_2 = $_POST['Diametr_inner_2'] ?? '';
    $height = $_POST['Height'] ?? '';
    $productivity = $_POST['productivity'] ?? '';
    $press = (isset($_POST['press']) && $_POST['press'] === '1') ? '1' : '';
    
    $stmt = $pdo_filter->prepare("UPDATE round_filter_structure SET category=?, filter_package=?, up_cap=?, down_cap=?, prefilter=?, plastic_insertion=?, packing=?, comment=?, analog=?, Diametr_outer=?, Diametr_inner_1=?, Diametr_inner_2=?, Height=?, PU_up_cap=?, PU_down_cap=?, productivity=?, press=? WHERE filter=?");
    $analog_val = empty($analog) ? null : $analog;
    $press_val = ($press === '1') ? '1' : null;
    if (!$stmt->execute([$category, $p_p_name, $up_cup, $down_cup, $pf_name, $pi_name, $pckg_name, $remark, $analog_val, $diametr_outer, $diametr_inner_1, $diametr_inner_2, $height, $up_cap_PU ?? '', $down_cap_PU ?? '', $productivity, $press_val, $filter_name])) {
        echo "Ошибка обновления фильтра";
        exit();
    }
    
    $stmt2 = $pdo_filter->prepare("UPDATE paper_package_round SET p_p_height=?, p_p_ext_wireframe=?, p_p_int_wireframe=?, p_p_paper_width=?, p_p_fold_height=?, p_p_fold_count=?, comment=? WHERE p_p_name=?");
    $stmt2->execute([$p_p_paper_width, $ext_wf_name ?? '', $int_wf_name ?? '', $p_p_paper_width, $p_p_fold_height, $p_p_fold_count, $p_p_remark ?? '', $p_p_name]);
    
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
$st1 = $pdo_filter->prepare("INSERT INTO round_filter_structure(filter, category, filter_package, up_cap, down_cap, prefilter, plastic_insertion, packing, comment, analog, Diametr_outer, Diametr_inner_1, Diametr_inner_2, Height, PU_up_cap, PU_down_cap, productivity, press) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$st1->execute([$filter_name, $category, $p_p_name, $up_cup, $down_cup, $pf_name, $pi_name, $pckg_name, $remark, $analog ?: null, $diametr_outer, $diametr_inner_1, $diametr_inner_2, $height, $up_cap_PU ?? '', $down_cap_PU ?? '', $productivity, ($press === '1') ? '1' : null]);

$st2 = $pdo_filter->prepare("INSERT INTO paper_package_round(p_p_name, p_p_height, p_p_ext_wireframe, p_p_int_wireframe, p_p_paper_width, p_p_fold_height, p_p_fold_count, comment) VALUES (?,?,?,?,?,?,?,?)");
$st2->execute([$p_p_name, $p_p_paper_width, $ext_wf_name ?? '', $int_wf_name ?? '', $p_p_paper_width, $p_p_fold_height, $p_p_fold_count, $p_p_remark ?? '']);

if ($ext_wf_name != ''){
    $pdo_filter->prepare("INSERT INTO wireframe_round(w_name, w_material) VALUES (?,?)")->execute([$ext_wf_name, $p_p_ext_wireframe]);
}
if ($int_wf_name != ''){
    $pdo_filter->prepare("INSERT INTO wireframe_round(w_name, w_material) VALUES (?,?)")->execute([$int_wf_name, $p_p_int_wireframe]);
}
if ($pf_name != ''){
    $pdo_filter->prepare("INSERT INTO prefilter_round(pf_name) VALUES (?)")->execute([$pf_name]);
}
if ($pi_name != ''){
    $pdo_filter->prepare("INSERT INTO insertions(i_name) VALUES (?)")->execute([$pi_name]);
}


echo "Фильтр {$filter_name} успешно добавлен в БД";
?>
<button onclick="window.close();">Закрыть окно</button>
