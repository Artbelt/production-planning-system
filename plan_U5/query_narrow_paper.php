<?php
/**
 * Запрос позиций с шириной бумаги менее 102.5 мм
 * Отсортированные по популярности (по количеству использований и сумме количества)
 * С возможностью фильтрации по периоду: все время или последний год
 */
header('Content-Type: text/html; charset=utf-8');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Получаем параметры фильтрации
$period = $_GET['period'] ?? 'all_time';
$filterByWidth = isset($_GET['filter_width']) && $_GET['filter_width'] === '1';
$widthValue = isset($_GET['width_value']) && $_GET['width_value'] !== '' ? (float)$_GET['width_value'] : 102.5;
// Валидация: ширина должна быть положительным числом
if ($widthValue <= 0) {
    $widthValue = 102.5;
}

try {
    // Определяем дату начала фильтрации
    $dateFilter = '';
    $dateCondition = '';
    if ($period === 'last_year') {
        // Фильтр за последний год (365 дней назад от сегодня)
        $dateFilter = date('Y-m-d', strtotime('-1 year'));
        // Используем дату из roll_plans или build_plan для фильтрации по заявкам
        // Если заявка имеет план порезки или сборки за последний год, она учитывается
        $dateCondition = " AND (
            EXISTS (
                SELECT 1 FROM roll_plans rp 
                WHERE rp.order_number = o.order_number
                AND rp.created_at >= '$dateFilter'
            )
            OR EXISTS (
                SELECT 1 FROM build_plan bp 
                WHERE bp.order_number = o.order_number
                AND bp.created_at >= '$dateFilter'
            )
            OR EXISTS (
                SELECT 1 FROM manufactured_production mp 
                WHERE mp.name_of_filter = o.filter 
                AND mp.name_of_order = o.order_number
                AND mp.date_of_production >= '$dateFilter'
            )
        )";
    }
    
    // Условие фильтрации по ширине - нужно будет добавить через подзапрос
    $widthFilter = '';
    if ($filterByWidth) {
        $widthValueEscaped = (float)$widthValue;
        // Фильтруем фильтры по ширине через подзапрос
        $widthFilter = " AND filter IN (
            SELECT DISTINCT sfs.filter 
            FROM salon_filter_structure sfs
            JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
            WHERE pps.p_p_width < $widthValueEscaped
        )";
    }
    
    // Увеличиваем лимит GROUP_CONCAT для отображения всех заявок
    $pdo->exec("SET SESSION group_concat_max_len = 1000000");
    
    // Простой запрос: достаем все заявки, в которых есть фильтр
    // Базовый запрос без фильтров - показываем все заявки, включая скрытые
    $baseWhere = "WHERE 1=1";
    
    // Добавляем фильтр по дате, если выбран период "за последний год"
    if ($period === 'last_year') {
        $dateFilter = date('Y-m-d', strtotime('-1 year'));
        $baseWhere .= " AND o.order_number IN (
            SELECT DISTINCT order_number FROM roll_plans WHERE created_at >= '$dateFilter'
            UNION
            SELECT DISTINCT order_number FROM build_plan WHERE created_at >= '$dateFilter'
            UNION
            SELECT DISTINCT name_of_order FROM manufactured_production WHERE date_of_production >= '$dateFilter'
            UNION
            SELECT DISTINCT order_number FROM corrugation_plan WHERE plan_date >= '$dateFilter' OR created_at >= '$dateFilter'
        )";
    }
    
    // Добавляем фильтр по ширине, если включен
    if ($filterByWidth) {
        $widthValueEscaped = (float)$widthValue;
        $baseWhere .= " AND o.filter IN (
            SELECT DISTINCT sfs.filter 
            FROM salon_filter_structure sfs
            JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
            WHERE pps.p_p_width < $widthValueEscaped
        )";
    }
    
    $sql = "
        SELECT 
            o.filter,
            (SELECT MAX(pps.p_p_width) FROM salon_filter_structure sfs 
             JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package 
             WHERE sfs.filter = o.filter) as paper_width,
            (SELECT MAX(pps.p_p_height) FROM salon_filter_structure sfs 
             JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package 
             WHERE sfs.filter = o.filter) as paper_height,
            (SELECT MAX(pps.p_p_material) FROM salon_filter_structure sfs 
             JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package 
             WHERE sfs.filter = o.filter) as material,
            (SELECT MAX(pps.p_p_pleats_count) FROM salon_filter_structure sfs 
             JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package 
             WHERE sfs.filter = o.filter) as pleats_count,
            COUNT(DISTINCT o.order_number) as orders_count,
            SUM(o.count) as total_filters_count,
            GROUP_CONCAT(DISTINCT o.order_number ORDER BY o.order_number SEPARATOR ', ') as order_numbers
        FROM orders o
        $baseWhere
        GROUP BY o.filter
        ORDER BY total_filters_count DESC, orders_count DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    // Отладка: проверяем количество заявок для AF5105 (показываем на странице)
    $debugInfo = '';
    if (isset($_GET['debug'])) {
        $pdo->exec("SET SESSION group_concat_max_len = 1000000");
        
        // Проверяем все строки (не только уникальные заявки)
        $debugSqlAll = "SELECT COUNT(*) as total_rows, COUNT(DISTINCT order_number) as unique_orders, GROUP_CONCAT(DISTINCT order_number ORDER BY order_number SEPARATOR ', ') as orders 
                        FROM orders 
                        WHERE filter = 'AF5105'";
        $debugStmtAll = $pdo->query($debugSqlAll);
        $debugResultAll = $debugStmtAll->fetch(PDO::FETCH_ASSOC);
        
        // Проверяем только не скрытые
        $debugSql = "SELECT COUNT(*) as total_rows, COUNT(DISTINCT order_number) as unique_orders, GROUP_CONCAT(DISTINCT order_number ORDER BY order_number SEPARATOR ', ') as orders 
                     FROM orders 
                     WHERE filter = 'AF5105' AND (hide IS NULL OR hide = 0)";
        $debugStmt = $pdo->query($debugSql);
        $debugResult = $debugStmt->fetch(PDO::FETCH_ASSOC);
        
        // Находим результат для AF5105 в основном запросе
        $af5105Result = null;
        foreach ($results as $row) {
            if ($row['filter'] === 'AF5105') {
                $af5105Result = $row;
                break;
            }
        }
        
        $debugInfo = '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <h3 style="margin-top: 0; color: #856404;">🔍 Отладочная информация (AF5105)</h3>
            <p><strong>Всего строк в таблице orders (включая скрытые):</strong> ' . $debugResultAll['total_rows'] . '</p>
            <p><strong>Уникальных заявок (включая скрытые):</strong> ' . $debugResultAll['unique_orders'] . '</p>
            <p><strong>Всего строк в таблице orders (не скрытые):</strong> ' . $debugResult['total_rows'] . '</p>
            <p><strong>Уникальных заявок (не скрытые):</strong> ' . $debugResult['unique_orders'] . '</p>
            <p><strong>Список всех заявок (не скрытые):</strong> ' . htmlspecialchars($debugResult['orders']) . '</p>
            <p><strong>Найдено в результате запроса:</strong> ' . ($af5105Result ? 'Да' : 'Нет') . '</p>';
        if ($af5105Result) {
            $debugInfo .= '<p><strong>Количество заявок в результате:</strong> ' . $af5105Result['orders_count'] . '</p>';
            $debugInfo .= '<p><strong>Список заявок в результате:</strong> ' . htmlspecialchars($af5105Result['order_numbers']) . '</p>';
        }
        $debugInfo .= '<p><strong>SQL запрос:</strong> <pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">' . htmlspecialchars($sql) . '</pre></p>';
        $debugInfo .= '</div>';
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Рейтинг популярности фильтров</title>
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
                cursor: pointer;
                user-select: none;
                position: relative;
            }
            th:hover {
                background-color: #1976D2;
            }
            th.sortable::after {
                content: ' ↕';
                opacity: 0.5;
                font-size: 0.8em;
            }
            th.sort-asc::after {
                content: ' ↑';
                opacity: 1;
            }
            th.sort-desc::after {
                content: ' ↓';
                opacity: 1;
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
            select {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 14px;
                cursor: pointer;
                background: white;
                transition: border-color 0.2s;
            }
            select:hover {
                border-color: #2196F3;
            }
            select:focus {
                outline: none;
                border-color: #2196F3;
                box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.2);
            }
            input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
                margin-right: 5px;
            }
            input[type="number"] {
                padding: 6px 8px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 14px;
                transition: border-color 0.2s;
            }
            input[type="number"]:hover {
                border-color: #2196F3;
            }
            input[type="number"]:focus {
                outline: none;
                border-color: #2196F3;
                box-shadow: 0 0 0 2px rgba(33, 150, 243, 0.2);
            }
            /* Индикатор загрузки */
            .loading-overlay {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: rgba(0, 0, 0, 0.7) !important;
                display: none !important;
                justify-content: center !important;
                align-items: center !important;
                z-index: 999999 !important;
                opacity: 0;
                transition: opacity 0.1s ease-in;
                margin: 0 !important;
                padding: 0 !important;
            }
            .loading-overlay.active {
                display: flex !important;
                opacity: 1 !important;
            }
            .loading-spinner {
                width: 50px;
                height: 50px;
                border: 5px solid #f3f3f3;
                border-top: 5px solid #2196F3;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto;
                display: block;
            }
            .loading-content {
                background: white;
                padding: 30px 40px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-width: 200px;
                margin: 0;
            }
            .loading-text {
                margin-top: 15px;
                color: #333;
                font-weight: bold;
                font-size: 16px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
        <script>
            // Глобальная функция для показа индикатора загрузки и отправки формы
            // Объявляем функцию глобально, чтобы она была доступна из onchange
            window.showLoadingAndSubmit = function() {
                try {
                    var loadingOverlay = document.getElementById('loadingOverlay');
                    var form = document.getElementById('filterForm');
                    
                    // Показываем индикатор немедленно
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'flex';
                        loadingOverlay.style.opacity = '1';
                        loadingOverlay.style.zIndex = '99999';
                        loadingOverlay.classList.add('active');
                    }
                    
                    // Небольшая задержка для визуального отображения перед отправкой
                    setTimeout(function() {
                        if (form) {
                            form.submit();
                        }
                    }, 150);
                    
                    return false;
                } catch(e) {
                    console.error('Ошибка при показе индикатора:', e);
                    var form = document.getElementById('filterForm');
                    if (form) {
                        form.submit();
                    }
                    return false;
                }
            };
            
            // Инициализация при загрузке страницы
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('filterForm');
                var loadingOverlay = document.getElementById('loadingOverlay');
                
                if (form && loadingOverlay) {
                    // Обработка отправки формы
                    form.addEventListener('submit', function(e) {
                        loadingOverlay.style.display = 'flex';
                        loadingOverlay.style.opacity = '1';
                        loadingOverlay.style.zIndex = '99999';
                        loadingOverlay.classList.add('active');
                    });
                }
                
                // Скрываем индикатор после полной загрузки страницы
                window.addEventListener('load', function() {
                    setTimeout(function() {
                        if (loadingOverlay) {
                            loadingOverlay.style.display = 'none';
                            loadingOverlay.style.opacity = '0';
                            loadingOverlay.classList.remove('active');
                        }
                    }, 300);
                });
            });
            
            // Обработка для случаев, когда страница уже загружена
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                setTimeout(function() {
                    var loadingOverlay = document.getElementById('loadingOverlay');
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'none';
                        loadingOverlay.style.opacity = '0';
                        loadingOverlay.classList.remove('active');
                    }
                }, 500);
            }
            
            // Показываем индикатор при уходе со страницы (перезагрузка, переход по ссылке)
            window.addEventListener('beforeunload', function() {
                var loadingOverlay = document.getElementById('loadingOverlay');
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                    loadingOverlay.style.opacity = '1';
                    loadingOverlay.style.zIndex = '99999';
                    loadingOverlay.classList.add('active');
                }
            });
            
            // Функция сортировки таблицы
            let sortDirection = {};
            
            function sortTable(columnIndex, type) {
                const table = document.getElementById('resultsTable');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const headers = table.querySelectorAll('th');
                
                // Определяем направление сортировки
                if (!sortDirection[columnIndex]) {
                    sortDirection[columnIndex] = 'asc';
                } else {
                    sortDirection[columnIndex] = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
                }
                
                // Убираем классы сортировки со всех заголовков
                headers.forEach((header, index) => {
                    header.classList.remove('sort-asc', 'sort-desc');
                    if (index === columnIndex) {
                        header.classList.add('sort-' + sortDirection[columnIndex]);
                    }
                });
                
                // Сортируем строки
                rows.sort(function(a, b) {
                    let aValue = a.cells[columnIndex].textContent.trim();
                    let bValue = b.cells[columnIndex].textContent.trim();
                    
                    if (type === 'number') {
                        // Для чисел убираем все нечисловые символы и преобразуем
                        aValue = parseFloat(aValue.replace(/[^\d.-]/g, '')) || 0;
                        bValue = parseFloat(bValue.replace(/[^\d.-]/g, '')) || 0;
                    }
                    
                    let comparison = 0;
                    if (aValue > bValue) {
                        comparison = 1;
                    } else if (aValue < bValue) {
                        comparison = -1;
                    }
                    
                    return sortDirection[columnIndex] === 'asc' ? comparison : -comparison;
                });
                
                // Пересчитываем номера строк для первого столбца
                rows.forEach((row, index) => {
                    row.cells[0].textContent = index + 1;
                    tbody.appendChild(row);
                });
            }
        </script>
    </head>
    <body>
        <!-- Индикатор загрузки -->
        <div id="loadingOverlay" class="loading-overlay" style="display: flex; opacity: 1;">
            <div class="loading-content">
                <div class="loading-spinner"></div>
                <div class="loading-text">Загрузка данных...</div>
            </div>
        </div>
        
        <script>
            // Показываем индикатор сразу при загрузке страницы (до загрузки DOM)
            (function() {
                if (document.getElementById('loadingOverlay')) {
                    var loadingOverlay = document.getElementById('loadingOverlay');
                    loadingOverlay.style.display = 'flex';
                    loadingOverlay.style.opacity = '1';
                    loadingOverlay.style.zIndex = '99999';
                    loadingOverlay.classList.add('active');
                }
            })();
            
            // Показываем индикатор при перезагрузке страницы
            window.addEventListener('beforeunload', function() {
                var loadingOverlay = document.getElementById('loadingOverlay');
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                    loadingOverlay.style.opacity = '1';
                    loadingOverlay.style.zIndex = '99999';
                    loadingOverlay.classList.add('active');
                }
            });
        </script>
        
        <div class="container">
            <h1>Рейтинг популярности фильтров</h1>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #ffc107;">
                <form method="GET" action="" id="filterForm" style="margin: 0; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-weight: bold;">Период:</label>
                        <select name="period" onchange="showLoadingAndSubmit()">
                            <option value="all_time" <?php echo $period === 'all_time' ? 'selected' : ''; ?>>За все время</option>
                            <option value="last_year" <?php echo $period === 'last_year' ? 'selected' : ''; ?>>За последний год</option>
                        </select>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-weight: bold; cursor: pointer;">
                            <input type="checkbox" name="filter_width" value="1" <?php echo $filterByWidth ? 'checked' : ''; ?> onchange="showLoadingAndSubmit()" style="margin-right: 5px;">
                            Только с шириной <
                        </label>
                        <input type="number" name="width_value" value="<?php echo htmlspecialchars($widthValue); ?>" step="0.1" min="0.1" max="1000" style="width: 80px; padding: 6px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;" onchange="showLoadingAndSubmit()">
                        <span style="font-weight: bold;">мм</span>
                    </div>
                </form>
            </div>
            
            <?php echo $debugInfo; ?>
            
            <div class="info">
                <strong>Найдено позиций:</strong> <?php echo count($results); ?><br>
                <strong>Фильтр по ширине:</strong> <?php echo $filterByWidth ? 'Только фильтры с шириной бумаги < ' . number_format($widthValue, 1, '.', '') . ' мм' : 'Все фильтры (без ограничения по ширине)'; ?><br>
                <strong>Период:</strong> <?php echo $period === 'all_time' ? 'За все время' : 'За последний год (с ' . date('d.m.Y', strtotime('-1 year')) . ')'; ?><br>
                <strong>Сортировка:</strong> По популярности (общее количество фильтров, затем количество заявок)
            </div>
            
            <?php if (count($results) > 0): ?>
            <table id="resultsTable">
                <thead>
                    <tr>
                        <th class="sortable" onclick="sortTable(0, 'number')">№</th>
                        <th class="sortable" onclick="sortTable(1, 'text')">Фильтр</th>
                        <th class="sortable" onclick="sortTable(2, 'number')">Ширина бумаги (мм)</th>
                        <th class="sortable" onclick="sortTable(3, 'number')">Высота бумаги (мм)</th>
                        <th class="sortable" onclick="sortTable(4, 'text')">Материал</th>
                        <th class="sortable" onclick="sortTable(5, 'number')">Количество складок</th>
                        <th class="sortable" onclick="sortTable(6, 'number')">Кол-во заявок</th>
                        <th class="sortable" onclick="sortTable(7, 'number')">Общее кол-во фильтров</th>
                        <th class="sortable" onclick="sortTable(8, 'text')">Номера заявок</th>
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
                <?php echo $filterByWidth ? 'Позиций с шириной бумаги менее ' . number_format($widthValue, 1, '.', '') . ' мм не найдено.' : 'Позиций не найдено.'; ?>
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






