<?php

require_once('../settings.php');
require_once __DIR__ . '/../../auth/includes/db.php';
require_once ('functions.php');

$action = $_GET['action'];
$workshop = 'U2';
switch ($action){

    case 'fill':
        $pdo = getPdo('plan_u3');
        $st = $pdo->prepare("INSERT INTO filters (filter, workshop) VALUES (?, ?)");
        for ($x = 1602; $x < 1900; $x++){
            $filter = "AF".$x;
            if (!$st->execute([$filter, $workshop])) {
                echo "Ошибка вставки для $filter";
                exit;
            }
        }
        echo "Готово";
        break;


}






