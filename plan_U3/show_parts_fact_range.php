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

$sql = "
SELECT
    name_of_order,
    name_of_parts,
    SUM(COALESCE(count_of_parts, 0)) AS fact_sum
FROM manufactured_parts
WHERE date_of_production BETWEEN :s AND :e
GROUP BY name_of_order, name_of_parts
HAVING fact_sum > 0
ORDER BY name_of_order, name_of_parts
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':s' => $start, ':e' => $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Выпуск гофропакетов за период '
    . htmlspecialchars($start, ENT_QUOTES, 'UTF-8')
    . ' — '
    . htmlspecialchars($end, ENT_QUOTES, 'UTF-8')
    . '</h3>';

if (!$rows) {
    echo '<p>Нет данных</p>';
    exit;
}

function tooltipForRange(PDO $pdo, string $order, string $part, string $start, string $end): string
{
    $q = $pdo->prepare("
        SELECT date_of_production, SUM(COALESCE(count_of_parts, 0)) AS qty
        FROM manufactured_parts
        WHERE date_of_production BETWEEN :s AND :e
          AND name_of_order = :o
          AND name_of_parts = :p
          AND COALESCE(count_of_parts, 0) > 0
        GROUP BY date_of_production
        ORDER BY date_of_production
    ");
    $q->execute([
        ':s' => $start,
        ':e' => $end,
        ':o' => $order,
        ':p' => $part
    ]);
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
echo '<tr><th>Заявка</th><th>Гофропакет</th><th>Факт, шт</th></tr>';
foreach ($rows as $r) {
    $order = (string)($r['name_of_order'] ?? '');
    $part = (string)($r['name_of_parts'] ?? '');
    $fact = (int)($r['fact_sum'] ?? 0);
    $tip = tooltipForRange($pdo, $order, $part, $start, $end);

    if ($tip !== '') {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><div class="tooltip">' . $fact . '<span class="tooltiptext">' . $tip . '</span></div></td>'
            . '</tr>';
    } else {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . $fact . '</td>'
            . '</tr>';
    }
}
echo '</table>';
