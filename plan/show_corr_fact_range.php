<?php
// show_corr_fact_range.php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = $_POST['start'] ?? '';
$end   = $_POST['end'] ?? '';

if (!$start || !$end) { echo "<p>Нужно указать обе даты</p>"; exit; }

// Суммарно по периоду из manufactured_corrugated_packages
$sql = "
SELECT
  order_number,
  TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
  SUM(COALESCE(count,0)) AS fact_sum
FROM manufactured_corrugated_packages
WHERE date_of_production BETWEEN :s AND :e
GROUP BY order_number, base_filter
HAVING fact_sum > 0
ORDER BY order_number, base_filter
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':s'=>$start, ':e'=>$end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Факт гофропакетов за период ".htmlspecialchars($start)." — ".htmlspecialchars($end)."</h3>";

if (!$rows){
    echo "<p>Нет данных</p>"; exit;
}

// Тултип: разбивка по датам
function tooltipRangeFor($pdo, $order, $baseFilter, $start, $end){
    $q = $pdo->prepare("
      SELECT date_of_production AS plan_date, SUM(COALESCE(count,0)) AS qty
      FROM manufactured_corrugated_packages
      WHERE date_of_production BETWEEN :s AND :e
        AND order_number = :o
        AND TRIM(SUBSTRING_INDEX(filter_label,' [',1)) = :f
        AND COALESCE(count,0) > 0
      GROUP BY date_of_production
      ORDER BY date_of_production
    ");
    $q->execute([':s'=>$start, ':e'=>$end, ':o'=>$order, ':f'=>$baseFilter]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    if(!$items) return '';
    $tip = '';
    foreach($items as $it){
        $tip .= $it['plan_date'].' — '.$it['qty']." шт\n";
    }
    return htmlspecialchars(trim($tip));
}

echo "<table>";
echo "<tr><th>Заявка</th><th>Фильтр</th><th>Факт, шт</th></tr>";
foreach($rows as $r){
    $tip = tooltipRangeFor($pdo, $r['order_number'], $r['base_filter'], $start, $end);
    $fact = (int)$r['fact_sum'];
    if ($tip){
        echo "<tr>
            <td>".htmlspecialchars($r['order_number'])."</td>
            <td>".htmlspecialchars($r['base_filter'])."</td>
            <td><div class='tooltip'>{$fact}<span class='tooltiptext'>{$tip}</span></div></td>
          </tr>";
    } else {
        echo "<tr>
            <td>".htmlspecialchars($r['order_number'])."</td>
            <td>".htmlspecialchars($r['base_filter'])."</td>
            <td>{$fact}</td>
          </tr>";
    }
}
echo "</table>";
