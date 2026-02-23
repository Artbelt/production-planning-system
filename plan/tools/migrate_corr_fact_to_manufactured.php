<?php
/**
 * Перенос изготовленных гофропакетов У2 из corrugation_plan.fact_count
 * в таблицу manufactured_corrugated_packages по указанной заявке.
 *
 * Использование:
 *   В браузере: plan/tools/migrate_corr_fact_to_manufactured.php
 *   Или с заявкой: plan/tools/migrate_corr_fact_to_manufactured.php?order=51-05-25
 *
 * По умолчанию переносится заявка 51-05-25.
 */

header('Content-Type: text/html; charset=utf-8');

$order_number = $_GET['order'] ?? '51-05-25';

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan');

    // Проверяем наличие колонки fact_count в corrugation_plan
    $cols = $pdo->query("SHOW COLUMNS FROM corrugation_plan LIKE 'fact_count'")->fetchAll();
    if (empty($cols)) {
        echo "<p><strong>Ошибка:</strong> В таблице <code>corrugation_plan</code> нет колонки <code>fact_count</code>.</p>";
        exit(1);
    }

    // Создаём таблицу manufactured_corrugated_packages, если её нет
    $pdo->exec("CREATE TABLE IF NOT EXISTS manufactured_corrugated_packages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        date_of_production DATE NOT NULL,
        order_number VARCHAR(50) NOT NULL DEFAULT '',
        filter_label TEXT NOT NULL,
        count INT(11) NOT NULL DEFAULT 0,
        bale_id INT(11) DEFAULT NULL,
        strip_no INT(11) DEFAULT NULL,
        team VARCHAR(50) DEFAULT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_date (date_of_production),
        INDEX idx_order (order_number),
        INDEX idx_date_order (date_of_production, order_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Выбираем строки плана с ненулевым фактом по заявке
    $stmt = $pdo->prepare("
        SELECT id, order_number, plan_date, filter_label, COALESCE(fact_count, 0) AS fact_count
        FROM corrugation_plan
        WHERE order_number = ? AND COALESCE(fact_count, 0) > 0
        ORDER BY plan_date, id
    ");
    $stmt->execute([$order_number]);
    $rows = $stmt->fetchAll();

    $total = count($rows);
    echo "<h2>Миграция гофропакетов по заявке " . htmlspecialchars($order_number) . "</h2>";
    echo "<p>Найдено записей с fact_count &gt; 0: <strong>{$total}</strong></p>";

    if ($total === 0) {
        echo "<p>Нет данных для переноса.</p>";
        exit(0);
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO manufactured_corrugated_packages
        (date_of_production, order_number, filter_label, count)
        VALUES (?, ?, ?, ?)
    ");

    $inserted = 0;
    $total_packages = 0;
    echo "<table border=\"1\" cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse:collapse;\">";
    echo "<thead><tr><th>id (план)</th><th>Дата</th><th>Фильтр</th><th>Кол-во</th><th>Статус</th></tr></thead><tbody>";

    foreach ($rows as $row) {
        $insertStmt->execute([
            $row['plan_date'],
            $row['order_number'],
            $row['filter_label'],
            (int) $row['fact_count']
        ]);
        $inserted++;
        $total_packages += (int) $row['fact_count'];
        echo "<tr>";
        echo "<td>" . (int) $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['plan_date']) . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($row['filter_label'], 0, 50)) . (mb_strlen($row['filter_label']) > 50 ? '…' : '') . "</td>";
        echo "<td>" . (int) $row['fact_count'] . "</td>";
        echo "<td>перенесено</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    echo "<p><strong>Перенесено записей:</strong> {$inserted}, <strong>всего гофропакетов (шт):</strong> {$total_packages}</p>";

    echo "<hr><p><strong>Опционально.</strong> После проверки данных можно обнулить fact_count по этой заявке в плане:</p>";
    echo "<pre>UPDATE corrugation_plan SET fact_count = 0 WHERE order_number = '" . htmlspecialchars($order_number) . "' AND COALESCE(fact_count, 0) > 0;</pre>";
    echo "<p>Или выполнить обнуление из этого скрипта: <a href=\"?order=" . urlencode($order_number) . "&zero=1\">обнулить fact_count по заявке " . htmlspecialchars($order_number) . "</a></p>";

    // Обнуление по запросу
    if (!empty($_GET['zero']) && $_GET['zero'] === '1') {
        $zeroStmt = $pdo->prepare("UPDATE corrugation_plan SET fact_count = 0 WHERE order_number = ? AND COALESCE(fact_count, 0) > 0");
        $zeroStmt->execute([$order_number]);
        $affected = $zeroStmt->rowCount();
        echo "<p style=\"color:green;\"><strong>Выполнено:</strong> обнулено fact_count в {$affected} строках по заявке " . htmlspecialchars($order_number) . ".</p>";
    }

} catch (PDOException $e) {
    echo "<p><strong>Ошибка БД:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    exit(1);
}
