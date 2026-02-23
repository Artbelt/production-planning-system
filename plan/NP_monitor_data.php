<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$date = $_GET['date'] ?? date('Y-m-d');
$hideDone = isset($_GET['hideDone']) && $_GET['hideDone']=='1';

// --- Порезка (roll_plan) ---
$cutTotals = $pdo->prepare("
  SELECT 
    COUNT(*) AS plan_total,
    SUM(done=1) AS done_total
  FROM roll_plan
  WHERE plan_date = ?
");
$cutTotals->execute([$date]);
$cutKpi = $cutTotals->fetch(PDO::FETCH_ASSOC) ?: ['plan_total'=>0,'done_total'=>0];

// по заявкам: сколько бухт всего и сколько готово
$cutByOrder = $pdo->prepare("
  SELECT order_number,
         COUNT(*) AS plan_bales,
         SUM(done=1) AS done_bales
  FROM roll_plan
  WHERE plan_date = ?
  GROUP BY order_number
  ORDER BY order_number
");
$cutByOrder->execute([$date]);
$cutRows = $cutByOrder->fetchAll(PDO::FETCH_ASSOC);
if ($hideDone) {
    $cutRows = array_values(array_filter($cutRows, fn($r)=> (int)$r['plan_bales'] > (int)$r['done_bales']));
}

// --- Гофрирование: план из corrugation_plan, факт из manufactured_corrugated_packages ---
$corrPlanTotals = $pdo->prepare("SELECT COALESCE(SUM(`count`),0) AS plan_total FROM corrugation_plan WHERE plan_date = ?");
$corrPlanTotals->execute([$date]);
$planTotal = (int)($corrPlanTotals->fetch(PDO::FETCH_ASSOC)['plan_total'] ?? 0);

$corrFactTotals = $pdo->prepare("SELECT COALESCE(SUM(`count`),0) AS fact_total FROM manufactured_corrugated_packages WHERE date_of_production = ?");
$corrFactTotals->execute([$date]);
$factTotal = (int)($corrFactTotals->fetch(PDO::FETCH_ASSOC)['fact_total'] ?? 0);
$corrKpi = ['plan_total' => $planTotal, 'fact_total' => $factTotal];

// по заявкам: план из corrugation_plan, факт из manufactured_corrugated_packages
$corrPlanByOrder = $pdo->prepare("
  SELECT order_number, COALESCE(SUM(`count`),0) AS plan_count
  FROM corrugation_plan WHERE plan_date = ? GROUP BY order_number ORDER BY order_number
");
$corrPlanByOrder->execute([$date]);
$planByOrder = [];
while ($r = $corrPlanByOrder->fetch(PDO::FETCH_ASSOC)) {
    $planByOrder[$r['order_number']] = (int)$r['plan_count'];
}
$corrFactByOrder = $pdo->prepare("
  SELECT order_number, COALESCE(SUM(`count`),0) AS fact_count
  FROM manufactured_corrugated_packages WHERE date_of_production = ? GROUP BY order_number ORDER BY order_number
");
$corrFactByOrder->execute([$date]);
$factByOrder = [];
while ($r = $corrFactByOrder->fetch(PDO::FETCH_ASSOC)) {
    $factByOrder[$r['order_number']] = (int)$r['fact_count'];
}
$allOrders = array_unique(array_merge(array_keys($planByOrder), array_keys($factByOrder)));
sort($allOrders);
$corrRows = [];
foreach ($allOrders as $ord) {
    $corrRows[] = [
        'order_number' => $ord,
        'plan_count'   => $planByOrder[$ord] ?? 0,
        'fact_count'   => $factByOrder[$ord] ?? 0,
    ];
}
if ($hideDone) {
    $corrRows = array_values(array_filter($corrRows, fn($r)=> (int)$r['plan_count'] > (int)$r['fact_count']));
}

echo json_encode([
    'date' => $date,
    'cut'  => [
        'kpi'  => [
            'plan' => (int)$cutKpi['plan_total'],
            'done' => (int)$cutKpi['done_total'],
        ],
        'byOrder' => array_map(function($r){
            $plan=(int)$r['plan_bales']; $done=(int)$r['done_bales'];
            return [
                'order' => $r['order_number'],
                'plan' => $plan,
                'done' => $done,
                'left' => max(0,$plan-$done),
            ];
        }, $cutRows),
    ],
    'corr' => [
        'kpi'  => [
            'plan' => (int)$corrKpi['plan_total'],
            'fact' => (int)$corrKpi['fact_total'],
        ],
        'byOrder' => array_map(function($r){
            $plan=(int)$r['plan_count']; $fact=(int)$r['fact_count'];
            return [
                'order' => $r['order_number'],
                'plan'  => $plan,
                'fact'  => $fact,
                'left'  => max(0,$plan-$fact),
            ];
        }, $corrRows),
    ],
], JSON_UNESCAPED_UNICODE);
