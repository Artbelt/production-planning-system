<?php
/**
 * Инициализация таблиц для системы управления ножами
 */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : getPdo('plan_u3');

// Создаем таблицу knives (справочник комплектов ножей)
$sql = "CREATE TABLE IF NOT EXISTS knives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    knife_name VARCHAR(255) NOT NULL,
    knife_type ENUM('bobinorezka', 'prosechnik') NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_knife_name (knife_name),
    INDEX idx_knife_type (knife_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
$pdo->exec($sql);

// Создаем таблицу knives_calendar (календарная таблица состояний)
$sql = "CREATE TABLE IF NOT EXISTS knives_calendar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    knife_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('in_stock', 'in_sharpening', 'out_to_sharpening', 'in_work') NOT NULL,
    user_id INT NULL,
    user_name VARCHAR(255) NULL,
    comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_knife_date (knife_id, date),
    INDEX idx_knife_id (knife_id),
    INDEX idx_date (date),
    INDEX idx_status (status),
    FOREIGN KEY (knife_id) REFERENCES knives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

$pdo->exec($sql);
$pdo->exec("ALTER TABLE knives_calendar MODIFY status ENUM('in_stock', 'in_sharpening', 'out_to_sharpening', 'in_work') NOT NULL");
