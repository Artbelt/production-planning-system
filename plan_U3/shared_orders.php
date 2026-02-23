<?php

/** Подключение файла настроек */
require_once('settings.php');
require_once('tools/tools.php');

$workshop = 'U3';

/** Подключаемся к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$rows = $pdo->query("SELECT DISTINCT order_number, workshop, hide FROM orders")->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="container">';
echo '<h2>Сохраненные заявки</h2>';

if (count($rows) === 0) {
    echo '<p>В базе нет ни одной заявки</p>';
} else {
    echo '<form action="show_order.php" method="post" target="_blank" class="order-form">';
    foreach ($rows as $orders_data) {
        if ($orders_data['hide'] != 1) {
            $color = str_contains($orders_data['order_number'], '[!]') ? 'green' : 'black';
            $bgColor = str_contains($orders_data['order_number'], '[!]') ? 'white' : 'lightgreen';
        } else {
            $color = 'black';
            $bgColor = 'lightgray';
        }
        echo "<button type='submit' name='order_number' value='{$orders_data['order_number']}' 
                style='color: {$color}; background: {$bgColor};' class='order-button'>
                {$orders_data['order_number']}
              </button>";
    }
    echo '</form>';
}

echo '<div class="actions">';
echo "<form action='search_filter_in_the_orders.php' method='post'>
        <input type='text' name='filter' placeholder='Поиск по заявкам'>
        <button type='submit'>Искать</button>
      </form>";
echo "<form action='product_output_view.php' method='post'>
        <button type='submit'>Просмотр продукции</button>
      </form>";
?>
<a href="test.php" target="_blank" rel="noopener noreferrer">
    <button style="height: 20px; width: 220px">Выпуск продукции </button>
</a>
<?php

echo "<form action='parts_output_for_workers.php' method='post'>
        <button type='submit'>Внесение гофропакетов</button>
      </form>";
echo '</div>';
echo '</div>';
?>

<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background: #f4f4f4;
    }
    .container {
        width: 80%;
        max-width: 1000px;
        margin: 20px auto;
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
    }
    h2 {
        text-align: center;
        color: #333;
    }
    .order-form {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: center;
    }
    .order-button {
        padding: 15px;
        width: 200px;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .order-button:hover {
        opacity: 0.8;
    }
    .actions {
        margin-top: 20px;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .actions form {
        display: flex;
        justify-content: center;
        gap: 10px;
    }
    .actions input, .actions button {
        padding: 12px;
        font-size: 16px;
        border-radius: 5px;
        border: 1px solid #ccc;
    }
    .actions button {
        background: #6495ed;
        color: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .actions button:hover {
        background: #4169e1;
    }
    .error {
        color: red;
        text-align: center;
        font-weight: bold;
    }
    @media (max-width: 768px) {
        .container {
            width: 95%;
            padding: 15px;
        }
        .order-form {
            flex-direction: column;
            align-items: center;
        }
        .order-button {
            width: 100%;
            font-size: 14px;
        }
        .actions {
            flex-direction: column;
        }
        .actions form {
            flex-direction: column;
            align-items: center;
        }
        .actions input, .actions button {
            width: 100%;
        }
    }
</style>
