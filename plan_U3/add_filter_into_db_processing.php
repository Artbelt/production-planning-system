<?php
/**                 add_filter_into_db_processing                     */
/** файл обрабатывает запрос доюавления названия нового фильтра в БД */

require_once('tools/tools.php');
require_once('settings.php');

/** @var  $filter  получаем имя фильтра для внесения в БД*/
$filter =  $_GET['filter'];
/** @var  $workshop получаем имя участка на котором производится фильтр*/
$workshop = $_GET['workshop'];

require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$stmt = $pdo->prepare("SELECT * FROM filters WHERE filter = ?");
$stmt->execute([$filter]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    $ins = $pdo->prepare("INSERT INTO filters(filter, workshop) VALUES (?, ?)");
    $ins->execute([$filter, $workshop]);
    /** Если внесение в БД успешно */
    echo "Фильтр ".$filter." успешно добавлен в БД";

} else {
    echo "Фильтр ".$filter." уже есть в БД";
}


?>
