<?php
require_once('tools/tools.php');

require_once('settings.php');

require_once ('style/table_1.txt');

?>
    <head></head>
    <style>
        /* Обнуляем отступы и используем box-sizing */
        * {
            margin: 10;
            padding: 0;
            box-sizing: border-box;
        }

        /* Устанавливаем высоту для всей страницы */
        html, body {
            height: 100%;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Центрирование контейнера с кнопками */
        .button-container {
            display: flex;
            flex-direction: column; /* Выстраиваем кнопки столбиком */
            gap: 20px; /* Задаем расстояние между кнопками */
        }

        /* Стили для кнопок */
        .btn {
            padding: 15px 30px;
            font-size: 18px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        /* Эффект при наведении */
        .btn:hover {
            background-color: green; /* Цвет фона при наведении */
            transform: scale(1.1); /* Увеличение размера кнопки на 10% */
        }
    </style>
<?php
/** Подключаемся к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$rows = $pdo->query("SELECT DISTINCT order_number, workshop, hide FROM orders")->fetchAll(PDO::FETCH_ASSOC);
/** Разбираем результат запроса */
if (count($rows) === 0) { echo "В базе нет ни одной заявки";}

/** Разбор массива значений  */
echo '<form action="show_order.php" method="post" target="_blank" >';
foreach ($rows as $orders_data) {
    if ( $orders_data['hide'] != 1) {
        if (str_contains($orders_data['order_number'], '[!]')) {
            echo "<input type='submit' class='alert-button' name='order_number' value=" . $orders_data['order_number'] . " style='background-color: orange;>";
        } else {
            echo "<input type='submit' class='btn' name='order_number' value=" . $orders_data['order_number'] . " >";
        }
    } else {
        echo "<input type='submit' class='btn'  name='order_number' value=" . $orders_data['order_number'] . " style='background-color: orange;'>";
    }
}
echo '</form>';
?>