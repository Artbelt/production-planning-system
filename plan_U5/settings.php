<?php
$application_name = 'Система планирования производства. Easy_Plan';
/** ---------------Параметры подключения к БД-------------------- */
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
// auth/includes/db.php использует DB_HOST, DB_USER, DB_PASS — без env.php подключаться как root нельзя
if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS')) {
    die('Создайте env.php в корне проекта (рядом с папкой plan_U5) и определите DB_HOST, DB_USER, DB_PASS');
}
$mysql_host = DB_HOST;
$mysql_database = 'plan_u5';
$mysql_user = DB_USER;
$mysql_user_pass = DB_PASS;
/** -----------------Настройки раскроя рулонов------------------- */
$width_of_main_roll = 1000; /** ширина бухты, идущей в порезку */
$main_roll_length = 0; /** Длина раскраиваемой бухты */
$min_gap = 20; /** минимальный остаток в бухте */
$max_gap = 55; /** максимальный остаток  в бухте*/
$max_rolls_count = 10; /** максимальное количество полос в раскрое */
$dsn = 'mysql:host=' . $mysql_host . ';dbname=' . $mysql_database . ';charset=utf8mb4';
$user = $mysql_user;
$pass = $mysql_user_pass;
