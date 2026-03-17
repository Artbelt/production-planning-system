<?php
/** СТраница отображает заявки в которых присутствует запрашиваемый фильтр */

/** Подключаем функции */
require_once('settings.php') ;
require_once ('tools/tools.php');

$filter = isset($_POST['filter']) ? trim((string)$_POST['filter']) : '';
if ($filter === '') {
    header('HTTP/1.1 400 Bad Request');
    echo '<div class="alert">Не указан фильтр для поиска.</div>';
    exit;
}

// Добавляем стили для результатов поиска
echo '<style>
    .filter-results {
        margin-top: 16px;
    }
    .filter-results h4 {
        font-size: 18px;
        font-weight: 600;
        color: #1e293b;
        margin: 0 0 12px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #e2e8f0;
        position: relative;
    }
    .filter-results h4::after {
        content: "";
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 40px;
        height: 2px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 1px;
    }
    .filter-results p {
        color: #64748b;
        margin: 0 0 16px 0;
        font-size: 14px;
    }
    .order-item {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        padding: 16px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }
    .order-item--hidden{ display:none; }
    .order-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
    }
    .order-button {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        min-width: 120px;
        text-align: center;
    }
    .order-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        filter: brightness(1.1);
    }
    .order-info {
        flex: 1;
        color: #374151;
        font-size: 14px;
        font-weight: 500;
    }
    .order-stats {
        display: flex;
        gap: 16px;
        margin-top: 4px;
    }
    .stat-item {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 13px;
        color: #64748b;
    }
    .stat-value {
        font-weight: 600;
        color: #1e293b;
    }
    .stat-ordered .stat-value {
        color: #f59e0b;
    }
    .stat-produced .stat-value {
        color: #10b981;
    }
</style>';

echo '<div class="filter-results">';
echo "<h4>Информация по наличию фильтра " . htmlspecialchars($filter) . " в заявках</h4>";
echo "<p>Заявки, в которых присутствует эта позиция:</p>";

/** Создаем подключение к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$stmt = $pdo->prepare("SELECT order_number FROM orders WHERE filter = ?");
$stmt->execute([$filter]);
$ordersRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Запрос количества заказанных фильтров по заявке (один раз подготавливаем) */
$stmtCount = $pdo->prepare("SELECT count FROM orders WHERE order_number = ? AND filter = ?");

/** Разбор массива значений  */
echo '<form action="show_order.php" method="post">';

// вычисляем год (две цифры) для фильтрации "последний год"
$yy_now = (int)date('y');
$yy_prev = ($yy_now - 1 + 100) % 100; // предыдущий год в формате 00..99
$hidden_count = 0;

foreach ($ordersRows as $orders_data) {
    $order_number = $orders_data['order_number'];
    // пытаемся извлечь 2 цифры года из начала номера заявки
    $yy_from_order = null;
    if (preg_match('/^(\d{2})/', (string)$order_number, $m)) {
        $yy_from_order = (int)$m[1];
    }
    // показываем по умолчанию только за последний год
    $is_recent = ($yy_from_order !== null) && ($yy_from_order === $yy_now || $yy_from_order === $yy_prev);
    
    /** Выполняем запрос о количестве заказанных фильтров через PDO */
    $stmtCount->execute([$order_number, $filter]);
    $show_count = $stmtCount->fetch(PDO::FETCH_ASSOC);
    if ($show_count === false) {
        echo '<div class="alert">Ошибка запроса количества</div>';
        continue;
    }
    $ordered_count = $show_count['count'];
    $produced_count = (int)select_produced_filters_by_order($filter, $order_number)[1];
    
    echo '<div class="order-item'.($is_recent?'' :' order-item--hidden').'">';
    echo '<input type="submit" name="order_number" value="' . htmlspecialchars($order_number) . '" class="order-button">';
    echo '<div class="order-info">';
    echo '<div>Заявка №' . htmlspecialchars($order_number) . '</div>';
    echo '<div class="order-stats">';
    echo '<div class="stat-item stat-ordered">';
    echo '<span>📋</span>';
    echo '<span>заказано:</span>';
    echo '<span class="stat-value">' . $ordered_count . '</span>';
    echo '</div>';
    echo '<div class="stat-item stat-produced">';
    echo '<span>✅</span>';
    echo '<span>изготовлено:</span>';
    echo '<span class="stat-value">' . $produced_count . '</span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    if(!$is_recent){ $hidden_count++; }
}
echo '</form>';
// Кнопка "Показать все" если есть скрытые
if ($hidden_count > 0) {
    echo '<div style="margin-top:12px;">'
        .'<button type="button" id="showAllOrders" class="order-button" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">Показать все</button>'
        .'</div>';
}
echo '</div>';

// Примечание: обработчик клика навешивается в main.php после вставки HTML

