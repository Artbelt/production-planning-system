<?php /** show_order.php  файл отображает выбранную заявку в режиме просмотра*/

require_once('tools/tools.php');
require_once('settings.php');

require_once ('style/table.txt');


/** Номер заявки которую надо нарисовать */
$order_number = $_POST['order_number'];

/** Показываем номер заявки */
echo '<h3>Заявка:'.$order_number.'</h3><p>';

/** Формируем шапку таблицы для вывода заявки */
echo "<table style='border: 1px solid black; border-collapse: collapse; font-size: 14px;'>
        <tr>
         
            <th style=' border: 1px solid black'> Фильтр
            </th>
            <th style=' border: 1px solid black'> Количество, шт           
            </th>
            <th style=' border: 1px solid black'> Примечание 1           
            </th>    
            </th>     
            <th style=' border: 1px solid black'> Высота ребра
            </th>              
            <th style=' border: 1px solid black'> Ширина бумаги
            </th>              
            <th style=' border: 1px solid black'> Количество ребер
            </th>              
            <th style=' border: 1px solid black'> Наружный каркас
            </th>              
            <th style=' border: 1px solid black'> Внутренний каркас
            </th>              
            <th style=' border: 1px solid black'> Крышка верхняя
            </th>              
            <th style=' border: 1px solid black'> Крышка нижняя
            </th>  
            <th style=' border: 1px solid black'> Примечание 2
            </th>  
                                                    
        </tr>";

/** Загружаем из БД заявку */
$result = show_order($order_number);

/** Переменная для подсчета суммы фильтров в заявке */
$filter_count_in_order = 0;



/** Переменная для подсчета количества сделанных фильтров */
$filter_count_produced = 0;

/** strings counter */
$count =0;

echo '<form action="filter_parameters.php" method="post">';

/** Разбор массива значений по подключению */
while ($row = $result->fetch(PDO::FETCH_ASSOC)){

    $count += 1;
    $filter_data = get_filter_data($row['filter']);


    echo "<tr style='hov'>"
        ."<td>".$row['filter']."</td>"
        ."<td>".$row['count']."</td>"
        ."<td>".$row['remark']."</td>"
        ."<td>".$filter_data['paper_package_fold_height']."</td>"
        ."<td>".$filter_data['paper_package_paper_width']."</td>"
        ."<td>".$filter_data['paper_package_fold_count']."</td>"
        ."<td>".$filter_data['paper_package_ext_wireframe']."</td>"
        ."<td>".$filter_data['paper_package_int_wireframe']."</td>"
        ."<td>".$filter_data['up_cap']."</td>"
        ."<td>".$filter_data['down_cap']."</td>"
        ."<td>".$filter_data['paper_package_remark']."</td>";

}

echo "</table>";
echo '</form>';
