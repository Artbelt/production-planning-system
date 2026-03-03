<?php
header('Content-Type: application/json; charset=utf-8');

$order = $_GET['order'] ?? '';

if (empty($order)) {
    echo json_encode(['ok' => false, 'error' => 'Не указана заявка']);
    exit;
}

try {
    require_once __DIR__ . '/../settings.php';
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    
    // Общая информация о заявке
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(count) as total_count FROM orders WHERE order_number = ? AND (hide IS NULL OR hide != 1)");
    $stmt->execute([$order]);
    $order_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Количество бухт в раскрое
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT bale_id) as bales_count FROM cut_plans WHERE order_number = ?");
    $stmt->execute([$order]);
    $bales_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Анализ позиций с большим количеством заказа и расчет смен (+ высота и диаметр для H*D)
    $stmt = $pdo->prepare("
        SELECT 
            o.filter,
            SUM(o.count) as total_count,
            rfs.productivity,
            rfs.Height,
            rfs.Diametr_outer
        FROM orders o
        LEFT JOIN round_filter_structure rfs ON TRIM(o.filter) = TRIM(rfs.filter)
        WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
        GROUP BY o.filter, rfs.productivity, rfs.Height, rfs.Diametr_outer
        ORDER BY SUM(o.count) DESC
    ");
    $stmt->execute([$order]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Рассчитываем количество смен для каждой позиции
    $positions_with_shifts = [];
    foreach ($positions as $pos) {
        $count = (int)$pos['total_count'];
        $productivity = $pos['productivity'] !== null ? (int)$pos['productivity'] : null;
        
        $shifts = null;
        if ($productivity !== null && $productivity > 0) {
            $shifts = ceil($count / $productivity); // Округляем вверх
        }
        
        $h = $pos['Height'];
        $d = $pos['Diametr_outer'];
        $height = ($h !== null && $h !== '') ? (float)$h : null;
        $diametr_outer = ($d !== null && $d !== '') ? (float)$d : null;
        
        $positions_with_shifts[] = [
            'filter' => $pos['filter'],
            'count' => $count,
            'productivity' => $productivity,
            'shifts' => $shifts,
            'height' => $height,
            'diametr_outer' => $diametr_outer
        ];
    }
    
    // Габаритный анализ: по высоте и наружному диаметру из round_filter_structure (сравнение с AF0167)
    $gabarit_ref = 'AF0167';
    // Эталон: ищем AF0167 или AF0167pe (в БД может быть с суффиксом pe)
    $stmt = $pdo->prepare("
        SELECT Diametr_outer, Height
        FROM round_filter_structure
        WHERE TRIM(filter) = ? OR TRIM(filter) = CONCAT(?, 'pe')
        ORDER BY LENGTH(TRIM(filter)) ASC
        LIMIT 1
    ");
    $stmt->execute([$gabarit_ref, $gabarit_ref]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);
    $ref_diam = null;
    $ref_height = null;
    if ($ref) {
        $d = $ref['Diametr_outer'];
        $h = $ref['Height'];
        if ($d !== null && $d !== '' && $h !== null && $h !== '') {
            $ref_diam = (float)$d;
            $ref_height = (float)$h;
        }
    }
    
    $gabarit_larger_count = 0;
    $gabarit_smaller_count = 0;
    if ($ref_diam !== null && $ref_height !== null) {
        $ref_gabarit = $ref_diam * $ref_height;
        // Позиции заявки + размеры из round_filter_structure; нормализуем filter заявки (неразрывный пробел)
        $stmt = $pdo->prepare("
            SELECT
                o.filter,
                SUM(o.count) AS total_count,
                rfs.Diametr_outer,
                rfs.Height
            FROM orders o
            LEFT JOIN round_filter_structure rfs
                ON TRIM(REPLACE(o.filter, CHAR(160), ' ')) = TRIM(rfs.filter)
            WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
            GROUP BY o.filter, rfs.Diametr_outer, rfs.Height
        ");
        $stmt->execute([$order]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cnt = (int)$r['total_count'];
            $d = $r['Diametr_outer'];
            $h = $r['Height'];
            if ($d === null || $d === '' || $h === null || $h === '') continue;
            $diam = (float)$d;
            $height = (float)$h;
            $gabarit = $diam * $height;
            if ($gabarit >= $ref_gabarit) {
                $gabarit_larger_count += $cnt;
            } elseif ($gabarit < $ref_gabarit) {
                $gabarit_smaller_count += $cnt;
            }
        }
    }
    
    echo json_encode([
        'ok' => true,
        'total_filters' => (int)$order_info['total_count'],
        'unique_filters' => (int)$order_info['total'],
        'bales_count' => (int)$bales_info['bales_count'],
        'positions' => $positions_with_shifts,
        'gabarit_larger_count' => $gabarit_larger_count,
        'gabarit_smaller_count' => $gabarit_smaller_count,
        'gabarit_ref' => $gabarit_ref
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>

