<?php
// show_corr_fact_range.php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4","root","");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = $_POST['start'] ?? '';
$end   = $_POST['end'] ?? '';

if (!$start || !$end) { echo "<p>Нужно указать обе даты</p>"; exit; }

// Суммарно по периоду
$sql = "
SELECT
  order_number,
  TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
  SUM(COALESCE(fact_count,0)) AS fact_sum
FROM corrugation_plan
WHERE plan_date BETWEEN :s AND :e
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
      SELECT plan_date, SUM(COALESCE(fact_count,0)) AS qty
      FROM corrugation_plan
      WHERE plan_date BETWEEN :s AND :e
        AND order_number = :o
        AND TRIM(SUBSTRING_INDEX(filter_label,' [',1)) = :f
        AND COALESCE(fact_count,0) > 0
      GROUP BY plan_date
      ORDER BY plan_date
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
