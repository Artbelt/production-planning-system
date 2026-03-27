<?php
// Analytics endpoint for `plan/NP_cut_index.php`.
// This page uses database `plan`, so this endpoint must also read from `plan`
// (otherwise totals/progress become zero).

header('Content-Type: application/json; charset=utf-8');

$order = trim((string)($_GET['order'] ?? ''));
if ($order === '') {
    echo json_encode(['ok' => false, 'error' => 'Не указана заявка']);
    exit;
}

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan');

    // Общая информация о заявке
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(count) as total_count
        FROM orders
        WHERE TRIM(order_number) = TRIM(?) AND (hide IS NULL OR hide != 1)
    ");
    $stmt->execute([$order]);
    $order_info = $stmt->fetch(PDO::FETCH_ASSOC);

    $uniqueFilters = (int)($order_info['total'] ?? 0);
    $totalFilters = (int)($order_info['total_count'] ?? 0);

    if ($uniqueFilters <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "Заявка '{$order}' не найдена в orders (или все строки скрыты hide=1)"]);
        exit;
    }

    // Количество бухт в раскрое
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT bale_id) as bales_count
        FROM cut_plans
        WHERE TRIM(order_number) = TRIM(?)
    ");
    $stmt->execute([$order]);
    $bales_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $bales_count = (int)($bales_info['bales_count'] ?? 0);

    // --------------------
    // Прогресс: РАСКРОЙ
    // --------------------
    // В БД `plan` раскрой считается по roll_plan.done (0/1), по bale_id.
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT bale_id) as total,
            COUNT(DISTINCT CASE WHEN done=1 THEN bale_id END) as done_cnt
        FROM roll_plan
        WHERE TRIM(order_number)=TRIM(?)
    ");
    $stmt->execute([$order]);
    $cut_progress = $stmt->fetch(PDO::FETCH_ASSOC);
    $cut_percent = 0;
    $cut_total = (int)($cut_progress['total'] ?? 0);
    $cut_done_cnt = (int)($cut_progress['done_cnt'] ?? 0);
    if ($cut_total > 0) {
        $cut_percent = (int)round(($cut_done_cnt / $cut_total) * 100);
    }

    // --------------------
    // Прогресс: ГОФРИРОВАНИЕ
    // --------------------
    // corrugation_plan: planned=count, fact_count=факт (если колонка присутствует)
    $corr_fact_exists = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM corrugation_plan LIKE 'fact_count'")->fetchAll(PDO::FETCH_ASSOC);
        $corr_fact_exists = !empty($cols);
    } catch (Throwable $e) {
        $corr_fact_exists = false;
    }

    if ($corr_fact_exists) {
        $stmt = $pdo->prepare("
            SELECT
                SUM(COALESCE(fact_count, 0)) as fact,
                SUM(COALESCE(count, 0)) as plan
            FROM corrugation_plan
            WHERE TRIM(order_number) = TRIM(?)
        ");
        $stmt->execute([$order]);
        $corr_progress = $stmt->fetch(PDO::FETCH_ASSOC);
        $corr_percent = 0;
        $planCnt = (int)($corr_progress['plan'] ?? 0);
        $factCnt = (int)($corr_progress['fact'] ?? 0);
        if ($planCnt > 0) {
            $corr_percent = (int)round(($factCnt / $planCnt) * 100);
        }
    } else {
        // Фолбэк: если нет факт-колонки — оцениваем по статусу
        $stmt = $pdo->prepare("SELECT MAX(corr_ready) as corr_ready FROM orders WHERE TRIM(order_number)=TRIM(?) AND (hide IS NULL OR hide != 1)");
        $stmt->execute([$order]);
        $corr_ready = (int)($stmt->fetch(PDO::FETCH_ASSOC)['corr_ready'] ?? 0);
        $corr_percent = $corr_ready ? 100 : 0;
    }

    // --------------------
    // Прогресс: СБОРКА
    // --------------------
    $build_fact_exists = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM build_plan LIKE 'fact_count'")->fetchAll(PDO::FETCH_ASSOC);
        $build_fact_exists = !empty($cols);
    } catch (Throwable $e) {
        $build_fact_exists = false;
    }

    if ($build_fact_exists) {
        $stmt = $pdo->prepare("
            SELECT
                SUM(COALESCE(fact_count, 0)) as fact,
                SUM(COALESCE(count, 0)) as plan
            FROM build_plan
            WHERE TRIM(order_number) = TRIM(?)
        ");
        $stmt->execute([$order]);
        $build_progress = $stmt->fetch(PDO::FETCH_ASSOC);
        $build_percent = 0;
        $planCnt = (int)($build_progress['plan'] ?? 0);
        $factCnt = (int)($build_progress['fact'] ?? 0);
        if ($planCnt > 0) {
            $build_percent = (int)round(($factCnt / $planCnt) * 100);
        }
    } else {
        $stmt = $pdo->prepare("SELECT MAX(build_ready) as build_ready FROM orders WHERE TRIM(order_number)=TRIM(?) AND (hide IS NULL OR hide != 1)");
        $stmt->execute([$order]);
        $build_ready = (int)($stmt->fetch(PDO::FETCH_ASSOC)['build_ready'] ?? 0);
        $build_percent = $build_ready ? 100 : 0;
    }

    // --------------------
    // Даты планирования
    // --------------------
    $stmt = $pdo->prepare("SELECT MIN(plan_date) as start_date, MAX(plan_date) as end_date FROM roll_plan WHERE TRIM(order_number)=TRIM(?)");
    $stmt->execute([$order]);
    $dates = $stmt->fetch(PDO::FETCH_ASSOC);

    // --------------------
    // Распределение по высотам и сложности
    // --------------------
    // Эта часть нужна для продолжения модалки ниже "карточек".
    $heights_data = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                h.height,
                h.strips_count,
                h.unique_filters,
                COALESCE(SUM(CASE WHEN sfs.build_complexity < 600 THEN o.count ELSE 0 END), 0) as complex_filters
            FROM (
                SELECT
                    height,
                    COUNT(*) as strips_count,
                    COUNT(DISTINCT filter) as unique_filters,
                    GROUP_CONCAT(DISTINCT filter) as filter_list
                FROM cut_plans
                WHERE TRIM(order_number) = TRIM(?)
                GROUP BY height
            ) h
            LEFT JOIN orders o
                ON FIND_IN_SET(TRIM(o.filter), h.filter_list) > 0
                AND TRIM(o.order_number) = TRIM(?)
            LEFT JOIN salon_filter_structure sfs
                ON TRIM(o.filter) = TRIM(sfs.filter)
            GROUP BY h.height, h.strips_count, h.unique_filters
            ORDER BY h.height
        ");
        $stmt->execute([$order, $order]);
        $heights_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $heights_data = [];
    }

    // Анализ сложности сборки
    $complexity_analysis = null;
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN sfs.build_complexity >= 600 THEN 1 END) as simple_count,
                COUNT(CASE WHEN sfs.build_complexity < 600 THEN 1 END) as complex_count,
                AVG(sfs.build_complexity) as avg_complexity,
                MIN(sfs.build_complexity) as min_complexity,
                MAX(sfs.build_complexity) as max_complexity
            FROM orders o
            LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
            WHERE TRIM(o.order_number) = TRIM(?) AND sfs.build_complexity IS NOT NULL
        ");
        $stmt->execute([$order]);
        $complexity_analysis = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $complexity_analysis = null;
    }

    // Материалы (опционально; если таблицы отсутствуют — вернем 0)
    $white_filters = 0;
    $carbon_filters = 0;
    try {
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
            WHERE TRIM(o.order_number) = TRIM(?) AND (o.hide IS NULL OR o.hide != 1)
            GROUP BY material_type
        ");
        $stmt->execute([$order]);
        $material_filters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($material_filters as $mf) {
            if (($mf['material_type'] ?? '') === 'white') {
                $white_filters = (int)($mf['filter_count'] ?? 0);
            } elseif (($mf['material_type'] ?? '') === 'carbon') {
                $carbon_filters = (int)($mf['filter_count'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // ignore - optional
    }

    // Топ-позиции/сложные позиции (опционально; фронт может их использовать ниже по странице)
    $complex_positions = [];
    $top_positions = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                o.filter,
                SUM(o.count) as total_count,
                sfs.build_complexity
            FROM orders o
            LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
            WHERE TRIM(o.order_number) = TRIM(?)
                AND (o.hide IS NULL OR o.hide != 1)
                AND sfs.build_complexity IS NOT NULL
                AND sfs.build_complexity < 600
            GROUP BY o.filter, sfs.build_complexity
            ORDER BY sfs.build_complexity ASC, SUM(o.count) DESC
        ");
        $stmt->execute([$order]);
        $complex_positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                o.filter,
                SUM(o.count) as total_count,
                sfs.build_complexity
            FROM orders o
            LEFT JOIN salon_filter_structure sfs ON TRIM(o.filter) = TRIM(sfs.filter)
            WHERE TRIM(o.order_number) = TRIM(?) AND (o.hide IS NULL OR o.hide != 1)
            GROUP BY o.filter, sfs.build_complexity
            ORDER BY SUM(o.count) DESC
            LIMIT 10
        ");
        $stmt->execute([$order]);
        $top_positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // ignore - optional
    }

    // Блок bales_stats в текущем фронте не используется, но пусть будет консистентным.
    $bales_stats = [
        'carbon' => ['meters' => 0, 'bales' => 0, 'bales_equiv' => 0],
        'white' => ['meters' => 0, 'bales' => 0, 'bales_equiv' => 0],
    ];

    echo json_encode([
        'ok' => true,
        'total_filters' => $totalFilters,
        'unique_filters' => $uniqueFilters,
        'bales_count' => $bales_count,
        'bales_stats' => $bales_stats,
        'progress' => [
            'cut' => $cut_percent,
            'corr' => $corr_percent,
            'build' => $build_percent,
        ],
        'dates' => $dates,
        'heights' => $heights_data,
        'complexity' => $complexity_analysis,
        'materials' => [
            'white' => $white_filters,
            'carbon' => $carbon_filters,
        ],
        'complex_positions' => $complex_positions,
        'top_positions' => $top_positions,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
