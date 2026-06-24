<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';

if (!$start || !$end) {
    echo '<p>Нужно указать обе даты</p>';
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
WHERE date_of_production BETWEEN :s AND :e
GROUP BY order_number, wireframe_name, part_type
HAVING fact_sum > 0
ORDER BY order_number, wireframe_name, part_type
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':s' => $start, ':e' => $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Выпуск каркасов за период '
    . htmlspecialchars($start, ENT_QUOTES, 'UTF-8')
    . ' — '
    . htmlspecialchars($end, ENT_QUOTES, 'UTF-8')
    . '</h3>';

if (!$rows) {
    echo '<p>Нет данных</p>';
    exit;
}

function rangeDetails(PDO $pdo, string $order, string $wf, string $partType, string $start, string $end): array
{
    $q = $pdo->prepare("
        SELECT date_of_production, SUM(COALESCE(count_of_parts, 0)) AS qty
        FROM manufactured_wireframes
        WHERE date_of_production BETWEEN :s AND :e
          AND order_number = :o
          AND wireframe_name = :w
          AND part_type = :t
          AND COALESCE(count_of_parts, 0) > 0
        GROUP BY date_of_production
        ORDER BY date_of_production
    ");
    $q->execute([
        ':s' => $start,
        ':e' => $end,
        ':o' => $order,
        ':w' => $wf,
        ':t' => $partType,
    ]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return ['dates' => '', 'tooltip' => ''];
    }

    $tip = '';
    $dates = [];
    foreach ($items as $it) {
        $date = (string)($it['date_of_production'] ?? '');
        if ($date !== '') {
            $dates[] = $date;
        }
        $tip .= $date . ' — ' . $it['qty'] . " шт\n";
    }
    return [
        'dates' => htmlspecialchars(implode(', ', $dates), ENT_QUOTES, 'UTF-8'),
        'tooltip' => htmlspecialchars(trim($tip), ENT_QUOTES, 'UTF-8'),
    ];
}

echo '<table>';
echo '<tr><th>Заявка</th><th>Каркас</th><th>Тип</th><th>Даты выпуска</th><th>Факт, шт</th></tr>';
foreach ($rows as $r) {
    $order = (string)($r['order_number'] ?? '');
    $wf = (string)($r['wireframe_name'] ?? '');
    $partType = (string)($r['part_type'] ?? '');
    $fact = (int)($r['fact_sum'] ?? 0);
    $details = rangeDetails($pdo, $order, $wf, $partType, $start, $end);
    $dates = $details['dates'];
    $tip = $details['tooltip'];

    if ($tip !== '') {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($wf, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars(wfPartTypeLabel($partType), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . $dates . '</td>'
            . '<td><div class="tooltip">' . $fact . '<span class="tooltiptext">' . $tip . '</span></div></td>'
            . '</tr>';
    } else {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($wf, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars(wfPartTypeLabel($partType), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>—</td>'
            . '<td>' . $fact . '</td>'
            . '</tr>';
    }
}
echo '</table>';
