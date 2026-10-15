<?php
/**
 * Создание таблиц и демо-дерева в БД diagnostics.
 * Саму БД и GRANT создаёт админ (plan_user не имеет CREATE DATABASE).
 */

require_once __DIR__ . '/db.php';

function diagnostics_ensure_schema(?PDO $pdo = null): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!$pdo instanceof PDO) {
        $cfg = diagnostics_db_config();
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'],
            $cfg['name']
        );
        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Unknown database') || str_contains($msg, '1049')) {
                throw new RuntimeException(
                    'База diagnostics не создана. Выполните от root: '
                    . 'CREATE DATABASE diagnostics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; '
                    . "GRANT ALL PRIVILEGES ON diagnostics.* TO 'plan_user'@'localhost'; FLUSH PRIVILEGES;",
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pu_diag_trees (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pu_diag_nodes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tree_id INT UNSIGNED NOT NULL,
        node_type ENUM('question','cause') NOT NULL DEFAULT 'question',
        body TEXT NOT NULL,
        action_text TEXT NULL,
        yes_node_id INT UNSIGNED NULL,
        no_node_id INT UNSIGNED NULL,
        is_root TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        INDEX idx_tree (tree_id),
        INDEX idx_tree_root (tree_id, is_root),
        CONSTRAINT fk_pu_diag_nodes_tree
            FOREIGN KEY (tree_id) REFERENCES pu_diag_trees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pu_diag_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tree_id INT UNSIGNED NULL,
        tree_title VARCHAR(255) NOT NULL DEFAULT '',
        user_id INT NULL,
        user_name VARCHAR(255) NOT NULL DEFAULT '',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        status ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
        result_node_id INT UNSIGNED NULL,
        result_body TEXT NULL,
        result_action_text TEXT NULL,
        PRIMARY KEY (id),
        INDEX idx_started (started_at),
        INDEX idx_status (status),
        INDEX idx_tree (tree_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pu_diag_session_steps (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id INT UNSIGNED NOT NULL,
        step_no INT UNSIGNED NOT NULL,
        node_id INT UNSIGNED NULL,
        node_body TEXT NOT NULL,
        node_type ENUM('question','cause') NOT NULL DEFAULT 'question',
        answer ENUM('yes','no') NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_session (session_id),
        CONSTRAINT fk_pu_diag_steps_session
            FOREIGN KEY (session_id) REFERENCES pu_diag_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    diagnostics_seed_demo($pdo);
    $done = true;
}

function diagnostics_seed_demo(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM pu_diag_trees')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec(
            "INSERT INTO pu_diag_trees (title, is_active, sort_order)
             VALUES ('Не греет стол', 1, 10)"
        );
        $treeId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO pu_diag_nodes (tree_id, node_type, body, action_text, is_root)
             VALUES (?, ?, ?, ?, ?)"
        );

        $ins->execute([
            $treeId,
            'question',
            'Горит ли индикатор нагрева на панели?',
            null,
            1,
        ]);
        $rootId = (int)$pdo->lastInsertId();

        $ins->execute([
            $treeId,
            'question',
            'Температура на контроллере растёт при включении нагрева?',
            null,
            0,
        ]);
        $q2Id = (int)$pdo->lastInsertId();

        $ins->execute([
            $treeId,
            'cause',
            'Нет питания нагревателя или сработал автомат',
            'Проверьте автомат нагрева в шкафу управления. Если выбит — выясните причину и включите. Если автомат включён, но индикатор не горит — вызовите электрика.',
            0,
        ]);
        $causeNoId = (int)$pdo->lastInsertId();

        $ins->execute([
            $treeId,
            'cause',
            'Неисправен датчик температуры или его кабель',
            'Проверьте разъём датчика на контроллере и целостность кабеля. При необходимости замените датчик.',
            0,
        ]);
        $causeYesSensorId = (int)$pdo->lastInsertId();

        $ins->execute([
            $treeId,
            'cause',
            'Неисправен ТЭН / нагревательный элемент стола',
            'Отключите питание. Проверьте сопротивление ТЭНа. При обрыве — замена нагревателя. Работы выполняет слесарь/электрик.',
            0,
        ]);
        $causeTenId = (int)$pdo->lastInsertId();

        $upd = $pdo->prepare(
            'UPDATE pu_diag_nodes SET yes_node_id = ?, no_node_id = ? WHERE id = ?'
        );
        $upd->execute([$q2Id, $causeNoId, $rootId]);
        $upd->execute([$causeYesSensorId, $causeTenId, $q2Id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
