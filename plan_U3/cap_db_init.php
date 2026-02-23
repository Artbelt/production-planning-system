<?php
/**
 * Инициализация таблиц для системы управления крышками
 * Создает таблицы если их нет
 */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : getPdo('plan_u3');

// Создаем таблицу cap_movements (движение крышек)
$sql = "CREATE TABLE IF NOT EXISTS cap_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    cap_name VARCHAR(255) NOT NULL,
    operation_type ENUM('INCOME', 'PRODUCTION_OUT', 'ADJUSTMENT') NOT NULL,
    quantity INT NOT NULL,
    order_number VARCHAR(255) NULL,
    filter_name VARCHAR(255) NULL,
    production_date DATE NULL,
    user_id INT NULL,
    user_name VARCHAR(255) NULL,
    comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (date),
    INDEX idx_cap_name (cap_name),
    INDEX idx_order_number (order_number),
    INDEX idx_operation_type (operation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$pdo->exec($sql);

// Создаем таблицу cap_stock (текущие остатки)
$sql = "CREATE TABLE IF NOT EXISTS cap_stock (
    cap_name VARCHAR(255) PRIMARY KEY,
    current_quantity INT NOT NULL DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_current_quantity (current_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$pdo->exec($sql);

// Миграция данных из старой таблицы list_of_caps в новую систему (если таблица существует)
$row = $pdo->query("SELECT COUNT(*) as cnt FROM cap_stock")->fetch(PDO::FETCH_ASSOC);
if ($row['cnt'] == 0) {
    $table_check = $pdo->query("SHOW TABLES LIKE 'list_of_caps'");
    if ($table_check && $table_check->rowCount() > 0) {
        $pdo->exec("INSERT INTO cap_stock (cap_name, current_quantity)
                         SELECT name_of_cap, cap_count 
                         FROM list_of_caps 
                         WHERE name_of_cap NOT IN (SELECT cap_name FROM cap_stock)
                         ON DUPLICATE KEY UPDATE current_quantity = VALUES(current_quantity)");
    }
    $pdo->exec("INSERT INTO cap_movements (date, cap_name, operation_type, quantity, user_name, comment)
                    SELECT date_of_operation, name_of_cap_field, 
                           CASE cap_action 
                               WHEN 'IN' THEN 'INCOME'
                               WHEN 'OUT' THEN 'PRODUCTION_OUT'
                               ELSE 'INCOME'
                           END,
                           count_of_caps, NULL, NULL
                    FROM cap_log
                    WHERE cap_action IN ('IN', 'OUT')
                    AND NOT EXISTS (
                        SELECT 1 FROM cap_movements cm 
                        WHERE cm.date = cap_log.date_of_operation 
                        AND cm.cap_name = cap_log.name_of_cap_field
                        AND cm.quantity = cap_log.count_of_caps
                    )");
}

