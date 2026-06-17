<?php
/**
 * Отметка бухты как порезанной: done, fact_cut_date, fact_cut_at (время отметки).
 */

function rollPlanTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function rollPlanEnsureFactCutAt(PDO $pdo, string $table): bool
{
    if (!rollPlanTableHasColumn($pdo, $table, 'fact_cut_at')) {
        if (!rollPlanTableHasColumn($pdo, $table, 'fact_cut_date')) {
            return false;
        }
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN fact_cut_at DATETIME NULL AFTER fact_cut_date");
        } catch (Throwable $e) {
            error_log('rollPlanEnsureFactCutAt: ' . $e->getMessage());
            return false;
        }
    }
    return rollPlanTableHasColumn($pdo, $table, 'fact_cut_at');
}

/**
 * @return array{success: bool, message?: string}
 */
function rollPlanMarkCutDoneById(PDO $pdo, string $table, int $id): array
{
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Нет ID'];
    }
    rollPlanEnsureFactCutAt($pdo, $table);
    $sets = ['done = 1', 'fact_cut_date = CURDATE()'];
    if (rollPlanTableHasColumn($pdo, $table, 'fact_cut_at')) {
        $sets[] = 'fact_cut_at = NOW()';
    }
    $sql = 'UPDATE `' . str_replace('`', '', $table) . '` SET ' . implode(', ', $sets) . ' WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([$id]);
        return ['success' => (bool) $ok];
    } catch (Throwable $e) {
        error_log('rollPlanMarkCutDoneById: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
