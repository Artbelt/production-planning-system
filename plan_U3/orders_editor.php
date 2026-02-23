<?php
/** подключение фалйа настроек */
require_once('settings.php') ;
require_once('tools/tools.php') ;
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$order_count = count($_POST['order_name']);
echo 'выбраны'.$order_count.' заявки для объединения';

for ($x = 0; $x < $order_count; $x++){
    echo '<br>';
    echo $_POST['order_name'][$x];

}

/** Даем имя объединенной заявке */
$combined_order_name = '[O]|';

/** @var  $combined_order  массив объединенной заявки*/
$combined_order =[];

/** СОхдаем имя объединенной заявки */
for ($x=0;$x<count($_POST['order_name']);$x++){
    $combined_order_name = $combined_order_name.$_POST['order_name'][$x].'|';
}

echo '<p>Заявке присвоено имя: '.$combined_order_name.'<br>';

/** Записываем элементы заявок под новым именем */
$st_sel = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
$st_ins = $pdo->prepare("INSERT INTO orders (order_number, workshop, filter, count, marking, personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark, hide, cut_ready, cut_confirmed, plan_ready, corr_ready, build_ready) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

for ($x=0;$x<count($_POST['order_name']);$x++) {
    $st_sel->execute([$_POST['order_name'][$x]]);
    while ($order_data = $st_sel->fetch(PDO::FETCH_ASSOC)) {
        $combined_order[] = $order_data;
    }
}

for ($z = 0; $z<count($combined_order); $z++){
    $st_ins->execute([
        $combined_order_name,
        $combined_order[$z]['workshop'],
        $combined_order[$z]['filter'],
        $combined_order[$z]['count'],
        $combined_order[$z]['marking'],
        $combined_order[$z]['personal_packaging'],
        $combined_order[$z]['personal_label'],
        $combined_order[$z]['group_packaging'],
        $combined_order[$z]['packaging_rate'],
        $combined_order[$z]['group_label'],
        $combined_order[$z]['remark'],
        $combined_order[$z]['hide'] ?? 0,
        $combined_order[$z]['cut_ready'] ?? 0,
        $combined_order[$z]['cut_confirmed'] ?? 0,
        $combined_order[$z]['plan_ready'] ?? 0,
        $combined_order[$z]['corr_ready'] ?? 0,
        $combined_order[$z]['build_ready'] ?? 0
    ]);
}

?>
<button onclick="window.close();">Закрыть окно</button>
