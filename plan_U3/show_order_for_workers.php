<?php /** show_order_for_workers.php — отображает заявку для заготовительного участка */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';
require_once __DIR__ . '/tools/tools.php';
require_once __DIR__ . '/style/table.txt';

/** Номер заявки — из POST (форма со show_order.php) или из GET (прямая ссылка) */
$order_number = trim((string)($_POST['order_number'] ?? $_GET['order_number'] ?? ''));

if ($order_number === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h3>Не указан номер заявки</h3><p>Откройте страницу из списка заявок (кнопка «Подготовить спецификацию заявки для заготовительного участка») или добавьте в адрес: <code>?order_number=НОМЕР_ЗАЯВКИ</code></p>';
    exit;
}

/** Показываем номер заявки */
echo '<h3>Заявка: ' . htmlspecialchars($order_number) . '</h3><p>';

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
try {
    $result = show_order($order_number);
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h3>Ошибка загрузки заявки</h3><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

/** Переменная для подсчета суммы фильтров в заявке */
$filter_count_in_order = 0;
/** Переменная для подсчета количества сделанных фильтров */
$filter_count_produced = 0;
/** strings counter */
$count = 0;

echo '<form action="filter_parameters.php" method="post">';

/** Разбор массива значений по подключению */
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $count += 1;
    try {
        $filter_data = get_filter_data($row['filter']);
    } catch (Throwable $e) {
        $filter_data = [
            'paper_package_fold_height' => '—',
            'paper_package_paper_width' => '—',
            'paper_package_fold_count' => '—',
            'paper_package_ext_wireframe' => '—',
            'paper_package_int_wireframe' => '—',
            'up_cap' => '—',
            'down_cap' => '—',
            'paper_package_remark' => htmlspecialchars($e->getMessage()),
        ];
    }
    echo "<tr style='hov'>"
        . "<td>" . htmlspecialchars($row['filter'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($row['count'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($row['remark'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_fold_height'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_paper_width'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_fold_count'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_ext_wireframe'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_int_wireframe'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['up_cap'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['down_cap'] ?? '') . "</td>"
        . "<td>" . htmlspecialchars($filter_data['paper_package_remark'] ?? '') . "</td>";
}

echo "</table>";
echo '</form>';
