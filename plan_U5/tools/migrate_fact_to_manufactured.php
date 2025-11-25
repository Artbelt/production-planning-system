<?php
/**
 * Миграция данных fact_count из corrugation_plan в manufactured_corrugated_packages
 * 
 * Этот скрипт переносит все существующие данные факта производства гофропакетов
 * из таблицы corrugation_plan в таблицу manufactured_corrugated_packages
 */

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_U5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "Начало миграции данных fact_count из corrugation_plan в manufactured_corrugated_packages...\n\n";

/**
 * Функция для расчета внутренних размеров ящика
 * Основана на логике из BOX_CREATOR_2.htm
 * 
 * @param float $length Длина фильтра (мм)
 * @param float $width Ширина фильтра (мм)
 * @param float $height Высота фильтра (мм)
 * @param float $sheet_width Длина листа гофрокартона (мм, по умолчанию 1535)
 * @param float $sheet_height Ширина листа гофрокартона (мм, по умолчанию 930)
 * @param float $overlap Перехлест уплотнителя (мм, по умолчанию 0)
 * @return array Массив с внутренними размерами ['length' => ..., 'width' => ..., 'height' => ...]
 */
function calculateBoxInnerDimensions($length, $width, $height, $sheet_width = 1535, $sheet_height = 930, $overlap = 0) {
    $tail = 40; // хвост
    $gap = 6; // зазор
    $wall_thickness = 3; // толщина стенки гофрокартона (мм)
    
    // Количество фильтров в ряду
    $COFIL = floor((($sheet_width - $tail - 2 * $length) / $height) / 2);
    
    // Размеры выкройки ящика
    $D = $height * $COFIL + 4; // ширина ящика
    $C = $length + 1; // длина ящика
    
    // Проверка длины выкройки
    $test_length = $tail + 2 * $C + 2 * $D + 3 * 6;
    if ($test_length > $sheet_width) {
        $COFIL = $COFIL - 1;
        $D = $height * $COFIL + 4;
    }
    
    // Высота клапана
    if ($D > $C) {
        $B = ($length / 2) + 1 + 3;
    } else {
        $B = ($D + 11) / 2;
    }
    
    // Количество рядов
    $COFIR = floor(($sheet_height - $B * 2 - 8) / $width);
    
    // Высота ящика
    $A = $width * $COFIR + 7 + 10 - $overlap * ($COFIR - 1);
    
    // Внутренние размеры (вычитаем толщину стенок с каждой стороны)
    $inner_length = $C - (2 * $wall_thickness);
    $inner_width = $D - (2 * $wall_thickness);
    $inner_height = $A - (2 * $wall_thickness);
    
    return [
        'length' => round($inner_length),
        'width' => round($inner_width),
        'height' => round($inner_height),
        'box_length' => round($C),
        'box_width' => round($D),
        'box_height' => round($A)
    ];
}

try {
    // Проверяем существование таблицы manufactured_corrugated_packages
    $checkTable = $pdo->query("SHOW TABLES LIKE 'manufactured_corrugated_packages'");
    if ($checkTable->rowCount() == 0) {
        echo "ОШИБКА: Таблица manufactured_corrugated_packages не существует!\n";
        echo "Сначала создайте таблицу, выполнив create_manufactured_corrugated_packages.sql\n";
        exit(1);
    }

    // Определяем поле для фильтра (может быть filter или filter_label)
    $hasFilterLabel = false;
    $checkCol = $pdo->query("SHOW COLUMNS FROM corrugation_plan LIKE 'filter_label'");
    if ($checkCol->rowCount() > 0) {
        $hasFilterLabel = true;
        $filterCol = 'filter_label';
    } else {
        $filterCol = 'filter';
    }
    echo "Используется поле фильтра: {$filterCol}\n\n";

    // Получаем все записи с fact_count > 0, включая размеры фильтра
    // Для расчета ящика нужны: length (длина), width (ширина), height (высота) фильтра
    $stmt = $pdo->prepare("
        SELECT 
            cp.id,
            cp.order_number,
            cp.plan_date,
            cp.{$filterCol} as filter_label,
            cp.fact_count,
            cp.bale_id,
            cp.strip_no,
            -- Размеры из cut_plans (если есть) или из paper_package_salon
            cut.length as filter_length,
            COALESCE(cut.width, pps.p_p_width) as filter_width,
            COALESCE(cut.height, pps.p_p_height) as filter_height
        FROM corrugation_plan cp
        LEFT JOIN cut_plans cut ON cut.order_number = cp.order_number 
            AND TRIM(cut.filter) = TRIM(cp.{$filterCol})
            AND (cp.bale_id IS NULL OR cut.bale_id = cp.bale_id)
            AND (cp.strip_no IS NULL OR cut.strip_no = cp.strip_no)
        LEFT JOIN salon_filter_structure sfs ON TRIM(sfs.filter) = TRIM(cp.{$filterCol})
        LEFT JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE cp.fact_count > 0
        ORDER BY cp.plan_date, cp.order_number, cp.id
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $totalRecords = count($rows);
    echo "Найдено записей с fact_count > 0: {$totalRecords}\n\n";

    if ($totalRecords == 0) {
        echo "Нет данных для миграции.\n";
        exit(0);
    }

    // Начинаем транзакцию
    $pdo->beginTransaction();

    $inserted = 0;
    $skipped = 0;
    $errors = [];

    $insertStmt = $pdo->prepare("
        INSERT INTO manufactured_corrugated_packages 
        (date_of_production, order_number, filter_label, count, bale_id, strip_no, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($rows as $row) {
        try {
            // Проверяем обязательные поля
            if (empty($row['plan_date']) || empty($row['order_number']) || empty($row['filter_label'])) {
                $skipped++;
                $errors[] = "ID {$row['id']}: пропущено (отсутствуют обязательные поля)";
                continue;
            }

            // Рассчитываем и отображаем внутренние размеры ящика, если есть размеры фильтра
            $boxInfo = '';
            if (!empty($row['filter_length']) && !empty($row['filter_width']) && !empty($row['filter_height'])) {
                $dimensions = calculateBoxInnerDimensions(
                    (float)$row['filter_length'],
                    (float)$row['filter_width'],
                    (float)$row['filter_height']
                );
                $boxInfo = sprintf(
                    " | Внутренние размеры ящика: %d×%d×%d мм (Д×Ш×В)",
                    $dimensions['length'],
                    $dimensions['width'],
                    $dimensions['height']
                );
            }

            // Вставляем запись в manufactured_corrugated_packages
            $insertStmt->execute([
                $row['plan_date'],
                $row['order_number'],
                $row['filter_label'],
                (int)$row['fact_count'],
                $row['bale_id'] ?? null,
                $row['strip_no'] ?? null
            ]);

            $inserted++;
            
            // Выводим информацию о записи с внутренними размерами
            if ($inserted % 100 == 0) {
                echo "Обработано: {$inserted} записей...\n";
            } else if ($inserted <= 20 || !empty($boxInfo)) {
                // Показываем первые 20 записей или все записи с рассчитанными размерами ящика
                echo sprintf(
                    "[%d] Заявка: %s, Фильтр: %s, Количество: %d%s\n",
                    $row['id'],
                    $row['order_number'],
                    $row['filter_label'],
                    $row['fact_count'],
                    $boxInfo
                );
            }
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = "ID {$row['id']}: ошибка - " . $e->getMessage();
        }
    }

    // Подтверждаем транзакцию
    $pdo->commit();

    echo "\n";
    echo "Миграция завершена!\n";
    echo "Успешно перенесено записей: {$inserted}\n";
    echo "Пропущено записей: {$skipped}\n";

    if (!empty($errors)) {
        echo "\nОшибки:\n";
        foreach (array_slice($errors, 0, 20) as $error) {
            echo "  - {$error}\n";
        }
        if (count($errors) > 20) {
            echo "  ... и еще " . (count($errors) - 20) . " ошибок\n";
        }
    }

    echo "\n";
    echo "ВАЖНО: После проверки данных вы можете обнулить fact_count в corrugation_plan,\n";
    echo "выполнив SQL запрос:\n";
    echo "UPDATE corrugation_plan SET fact_count = 0 WHERE fact_count > 0;\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    exit(1);
}

