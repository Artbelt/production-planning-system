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

    $sql = 'UPDATE `' . str_replace('`', '', $table) . '` SET ' . implode(', ', $sets) . ' WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([$id]);
        if (!$ok || $stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Запись не обновлена'];
        }

        $sel = ['done'];
        if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_date')) {
            $sel[] = 'fact_cut_date';
        }
        if (rollPlanTableHasColumnPdo($pdo, $table, 'fact_cut_at')) {
            $sel[] = 'fact_cut_at';
        }
        $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . ' FROM `' . str_replace('`', '', $table) . '` WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

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

/**
 * Отметка по id с автопоиском таблицы участка (roll_plan / roll_plans).
 *
 * @return array{success: bool, message?: string, fact_cut_date?: string, fact_cut_at?: string}
 */
function rollPlanMarkCutDone(PDO $pdo, string $departmentCode, int $id): array
{
    foreach (rollPlanTableCandidates($pdo, $departmentCode) as $table) {
        $chk = $pdo->prepare('SELECT id FROM `' . str_replace('`', '', $table) . '` WHERE id = ?');
        $chk->execute([$id]);
        if ($chk->fetchColumn()) {
            return rollPlanMarkCutDoneById($pdo, $table, $id);
        }
    }
    return ['success' => false, 'message' => 'Запись не найдена'];
}
