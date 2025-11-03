<?php
header("Content-Type: application/json");
$orders = [
    ["id" => 1, "product" => "Фильтр масляный", "status" => "В производстве"],
    ["id" => 2, "product" => "Фильтр воздушный", "status" => "Ожидает комплектующие"]
];
echo json_encode($orders);
