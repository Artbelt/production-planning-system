<?php
/**
 * Выбор таблицы плана порезки бухт — единообразно с cut_operator.
 * U2: roll_plan; U3/U4/U5: roll_plans (после миграции это VIEW на roll_plan).
 */
function resolveRollPlanTable(mysqli $mysqli, string $departmentCode): ?string
{
    $candidates = ($departmentCode === 'U2')
        ? ['roll_plan', 'roll_plans']
        : ['roll_plans', 'roll_plan'];

    $bestTable = null;
    $bestCount = -1;

    foreach ($candidates as $table) {
        $chk = $mysqli->query("SHOW TABLES LIKE '{$table}'");
        if (!$chk || $chk->num_rows === 0) {
            continue;
        }
        $cntRes = $mysqli->query("SELECT COUNT(*) AS c FROM `{$table}`");
        $count = $cntRes ? (int)($cntRes->fetch_assoc()['c'] ?? 0) : 0;
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestTable = $table;
        }
    }

    return $bestTable;
}

/**
 * Поле даты плана порезки. U2 — plan_date; U3/U4/U5 — work_date (так пишет NP_roll_plan и cut_operator).
 */
function rollPlanDateExpression(mysqli $mysqli, string $table, string $departmentCode = ''): ?string
{
    $hasPlanDate = false;
    $hasWorkDate = false;

    $colChk = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE 'plan_date'");
    if ($colChk && $colChk->num_rows > 0) {
        $hasPlanDate = true;
    }
    $colChk = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE 'work_date'");
    if ($colChk && $colChk->num_rows > 0) {
        $hasWorkDate = true;
    }

    if ($departmentCode !== 'U2' && $departmentCode !== '' && $hasWorkDate) {
        return 'work_date';
    }
    if ($hasPlanDate && $hasWorkDate) {
        return 'COALESCE(plan_date, work_date)';
    }
    if ($hasPlanDate) {
        return 'plan_date';
    }
    if ($hasWorkDate) {
        return 'work_date';
    }

    return null;
}

function rollPlanHasFactCutDate(mysqli $mysqli, string $table): bool
{
    $colChk = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE 'fact_cut_date'");
    return $colChk && $colChk->num_rows > 0;
}

/** Считается порезанной на дату отчёта (та же логика, что и факт в аналитике). */
function rollPlanIsCutOnReportDate(array $row, string $reportDate, string $planDateExpr, bool $hasFactCutDate): bool
{
    if ((int)($row['done'] ?? 0) !== 1) {
        return false;
    }
    if (!$hasFactCutDate) {
        return true;
    }
    $factDate = $row['fact_cut_date'] ?? null;
    if ($factDate !== null && $factDate !== '') {
        return $factDate === $reportDate;
    }
    $planDate = $row['plan_date_computed'] ?? null;
    return $planDate === $reportDate;
}

/**
 * @return array{planned: list<array{order_number: string, bale_id: string|int, is_cut: bool}>, cut: list<array{order_number: string, bale_id: string|int}>}
 */
function fetchRollPlanBalesForReport(
    mysqli $mysqli,
    string $table,
    string $planDateExpr,
    string $reportDate,
    bool $hasFactCutDate
): array {
    $planned = [];
    $cut = [];
    $seenCut = [];

    $selectCols = 'order_number, bale_id, done';
    if ($hasFactCutDate) {
        $selectCols .= ', fact_cut_date';
    }
    $selectCols .= ", {$planDateExpr} AS plan_date_computed";

    $stmt = $mysqli->prepare("
        SELECT {$selectCols}
        FROM `{$table}`
        WHERE {$planDateExpr} = ?
        ORDER BY order_number, bale_id
    ");
    if ($stmt) {
        $stmt->bind_param('s', $reportDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $isCut = rollPlanIsCutOnReportDate($row, $reportDate, $planDateExpr, $hasFactCutDate);
            $planned[] = [
                'order_number' => (string)($row['order_number'] ?? ''),
                'bale_id' => $row['bale_id'],
                'is_cut' => $isCut,
            ];
            if ($isCut) {
                $key = ($row['order_number'] ?? '') . "\0" . $row['bale_id'];
                $seenCut[$key] = [
                    'order_number' => (string)($row['order_number'] ?? ''),
                    'bale_id' => $row['bale_id'],
                ];
            }
        }
        $stmt->close();
    }

    if ($hasFactCutDate) {
        $stmtC = $mysqli->prepare("
            SELECT order_number, bale_id
            FROM `{$table}`
            WHERE done = 1 AND fact_cut_date = ?
            ORDER BY order_number, bale_id
        ");
        if ($stmtC) {
            $stmtC->bind_param('s', $reportDate);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($row = $resC->fetch_assoc()) {
                $key = ($row['order_number'] ?? '') . "\0" . $row['bale_id'];
                if (!isset($seenCut[$key])) {
                    $seenCut[$key] = [
                        'order_number' => (string)($row['order_number'] ?? ''),
                        'bale_id' => $row['bale_id'],
                    ];
                }
            }
            $stmtC->close();
        }
    }

    $cut = array_values($seenCut);
    usort($cut, static function ($a, $b) {
        $cmp = strcmp($a['order_number'], $b['order_number']);
        return $cmp !== 0 ? $cmp : ((int)$a['bale_id'] <=> (int)$b['bale_id']);
    });

    return ['planned' => $planned, 'cut' => $cut];
}
