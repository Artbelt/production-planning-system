<?php
/**
 * Скрипт для восстановления базы данных из файла резервной копии
 * ВНИМАНИЕ: Используйте с осторожностью! Это перезапишет текущие данные!
 */

header('Content-Type: text/html; charset=utf-8');

$backup_dir = 'C:/xampp/backups/plan_u5';
$db_name = 'plan_u5';
$db_user = 'root';
$db_pass = '';
$db_host = '127.0.0.1';

// Получаем список резервных копий
$backup_files = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '/plan_u5_*.{sql,sql.zip}', GLOB_BRACE);
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    foreach ($files as $file) {
        $backup_files[] = [
            'path' => $file,
            'name' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file)),
            'size_mb' => round(filesize($file) / 1024 / 1024, 2)
        ];
    }
}

// Если это POST запрос на восстановление
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    $backup_file = $_POST['backup_file'] ?? '';
    
    if (empty($backup_file) || !file_exists($backup_file)) {
        die("Ошибка: файл резервной копии не найден!");
    }
    
    // Проверяем, что файл находится в разрешенной директории
    $real_backup_dir = realpath($backup_dir);
    $real_file = realpath($backup_file);
    
    if (strpos($real_file, $real_backup_dir) !== 0) {
        die("Ошибка безопасности: недопустимый путь к файлу!");
    }
    
    // Если это ZIP файл, распаковываем
    $sql_file = $backup_file;
    if (pathinfo($backup_file, PATHINFO_EXTENSION) === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($backup_file) === TRUE) {
            $temp_dir = sys_get_temp_dir();
            $zip->extractTo($temp_dir);
            $sql_file = $temp_dir . '/' . $zip->getNameIndex(0);
            $zip->close();
        } else {
            die("Ошибка: не удалось распаковать ZIP файл!");
        }
    }
    
    // Путь к mysql.exe
    $mysql_path = 'C:/xampp/mysql/bin/mysql.exe';
    
    if (!file_exists($mysql_path)) {
        die("Ошибка: mysql.exe не найден по пути: $mysql_path");
    }
    
    // Формируем команду восстановления
    $command = sprintf(
        '"%s" -h%s -u%s %s %s < "%s"',
        $mysql_path,
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        $db_pass ? '-p' . escapeshellarg($db_pass) : '',
        escapeshellarg($db_name),
        escapeshellarg($sql_file)
    );
    
    // Выполняем команду через командную строку
    $output = [];
    $return_code = 0;
    exec($command . ' 2>&1', $output, $return_code);
    
    // Удаляем временный файл, если был распакован из ZIP
    if ($sql_file !== $backup_file && file_exists($sql_file)) {
        unlink($sql_file);
    }
    
    if ($return_code === 0) {
        echo "<!DOCTYPE html><html lang='ru'><head><meta charset='UTF-8'><title>Восстановление выполнено</title>";
        echo "<style>body{font-family:Arial,sans-serif;max-width:600px;margin:50px auto;padding:20px;background:#f5f5f5;}";
        echo ".success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:20px;border-radius:8px;text-align:center;}";
        echo ".btn{display:inline-block;padding:10px 20px;background:#2563eb;color:white;text-decoration:none;border-radius:8px;margin-top:20px;}</style></head><body>";
        echo "<div class='success'>";
        echo "<h2>✅ База данных успешно восстановлена!</h2>";
        echo "<p>Резервная копия <strong>" . htmlspecialchars(basename($backup_file)) . "</strong> была успешно восстановлена.</p>";
        echo "<a href='restore_from_file.php' class='btn'>Вернуться к списку резервных копий</a>";
        echo "</div></body></html>";
        exit;
    } else {
        $error = "Ошибка при восстановлении базы данных. Код возврата: $return_code";
        if (!empty($output)) {
            $error .= "<br><pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        }
        die($error);
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Восстановление базы данных из резервной копии</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #dc3545;
            padding-bottom: 10px;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th {
            background: #dc3545;
            color: white;
            padding: 10px;
            text-align: left;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #c82333;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ Восстановление базы данных из резервной копии</h1>
        
        <div class="warning">
            <strong>ВНИМАНИЕ!</strong> Восстановление базы данных перезапишет все текущие данные!
            Убедитесь, что у вас есть актуальная резервная копия перед восстановлением.
        </div>
        
        <?php if (empty($backup_files)): ?>
            <p style="color: #dc3545;">Резервные копии не найдены в директории: <?= htmlspecialchars($backup_dir) ?></p>
            <p>Убедитесь, что автоматическое резервное копирование настроено и работает.</p>
        <?php else: ?>
            <h2>Доступные резервные копии:</h2>
            
            <table>
                <tr>
                    <th>Имя файла</th>
                    <th>Размер</th>
                    <th>Дата создания</th>
                    <th>Действие</th>
                </tr>
                <?php foreach ($backup_files as $backup): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($backup['name']) ?></strong></td>
                    <td><?= htmlspecialchars($backup['size_mb']) ?> MB</td>
                    <td><?= htmlspecialchars($backup['date']) ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['path']) ?>">
                            <input type="hidden" name="confirm_restore" value="1">
                            <button type="submit" class="btn" 
                                    onclick="return confirm('ВНИМАНИЕ! Это действие перезапишет все текущие данные в базе данных plan_u5!\\n\\nВы уверены, что хотите восстановить базу данных из резервной копии?\\n\\nФайл: <?= htmlspecialchars($backup['name']) ?>')">
                                Восстановить
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <p style="margin-top: 30px;">
            <a href="check_salon_filter_data.php" class="btn btn-secondary">Вернуться к диагностике</a>
            <a href="main.php" class="btn btn-secondary">На главную</a>
        </p>
    </div>
</body>
</html>






