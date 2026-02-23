<?php
$application_name = 'Система планирования производства. Easy_Plan';
/** ---------------Параметры подключения к БД-------------------- */
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$mysql_host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$mysql_database = 'plan_u5';
$mysql_user = defined('DB_USER') ? DB_USER : 'root';
$mysql_user_pass = defined('DB_PASS') ? DB_PASS : '';
/** -----------------Настройки раскроя рулонов------------------- */
$width_of_main_roll = 1000; /** ширина бухты, идущей в порезку */
$main_roll_length = 0; /** Длина раскраиваемой бухты */
$min_gap = 20; /** минимальный остаток в бухте */
$max_gap = 55; /** максимальный остаток  в бухте*/
$max_rolls_count = 10; /** максимальное количество полос в раскрое */
$dsn = 'mysql:host=' . $mysql_host . ';dbname=' . $mysql_database . ';charset=utf8mb4';
$user = $mysql_user;
$pass = $mysql_user_pass;
