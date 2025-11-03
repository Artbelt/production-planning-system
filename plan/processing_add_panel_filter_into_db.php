<?php
require_once('tools/tools.php');
$filter_name = $_POST['filter_name'];
$category    = $_POST['category'];

/** ГОФРОПАКЕТ */
$p_p_name          = "гофропакет " . $filter_name;
$p_p_length        = $_POST['p_p_length'];
$p_p_width         = $_POST['p_p_width'];
$p_p_height        = $_POST['p_p_height'];
$p_p_pleats_count  = $_POST['p_p_pleats_count'];
$p_p_amplifier     = $_POST['p_p_amplifier'];
$p_p_supplier      = $_POST['p_p_supplier'];
$p_p_remark        = $_POST['p_p_remark'];

/** КАРКАС */
$wf_length   = $_POST['wf_length'];
$wf_width    = $_POST['wf_width'];
$wf_material = $_POST['wf_material'];
$wf_supplier = $_POST['wf_supplier'];
$wf_name     = ($wf_length && $wf_width && $wf_material && $wf_supplier) ? "каркас " . $filter_name : "";

/** ПРЕДФИЛЬТР */
$pf_length   = $_POST['pf_length'];
$pf_width    = $_POST['pf_width'];
$pf_material = $_POST['pf_material'];
$pf_supplier = $_POST['pf_supplier'];
$pf_remark   = $_POST['pf_remark'];
$pf_name     = ($pf_length && $pf_width && $pf_material && $pf_supplier) ? "предфильтр " . $filter_name : "";

/** ПРОЛИВКА */
$glueing        = $_POST['glueing'] ?? '';
$glueing_remark = $_POST['glueing_remark'] ?? '';

/** ФОРМ-ФАКТОР */
$form_factor_id     = $_POST['form_factor'] ?? '';
$form_factor_remark = $_POST['form_factor_remark'] ?? '';

/** УПАКОВКА */
$box    = $_POST['box'];
$g_box  = $_POST['g_box'];
$remark = $_POST['remark'];

/** ЛОГ ИЗМЕНЕНИЙ */
$changes_log = $_POST['changes_log'] ?? '';
$ip_address  = $_SERVER['REMOTE_ADDR'];
$user_name   = $_SESSION['user_name'] ?? 'Гость';

/** Проверяем, есть ли фильтр в БД */
$a = check_filter($filter_name);

/** === panel_filter_structure === */
$sql = "INSERT INTO panel_filter_structure 
        (filter, category, paper_package, wireframe, prefilter, glueing, glueing_remark, 
         form_factor_id, form_factor_remark, box, g_box, comment)
        VALUES 
        ('$filter_name','$category','$p_p_name','$wf_name','$pf_name','$glueing',
         '$glueing_remark','$form_factor_id','$form_factor_remark','$box','$g_box','$remark')
        ON DUPLICATE KEY UPDATE
            category = VALUES(category),
            paper_package = VALUES(paper_package),
            wireframe = VALUES(wireframe),
            prefilter = VALUES(prefilter),
            glueing = VALUES(glueing),
            glueing_remark = VALUES(glueing_remark),
            form_factor_id = VALUES(form_factor_id),
            form_factor_remark = VALUES(form_factor_remark),
            box = VALUES(box),
            g_box = VALUES(g_box),
            comment = VALUES(comment);";
mysql_execute($sql);

/** === paper_package_panel === */
$sql = "INSERT INTO paper_package_panel 
        (p_p_name, p_p_length, p_p_height, p_p_width, p_p_pleats_count, p_p_amplifier, supplier, p_p_remark)
        VALUES 
        ('$p_p_name','$p_p_length','$p_p_height','$p_p_width','$p_p_pleats_count','$p_p_amplifier','$p_p_supplier','$p_p_remark')
        ON DUPLICATE KEY UPDATE
            p_p_length = VALUES(p_p_length),
            p_p_height = VALUES(p_p_height),
            p_p_width = VALUES(p_p_width),
            p_p_pleats_count = VALUES(p_p_pleats_count),
            p_p_amplifier = VALUES(p_p_amplifier),
            supplier = VALUES(supplier),
            p_p_remark = VALUES(p_p_remark);";
mysql_execute($sql);

/** === wireframe_panel === */
if (!empty($wf_name)) {
    $sql = "INSERT INTO wireframe_panel (w_name, w_length, w_width, w_material, w_supplier)
            VALUES ('$wf_name','$wf_length','$wf_width','$wf_material','$wf_supplier')
            ON DUPLICATE KEY UPDATE
                w_length = VALUES(w_length),
                w_width = VALUES(w_width),
                w_material = VALUES(w_material),
                w_supplier = VALUES(w_supplier);";
    mysql_execute($sql);
}

/** === prefilter_panel === */
if (!empty($pf_name)) {
    $sql = "INSERT INTO prefilter_panel (p_name, p_length, p_width, p_material, p_supplier, p_remark)
            VALUES ('$pf_name','$pf_length','$pf_width','$pf_material','$pf_supplier','$pf_remark')
            ON DUPLICATE KEY UPDATE
                p_length = VALUES(p_length),
                p_width = VALUES(p_width),
                p_material = VALUES(p_material),
                p_supplier = VALUES(p_supplier),
                p_remark = VALUES(p_remark);";
    mysql_execute($sql);
}

/** === Запись лога === */
if (!empty(trim($changes_log))) {
    $changes_log = addslashes($changes_log);
    $sql = "INSERT INTO changes_log (filter_name, user_name, changes, ip_address)
            VALUES ('$filter_name', '$user_name', '$changes_log', '$ip_address');";
    mysql_execute($sql);
}

/** Вывод результата */
if ($a > 0) {
    echo "Фильтр {$filter_name} обновлён в БД";
} else {
    echo "Фильтр {$filter_name} успешно добавлен в БД";
}
?>
