<?php
/**
 * PHP скрипт для автоматического резервного копирования базы данных plan_u5
 * Можно запускать через планировщик задач Windows или cron
 */

// Настройки
$backup_dir = 'C:/xampp/backups/plan_u5';
$db_name = 'plan_u5';
$db_user = 'root';
$db_pass = '';
$db_host = '127.0.0.1';
$keep_days = 30; // Хранить резервные копии 30 дней

// Создаем директорию для резервных копий
if (!is_dir($backup_dir)) {
    if (!mkdir($backup_dir, 0755, true)) {
        die("Ошибка: не удалось создать директорию для резервных копий: $backup_dir\n");
    }
}

// Формируем имя файла резервной копии
$date = date('Ymd_His');
$backup_file = $backup_dir . '/plan_u5_' . $date . '.sql';
$log_file = $backup_dir . '/backup_log.txt';

// Функция для записи в лог
function writeLog($message, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
    echo $log_message;
}

writeLog("Начало создания резервной копии базы данных $db_name", $log_file);

// Путь к mysqldump
$mysql_path = 'C:/xampp/mysql/bin/mysqldump.exe';

if (!file_exists($mysql_path)) {
    $error = "Ошибка: mysqldump.exe не найден по пути: $mysql_path";
    writeLog($error, $log_file);
    die($error . "\n");
}

// Формируем команду mysqldump
$command = sprintf(
    '"%s" -h%s -u%s %s --single-transaction --routines --triggers --events %s > "%s" 2>&1',
    $mysql_path,
    escapeshellarg($db_host),
    escapeshellarg($db_user),
    $db_pass ? '-p' . escapeshellarg($db_pass) : '',
    escapeshellarg($db_name),
    escapeshellarg($backup_file)
);

// Выполняем команду
exec($command, $output, $return_code);

if ($return_code === 0 && file_exists($backup_file) && filesize($backup_file) > 0) {
    $size = filesize($backup_file);
    $size_mb = round($size / 1024 / 1024, 2);
    
    writeLog("Резервная копия успешно создана: $backup_file ($size_mb MB)", $log_file);
    
    // Пытаемся сжать резервную копию (если установлен 7-Zip)
    $zip_path = 'C:/Program Files/7-Zip/7z.exe';
    if (file_exists($zip_path)) {
        $zip_file = $backup_file . '.zip';
        $zip_command = sprintf(
            '"%s" a -tzip "%s" "%s"',
            $zip_path,
            escapeshellarg($zip_file),
            escapeshellarg($backup_file)
        );
        
        exec($zip_command, $zip_output, $zip_return_code);
        
        if ($zip_return_code === 0 && file_exists($zip_file)) {
            $zip_size = filesize($zip_file);
            $zip_size_mb = round($zip_size / 1024 / 1024, 2);
            unlink($backup_file); // Удаляем несжатую копию
            writeLog("Резервная копия сжата: $zip_file ($zip_size_mb MB)", $log_file);
        }
    }
    
    // Очистка старых резервных копий
    $files = glob($backup_dir . '/plan_u5_*.{sql,sql.zip}', GLOB_BRACE);
    $deleted_count = 0;
    $cutoff_time = time() - ($keep_days * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff_time) {
            if (unlink($file)) {
                $deleted_count++;
            }
        }
    }
    
    if ($deleted_count > 0) {
        writeLog("Удалено старых резервных копий: $deleted_count", $log_file);
    }
    
    // Показываем список последних резервных копий
    $recent_files = glob($backup_dir . '/plan_u5_*.{sql,sql.zip}', GLOB_BRACE);
    usort($recent_files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    writeLog("Последние резервные копии:", $log_file);
    foreach (array_slice($recent_files, 0, 5) as $file) {
        $size = filesize($file);
        $size_mb = round($size / 1024 / 1024, 2);
        $date = date('Y-m-d H:i:s', filemtime($file));
        writeLog("  - " . basename($file) . " ($size_mb MB, $date)", $log_file);
    }
    
    writeLog("Резервное копирование завершено успешно!", $log_file);
    exit(0);
} else {
    $error = "Ошибка при создании резервной копии. Код возврата: $return_code";
    if (!empty($output)) {
        $error .= "\nВывод: " . implode("\n", $output);
    }
    writeLog($error, $log_file);
    exit(1);
}








