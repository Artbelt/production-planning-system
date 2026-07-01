<?php
require_once __DIR__ . '/../auth/includes/db.php';
require_once __DIR__ . '/gofro_machine_helpers.php';
$pdo = getPdo('plan_u3');

$date = $_POST['date'] ?? '';

if (!$date) {
    echo '<p>Не передана дата</p>';
    exit;
}

$sql = "
SELECT
    name_of_order,
    name_of_parts,
    SUM(COALESCE(count_of_parts, 0)) AS fact_sum
FROM manufactured_parts
WHERE date_of_production = :d
GROUP BY name_of_order, name_of_parts
HAVING fact_sum > 0
ORDER BY name_of_order, name_of_parts
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':d' => $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Выпуск гофропакетов за ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</h3>';

if (!$rows) {
    echo '<p>Нет данных</p>';
    exit;
}

$foldHeightMap = loadGofroFoldHeightByPackage($pdo);
$knifeTotal = 0;
$rotaryTotal = 0;
foreach ($rows as $r) {
    $fact = (int)($r['fact_sum'] ?? 0);
    $machineType = gofroMachineTypeForPackage((string)($r['name_of_parts'] ?? ''), $foldHeightMap);
    if ($machineType === 'rotary') {
        $rotaryTotal += $fact;
    } else {
        $knifeTotal += $fact;
    }
}
$grandTotal = $knifeTotal + $rotaryTotal;

echo renderGofroMachineTotalsSummary($knifeTotal, $rotaryTotal, $grandTotal);

function tooltipForDay(PDO $pdo, string $order, string $part, string $date): string
{
    $q = $pdo->prepare("
        SELECT date_of_production, SUM(COALESCE(count_of_parts, 0)) AS qty
        FROM manufactured_parts
        WHERE date_of_production = :d
          AND name_of_order = :o
          AND name_of_parts = :p
          AND COALESCE(count_of_parts, 0) > 0
        GROUP BY date_of_production
        ORDER BY date_of_production
    ");
    $q->execute([':d' => $date, ':o' => $order, ':p' => $part]);
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
echo '<tr><th>Заявка</th><th>Гофропакет</th><th>Маш.</th><th>Факт, шт</th></tr>';
foreach ($rows as $r) {
    $order = (string)($r['name_of_order'] ?? '');
    $part = (string)($r['name_of_parts'] ?? '');
    $fact = (int)($r['fact_sum'] ?? 0);
    $machineType = gofroMachineTypeForPackage($part, $foldHeightMap);
    $machineLabel = gofroMachineShortLabel($machineType);
    $machineTitle = htmlspecialchars(gofroMachineTitle($machineType), ENT_QUOTES, 'UTF-8');
    $tip = tooltipForDay($pdo, $order, $part, $date);

    if ($tip !== '') {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td title="' . $machineTitle . '" style="text-align:center;font-weight:600;">' . htmlspecialchars($machineLabel, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td><div class="tooltip">' . $fact . '<span class="tooltiptext">' . $tip . '</span></div></td>'
            . '</tr>';
    } else {
        echo '<tr>'
            . '<td>' . htmlspecialchars($order, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td title="' . $machineTitle . '" style="text-align:center;font-weight:600;">' . htmlspecialchars($machineLabel, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . $fact . '</td>'
            . '</tr>';
    }
}
echo '</table>';
