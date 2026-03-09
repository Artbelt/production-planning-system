<?php /** show_order.php  файл отображает выбранную заявку в режиме просмотра*/

//require_once('tools/tools.php');
//require_once('settings.php');
//require_once ('style/table.txt');

require('tools/tools.php');
require('settings.php');
require ('style/table.txt');


/** Номер заявки которую надо нарисовать (POST — при переходе с главной, иначе пусто при прямой ссылке) */
$order_number = isset($_POST['order_number']) ? trim((string)$_POST['order_number']) : '';

/** При отсутствии номера заявки — сообщение и выход (таблица не заполняется при прямой загрузке страницы) */
if ($order_number === '') {
    echo '<h3>Заявка</h3>';
    echo '<p style="color:#c00;">Номер заявки не передан. Выберите заявку на <a href="main.php">главной странице</a> (кнопка с номером заявки).</p>';
    echo '<p>Страница show_order.php открывается с данными только при переходе из списка заявок.</p>';
    return;
}

/** Заголовок страницы с номером заявки */
echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Заявка '.htmlspecialchars($order_number).'</title></head><body>';

/** Показываем номер заявки */
echo '<h3>Заявка: '.htmlspecialchars($order_number).'</h3><p>';

/** Стили для всплывающих подсказок (когда делалось) */
echo '<style>
.tooltip { position: relative; display: inline-block; cursor: help; }
.tooltip .tooltiptext {
    visibility: hidden; width: max-content; max-width: 400px;
    background-color: #333; color: #fff; text-align: left; padding: 5px 10px;
    border-radius: 6px; position: absolute; z-index: 10; bottom: 125%; left: 50%;
    transform: translateX(-50%); opacity: 0; transition: opacity 0.3s; white-space: pre-line;
}
.tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }
</style>';

?>




    <script>
        function getCellNumber(cell) {
            var t = cell.querySelector('.tooltip');
            if (t && t.firstChild) return parseInt(t.firstChild.textContent.trim(), 10);
            return parseInt(cell.innerText, 10) || 0;
        }
        function show_zero(){
            var table = document.getElementById('order_table');
            var newTable = document.createElement('table');
            var newRow, newCell;

            for (var i = 1; i < table.rows.length; i++) {
                var currentRow = table.rows[i];
                var manufactured = getCellNumber(currentRow.cells[10]);

                if (manufactured === 0) {
                    newRow = newTable.insertRow();
                    // Проходимо по кожному стовпцю у рядку оригінальної таблиці та додаємо відповідні дані в новий рядок
                    for (var j = 0; j < currentRow.cells.length; j++) {
                        newCell = newRow.insertCell();
                        newCell.innerHTML = currentRow.cells[j].innerHTML;
                    }
                }
            }

// Створюємо нове вікно для відображення нової таблиці
            var newWindow = window.open('', 'New Window', 'width=1400,height=600');

            newWindow.document.body.append('Позиции, производство которых не начато')
            newWindow.document.body.appendChild(newTable);
            newWindow.document.body.clearAll();
        }
    </script>
<script>
    function show_zero_paper(){
        // Отримуємо таблицю
        var table = document.getElementById('order_table');

// Створюємо нову таблицю для позицій з нульовим значенням "Изготовлено шт"
        var newTable = document.createElement('table');
        var newRow, newCell;
        var header = table.rows[0]; // Рядок заголовка оригінальної таблиці

// Проходимо по кожному рядку таблиці
        for (var i = 1; i < table.rows.length; i++) {
            var currentRow = table.rows[i];
            var manufactured = getCellNumber(currentRow.cells[13]);

            if (manufactured === 0) {
                newRow = newTable.insertRow();
                // Проходимо по кожному стовпцю у рядку оригінальної таблиці та додаємо відповідні дані в новий рядок
                for (var j = 0; j < currentRow.cells.length; j++) {
                    newCell = newRow.insertCell();
                    newCell.innerHTML = currentRow.cells[j].innerHTML;
                }
            }
        }

// Створюємо нове вікно для відображення нової таблиці
        var newWindow = window.open('', 'New Window1', 'width=1400,height=600');

        newWindow.document.body.append('Позиции, производство которых не начато')
        newWindow.document.body.appendChild(newTable);
        newWindow.document.body.clearAll();
    }
</script>
    <button onclick="show_zero()"> Позиции, выпуск которых = 0</button>
    <button onclick="show_zero_paper()"> Гофропакеты, выпуск которых = 0</button>
<?php

/** Кнопка перехода в режим спецификации заявки*/
echo "<br><form action='show_order_for_workers.php' method='post'>"
    ."<input type='hidden' name='order_number' value='$order_number'>"
    ."<input type='submit' value='Подготовить спецификацию заявки для заготовительного участка'>"
    ."</form>";

/** Формируем шапку таблицы для вывода заявки */
echo "<table id='order_table' style='border: 1px solid black; border-collapse: collapse; font-size: 14px;'>
        <tr>
            <th> №п/п</th>                       
            <th> Фильтр</th>
            <th> Количество, шт</th>
            <th> Маркировка</th>
            <th> Упаковка инд.</th>  
            <th> Этикетка инд.</th>
            <th> Упаковка групп.</th>
            <th> Норма упаковки</th>
            <th> Этикетка групп.</th>    
            <th> Примечание</th>     
            <th> Изготовлено, шт</th>  
            <th> Остаток, шт</th>
            <th> Изготовленные крышки, шт</th>                                                       
            <th> Изготовленные гофропакеты, шт</th>                                                       
        </tr>";

/**
 * Рендер ячейки с тултипом по датам (когда делалось).
 * $dateList — массив вида [дата1, кол-во1, дата2, кол-во2, ...]
 * $totalQty — итоговое число в ячейке
 */
function renderTooltipCell($dateList, $totalQty) {
    if (empty($dateList)) {
        return "<td>" . (int)$totalQty . "</td>";
    }
    $tooltip = '';
    for ($i = 0; $i < count($dateList); $i += 2) {
        $tooltip .= $dateList[$i] . ' — ' . $dateList[$i + 1] . " шт\n";
    }
    return "<td><div class='tooltip'>" . (int)$totalQty . "<span class='tooltiptext'>" . htmlspecialchars(trim($tooltip)) . "</span></div></td>";
}

/** Загружаем из БД заявку */
$result = show_order($order_number);

/** Переменная для подсчета суммы фильтров в заявке */
$filter_count_in_order = 0;



/** Переменная для подсчета количества сделанных фильтров */
$filter_count_produced = 0;

/** Переменная для подсчета количества изготовленных гофропакетов */
$gofro_packages_produced = 0;

/** Переменная для подсчета количества изготовленных крышек по заявке */
$caps_produced = 0;

/** strings counter */
$count =0;

//echo '<form action="filter_parameters.php" method="post">';

/** Подключение к БД для получения гофропакетов */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo_gofro = getPdo('plan_u3');

/** Разбор массива значений по подключению */
while ($row = $result->fetch(PDO::FETCH_ASSOC)){
    $prod_info = select_produced_filters_by_order($row['filter'], $order_number);
    $date_list_filters = $prod_info[0];
    $total_qty_filters = $prod_info[1];
    $difference = (int)$row['count'] - $total_qty_filters;
    $filter_count_in_order = $filter_count_in_order + (int)$row['count'];
    $filter_count_produced = $filter_count_produced + $total_qty_filters;

    // Получаем гофропакет для фильтра из round_filter_structure
    $gofro_package = '';
    $gofro_date_list = [];
    $gofro_package_count = 0;
    $st_gofro = $pdo_gofro->prepare("SELECT filter_package FROM round_filter_structure WHERE filter = ?");
    $st_gofro->execute([$row['filter']]);
    $row_gofro = $st_gofro->fetch(PDO::FETCH_ASSOC);
    if ($row_gofro && !empty($row_gofro['filter_package'])) {
        $gofro_package = $row_gofro['filter_package'];
        list($gofro_date_list, $gofro_package_count) = get_parts_fact_dates($gofro_package, $order_number);
        $gofro_packages_produced += (int)$gofro_package_count;
    }

    $count += 1;
    echo "<tr style='hov'>"
        ."<td>".$count."</td>"
        ."<td>".htmlspecialchars($row['filter'] ?? '')."</td>"
        ."<td>".$row['count']."</td>"
        ."<td>".htmlspecialchars($row['marking'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['personal_packaging'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['personal_label'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['group_packaging'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['packaging_rate'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['group_label'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['remark'] ?? '')."</td>";
    // Изготовлено фильтров — с тултипом по датам
    echo renderTooltipCell($date_list_filters, $total_qty_filters);
    echo "<td>".$difference."</td>";
    // Изготовленные крышки — с тултипом по датам
    $caps_info = get_caps_fact_dates_by_filter($order_number, $row['filter']);
    $caps_produced += $caps_info[1];
    if ($caps_info[1] > 0) {
        echo renderTooltipCell($caps_info[0], $caps_info[1]);
    } else {
        echo "<td>-</td>";
    }
    // Изготовленные гофропакеты — с тултипом по датам
    if ($gofro_package_count > 0) {
        echo renderTooltipCell($gofro_date_list, $gofro_package_count);
    } else {
        echo "<td>-</td>";
    }
    echo "</tr>";
}

/** Если по заявке не найдено ни одной позиции — выводим подсказку */
if ($count === 0) {
    echo "<tr><td colspan='14' style='padding:10px; color:#666;'>По заявке «".htmlspecialchars($order_number)."» в базе не найдено ни одной позиции. Проверьте номер заявки или создайте заявку.</td></tr>";
}

/** @var расчет оставшегося количества продукции для производства $summ_difference */
$summ_difference = $filter_count_in_order - $filter_count_produced;
echo "<tr style='hov'>"
    ."<td>Итого:</td>"
    ."<td></td>"
    ."<td>".$filter_count_in_order."</td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td>".$filter_count_produced."</td>"
    ."<td>".$summ_difference.'*'."</td>"
    ."<td>".$caps_produced."</td>"
    ."<td>".$gofro_packages_produced."</td>"
    ."</tr>";

echo "</table>";
echo "* - без учета перевыполнения";
echo '</form>';

/** Кнопка перехода в режим планирования для У2*/
echo "<br><form action='order_planning_U3.php' method='post'>"
    ."<input type='hidden' name='order_number' value='$order_number'>"
    ."<input type='submit' value='Режим простого планирования'>"
    ."</form>";

/** Кнопка сокрытия заявки*/
echo "<br><form action='hiding_order.php' method='post'>"
    ."<input type='hidden' name='order_number' value='$order_number'>"
    ."<input type='submit' value='Отправить заявку в архив'>"
    ."</form>";

?>
<script>
    document.querySelectorAll('td').forEach(cell => {
        cell.innerHTML = cell.innerHTML.replace(/\[!(.*?)!\]/g, '<span style="background-color: yellow;">$1</span>');
    });
</script>
</body></html>
