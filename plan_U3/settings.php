<?php
$application_name = 'Система планирования производства. Easy_Plan';
/** ---------------Параметры подключения к БД-------------------- */
$envPath = __DIR__ . '/../env.php';
if (file_exists($envPath)) {
    require $envPath;
}
// auth/includes/db.php ожидает DB_HOST, DB_USER, DB_PASS — их задаёт env.php
if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS')) {
    die('Создайте env.php в корне проекта и определите DB_HOST, DB_USER, DB_PASS');
}

$mysql_host = DB_HOST;
$mysql_database = 'plan_u3';
$mysql_user = DB_USER;
$mysql_user_pass = DB_PASS;
/** -----------------Настройки раскроя рулонов------------------- */
$width_of_main_roll = 1200; /** ширина бухты, идущей в порезку */
$main_roll_length = 1200; /** Длина раскраиваемой бухты */
$min_gap = 5; /** минимальный остаток в бухте */
$max_gap = 30; /** максимальный остаток  в бухте*/
$max_rolls_count = 10; /** максимальное количество полос в раскрое */
