<?php
header('Content-Type: text/plain; charset=utf-8');

if ($_POST['action'] === 'serialize_order' && isset($_POST['order_data'])) {
    $orderData = json_decode($_POST['order_data'], true);
    
    if ($orderData === null) {
        echo '';
        exit;
    }
    
    // Преобразуем данные в формат, совместимый с оригинальной структурой
    $order = [];
    foreach ($orderData as $row) {
        $arr = [];
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $key) {
            $arr[$key] = isset($row[$key]) ? $row[$key] : '';
        }
        $order[] = $arr;
    }
    
    // Сериализуем в PHP формат
    $serialized = serialize($order);
    echo $serialized;
} else {
    echo '';
}
?>
