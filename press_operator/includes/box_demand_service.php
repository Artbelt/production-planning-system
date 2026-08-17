<?php
/**
 * Расчёт актуальной потребности в индивидуальных коробках по активным заявкам всех участков.
 */

require_once __DIR__ . '/../../auth/includes/db.php';

/** @return array<int, array{code:string,label:string,db:string,structure:string,box_col:string,skip_values:array<int,string>}> */
function boxDemandDepartments(): array
{
    return [
        ['code' => 'U2', 'label' => 'У2', 'db' => 'plan', 'structure' => 'panel_filter_structure', 'box_col' => 'box', 'skip_values' => []],
        ['code' => 'U5', 'label' => 'У5', 'db' => 'plan_u5', 'structure' => 'salon_filter_structure', 'box_col' => 'box', 'skip_values' => []],
    ];
}

function boxDemandIsValidBoxName(string $name, array $skipValues = []): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    $upper = mb_strtoupper($name, 'UTF-8');
    foreach ($skipValues as $skip) {
        if ($upper === mb_strtoupper(trim((string)$skip), 'UTF-8')) {
            return false;
        }
    }

    return true;
}

function boxDemandSql(string $structureTable, string $boxColumn, bool $withDetails = false, ?string $boxFilter = null, array $skipValues = []): string
{
    $boxExpr = "TRIM(fs.`{$boxColumn}`)";
    $select = $withDetails
        ? "agg.order_number, agg.filter AS filter_name, GREATEST(0, agg.ordered - COALESCE(prod.produced, 0)) AS need_qty"
        : "{$boxExpr} AS box_name, SUM(GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))) AS need_qty";

    $boxFilterClause = $boxFilter !== null ? " AND {$boxExpr} = :box_name" : '';
    $skipClause = '';
    foreach ($skipValues as $idx => $skipValue) {
        $skipClause .= " AND UPPER({$boxExpr}) <> :skip_{$idx}";
    }
    $groupBy = $withDetails ? '' : "GROUP BY {$boxExpr} HAVING need_qty > 0";
    $orderBy = $withDetails
        ? 'ORDER BY need_qty DESC, agg.order_number, agg.filter'
        : 'ORDER BY box_name';

    return "
        SELECT {$select}
        FROM (
            SELECT order_number, `filter`, SUM(`count`) AS ordered
            FROM orders
            WHERE (hide IS NULL OR hide != 1)
            GROUP BY order_number, `filter`
        ) agg
        INNER JOIN `{$structureTable}` fs ON TRIM(fs.filter) = TRIM(agg.filter)
        LEFT JOIN (
            SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
            FROM manufactured_production
            GROUP BY name_of_order, name_of_filter
        ) prod
            ON prod.name_of_order = agg.order_number
           AND TRIM(prod.name_of_filter) = TRIM(agg.filter)
        WHERE {$boxExpr} <> ''{$skipClause}{$boxFilterClause}
          AND agg.ordered > COALESCE(prod.produced, 0)
        {$groupBy}
        {$orderBy}
    ";
}

function boxDemandBindSkipValues(PDOStatement $stmt, array $skipValues): void
{
    foreach ($skipValues as $idx => $skipValue) {
        $stmt->bindValue(':skip_' . $idx, mb_strtoupper(trim((string)$skipValue), 'UTF-8'), PDO::PARAM_STR);
    }
}

/**
 * @return array{boxes: array<int, array{box_name:string,total:int,by_department:array<string,int>}>, departments: array<int, array{code:string,label:string}>, errors: array<int, string>}
 */
function boxDemandFetchSummary(): array
{
    $departments = boxDemandDepartments();
    $boxes = [];
    $errors = [];

    foreach ($departments as $dept) {
        try {
            $pdo = getPdo($dept['db']);
            $skipValues = $dept['skip_values'] ?? [];
            $stmt = $pdo->prepare(boxDemandSql($dept['structure'], $dept['box_col'], false, null, $skipValues));
            boxDemandBindSkipValues($stmt, $skipValues);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $errors[] = $dept['label'] . ': ' . $e->getMessage();
            continue;
        }

        foreach ($rows as $row) {
            $boxName = trim((string)($row['box_name'] ?? ''));
            $qty = (int)($row['need_qty'] ?? 0);
            if (!boxDemandIsValidBoxName($boxName, $dept['skip_values'] ?? []) || $qty <= 0) {
                continue;
            }
            if (!isset($boxes[$boxName])) {
                $boxes[$boxName] = [
                    'box_name' => $boxName,
                    'total' => 0,
                    'by_department' => [],
                ];
            }
            $boxes[$boxName]['total'] += $qty;
            $boxes[$boxName]['by_department'][$dept['code']] = ($boxes[$boxName]['by_department'][$dept['code']] ?? 0) + $qty;
        }
    }

    $result = array_values($boxes);
    usort($result, static function (array $a, array $b): int {
        $byTotal = $b['total'] <=> $a['total'];
        if ($byTotal !== 0) {
            return $byTotal;
        }
        return strnatcasecmp($a['box_name'], $b['box_name']);
    });

    return [
        'boxes' => $result,
        'departments' => array_map(static fn(array $d) => ['code' => $d['code'], 'label' => $d['label']], $departments),
        'errors' => $errors,
    ];
}

/**
 * @return array{box_name:string,total:int,departments:array<int, array{code:string,label:string,qty:int,orders:array<int, array{order_number:string,filter_name:string,need_qty:int}>}>, errors:array<int,string>}
 */
function boxDemandFetchBoxDetail(string $boxName): array
{
    $boxName = trim($boxName);
    if ($boxName === '') {
        return [
            'box_name' => '',
            'total' => 0,
            'departments' => [],
            'errors' => ['Не указано название коробки.'],
        ];
    }

    $departments = boxDemandDepartments();
    $detail = [];
    $total = 0;
    $errors = [];

    foreach ($departments as $dept) {
        try {
            $pdo = getPdo($dept['db']);
            $skipValues = $dept['skip_values'] ?? [];
            $sql = boxDemandSql($dept['structure'], $dept['box_col'], true, $boxName, $skipValues);
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':box_name', $boxName, PDO::PARAM_STR);
            boxDemandBindSkipValues($stmt, $skipValues);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $errors[] = $dept['label'] . ': ' . $e->getMessage();
            continue;
        }

        $qty = 0;
        $orders = [];
        foreach ($rows as $row) {
            $need = (int)($row['need_qty'] ?? 0);
            if ($need <= 0) {
                continue;
            }
            $qty += $need;
            $orders[] = [
                'order_number' => (string)($row['order_number'] ?? ''),
                'filter_name' => (string)($row['filter_name'] ?? ''),
                'need_qty' => $need,
            ];
        }

        if ($qty > 0) {
            $total += $qty;
            $detail[] = [
                'code' => $dept['code'],
                'label' => $dept['label'],
                'qty' => $qty,
                'orders' => $orders,
            ];
        }
    }

    usort($detail, static fn(array $a, array $b): int => $b['qty'] <=> $a['qty']);

    return [
        'box_name' => $boxName,
        'total' => $total,
        'departments' => $detail,
        'errors' => $errors,
    ];
}
