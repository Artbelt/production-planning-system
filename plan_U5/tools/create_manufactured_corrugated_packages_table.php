<?php
/**
 * Создание таблицы manufactured_corrugated_packages для факта производства гофропакетов
 * Аналогично таблице manufactured_production, но для гофропакетов
 */

$server_name = '127.0.0.1';
$user_name = 'root';
$password = '';
$database_name = 'plan_U5';

// Подключение к MySQL
$connection = new mysqli($server_name, $user_name, $password);

if ($connection->error) {
    die('Ошибка подключения: ' . $connection->error . ' error_code= ' . $connection->errno);
}
echo 'Подключение к MySql успешно.<br>';

// Подключение к БД
$sql = "USE $database_name";
if ($connection->query($sql) === TRUE) {
    echo 'Подключение к БД "' . $database_name . '" успешно <br>';
} else {
    die('Ошибка подключения к БД: ' . $connection->error . ' error_code= ' . $connection->errno);
}

// Создаем таблицу manufactured_corrugated_packages
$table_name = 'manufactured_corrugated_packages';
$sql = "CREATE TABLE IF NOT EXISTS $table_name (
    id INT(11) NOT NULL AUTO_INCREMENT,
    date_of_production DATE NOT NULL,
    order_number VARCHAR(50) NOT NULL,
    filter_label TEXT NOT NULL,
    count INT(11) NOT NULL DEFAULT 0,
    bale_id INT(11) DEFAULT NULL,
    strip_no INT(11) DEFAULT NULL,
    team VARCHAR(50) DEFAULT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_date (date_of_production),
    INDEX idx_order (order_number),
    INDEX idx_date_order (date_of_production, order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($connection->query($sql) === TRUE) {
    echo 'Таблица "' . $table_name . '" создана успешно <br>';
} else {
    die('Ошибка создания таблицы: ' . $connection->error . ' error_code= ' . $connection->errno);
}

// Закрываем подключение
$connection->close();
echo "Подключение закрыто.";

