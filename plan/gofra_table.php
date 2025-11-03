<?php
require_once('settings.php');

// Подключение к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    die("Ошибка подключения: " . $mysqli->connect_error);
}

// SQL-запрос с сортировкой и объединением примечаний
$sql = "
SELECT 
    pfs.filter AS 'Фильтр',
    ppp.p_p_amplifier AS 'Количество усилителей',
    ppp.p_p_height AS 'Высота гофропакета',
    ppp.p_p_width AS 'Ширина гофропакета',
    ppp.p_p_length AS 'Длина гофропакета',
    ppp.p_p_pleats_count AS 'Количество ребер гофропакета',
    ppp.p_p_remark AS 'Примечания гофропакета',
    pfs.glueing AS 'Проливка',
    pfs.form_factor_remark AS 'Примечание к форме',
    pfs.glueing_remark AS 'Примечание к проливке',
    pf.p_name AS 'Предфильтр',
    CONCAT_WS('  ', pfs.comment, ppp.p_p_remark, pf.p_remark) AS 'Примечания'
FROM panel_filter_structure pfs
LEFT JOIN paper_package_panel ppp ON pfs.paper_package = ppp.p_p_name
LEFT JOIN prefilter_panel pf ON pfs.prefilter = pf.p_name
ORDER BY pfs.filter ASC;
";

$result = $mysqli->query($sql);
if (!$result) {
    die("Ошибка запроса: " . $mysqli->error);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал для гофропакетчиков</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f7f7f7;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            background: #4a90e2;
            color: white;
        }
        tr:nth-child(even) {
            background: #f2f2f2;
        }
    </style>
</head>
<body>
<h2>Журнал фильтров</h2>
<table>
    <thead>
    <tr>
        <th>Фильтр</th>
        <th>Количество усилителей</th>
        <th>Высота гофропакета</th>
        <th>Ширина гофропакета</th>
        <th>Длина гофропакета</th>
        <th>Количество ребер гофропакета</th>
        <th>Проливка</th>
        <th>Предфильтр</th>
        <th>Примечания</th>
    </tr>
    </thead>
    <tbody>
    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($row['Фильтр']) ?></td>
            <td><?= htmlspecialchars($row['Количество усилителей']) ?></td>
            <td><?= htmlspecialchars($row['Высота гофропакета']) ?></td>
            <td><?= htmlspecialchars($row['Ширина гофропакета']) ?></td>
            <td><?= htmlspecialchars($row['Длина гофропакета']) ?></td>
            <td><?= htmlspecialchars($row['Количество ребер гофропакета']) ?></td>
            <td><?= htmlspecialchars($row['Проливка']) ?></td>
            <td><?= htmlspecialchars($row['Предфильтр']) ?></td>
            <td><?= htmlspecialchars($row['Примечания']) ?>
                <?= htmlspecialchars($row['Примечание к форме']) ?>
                <?= htmlspecialchars($row['Примечание к проливке']) ?>
                <?= htmlspecialchars($row['Примечания гофропакета']) ?>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</body>
</html>
<?php
$result->free();
$mysqli->close();
?>
