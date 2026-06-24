<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$date = $_POST['date'] ?? '';

if (!$date) {
    echo '<p>Не передана дата</p>';
    exit;
}

function wfPartTypeLabel(string $type): string
{
    return $type === 'int' ? 'внутренний' : ($type === 'ext' ? 'наружный' : $type);
}

$sql = "
SELECT
    order_number,
    wireframe_name,
    part_type,
    SUM(COALESCE(count_of_parts, 0)) AS fact_sum
FROM manufactured_wireframes
WHERE date_of_production = :d
GROUP BY order_number, wireframe_name, part_type
HAVING fact_sum > 0
ORDER BY order_number, wireframe_name, part_type
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':d' => $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Выпуск каркасов за ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</h3>';

if (!$rows) {
    echo '<p>Нет данных</p>';
    exit;
}

function tooltipForDay(PDO $pdo, string $order, string $wf, string $partType, string $date): string
{
    $q = $pdo->prepare("
        SELECT date_of_production, SUM(COALESCE(count_of_parts, 0)) AS qty
        FROM manufactured_wireframes
        WHERE date_of_production = :d
          AND order_number = :o
          AND wireframe_name = :w
          AND part_type = :t
          AND COALESCE(count_of_parts, 0) > 0
        GROUP BY date_of_production
        ORDER BY date_of_production
    ");
    $q->execute([':d' => $date, ':o' => $order, ':w' => $wf, ':t' => $partType]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return '';
    }

    $tip = '';
    foreach ($items as $it) {
        $tip .= $it['date_of_production'] . ' — ' . $it['qty'] . " шт\n";
    }
    return htmlspecialchars(trim($tip), ENT_QUOTES, 'UTF-8');
}

echo '<table>';
echo '<tr><th>Заявка</th><th>Каркас</th><th>Тип</th><th>Факт, шт</th></tr>';
foreach ($rows as $r) {
    $order = (string)($r['order_number'] ?? '');
    $wf = (string)($r['wireframe_name'] ?? '');
    $partType = (string)($r['part_type'] ?? '');
    $fact = (int)($r['fact_sum'] ?? 0);
    $tip = tooltipForDay($pdo, $order, $wf, $partType, $date);

    if ($tip !== '') {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($wf, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars(wfPartTypeLabel($partType), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><div class="tooltip">' . $fact . '<span class="tooltiptext">' . $tip . '</span></div></td>'
            . '</tr>';
    } else {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($wf, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars(wfPartTypeLabel($partType), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . $fact . '</td>'
            . '</tr>';
    }
}
echo '</table>';
