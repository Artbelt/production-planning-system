<?php
/**
 * Отметка бухты как порезанной.
 *
 * Плановая дата порезки (не меняется при отметке):
 *   U2 — plan_date
 *   U3/U4/U5 — work_date (иногда plan_date)
 *
 * Факт порезки (записывается при done=1):
 *   fact_cut_date — календарная дата факта (DATE)
 *   fact_cut_at   — дата и время отметки (DATETIME)
 */

require_once __DIR__ . '/roll_plan_table.php';

function rollPlanTableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function rollPlanTableType(PDO $pdo, string $table): ?string
{
    $st = $pdo->prepare(
        'SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    $type = $st->fetchColumn();
    return $type !== false ? (string) $type : null;
}

/** Таблица для ALTER (VIEW → базовая roll_plan). */
function rollPlanPhysicalTable(PDO $pdo, string $table): string
{
    if (rollPlanTableType($pdo, $table) !== 'VIEW') {
        return $table;
    }
    if (rollPlanTableExists($pdo, 'roll_plan') && rollPlanTableType($pdo, 'roll_plan') === 'BASE TABLE') {
        return 'roll_plan';
    }
    return $table;
}

function rollPlanSyncSessionTimezone(PDO $pdo): void
{
    static $synced = [];
    $key = spl_object_id($pdo);
    if (isset($synced[$key])) {
        return;
    }
    try {
        $offset = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
    } catch (Throwable $e) {
        error_log('rollPlanSyncSessionTimezone: ' . $e->getMessage());
    }
    $synced[$key] = true;
}

/** Добавляет fact_cut_date / fact_cut_at на физической таблице, если их нет. */
function rollPlanEnsureFactCutColumns(PDO $pdo, string $table): void
{
    $physical = rollPlanPhysicalTable($pdo, $table);
    if (rollPlanTableType($pdo, $physical) !== 'BASE TABLE') {
        return;
    }
    if (!rollPlanTableHasColumnPdo($pdo, $physical, 'done')) {
        try {
            $pdo->exec("ALTER TABLE `{$physical}` ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            error_log('rollPlanEnsureFactCutColumns done: ' . $e->getMessage());
        }
    }
    if (!rollPlanTableHasColumnPdo($pdo, $physical, 'fact_cut_date')) {
        try {
            $after = rollPlanTableHasColumnPdo($pdo, $physical, 'done') ? 'done' : 'bale_id';
            $pdo->exec("ALTER TABLE `{$physical}` ADD COLUMN fact_cut_date DATE NULL AFTER `{$after}`");
        } catch (Throwable $e) {
            error_log('rollPlanEnsureFactCutColumns fact_cut_date: ' . $e->getMessage());
        }
    }
    if (!rollPlanTableHasColumnPdo($pdo, $physical, 'fact_cut_at')) {
        try {
            $after = rollPlanTableHasColumnPdo($pdo, $physical, 'fact_cut_date') ? 'fact_cut_date' : 'done';
            $pdo->exec("ALTER TABLE `{$physical}` ADD COLUMN fact_cut_at DATETIME NULL AFTER `{$after}`");
        } catch (Throwable $e) {
            error_log('rollPlanEnsureFactCutColumns fact_cut_at: ' . $e->getMessage());
        }
    }
}

/**
 * @return list<string>
 */
function rollPlanTableCandidates(PDO $pdo, string $departmentCode): array
{
    $out = [];
    $primary = resolveRollPlanTablePdo($pdo, $departmentCode);
    if ($primary) {
        $out[] = $primary;
    }
    foreach (['roll_plan', 'roll_plans'] as $t) {
        if (!in_array($t, $out, true) && rollPlanTableExists($pdo, $t)) {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * @return array{success: bool, message?: string, fact_cut_date?: string, fact_cut_at?: string}
 */
function rollPlanMarkCutDoneById(PDO $pdo, string $table, int $id): array
{
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Нет ID'];
    }
    if (!rollPlanTableExists($pdo, $table)) {
        return ['success' => false, 'message' => 'Таблица не найдена'];
    }

    rollPlanSyncSessionTimezone($pdo);
    rollPlanEnsureFactCutColumns($pdo, $table);

    $sets = ['done = 1'];
    if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_date')) {
        $sets[] = 'fact_cut_date = CURDATE()';
    }
    if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_at')) {
        $sets[] = 'fact_cut_at = NOW()';
    }

    $safeTable = str_replace('`', '', $table);
    $sql = 'UPDATE `' . $safeTable . '` SET ' . implode(', ', $sets) . ' WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $sel = ['done'];
        if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_date')) {
            $sel[] = 'fact_cut_date';
        }
        if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_at')) {
            $sel[] = 'fact_cut_at';
        }
        $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . ' FROM `' . $safeTable . '` WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        // rowCount() у UPDATE часто 0, если done уже был 1 или драйвер не сообщил
        // число изменённых строк. Для интерфейса важно, что бухта стала выполненной.
        if ((int) ($row['done'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Запись не обновлена'];
        }

        return [
            'success' => true,
            'fact_cut_date' => isset($row['fact_cut_date']) ? (string) $row['fact_cut_date'] : null,
            'fact_cut_at' => isset($row['fact_cut_at']) ? (string) $row['fact_cut_at'] : null,
        ];
    } catch (Throwable $e) {
        error_log('rollPlanMarkCutDoneById: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rollPlanNormalizeTableName(?string $table): string
{
    $table = strtolower(trim((string) $table));
    return in_array($table, ['roll_plan', 'roll_plans'], true) ? $table : '';
}

/**
 * Базовые таблицы, куда можно писать done.
 * VIEW не дублируем, если рядом есть roll_plan / roll_plans как BASE TABLE.
 *
 * @return list<string>
 */
function rollPlanWritableTables(PDO $pdo): array
{
    $base = [];
    foreach (['roll_plan', 'roll_plans'] as $table) {
        if (rollPlanTableExists($pdo, $table) && rollPlanTableType($pdo, $table) === 'BASE TABLE') {
            $base[] = $table;
        }
    }
    if ($base !== []) {
        return $base;
    }
    foreach (['roll_plan', 'roll_plans'] as $table) {
        if (rollPlanTableExists($pdo, $table)) {
            return [$table];
        }
    }
    return [];
}

/**
 * Ставит done=1 на всех копиях бухты (order_number + bale_id) в roll_plan и roll_plans.
 * $sourceTable/$sourceId — строка, которую показал интерфейс: её id может совпадать
 * с другой бухтой в соседней таблице, поэтому одной проверки «первый id нашёлся» мало.
 *
 * @return array{success: bool, message?: string, fact_cut_date?: string, fact_cut_at?: string}
 */
function rollPlanMarkCutDoneByOrderBale(
    PDO $pdo,
    string $orderNumber,
    string $baleId,
    string $sourceTable = '',
    int $sourceId = 0
): array {
    $orderNumber = trim($orderNumber);
    $baleId = trim($baleId);
    $sourceTable = rollPlanNormalizeTableName($sourceTable);
    if ($orderNumber === '' || $baleId === '') {
        return ['success' => false, 'message' => 'Не указаны заявка и бухта'];
    }

    $targets = [];
    foreach (rollPlanWritableTables($pdo) as $table) {
        $safe = str_replace('`', '', $table);
        try {
            $st = $pdo->prepare(
                "SELECT id FROM `{$safe}`
                 WHERE TRIM(order_number) = ? AND TRIM(bale_id) = ?"
            );
            $st->execute([$orderNumber, $baleId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('rollPlanMarkCutDoneByOrderBale: ' . $e->getMessage());
            continue;
        }
        foreach ($ids as $rowId) {
            $targets[$table . '#' . (int) $rowId] = [$table, (int) $rowId];
        }
    }

    if ($sourceTable !== '' && $sourceId > 0 && rollPlanTableExists($pdo, $sourceTable)) {
        $key = $sourceTable . '#' . $sourceId;
        // VIEW поверх базовой таблицы не обновляем отдельно: запись уже уйдёт в roll_plan.
        $coveredByBase = $targets !== [] && rollPlanTableType($pdo, $sourceTable) === 'VIEW';
        if (!isset($targets[$key]) && !$coveredByBase) {
            $targets[$key] = [$sourceTable, $sourceId];
        }
    }

    if ($targets === []) {
        return ['success' => false, 'message' => 'Запись не найдена'];
    }

    $lastOk = null;
    $lastError = 'Запись не обновлена';
    $failed = 0;
    foreach ($targets as $target) {
        $res = rollPlanMarkCutDoneById($pdo, $target[0], $target[1]);
        if (!empty($res['success'])) {
            $lastOk = $res;
        } else {
            $failed++;
            $lastError = $res['message'] ?? $lastError;
        }
    }

    if ($failed > 0 || $lastOk === null) {
        return ['success' => false, 'message' => $lastError];
    }
    return $lastOk;
}

/**
 * Отметка по id с автопоиском таблицы участка (roll_plan / roll_plans).
 *
 * На U6 одновременно живут две базовые таблицы. Список просроченных читает ту,
 * где больше строк (часто roll_plans), а поиск только по id сначала попадал в roll_plan
 * и отмечал чужую бухту с тем же id. Если переданы source_table или заявка+бухта,
 * отмечается именно эта бухта во всех копиях.
 *
 * @return array{success: bool, message?: string, fact_cut_date?: string, fact_cut_at?: string}
 */
function rollPlanMarkCutDone(
    PDO $pdo,
    string $departmentCode,
    int $id,
    ?string $orderNumber = null,
    ?string $baleId = null,
    ?string $sourceTable = null
): array {
    $orderNumber = trim((string) $orderNumber);
    $baleId = trim((string) $baleId);
    $sourceTable = rollPlanNormalizeTableName($sourceTable);

    if ($sourceTable !== '' && $id > 0 && rollPlanTableExists($pdo, $sourceTable)) {
        $safe = str_replace('`', '', $sourceTable);
        try {
            $chk = $pdo->prepare(
                "SELECT order_number, bale_id FROM `{$safe}` WHERE id = ?"
            );
            $chk->execute([$id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rollPlanMarkCutDone source: ' . $e->getMessage());
            $row = false;
        }
        if ($row) {
            $orderFromRow = trim((string) ($row['order_number'] ?? ''));
            $baleFromRow = trim((string) ($row['bale_id'] ?? ''));
            if ($orderFromRow !== '' && $baleFromRow !== '') {
                return rollPlanMarkCutDoneByOrderBale($pdo, $orderFromRow, $baleFromRow, $sourceTable, $id);
            }
            return rollPlanMarkCutDoneById($pdo, $sourceTable, $id);
        }
    }

    if ($orderNumber !== '' && $baleId !== '') {
        return rollPlanMarkCutDoneByOrderBale($pdo, $orderNumber, $baleId, $sourceTable, $id);
    }

    if ($id <= 0) {
        return ['success' => false, 'message' => 'Нет ID'];
    }

    foreach (rollPlanTableCandidates($pdo, $departmentCode) as $table) {
        $safe = str_replace('`', '', $table);
        try {
            $chk = $pdo->prepare(
                "SELECT order_number, bale_id FROM `{$safe}` WHERE id = ?"
            );
            $chk->execute([$id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('rollPlanMarkCutDone id: ' . $e->getMessage());
            continue;
        }
        if (!$row) {
            continue;
        }
        $orderFromRow = trim((string) ($row['order_number'] ?? ''));
        $baleFromRow = trim((string) ($row['bale_id'] ?? ''));
        if ($orderFromRow !== '' || $baleFromRow !== '') {
            if ($orderFromRow === '' || $baleFromRow === '') {
                return rollPlanMarkCutDoneById($pdo, $table, $id);
            }
            return rollPlanMarkCutDoneByOrderBale($pdo, $orderFromRow, $baleFromRow, $table, $id);
        }
        return rollPlanMarkCutDoneById($pdo, $table, $id);
    }
    return ['success' => false, 'message' => 'Запись не найдена'];
}
