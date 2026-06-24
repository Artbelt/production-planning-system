<?php
/**
 * Диагностика лога порезки по заявкам/бухтам. Запуск: php tools/diag_cut_log.php
 */
require_once __DIR__ . '/../auth/includes/db.php';
require_once __DIR__ . '/../auth/includes/roll_plan_table.php';

$reportDate = $argv[1] ?? '2026-06-18';

$cases = [
    ['db' => 'plan_u5', 'shop' => 'U5', 'order' => '24-27-26', 'bales' => [59, 60, 23, 6]],
    ['db' => 'plan', 'shop' => 'U2', 'order' => '22-30-26', 'bales' => [554, 560, 561]],
    ['db' => 'plan_u3', 'shop' => 'U3', 'order' => '25-33-26', 'bales' => [6, 8]],
];

echo "Дата отчёта: {$reportDate}\n";
echo str_repeat('=', 72) . "\n";

foreach ($cases as $case) {
    echo "\n{$case['shop']} заявка {$case['order']}\n";
    try {
        $pdo = getPdo($case['db']);
        $table = resolveRollPlanTablePdo($pdo, $case['shop']) ?? '(нет таблицы)';
        echo "Таблица: {$table}\n";
        if ($table === '(нет таблицы)') {
            continue;
        }
        $planExpr = rollPlanDateExpressionPdo($pdo, $table, $case['shop']) ?? '?';
        $hasAt = rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_at');
        $hasDate = rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_date');
        $cutExpr = rollPlanSqlEffectiveCutDate($planExpr, $hasAt, $hasDate);
        echo "plan_date expr: {$planExpr}; fact_cut_at=" . ($hasAt ? 'yes' : 'no') . "; fact_cut_date=" . ($hasDate ? 'yes' : 'no') . "\n";

        $cols = ['id', 'bale_id', 'done'];
        foreach (['work_date', 'plan_date', 'fact_cut_date', 'fact_cut_at'] as $c) {
            if (rollPlanTableHasColumnPdo($pdo, $table, $c)) {
                $cols[] = $c;
            }
        }
        $sel = implode(', ', $cols);
        $in = implode(',', array_map('intval', $case['bales']));
        $sql = "SELECT {$sel}, ({$cutExpr}) AS effective_cut_date FROM `{$table}`
                WHERE order_number = ? AND bale_id IN ({$in}) ORDER BY bale_id";
        $st = $pdo->prepare($sql);
        $st->execute([$case['order']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "  (нет строк по указанным bale_id)\n";
        }
        foreach ($rows as $r) {
            $inLog = ($r['done'] == 1 && ($r['effective_cut_date'] ?? '') === $reportDate) ? 'ДА в логе' : 'нет в логе';
            echo '  bale ' . $r['bale_id'] . ': done=' . $r['done']
                . ' plan=' . ($r['work_date'] ?? $r['plan_date'] ?? '-')
                . ' fact_cut_date=' . ($r['fact_cut_date'] ?? 'NULL')
                . ' fact_cut_at=' . ($r['fact_cut_at'] ?? 'NULL')
                . ' effective=' . ($r['effective_cut_date'] ?? '?')
                . " => {$inLog}\n";
        }

        // Все done=1 по заявке с effective date = report date
        $sql2 = "SELECT bale_id, done, ({$cutExpr}) AS effective_cut_date,
                        fact_cut_date, fact_cut_at, {$planExpr} AS plan_d
                 FROM `{$table}` WHERE order_number = ? AND done = 1
                 HAVING effective_cut_date = ?
                 ORDER BY bale_id";
        $st2 = $pdo->prepare($sql2);
        $st2->execute([$case['order'], $reportDate]);
        $logRows = $st2->fetchAll(PDO::FETCH_ASSOC);
        echo "  В логе за {$reportDate} по этой заявке: ";
        if (!$logRows) {
            echo "(ничего)\n";
        } else {
            echo implode(', ', array_column($logRows, 'bale_id')) . "\n";
        }
    } catch (Throwable $e) {
        echo '  ERR: ' . $e->getMessage() . "\n";
    }
}
