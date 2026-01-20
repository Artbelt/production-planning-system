<?php
header('Content-Type: application/json; charset=utf-8');

$order = $_GET['order'] ?? '';

if (empty($order)) {
    echo json_encode(['ok' => false, 'error' => 'Не указана заявка']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Общая информация о заявке
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(count) as total_count FROM orders WHERE order_number = ? AND (hide IS NULL OR hide != 1)");
    $stmt->execute([$order]);
    $order_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Количество бухт в раскрое
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT bale_id) as bales_count FROM cut_plans WHERE order_number = ?");
    $stmt->execute([$order]);
    $bales_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Статистика по бухтам (материалы, метры, раскрои, эквивалент бухт)
    // Группируем по bale_id и material, берем MAX(fact_length) для каждой бухты
    $stmt = $pdo->prepare("
        SELECT 
            bale_id,
            material,
            MAX(fact_length) as bale_fact_length
        FROM cut_plans
        WHERE order_number = ?
        GROUP BY bale_id, material
    ");
    $stmt->execute([$order]);
    $bales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $carbonMeters = 0;
    $carbonBales = 0;
    $whiteMeters = 0;
    $whiteBales = 0;
    
    foreach ($bales_data as $bale) {
        $matUpper = strtoupper(trim($bale['material'] ?? ''));
        $factLength = (float)($bale['bale_fact_length'] ?? 0);
        
        // Если фактическая длина не указана, используем стандартную (300 для угольного, 400 для белого)
        if ($factLength <= 0) {
            $factLength = ($matUpper === 'CARBON') ? 300 : 400;
        }
        
        if ($matUpper === 'CARBON') {
            $carbonMeters += round($factLength);
            $carbonBales++;
        } else {
            $whiteMeters += round($factLength);
            $whiteBales++;
        }
    }
    
    // Вычисляем эквивалент бухт (угольные по 300м, белые по 400м)
    $carbonBalesEquiv = $carbonMeters > 0 ? round($carbonMeters / 300, 2) : 0;
    $whiteBalesEquiv = $whiteMeters > 0 ? round($whiteMeters / 400, 2) : 0;
    
    // Прогресс по раскрою
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN fact_length > 0 THEN 1 ELSE 0 END) as done,
            COUNT(*) as total
        FROM cut_plans 
        WHERE order_number = ?
    ");
    $stmt->execute([$order]);
    $cut_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $cut_percent = $cut_progress['total'] > 0 ? round(($cut_progress['done'] / $cut_progress['total']) * 100) : 0;
    
    // Прогресс по гофрированию
    $stmt = $pdo->prepare("
        SELECT 
            SUM(COALESCE(fact_count, 0)) as fact,
            SUM(count) as plan
        FROM corrugation_plan 
        WHERE order_number = ?
    ");
    $stmt->execute([$order]);
    $corr_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $corr_percent = $corr_progress['plan'] > 0 ? round(($corr_progress['fact'] / $corr_progress['plan']) * 100) : 0;
    
    // Прогресс по сборке
    $stmt = $pdo->prepare("
        SELECT 
            SUM(COALESCE(fact_count, 0)) as fact,
            SUM(count) as plan
        FROM build_plan 
        WHERE order_number = ?
    ");
    $stmt->execute([$order]);
    $build_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $build_percent = $build_progress['plan'] > 0 ? round(($build_progress['fact'] / $build_progress['plan']) * 100) : 0;
    
    // Информация о датах планирования
    $stmt = $pdo->prepare("SELECT MIN(work_date) as start_date, MAX(work_date) as end_date FROM roll_plans WHERE order_number = ?");
    $stmt->execute([$order]);
    $dates = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Распределение по высотам из cut_plans с учетом количества фильтров и сложности
    // Используем подзапрос чтобы избежать дублирования при JOIN
    $stmt = $pdo->prepare("
        SELECT 
            h.height,
            h.strips_count,
            h.unique_filters,
            COALESCE(SUM(o.count), 0) as total_filters,
            COALESCE(SUM(CASE WHEN sfs.build_complexity < 600 THEN o.count ELSE 0 END), 0) as complex_filters
        FROM (
            SELECT 
                height,
                COUNT(*) as strips_count,
                COUNT(DISTINCT filter) as unique_filters,
                GROUP_CONCAT(DISTINCT filter) as filter_list
            FROM cut_plans
            WHERE order_number = ?
            GROUP BY height
        ) h
        LEFT JOIN orders o ON FIND_IN_SET(TRIM(o.filter), h.filter_list) > 0 AND o.order_number = ?
        LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
        GROUP BY h.height, h.strips_count, h.unique_filters
        ORDER BY h.height
    ");
    $stmt->execute([$order, $order]);
    $heights_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Анализ сложности (считаем простые >= 600, сложные < 600)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN sfs.build_complexity >= 600 THEN 1 END) as simple_count,
            COUNT(CASE WHEN sfs.build_complexity < 600 THEN 1 END) as complex_count,
            AVG(sfs.build_complexity) as avg_complexity,
            MIN(sfs.build_complexity) as min_complexity,
            MAX(sfs.build_complexity) as max_complexity
        FROM orders o
        LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
        WHERE o.order_number = ? AND sfs.build_complexity IS NOT NULL
    ");
    $stmt->execute([$order]);
    $complexity_analysis = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Подсчет фильтров по материалам (белый и угольный)
    // Считаем из заявки (orders) по материалу из paper_package_salon через salon_filter_structure
    $stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN UPPER(TRIM(pps.p_p_material)) = 'CARBON' THEN 'carbon'
                ELSE 'white'
            END as material_type,
            SUM(o.count) as filter_count
        FROM orders o
        LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
        LEFT JOIN paper_package_salon pps ON pps.p_p_name = sfs.paper_package
        WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
        GROUP BY material_type
    ");
    $stmt->execute([$order]);
    $material_filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $white_filters = 0;
    $carbon_filters = 0;
    foreach ($material_filters as $mf) {
        if ($mf['material_type'] === 'white') {
            $white_filters = (int)$mf['filter_count'];
        } elseif ($mf['material_type'] === 'carbon') {
            $carbon_filters = (int)$mf['filter_count'];
        }
    }
    
    // Сложные позиции (build_complexity < 600)
    $stmt = $pdo->prepare("
        SELECT 
            o.filter,
            SUM(o.count) as total_count,
            sfs.build_complexity
        FROM orders o
        LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
        WHERE o.order_number = ? 
            AND (o.hide IS NULL OR o.hide != 1)
            AND sfs.build_complexity IS NOT NULL
            AND sfs.build_complexity < 600
        GROUP BY o.filter, sfs.build_complexity
        ORDER BY sfs.build_complexity ASC, SUM(o.count) DESC
    ");
    $stmt->execute([$order]);
    $complex_positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Позиции с большим количеством (топ-10)
    $stmt = $pdo->prepare("
        SELECT 
            o.filter,
            SUM(o.count) as total_count,
            sfs.build_complexity
        FROM orders o
        LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
        WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
        GROUP BY o.filter, sfs.build_complexity
        ORDER BY SUM(o.count) DESC
        LIMIT 10
    ");
    $stmt->execute([$order]);
    $top_positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'total_filters' => (int)$order_info['total_count'],
        'unique_filters' => (int)$order_info['total'],
        'bales_count' => (int)$bales_info['bales_count'],
        'bales_stats' => [
            'carbon' => [
                'meters' => $carbonMeters,
                'bales' => $carbonBales,
                'bales_equiv' => $carbonBalesEquiv
            ],
            'white' => [
                'meters' => $whiteMeters,
                'bales' => $whiteBales,
                'bales_equiv' => $whiteBalesEquiv
            ]
        ],
        'progress' => [
            'cut' => $cut_percent,
            'corr' => $corr_percent,
            'build' => $build_percent
        ],
        'dates' => $dates,
        'heights' => $heights_data,
        'complexity' => $complexity_analysis,
        'materials' => [
            'white' => $white_filters,
            'carbon' => $carbon_filters
        ],
        'complex_positions' => $complex_positions,
        'top_positions' => $top_positions
    ]);
    
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>

