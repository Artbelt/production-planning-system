<?php
/** СТраница отображает заявки в которых присутствует запрашиваемый фильтр */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tools/tools.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['filter'])) {
    http_response_code(400);
    echo '<div class="alert">Не указан фильтр.</div>';
    exit;
}

$rawFilter = (string) $_POST['filter'];
$filter = trim($rawFilter);
// Неразрывный пробел и прочие «похожие на пробел» из справочника/браузера
$filter = str_replace("\xC2\xA0", ' ', $filter);
$filter = trim(preg_replace('/\s+/u', ' ', $filter));
if ($filter === '') {
    echo '<div class="muted">Выберите фильтр…</div>';
    exit;
}

$filterEsc = htmlspecialchars($filter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<h4>Информация по наличию фильтра ' . $filterEsc . ' в заявках</h4><p>';

$pdo = getPdo('plan_u3');
/*
 * Сравнение после TRIM и замены UTF‑8 NBSP (частая причина: справочник без лишних пробелов, в заявке — с NBSP/хвостом).
 * SUM(count) — если по заявке несколько строк с тем же фильтром (разное написание пробелов).
 */
$nb = "REPLACE(REPLACE(REPLACE(%s, UNHEX('C2A0'), ' '), UNHEX('E28087'), ' '), UNHEX('E280AF'), ' ')";
$col = sprintf($nb, 'filter');
$prm = sprintf($nb, '?');
$sql = "SELECT order_number,
               SUM(`count`) AS cnt,
               MAX(`filter`) AS sample_filter,
               MAX(CASE WHEN COALESCE(hide, 0) = 1 THEN 1 ELSE 0 END) AS is_hidden
        FROM orders
        WHERE TRIM($col) = TRIM($prm)
        GROUP BY order_number
        ORDER BY order_number";
$stmt = $pdo->prepare($sql);
$stmt->execute([$filter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Заявки, в которых присутствует эта позиция:<br>";
if ($rows === []) {
    echo '<p class="muted">Нет заявок с таким фильтром (проверьте совпадение строки в <code>orders.filter</code> и справочнике).</p>';
}
echo '<style>
    .filter-search-form { margin-top: 8px; }
    .filter-search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 8px 0;
    }
    .filter-search-btn {
        min-height: 42px;
        min-width: 160px;
        padding: 10px 16px;
        border: 0;
        border-radius: 12px;
        color: #fff;
        font-weight: 700;
        font-size: 16px;
        line-height: 1.2;
        text-align: center;
        cursor: pointer;
    }
    .filter-search-btn--active { background: #2f5bd8; }
    .filter-search-btn--closed { background: #9ca3af; }
    .filter-search-meta { color: #334155; font-size: 13px; }
</style>';
echo '<form action="show_order.php" method="post" target="_blank" class="filter-search-form">';
foreach ($rows as $row) {
    $orderNum = $row['order_number'];
    $ordered = (int)($row['cnt'] ?? 0);
    $filterForFact = (string)($row['sample_filter'] ?? $filter);
    $isHidden = (int)($row['is_hidden'] ?? 0) === 1;
    $btnClass = $isHidden ? 'filter-search-btn filter-search-btn--closed' : 'filter-search-btn filter-search-btn--active';
    $on = htmlspecialchars($orderNum, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $produced = (int)select_produced_filters_by_order($filterForFact, $orderNum)[1];
    echo '<div class="filter-search-row">';
    echo '<button type="submit" name="order_number" value="' . $on . '" class="' . $btnClass . '">' . $on . '</button>';
    echo '<span class="filter-search-meta">заказано: ' . $ordered . ' | изготовлено: ' . $produced . '</span>';
    echo '</div>';
}
echo '</form>';


