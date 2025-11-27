<?php
/**
 * Скрипт для добавления поля build_complexity в таблицу salary_tariffs
 * Запустите этот файл один раз для обновления структуры БД
 */

require_once('tools/tools.php');
require_once('settings.php');

global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error);
}

// Проверяем, существует ли поле build_complexity
$check_sql = "SELECT COUNT(*) as count 
              FROM INFORMATION_SCHEMA.COLUMNS 
              WHERE TABLE_SCHEMA = ? 
              AND TABLE_NAME = 'salary_tariffs' 
              AND COLUMN_NAME = 'build_complexity'";

$stmt = $mysqli->prepare($check_sql);
$stmt->bind_param('s', $mysql_database);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$exists = $row['count'] > 0;
$stmt->close();

if ($exists) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Поле уже существует</title></head><body>";
    echo "<div style='max-width:600px; margin:50px auto; padding:20px; background:#d1fae5; border:2px solid #10b981; border-radius:8px;'>";
    echo "<h2 style='color:#065f46; margin-top:0;'>✓ Поле build_complexity уже существует</h2>";
    echo "<p>Поле build_complexity уже добавлено в таблицу salary_tariffs. Никаких действий не требуется.</p>";
    echo "<p><a href='manage_tariffs.php' style='color:#059669; font-weight:600;'>← Вернуться к управлению тарифами</a></p>";
    echo "</div></body></html>";
} else {
    // Добавляем поле build_complexity
    $alter_sql = "ALTER TABLE salary_tariffs 
                  ADD COLUMN build_complexity DECIMAL(10,2) NULL DEFAULT NULL 
                  COMMENT 'Сложность сборки (шт/смену)'";
    
    if ($mysqli->query($alter_sql)) {
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Поле добавлено</title></head><body>";
        echo "<div style='max-width:600px; margin:50px auto; padding:20px; background:#d1fae5; border:2px solid #10b981; border-radius:8px;'>";
        echo "<h2 style='color:#065f46; margin-top:0;'>✓ Поле успешно добавлено</h2>";
        echo "<p>Поле <strong>build_complexity</strong> успешно добавлено в таблицу <strong>salary_tariffs</strong>.</p>";
        echo "<p>Теперь вы можете управлять сложностью сборки для каждого тарифа.</p>";
        echo "<p><a href='manage_tariffs.php' style='color:#059669; font-weight:600;'>← Вернуться к управлению тарифами</a></p>";
        echo "</div></body></html>";
    } else {
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Ошибка</title></head><body>";
        echo "<div style='max-width:600px; margin:50px auto; padding:20px; background:#fee2e2; border:2px solid #ef4444; border-radius:8px;'>";
        echo "<h2 style='color:#991b1b; margin-top:0;'>✗ Ошибка при добавлении поля</h2>";
        echo "<p style='color:#991b1b;'>Ошибка: " . htmlspecialchars($mysqli->error) . "</p>";
        echo "<p><a href='manage_tariffs.php' style='color:#dc2626; font-weight:600;'>← Вернуться к управлению тарифами</a></p>";
        echo "</div></body></html>";
    }
}

$mysqli->close();
?>











