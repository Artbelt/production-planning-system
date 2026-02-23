<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');

$sql = "SELECT p_p_name, p_p_height, p_p_pleats_count FROM paper_package_panel";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <?php if (count($rows) > 0): ?>
        <?php foreach ($rows as $row): ?>
            <?php
            $length = ($row['p_p_height'] * 2 + 1) * $row['p_p_pleats_count'] / 1000;
            $count_from = $length > 0 ? 1000 / $length : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($row['p_p_name']) ?></td>
                <td><?= htmlspecialchars($length) ?></td>
                <td><?= htmlspecialchars(round($count_from, 0)) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="3">Нет данных для отображения.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>