<?php
/**
 * Запрос позиций с шириной бумаги менее 102.5 мм
 * Отсортированные по популярности (по количеству использований и сумме количества)
 */
header('Content-Type: text/html; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

try {
    // Запрос позиций с шириной бумаги менее 102.5
    // Популярность определяется по сумме количества фильтров и количеству заявок
    $sql = "
        SELECT 
            o.filter,
            pps.p_p_width as paper_width,
            pps.p_p_height as paper_height,
            pps.p_p_material as material,
            pps.p_p_pleats_count as pleats_count,
            COUNT(DISTINCT o.order_number) as orders_count,
            SUM(o.count) as total_filters_count,
            GROUP_CONCAT(DISTINCT o.order_number ORDER BY o.order_number SEPARATOR ', ') as order_numbers
        FROM orders o
        JOIN salon_filter_structure sfs ON sfs.filter = o.filter
        JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE pps.p_p_width < 102.5
          AND (o.hide IS NULL OR o.hide = 0)
        GROUP BY o.filter, pps.p_p_width, pps.p_p_height, pps.p_p_material, pps.p_p_pleats_count
        ORDER BY total_filters_count DESC, orders_count DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Позиции с шириной бумаги менее 102.5 мм</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                background-color: #f5f5f5;
            }
            .container {
                max-width: 1400px;
                margin: 0 auto;
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                margin-bottom: 20px;
            }
            .info {
                background: #e3f2fd;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th {
                background-color: #2196F3;
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: bold;
            }
            td {
                padding: 10px;
                border-bottom: 1px solid #ddd;
            }
            tr:hover {
                background-color: #f5f5f5;
            }
            .width {
                font-weight: bold;
                color: #d32f2f;
            }
            .count {
                text-align: center;
                font-weight: bold;
            }
            .orders {
                font-size: 0.9em;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Позиции с шириной бумаги менее 102.5 мм</h1>
            
            <div class="info">
                <strong>Найдено позиций:</strong> <?php echo count($results); ?><br>
                <strong>Критерий:</strong> Ширина бумаги (p_p_width) < 102.5 мм<br>
                <strong>Сортировка:</strong> По популярности (общее количество фильтров, затем количество заявок)
            </div>
            
            <?php if (count($results) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Фильтр</th>
                        <th>Ширина бумаги (мм)</th>
                        <th>Высота бумаги (мм)</th>
                        <th>Материал</th>
                        <th>Количество складок</th>
                        <th>Кол-во заявок</th>
                        <th>Общее кол-во фильтров</th>
                        <th>Номера заявок</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $index = 1;
                    foreach ($results as $row): 
                    ?>
                    <tr>
                        <td><?php echo $index++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['filter']); ?></strong></td>
                        <td class="width"><?php echo number_format((float)$row['paper_width'], 1, '.', ''); ?></td>
                        <td><?php echo $row['paper_height'] ? number_format((float)$row['paper_height'], 1, '.', '') : '-'; ?></td>
                        <td><?php echo htmlspecialchars($row['material'] ?? '-'); ?></td>
                        <td><?php echo $row['pleats_count'] ?? '-'; ?></td>
                        <td class="count"><?php echo (int)$row['orders_count']; ?></td>
                        <td class="count"><?php echo (int)$row['total_filters_count']; ?></td>
                        <td class="orders"><?php echo htmlspecialchars($row['order_numbers']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="padding: 20px; text-align: center; color: #666;">
                Позиций с шириной бумаги менее 102.5 мм не найдено.
            </p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    echo "<h1>Ошибка</h1>";
    echo "<p style='color: red;'>Ошибка базы данных: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>




