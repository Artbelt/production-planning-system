<?php
/**
 * Инициализация таблиц для системы управления ножами
 * Создает таблицы если их нет
 * Если соединение передано как параметр, использует его, иначе создает новое
 */

require_once('settings.php');

// Если соединение уже передано, используем его
if (!isset($mysqli) || !$mysqli instanceof mysqli) {
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        die('Ошибка подключения к БД: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
    $close_connection = true; // Помечаем, что нужно закрыть соединение
} else {
    $close_connection = false; // Не закрываем, т.к. соединение используется дальше
}

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

if (!$mysqli->query($sql)) {
    die('Ошибка создания таблицы knives: ' . $mysqli->error);
}

// Создаем таблицу knives_calendar (календарная таблица состояний)
$sql = "CREATE TABLE IF NOT EXISTS knives_calendar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    knife_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('in_stock', 'in_sharpening', 'in_work') NOT NULL,
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

if (!$mysqli->query($sql)) {
    die('Ошибка создания таблицы knives_calendar: ' . $mysqli->error);
}

// Закрываем соединение только если мы его создали
if (isset($close_connection) && $close_connection) {
    $mysqli->close();
}
