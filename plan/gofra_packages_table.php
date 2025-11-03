<?php
// Подключение к базе данных
$mysql_host = '127.0.0.1';
$mysql_user = 'root';
$mysql_user_pass = '';
$mysql_database = 'plan';

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

// Проверка подключения
if ($mysqli->connect_error) {
    die('Ошибка подключения (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

// SQL-запрос для получения данных
$sql = "SELECT p_p_name, p_p_height, p_p_pleats_count FROM paper_package_panel";
$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Фильтр таблицы</title>
    <style>
        input[type="text"] {
            margin-bottom: 10px;
            padding: 5px;
            width: 300px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background-color: #f3f4f6;
        }
    </style>
    <script>
        function filterTable() {
            const filter = document.getElementById('filterInput').value.toLowerCase();
            const rows = document.querySelectorAll('table tbody tr');

            rows.forEach(row => {
                const cell = row.querySelector('td:first-child');
                if (cell) {
                    const text = cell.textContent.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                }
            });
        }
    </script>
</head>
<body>
<h2>Фильтр таблицы</h2>
<input type="text" id="filterInput" onkeyup="filterTable()" placeholder="Введите название для фильтрации...">

<table>
    <thead>
    <tr>
        <th>Наименование</th>
        <th>Длина, м</th>
        <th>Количество из 1000 м</th>
    </tr>
    </thead>
    <tbody>
    <?php if ($result->num_rows > 0): ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <?php
            $length = ($row['p_p_height'] * 2 + 1) * $row['p_p_pleats_count'] / 1000;
            $count_from = $length > 0 ? 1000 / $length : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($row['p_p_name']) ?></td>
                <td><?= htmlspecialchars($length) ?></td>
                <td><?= htmlspecialchars(round($count_from, 0)) ?></td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="3">Нет данных для отображения.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>