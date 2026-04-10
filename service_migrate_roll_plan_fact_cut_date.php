<?php
/**
 * Сервисная миграция:
 * 1) Нормализует таблицу раскроя к имени roll_plan (У2/У3/У4/У5),
 *    при необходимости создаёт совместимый VIEW roll_plans.
 * 2) Добавляет поле fact_cut_date (DATE NULL) — фактическая дата порезки бухты.
 * 3) Добавляет done, если его нет (для старых схем).
 *
 * Запуск:
 *   /service_migrate_roll_plan_fact_cut_date.php
 */

header('Content-Type: text/html; charset=utf-8');

$envPath = __DIR__ . '/env.php';
if (file_exists($envPath)) {
    require_once $envPath;
}

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

$targets = [
    ['code' => 'U2', 'db' => 'plan'],
    ['code' => 'U3', 'db' => 'plan_u3'],
    ['code' => 'U4', 'db' => 'plan_u4'],
    ['code' => 'U5', 'db' => 'plan_u5'],
];

function hasTable(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$name]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

echo '<h2>Миграция roll_plan: fact_cut_date + нормализация таблиц</h2>';
echo '<p>Дата запуска: ' . date('d.m.Y H:i:s') . '</p>';
echo '<hr>';

foreach ($targets as $target) {
    $code = $target['code'];
    $dbName = $target['db'];

    echo '<h3>' . htmlspecialchars($code) . ' (' . htmlspecialchars($dbName) . ')</h3>';

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $hasRollPlan = hasTable($pdo, 'roll_plan');
        $hasRollPlans = hasTable($pdo, 'roll_plans');

        if (!$hasRollPlan && !$hasRollPlans) {
            echo '<p style="color:#b45309;">Пропуск: нет таблиц roll_plan/roll_plans.</p>';
            continue;
        }

        // Нормализация имени физической таблицы: roll_plan
        if (!$hasRollPlan && $hasRollPlans) {
            $pdo->exec("RENAME TABLE roll_plans TO roll_plan");
            echo '<p>Таблица <code>roll_plans</code> переименована в <code>roll_plan</code>.</p>';
            $hasRollPlan = true;
            $hasRollPlans = false;
        }

        if ($hasRollPlan && !$hasRollPlans) {
            // Совместимость со старым кодом (который может читать/писать roll_plans)
            $pdo->exec("CREATE OR REPLACE VIEW roll_plans AS SELECT * FROM roll_plan");
            echo '<p>Создан/обновлён VIEW <code>roll_plans</code> → <code>roll_plan</code>.</p>';
        }

        if ($hasRollPlan && $hasRollPlans) {
            echo '<p style="color:#b45309;">Обе сущности roll_plan и roll_plans уже есть. Нормализация имени пропущена.</p>';
        }

        $table = 'roll_plan';

        // Нормализация даты плана: для legacy-схемы с work_date
        $hasPlanDate = hasColumn($pdo, $table, 'plan_date');
        $hasWorkDate = hasColumn($pdo, $table, 'work_date');
        if (!$hasPlanDate && $hasWorkDate) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN plan_date DATE NULL AFTER work_date");
            $pdo->exec("UPDATE {$table} SET plan_date = work_date WHERE plan_date IS NULL AND work_date IS NOT NULL");
            echo '<p>Добавлена и заполнена колонка <code>plan_date</code> из <code>work_date</code>.</p>';
        }

        if (!hasColumn($pdo, $table, 'done')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
            echo '<p>Добавлена колонка <code>done</code>.</p>';
        }

        if (!hasColumn($pdo, $table, 'fact_cut_date')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN fact_cut_date DATE NULL AFTER done");
            echo '<p>Добавлена колонка <code>fact_cut_date</code>.</p>';
        } else {
            echo '<p>Колонка <code>fact_cut_date</code> уже существует.</p>';
        }

        $idxStmt = $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_fact_cut_date'");
        if (!$idxStmt || $idxStmt->rowCount() === 0) {
            $pdo->exec("CREATE INDEX idx_fact_cut_date ON {$table}(fact_cut_date)");
            echo '<p>Добавлен индекс <code>idx_fact_cut_date</code>.</p>';
        } else {
            echo '<p>Индекс <code>idx_fact_cut_date</code> уже существует.</p>';
        }

        echo '<p style="color:#166534;"><strong>OK</strong></p>';
    } catch (Throwable $e) {
        echo '<p style="color:#b91c1c;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

    echo '<hr>';
}

echo '<p>Готово.</p>';
