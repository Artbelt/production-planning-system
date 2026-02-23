<?php

require_once('../settings.php');
require_once __DIR__ . '/../../auth/includes/db.php';
require_once ('functions.php');

$pdo = getPdo('plan_u3');
$action = $_GET['action'];

switch ($action){

    case 'ADD':
        $st = $pdo->prepare("INSERT INTO paper_package VALUES (?, ?, ?, ?)");
        for ($x = 1601; $x < 1900; $x++){
            $paper_package = "AF".$x;
            $height = choose_height();
            $width = choose_width();
            $pleats_count = choose_pleats_count();
            if (!$st->execute([$paper_package, $height, $width, $pleats_count])) {
                echo "Ошибка вставки для $paper_package"; exit;
            }
        }
        echo "Готово"; break;

    case 'delete_first_part':
        $result = $pdo->query("SELECT paper_package_name FROM paper_package");
        if (!$result) { echo "Ошибка запроса"; exit; }
        $stUpd = $pdo->prepare("UPDATE paper_package SET paper_package_name = ? WHERE paper_package_name = ?");
        foreach ($result as $row){
            $old_name = $row['paper_package_name'];
            $pos = strpos($old_name,'гофро');
            if ($pos === false) {continue;}
            $new_name = substr($row['paper_package_name'],-5,5);
            $stUpd->execute([$new_name, $old_name]);
        }
        echo "Готово"; break;

    case 'add_first_part':
        $result = $pdo->query("SELECT paper_package_name FROM paper_package");
        if (!$result) { echo "Ошибка запроса"; exit; }
        $stUpd = $pdo->prepare("UPDATE paper_package SET paper_package_name = ? WHERE paper_package_name = ?");
        foreach ($result as $row){
            $old_name = $row['paper_package_name'];
            $new_name = "AF".$old_name;
            $stUpd->execute([$new_name, $old_name]);
        }
        echo "Готово"; break;

    case 'delete_spaces':
        $result = $pdo->query("SELECT paper_package_name FROM paper_package");
        if (!$result) { echo "Ошибка запроса"; exit; }
        $stUpd = $pdo->prepare("UPDATE paper_package SET paper_package_name = ? WHERE paper_package_name = ?");
        foreach ($result as $row){
            $old_name = $row['paper_package_name'];
            $new_name = str_replace(" ","",$old_name);
            $stUpd->execute([$new_name, $old_name]);
        }
        echo "Готово"; break;
}






