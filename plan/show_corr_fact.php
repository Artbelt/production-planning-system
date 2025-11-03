<?php
// show_corr_fact.php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4","root","");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$date = $_POST['date'] ?? '';

if (!$date) { echo "<p>Не передана дата</p>"; exit; }

// Готовим нормализованное имя фильтра на стороне БД:
// base_filter = TRIM(SUBSTRING_INDEX(filter_label,' [',1))
$sql = "
SELECT
  order_number,
  TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
  SUM(COALESCE(fact_count,0)) AS fact_sum
FROM corrugation_plan
WHERE plan_date = :d
GROUP BY order_number, base_filter
HAVING fact_sum > 0
ORDER BY order_number, base_filter
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':d'=>$date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Факт гофропакетов за ".htmlspecialchars($date)."</h3>";

if (!$rows){
    echo "<p>Нет данных</p>"; exit;
}

// Чтобы собрать тултип (разбивка по позициям внутри дня),
/* по каждой строке берём конкретные записи (если нужно) */
function tooltipFor($pdo, $order, $baseFilter, $date){
    $q = $pdo->prepare("
      SELECT filter_label, COALESCE(fact_count,0) AS qty
      FROM corrugation_plan
      WHERE plan_date = :d
        AND order_number = :o
        AND TRIM(SUBSTRING_INDEX(filter_label,' [',1)) = :f
        AND COALESCE(fact_count,0) > 0
      ORDER BY id
    ");
    $q->execute([':d'=>$date, ':o'=>$order, ':f'=>$baseFilter]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    if(!$items) return '';
    $tip = '';
    foreach($items as $it){
        $tip .= $it['filter_label'].' — '.$it['qty']." шт\n";
    }
    return htmlspecialchars(trim($tip));
}

echo "<table>";
echo "<tr><th>Заявка</th><th>Фильтр</th><th>Факт, шт</th></tr>";
foreach($rows as $r){
    $tip = tooltipFor($pdo, $r['order_number'], $r['base_filter'], $date);
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
