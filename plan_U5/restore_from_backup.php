<?php
/**
 * Скрипт для восстановления данных фильтра из резервной копии
 */

require_once('tools/tools.php');
require_once('tools/backup_before_update.php');

header('Content-Type: text/html; charset=utf-8');

$filter_name = $_GET['filter'] ?? $_POST['filter'] ?? '';
$backup_id = $_GET['backup_id'] ?? $_POST['backup_id'] ?? null;

if (empty($filter_name)) {
    die("Ошибка: не указано имя фильтра");
}

global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error);
}

// Если это POST запрос на восстановление
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    if (restore_filter_from_backup($mysqli, $filter_name, $backup_id)) {
        echo "<!DOCTYPE html><html lang='ru'><head><meta charset='UTF-8'><title>Восстановление выполнено</title>";
        echo "<style>body{font-family:Arial,sans-serif;max-width:600px;margin:50px auto;padding:20px;background:#f5f5f5;}";
        echo ".success{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:20px;border-radius:8px;text-align:center;}";
        echo ".btn{display:inline-block;padding:10px 20px;background:#2563eb;color:white;text-decoration:none;border-radius:8px;margin-top:20px;}</style></head><body>";
        echo "<div class='success'>";
        echo "<h2>✅ Данные успешно восстановлены!</h2>";
        echo "<p>Фильтр <strong>" . htmlspecialchars($filter_name) . "</strong> был восстановлен из резервной копии.</p>";
        echo "<a href='restore_from_backup.php?filter=" . urlencode($filter_name) . "' class='btn'>Вернуться к списку резервных копий</a>";
        echo "</div></body></html>";
        exit;
    } else {
        die("Ошибка: не удалось восстановить данные. Резервная копия не найдена.");
    }
}

// Получаем список резервных копий
$backups = [];
$stmt = $mysqli->prepare("SELECT * FROM salon_filter_structure_backup WHERE filter = ? ORDER BY backup_time DESC LIMIT 20");
$stmt->bind_param('s', $filter_name);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $backups[] = $row;
}
$stmt->close();

// Получаем текущие данные фильтра
$current_data = null;
$stmt = $mysqli->prepare("SELECT * FROM salon_filter_structure WHERE filter = ?");
$stmt->bind_param('s', $filter_name);
$stmt->execute();
$current_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Восстановление из резервной копии</title>
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
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .current-data {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th {
            background: #4CAF50;
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
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #1e4ed8;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .empty {
            color: #dc3545;
            font-weight: bold;
        }
        .has-data {
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Восстановление данных фильтра: <?= htmlspecialchars($filter_name) ?></h1>
        
        <?php if ($current_data): ?>
        <div class="current-data">
            <h3>Текущие данные:</h3>
            <table>
                <tr>
                    <th>Поле</th>
                    <th>Значение</th>
                </tr>
                <tr>
                    <td><strong>box</strong></td>
                    <td class="<?= empty($current_data['box']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($current_data['box'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>insertion_count</strong></td>
                    <td class="<?= empty($current_data['insertion_count']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($current_data['insertion_count'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>g_box</strong></td>
                    <td class="<?= empty($current_data['g_box']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($current_data['g_box'] ?: '—') ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>side_type</strong></td>
                    <td class="<?= empty($current_data['side_type']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($current_data['side_type'] ?: '—') ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <h2>Доступные резервные копии:</h2>
        
        <?php if (empty($backups)): ?>
            <p style="color: #dc3545;">Резервные копии не найдены для этого фильтра.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Дата создания</th>
                    <th>box</th>
                    <th>insertion_count</th>
                    <th>g_box</th>
                    <th>side_type</th>
                    <th>Действие</th>
                </tr>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= htmlspecialchars($backup['id']) ?></td>
                    <td><?= htmlspecialchars($backup['backup_time']) ?></td>
                    <td class="<?= empty($backup['box']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($backup['box'] ?: '—') ?>
                    </td>
                    <td class="<?= empty($backup['insertion_count']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($backup['insertion_count'] ?: '—') ?>
                    </td>
                    <td class="<?= empty($backup['g_box']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($backup['g_box'] ?: '—') ?>
                    </td>
                    <td class="<?= empty($backup['side_type']) ? 'empty' : 'has-data' ?>">
                        <?= htmlspecialchars($backup['side_type'] ?: '—') ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_name) ?>">
                            <input type="hidden" name="backup_id" value="<?= htmlspecialchars($backup['id']) ?>">
                            <input type="hidden" name="confirm_restore" value="1">
                            <button type="submit" class="btn btn-danger" 
                                    onclick="return confirm('Вы уверены, что хотите восстановить данные из этой резервной копии? Текущие данные будут перезаписаны!')">
                                Восстановить
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <p style="margin-top: 30px;">
            <a href="check_salon_filter_data.php" class="btn">Вернуться к диагностике</a>
        </p>
    </div>
</body>
</html>



