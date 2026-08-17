<?php
// NP_cut_index.php
require_once __DIR__ . '/../auth/includes/db.php';

/* ================= AJAX: CHANGE STATUS ================= */
if (isset($_GET['action']) && $_GET['action'] === 'change_status') {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw, true);
        $order = $in['order'] ?? ($_POST['order'] ?? '');
        $status = $in['status'] ?? ($_POST['status'] ?? '');
        
        if ($order === '' || $status === '') { 
            http_response_code(400); 
            echo json_encode(['ok'=>false,'error'=>'no order or status']); 
            exit; 
        }

        $pdo = getPdo('plan');

        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE order_number = ?");
        $stmt->execute([$status, $order]);
        
        echo json_encode(['ok'=>true,'status'=>$status]); 
        exit;
    }catch(Throwable $e){
        http_response_code(500); 
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); 
        exit;
    }
}

/* ================= AJAX: FULL REPLANNING ================= */
if (isset($_GET['action']) && $_GET['action'] === 'full_replanning') {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw, true);
        $order = $in['order'] ?? ($_POST['order'] ?? '');
        if ($order === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }

        $pdo = getPdo('plan');
        $pdo->beginTransaction();

        $currentDate = date('Y-m-d');
        $results = ['fact_to_plan' => [], 'cleared_future' => [], 'new_planning' => []];

        // 1. Переносим выполненные операции в план (факт → план)
        $factOperations = [
            'cut_plans' => "SELECT DISTINCT filter, SUM(fact_length) as total_fact FROM cut_plans WHERE order_number = ? AND fact_length > 0 GROUP BY filter",
            'corrugation_plan' => "SELECT DISTINCT filter_label as filter, SUM(count) as total_fact FROM manufactured_corrugated_packages WHERE order_number = ? AND count > 0 GROUP BY filter_label",
            'build_plan' => "SELECT DISTINCT filter_label as filter, SUM(fact_count) as total_fact FROM build_plan WHERE order_number = ? AND fact_count > 0 GROUP BY filter_label"
        ];

        foreach ($factOperations as $table => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$order]);
            $facts = $stmt->fetchAll();
            $results['fact_to_plan'][$table] = $facts;
        }

        // 2. Очищаем будущие планы (начиная с текущей даты) - ИСКЛЮЧАЕМ cut_plans
        // Для roll_plan не трогаем записи с done=1 (выполненные)
        $clearFuture = [
            "DELETE FROM roll_plan WHERE order_number = ? AND plan_date >= ? AND (done IS NULL OR done = 0)", 
            "DELETE FROM corrugation_plan WHERE order_number = ? AND plan_date >= ?",
            "DELETE FROM build_plan WHERE order_number = ? AND assign_date >= ?"
        ];

        foreach ($clearFuture as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$order, $currentDate]);
            $results['cleared_future'][] = $stmt->rowCount();
        }

        // 2.5. Устанавливаем статусы "replanning" для заявки и операций
        $stmt = $pdo->prepare("UPDATE orders SET plan_ready = 0, corr_ready = 0, build_ready = 0 WHERE order_number = ?");
        $stmt->execute([$order]);
        $results['status_updated'] = $stmt->rowCount();

        // 3. Рассчитываем остатки работ для информации (без автоматического планирования)
        $remainingWork = $pdo->prepare("
            SELECT filter, count as total_planned, 
                   COALESCE((SELECT SUM(fact_length) FROM cut_plans WHERE order_number = o.order_number AND filter = o.filter), 0) as cut_fact,
                   COALESCE((SELECT SUM(count) FROM manufactured_corrugated_packages WHERE order_number = o.order_number AND filter_label = o.filter), 0) as corr_fact,
                   COALESCE((SELECT SUM(fact_count) FROM build_plan WHERE order_number = o.order_number AND filter_label = o.filter), 0) as build_fact
            FROM orders o 
            WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
        ");
        $remainingWork->execute([$order]);
        $remaining = $remainingWork->fetchAll();

        // Сохраняем информацию об остатках для отчета
        foreach ($remaining as $row) {
            $cutRemaining = max(0, $row['total_planned'] - $row['cut_fact']);
            $corrRemaining = max(0, $row['total_planned'] - $row['corr_fact']); 
            $buildRemaining = max(0, $row['total_planned'] - $row['build_fact']);
            
            if ($cutRemaining > 0) {
                $results['remaining_work']['cut'][] = ['filter' => $row['filter'], 'count' => $cutRemaining];
            }
            if ($corrRemaining > 0) {
                $results['remaining_work']['corr'][] = ['filter' => $row['filter'], 'count' => $corrRemaining];
            }
            if ($buildRemaining > 0) {
                $results['remaining_work']['build'][] = ['filter' => $row['filter'], 'count' => $buildRemaining];
            }
        }

        $pdo->commit();
        echo json_encode(['ok'=>true,'results'=>$results]); exit;
    }catch(Throwable $e){
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* ================= AJAX: CLEAR ================= */
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw, true);
        $order = $in['order'] ?? ($_POST['order'] ?? '');
        if ($order === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }

        $pdo = getPdo('plan');
        $pdo->beginTransaction();

        $aff = ['cut_plans'=>0,'roll_plan'=>0,'corr'=>0,'build'=>0,'orders'=>0];

        // Удаления (таблицы из текущего проекта)
        foreach ([
                     ['sql'=>"DELETE FROM build_plan WHERE order_number=?", 'key'=>'build'],
                     ['sql'=>"DELETE FROM corrugation_plan WHERE order_number=?", 'key'=>'corr'],
                     ['sql'=>"DELETE FROM roll_plan WHERE order_number=?", 'key'=>'roll_plan'],
                     ['sql'=>"DELETE FROM cut_plans WHERE order_number=?", 'key'=>'cut_plans'],
                 ] as $q){
            try{
                $st = $pdo->prepare($q['sql']);
                $st->execute([$order]);
                $aff[$q['key']] = $st->rowCount();
            } catch(Throwable $e){
                // если таблицы нет — молча игнорируем
            }
        }

        // Сброс статусов в orders (только существующие поля)
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders'")->fetchAll(PDO::FETCH_COLUMN);
        $want = ['cut_ready','cut_confirmed','plan_ready','corr_ready','build_ready'];
        $set  = [];
        foreach($want as $c){ if(in_array($c,$cols,true)) $set[] = "$c=0"; }
        if ($set){
            $sql = "UPDATE orders SET ".implode(',', $set)." WHERE order_number=?";
            $st  = $pdo->prepare($sql); $st->execute([$order]);
            $aff['orders'] = $st->rowCount();
        }

        $pdo->commit();
        echo json_encode(['ok'=>true,'aff'=>$aff]); exit;
    }catch(Throwable $e){
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
    }
}

/* ================= PAGE ================= */
try{
    $pdo = getPdo('plan');

    // Статусы заявок (для plan нет поля status, убираем его)
$rows = $pdo->query("
    SELECT
        order_number,
        `filter`,
        `count`,
        cut_ready,
        plan_ready,
        corr_ready,
        build_ready
    FROM orders
    WHERE hide IS NULL OR hide != 1
    ORDER BY order_number
")->fetchAll(PDO::FETCH_ASSOC);

    $cardsByOrder = [];
    foreach ($rows as $r) {
        $ord = (string)($r['order_number'] ?? '');
        if ($ord === '') continue;

        // Ключ карточки: статусы этапов, которые реально отображаются на странице
        $comboKey = implode('|', [
            (int)($r['cut_ready'] ?? 0),
            (int)($r['plan_ready'] ?? 0),
            (int)($r['corr_ready'] ?? 0),
            (int)($r['build_ready'] ?? 0),
        ]);

        if (!isset($cardsByOrder[$ord]['combos'][$comboKey])) {
            $cardsByOrder[$ord]['combos'][$comboKey] = [
                'order_number' => $ord,
                'cut_ready' => (int)($r['cut_ready'] ?? 0),
                'plan_ready' => (int)($r['plan_ready'] ?? 0),
                'corr_ready' => (int)($r['corr_ready'] ?? 0),
                'build_ready' => (int)($r['build_ready'] ?? 0),
                'filter_counts' => [],
            ];
        }

        $filter = trim((string)($r['filter'] ?? ''));
        $cnt = (int)($r['count'] ?? 0);
        if ($filter !== '' && $cnt > 0) {
            if (!isset($cardsByOrder[$ord]['combos'][$comboKey]['filter_counts'][$filter])) {
                $cardsByOrder[$ord]['combos'][$comboKey]['filter_counts'][$filter] = 0;
            }
            $cardsByOrder[$ord]['combos'][$comboKey]['filter_counts'][$filter] += $cnt;
        }
    }

    $orders = [];
    $stageLabel = function(array $s): string {
        if (empty($s['cut_ready'])) return 'раскрой';
        if (empty($s['plan_ready'])) return 'план раскроя рулона';
        if (empty($s['corr_ready'])) return 'план гофрирования';
        if (empty($s['build_ready'])) return 'план сборки';
        return 'этап';
    };

    foreach ($cardsByOrder as $ord => $data) {
        $comboList = array_values($data['combos']);
        $comboCount = count($comboList);

        // Порядок карточек: сначала "хуже" (больше неготовности)
        usort($comboList, function($a, $b) {
            $scoreA = ((int)$a['cut_ready'] << 3) | ((int)$a['plan_ready'] << 2) | ((int)$a['corr_ready'] << 1) | ((int)$a['build_ready']);
            $scoreB = ((int)$b['cut_ready'] << 3) | ((int)$b['plan_ready'] << 2) | ((int)$b['corr_ready'] << 1) | ((int)$b['build_ready']);
            return $scoreA <=> $scoreB;
        });

        foreach ($comboList as $card) {
            $card['duplicate_reason'] = '';
            $isIncomplete = empty($card['cut_ready'])
                || empty($card['plan_ready'])
                || empty($card['corr_ready'])
                || empty($card['build_ready']);

            // Памятка только на «недопланированной» карточке-дубликате
            if ($comboCount > 1 && $isIncomplete) {
                $stage = $stageLabel($card);
                $filterCounts = $card['filter_counts'] ?? [];
                arsort($filterCounts);
                $top = array_slice($filterCounts, 0, 3, true);

                $parts = [];
                foreach ($top as $fname => $fcount) {
                    $fcount = (int)$fcount;
                    if ($fcount > 0) $parts[] = $fname . ' (' . $fcount . ' шт)';
                }

                $more = (count($filterCounts) > 3) ? (' и ещё ' . (count($filterCounts) - 3) . ' фильтров') : '';
                $filtersPart = $parts ? implode(', ', $parts) . $more : '—';

                $card['duplicate_reason'] = 'Добавлены и ещё не распланированы: ' . $filtersPart
                    . ' — этап «' . $stage . '».';
            }

            unset($card['filter_counts']);
            $orders[] = $card;
        }
    }

// Заявки, по которым уже есть гофроплан
$stmt = $pdo->query("SELECT DISTINCT order_number FROM corrugation_plan");
$corr_done = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

} catch(Throwable $e){
    http_response_code(500); exit("Ошибка БД: ".htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Этапы планирования</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --background: hsl(220, 20%, 97%);
            --foreground: hsl(220, 15%, 15%);
            --card: hsl(0, 0%, 100%);
            --card-foreground: hsl(220, 15%, 15%);
            --primary: hsl(217, 91%, 60%);
            --primary-foreground: hsl(0, 0%, 100%);
            --secondary: hsl(220, 14%, 96%);
            --secondary-foreground: hsl(220, 15%, 15%);
            --muted: hsl(220, 14%, 96%);
            --muted-foreground: hsl(220, 10%, 45%);
            --success: hsl(142, 71%, 45%);
            --success-foreground: hsl(0, 0%, 100%);
            --warning: hsl(38, 92%, 50%);
            --warning-foreground: hsl(0, 0%, 100%);
            --destructive: hsl(0, 84%, 60%);
            --destructive-foreground: hsl(0, 0%, 100%);
            --border: hsl(220, 13%, 91%);
            --radius: 0.75rem;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background);
            color: var(--foreground);
            line-height: 1.6;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(8px);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .header-content {
            padding: 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-wrapper {
            padding: 0.5rem;
            background: hsla(217, 91%, 60%, 0.1);
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon {
            width: 1.5rem;
            height: 1.5rem;
            color: var(--primary);
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            margin: 0;
        }

        .subtitle {
            font-size: 0.875rem;
            color: var(--muted-foreground);
            margin: 0;
        }

        main {
            padding: 2rem 0;
        }

        .applications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .application-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            box-shadow: 0 1px 2px 0 hsla(220, 15%, 15%, 0.05);
            transition: box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .application-card:hover {
            box-shadow: 0 4px 6px -1px hsla(220, 15%, 15%, 0.1);
        }

        .application-card.is-duplicate {
            border-color: hsl(38, 92%, 75%);
        }

        .duplicate-caption {
            width: 100%;
            margin: 0 0 0.75rem;
            padding: 0.5rem 0.75rem;
            background: hsl(38, 92%, 95%);
            border: 1px solid hsl(38, 92%, 80%);
            border-radius: calc(var(--radius) - 2px);
            color: hsl(32, 80%, 28%);
            font-size: 0.8125rem;
            line-height: 1.4;
        }

        .card-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .card-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        .app-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .app-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0;
        }

        .app-title {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--foreground);
        }

        .app-description {
            font-size: 0.75rem;
            color: var(--muted-foreground);
            margin-bottom: 0;
            line-height: 1.4;
        }

        .stage-section {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .stage-title {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--muted-foreground);
            margin-bottom: 0;
        }
        button, a.btn-primary, a.btn-secondary, a.btn-outline, a.btn-print {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 500;
            border-radius: calc(var(--radius) - 2px);
            border: none;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            text-decoration: none;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--foreground);
        }

        .btn-outline:hover {
            background: var(--secondary);
        }

        .btn-secondary {
            background: var(--secondary);
            color: var(--secondary-foreground);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: hsl(220, 14%, 92%);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--primary-foreground);
        }

        .btn-primary:hover {
            background: hsl(217, 91%, 55%);
        }

        .btn-destructive {
            color: var(--destructive);
            background: transparent;
            border: 1px solid var(--border);
        }

        .btn-destructive:hover {
            color: var(--destructive);
            background: hsla(0, 84%, 60%, 0.1);
        }

        .btn-print {
            background: hsl(184, 95%, 95%);
            color: hsl(184, 95%, 39%);
            border: 1px solid hsl(184, 95%, 85%);
        }

        .btn-print:hover {
            background: hsl(184, 95%, 90%);
        }

        .btn-sm {
            padding: 0.3rem 0.625rem;
            font-size: 0.75rem;
        }

        .btn-icon {
            padding: 0.3rem;
            width: 28px;
            height: 28px;
        }

        .btn-full {
            width: 100%;
        }

        .flex-1 {
            flex: 1;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            width: fit-content;
        }

        .badge-success {
            background: var(--success);
            color: var(--success-foreground);
        }

        .badge-warning {
            background: var(--warning);
            color: var(--warning-foreground);
        }

        .badge-muted {
            background: var(--muted);
            color: var(--muted-foreground);
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
        }

        .note {
            font-size: 0.6875rem;
            color: var(--muted-foreground);
            margin-top: 0;
            line-height: 1.3;
        }
        
        svg {
            width: 1rem;
            height: 1rem;
        }
        @media (max-width: 1024px) {
            .card-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        .btn-analysis {
            background: hsl(142, 76%, 95%);
            color: var(--success);
            border: 1px solid hsl(142, 76%, 85%);
        }

        .btn-analysis:hover {
            background: hsl(142, 76%, 90%);
        }
        
        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 1100px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--card);
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--foreground);
        }
        
        .modal-close {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--muted-foreground);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        
        .modal-close:hover {
            background: var(--secondary);
            color: var(--foreground);
        }
        
        .modal-body {
            padding: 1.5rem;
            background: var(--card);
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .info-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            padding: 0.625rem 0.75rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .info-card:hover {
            box-shadow: 0 2px 4px 0 hsla(220, 15%, 15%, 0.08);
            border-color: var(--primary);
        }
        
        .info-card h4 {
            margin: 0 0 0.3rem;
            font-size: 0.6875rem;
            color: var(--muted-foreground);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .info-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--foreground);
            line-height: 1;
        }
        
        .info-label {
            font-size: 0.6875rem;
            color: var(--muted-foreground);
            margin-top: 0.2rem;
        }
        
        .section-block {
            margin-top: 0.75rem;
            padding: 0.75rem;
            background: var(--muted);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
        }
        
        .section-title {
            margin: 0 0 0.5rem;
            font-size: 0.8125rem;
            color: var(--foreground);
            font-weight: 600;
        }
        
        .heights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.5rem;
        }
        
        .height-tile {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            padding: 0.5rem 0.625rem;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.8125rem;
        }
        
        .height-tile:hover {
            box-shadow: 0 2px 4px 0 hsla(220, 15%, 15%, 0.08);
            border-color: var(--primary);
        }

        /* --- Фильтры с каркасами (подсказка по сменам) --- */
        .wf-section { margin-top: 0.75rem; }
        .wf-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .wf-toolbar label {
            font-size: 0.75rem;
            color: var(--muted-foreground);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .wf-toolbar input[type="number"] {
            width: 4.5rem;
            padding: 0.25rem 0.4rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.8125rem;
        }
        .wf-btn {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--card);
            cursor: pointer;
            color: var(--foreground);
        }
        .wf-btn:hover { background: var(--secondary); }
        .wf-btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .wf-btn-primary:hover { filter: brightness(0.95); }
        .wf-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        @media (max-width: 720px) {
            .wf-summary-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .wf-layout {
            display: grid;
            grid-template-columns: minmax(200px, 240px) 1fr;
            gap: 0.75rem;
        }
        @media (max-width: 800px) {
            .wf-layout { grid-template-columns: 1fr; }
        }
        .wf-pool {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.5rem;
            min-height: 280px;
            max-height: min(70vh, 720px);
            overflow-y: auto;
        }
        .wf-pool.wf-drop-hover { border-color: var(--primary); background: #f0f7ff; }
        .wf-pool-title {
            font-size: 0.75rem;
            font-weight: 600;
            margin: 0 0 0.4rem;
        }
        .wf-card {
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 0.4rem 0.45rem;
            margin-bottom: 0.4rem;
            background: #fff;
            cursor: grab;
            font-size: 0.75rem;
            user-select: none;
        }
        .wf-card:active { cursor: grabbing; }
        .wf-card.wf-dragging { opacity: 0.45; }
        .wf-card-name { font-weight: 600; color: var(--foreground); }
        .wf-card-sub { color: var(--muted-foreground); font-size: 0.65rem; margin-top: 0.15rem; }
        .wf-card-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.3rem;
        }
        .wf-card input[type="number"] {
            width: 3rem;
            padding: 0.15rem 0.25rem;
            border: 1px solid var(--border);
            border-radius: 3px;
            font-size: 0.7rem;
        }
        .wf-done { opacity: 0.55; cursor: default; }
        .wf-stats {
            margin: 0.5rem 0 0.75rem;
            padding: 0.5rem 0.65rem;
            background: var(--muted);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.78rem;
            color: var(--foreground);
            line-height: 1.45;
        }
        .wf-stats strong { font-weight: 600; }
        .wf-stats-empty { color: var(--muted-foreground); }
        .wf-grid-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            padding: 0.4rem;
        }
        .wf-band {
            margin-bottom: 0.65rem;
        }
        .wf-band:last-child { margin-bottom: 0; }
        .wf-band-table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
            font-size: 0.7rem;
        }
        .wf-band-table th,
        .wf-band-table td {
            border: 1px solid #d1d5db;
            padding: 0;
            height: 18px;
            text-align: center;
            vertical-align: middle;
            position: relative;
        }
        .wf-band-table td[rowspan] {
            height: auto;
        }
        .wf-band-table th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
            height: 16px;
            font-size: 0.6rem;
        }
        .wf-cell {
            min-height: 18px;
        }
        .wf-cell.wf-drop-hover {
            outline: 2px solid var(--primary);
            outline-offset: -2px;
            background: #eff6ff !important;
        }
        .wf-cell.wf-drop-deny {
            outline: 2px solid #dc2626;
            outline-offset: -2px;
            background: #fef2f2 !important;
        }
        .wf-seg {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            min-height: 18px;
            box-sizing: border-box;
            font-weight: 600;
            font-size: 0.62rem;
            cursor: grab;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding: 0 2px;
            border: none;
        }
        .wf-seg:active { cursor: grabbing; }
        .wf-seg.wf-dragging { opacity: 0.4; }
        .wf-seg-start { border-left: 2px solid rgba(0,0,0,0.25); }
        .wf-seg-end { border-right: 2px solid rgba(0,0,0,0.25); }
        .wf-seg-tall {
            writing-mode: horizontal-tb;
        }

        @media print {
            @page { margin: 10mm; size: A4 portrait; }
            body.wf-print-grid * { visibility: hidden !important; }
            body.wf-print-grid #wfPrintRoot,
            body.wf-print-grid #wfPrintRoot * { visibility: visible !important; }
            body.wf-print-grid #wfPrintRoot {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                background: #fff !important;
            }
            body.wf-print-grid #analysisModal {
                position: static !important;
                display: block !important;
                background: transparent !important;
                inset: auto !important;
            }
            body.wf-print-grid .modal-content {
                box-shadow: none !important;
                border: none !important;
                max-width: none !important;
                max-height: none !important;
                overflow: visible !important;
                background: transparent !important;
            }
            body.wf-print-grid .wf-grid-wrap {
                border: none !important;
                padding: 0 !important;
                overflow: visible !important;
            }
            body.wf-print-grid .wf-print-title {
                display: block !important;
                font-size: 14pt;
                font-weight: 700;
                margin: 0 0 8px;
                color: #000;
            }
            body.wf-print-grid .wf-print-meta {
                display: block !important;
                font-size: 9pt;
                margin: 0 0 10px;
                color: #333;
            }
            body.wf-print-grid .wf-seg { cursor: default !important; }
            body.wf-print-grid .wf-band { break-inside: avoid; page-break-inside: avoid; }
        }
        .wf-print-title,
        .wf-print-meta { display: none; }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container">
            <div class="header-content">
                <div class="icon-wrapper">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                </div>
                <div>
                    <h1>Планирование заявок</h1>
                    <p class="subtitle">Управление производственными заявками и планами</p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="applications-list">
        <?php foreach ($orders as $o): $ord = $o['order_number']; ?>
                <div class="application-card<?= !empty($o['duplicate_reason']) ? ' is-duplicate' : '' ?>">
                    <?php if (!empty($o['duplicate_reason'])): ?>
                        <div class="duplicate-caption"><?= htmlspecialchars($o['duplicate_reason'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div class="card-grid">
                        <div class="app-info">
                            <div class="app-header">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary); width: 1.25rem; height: 1.25rem;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                </svg>
                                <h3 class="app-title"><?= htmlspecialchars($ord) ?></h3>
                            </div>
                            <button class="btn-outline btn-sm btn-destructive" onclick="clearOrder('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')" title="Удалит раскрой, раскладку по дням, гофро- и сборочный планы, а также сбросит статусы">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                                Очистить всё
                                </button>
                            <button class="btn-analysis btn-sm" onclick="showAnalysis('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')" title="Анализ заявки">Анализ</button>
                    </div>

                        <div class="stage-section">
                            <h4 class="stage-title">Раскрой (подготовка)</h4>
                            <span class="badge <?= $o['cut_ready'] ? 'badge-success' : 'badge-warning' ?>">
                                <?= $o['cut_ready'] ? 'Готово' : 'Не готов' ?>
                            </span>
                    <?php if ($o['cut_ready']): ?>
                                <div class="button-group">
                                    <button class="btn-outline btn-sm btn-icon" onclick="window.open('NP_view_cut.php?order=<?= urlencode($ord) ?>', '_blank')" title="Просмотр раскроя (собранные бухты)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="6 9 6 2 18 2 18 9"/>
                                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                            <rect x="6" y="14" width="12" height="8"/>
                                        </svg>
                                    </button>
                                    <button class="btn-secondary btn-sm flex-1" onclick="editCutPlan('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')">Изменить</button>
                        </div>
                    <?php else: ?>
                                <a class="btn-primary btn-sm btn-full" target="_blank" href="NP_cut_plan.php?order_number=<?= urlencode($ord) ?>">Сделать</a>
                            <?php endif; ?>
                        </div>

                        <div class="stage-section">
                            <h4 class="stage-title">План раскроя рулона</h4>
                    <?php if (!$o['cut_ready']): ?>
                                <span class="badge badge-muted">Раскрой не готов</span>
                            <?php elseif ($o['plan_ready']): ?>
                                <span class="badge badge-success">Готово</span>
                                <div class="button-group">
                                    <button class="btn-outline btn-sm btn-icon" onclick="window.open('NP_view_roll_plan.php?order=<?= urlencode($ord) ?>', '_blank')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                    <button class="btn-secondary btn-sm flex-1" onclick="editRollPlan('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')">Изменить</button>
                        </div>
                    <?php else: ?>
                                <button class="btn-primary btn-sm btn-full" onclick="window.location.href='NP_roll_plan.php?order=<?= urlencode($ord) ?>'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Планировать
                                </button>
                                <p class="note">после планирования будет доступен просмотр</p>
                            <?php endif; ?>
                        </div>

                        <div class="stage-section">
                            <h4 class="stage-title">План гофрирования</h4>
                            <?php if (!$o['plan_ready']): ?>
                                <span class="badge badge-muted">Нет плана раскроя</span>
                            <?php elseif ($o['corr_ready']): ?>
                                <span class="badge badge-success">Готово</span>
                                <div class="button-group">
                                    <button class="btn-outline btn-sm btn-icon" onclick="window.open('NP_view_corrugation_plan.php?order=<?= urlencode($ord) ?>', '_blank')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                    <button class="btn-secondary btn-sm flex-1" onclick="editCorrugationPlan('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')">Изменить</button>
                        </div>
                    <?php else: ?>
                                <button class="btn-primary btn-sm btn-full" onclick="window.location.href='NP_corrugation_plan.php?order=<?= urlencode($ord) ?>'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Планировать
                                </button>
                                <p class="note">после планирования будет доступен просмотр</p>
                            <?php endif; ?>
                        </div>

                        <div class="stage-section">
                            <h4 class="stage-title">План сборки</h4>
                            <?php if (!$o['corr_ready']): ?>
                                <span class="badge badge-muted">Нет гофроплана</span>
                            <?php elseif ($o['build_ready']): ?>
                                <span class="badge badge-success">Готово</span>
                                <div class="button-group">
                                    <button class="btn-outline btn-sm btn-icon" onclick="window.open('view_production_plan.php?order=<?= urlencode($ord) ?>', '_blank')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                    </button>
                                    <button class="btn-secondary btn-sm flex-1" onclick="editBuildPlan('<?= htmlspecialchars($ord, ENT_QUOTES) ?>')">Изменить</button>
                        </div>
                    <?php else: ?>
                                <button class="btn-primary btn-sm btn-full" onclick="window.location.href='NP_build_plan.php?order=<?= urlencode($ord) ?>'">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Планировать
                                </button>
                                <p class="note">после планирования будет доступен просмотр</p>
                            <?php endif; ?>
                        </div>
                        
                    </div><!-- .card-grid -->
                </div><!-- .application-card -->
                <?php endforeach; ?>
            </div><!-- .applications-list -->
        </div><!-- .container -->
    </main>

<!-- Модальное окно анализа -->
<div id="analysisModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Анализ заявки</h3>
            <button class="modal-close" onclick="closeAnalysis()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <div style="text-align:center;padding:40px;color:#9ca3af;">
                <p>Загрузка данных...</p>
                                </div>
                        </div>
                        </div>
</div>

<script>
    // Очистка плана целиком
    async function clearOrder(order){
        if (!confirm('Очистить ВСЁ планирование по заявке '+order+'?\nБудут удалены: раскрой, раскладка по дням, гофро- и сборочный планы.\nСтатусы заявки будут сброшены.')) return;
        try{
            const res = await fetch('NP_cut_index.php?action=clear', {
                method: 'POST',
                headers: {'Content-Type':'application/json', 'Accept':'application/json'},
                body: JSON.stringify({order})
            });
            let data;
            try{ data = await res.json(); }
            catch(e){
                const t = await res.text();
                throw new Error('Backend вернул не JSON:\n'+t.slice(0,500));
            }
            if (!data.ok) throw new Error(data.error || 'unknown');
            alert('Готово. Удалено записей:\n' +
                'cut_plans: '+(data.aff?.cut_plans ?? 0)+'\n' +
                'roll_plan: '+(data.aff?.roll_plan ?? 0)+'\n' +
                'corrugation_plan: '+(data.aff?.corr ?? 0)+'\n' +
                'build_plan: '+(data.aff?.build ?? 0));
            location.reload();
        }catch(e){
            alert('Не удалось очистить: '+e.message);
        }
    }
    
    // Редактирование раскроя — открытие без автопересчёта; пересчёт только по кнопке на странице
    function editCutPlan(order){
        if (confirm(
            'Открыть сохранённый раскрой?\n\n' +
            'Страница покажет текущий раскрой из базы без автоматического пересчёта.\n' +
            'Для пересоздания используйте кнопку «Пересчитать раскрой» на открывшейся странице.\n\n' +
            'Продолжить?'
        )) {
            window.open('NP_cut_plan.php?order_number=' + encodeURIComponent(order), '_blank');
        }
    }
    
    // Редактирование плана раскроя рулона с предупреждением
    function editRollPlan(order){
        if (confirm(
            '⚠️ ВНИМАНИЕ!\n\n' +
            'При редактировании плана раскроя рулона нарушится синхронизация с последующими этапами:\n\n' +
            '• План гофрирования\n' +
            '• План сборки\n\n' +
            'Вероятно, их придется переделывать заново.\n\n' +
            'Продолжить редактирование?'
        )) {
            window.open('NP_roll_plan.php?order=' + encodeURIComponent(order), '_blank');
        }
    }
    
    // Редактирование плана гофрирования с предупреждением
    function editCorrugationPlan(order){
        if (confirm(
            '⚠️ ВНИМАНИЕ!\n\n' +
            'При редактировании плана гофрирования нарушится синхронизация с последующим этапом:\n\n' +
            '• План сборки\n\n' +
            'Вероятно, его придется переделывать заново.\n\n' +
            'Продолжить редактирование?'
        )) {
            window.open('NP_corrugation_plan.php?order=' + encodeURIComponent(order), '_blank');
        }
    }
    
    // Редактирование плана сборки с предупреждением
    function editBuildPlan(order){
        if (confirm(
            '⚠️ ВНИМАНИЕ!\n\n' +
            'Вы собираетесь изменить готовый план сборки.\n\n' +
            'Убедитесь, что внесенные изменения не нарушат синхронизацию с предыдущими этапами планирования.\n\n' +
            'Продолжить редактирование?'
        )) {
            window.open('NP_build_plan.php?order=' + encodeURIComponent(order), '_blank');
        }
    }
    
    // Анализ заявки
    async function showAnalysis(order){
        const modal = document.getElementById('analysisModal');
        const title = document.getElementById('modalTitle');
        const body = document.getElementById('modalBody');
        
        title.textContent = 'Анализ заявки ' + order;
        body.innerHTML = '<div style="text-align:center;padding:40px;color:#9ca3af;"><p>Загрузка данных...</p></div>';
        modal.style.display = 'flex';
        
        try {
            const response = await fetch('NP/get_order_analysis.php?order=' + encodeURIComponent(order));
            const data = await response.json();
            
            if (!data.ok) {
                body.innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><p>Ошибка загрузки данных</p></div>';
                return;
            }
            
            // Формируем HTML с информацией
            let html = '<div class="info-grid">';
            
            // Общая информация
            html += `
                <div class="info-card">
                    <h4>Всего фильтров</h4>
                    <div class="info-value">${data.total_filters || 0}</div>
                    <div class="info-label">в заявке</div>
                </div>
                <div class="info-card">
                    <h4>Уникальных позиций</h4>
                    <div class="info-value">${data.unique_filters || 0}</div>
                    <div class="info-label">типов фильтров</div>
                </div>
                <div class="info-card">
                    <h4>Бухты</h4>
                    <div class="info-value">${data.bales_count || 0}</div>
                    <div class="info-label">в раскрое</div>
                </div>
            `;
            
            // Убраны плашки прогресса по этапам (Раскрой/Гофрирование/Сборка)
            
            html += '</div>';
            
            // Распределение по высотам плиткой
            if (data.heights && data.heights.length > 0) {
                html += '<div class="section-block">';
                html += '<div class="section-title">Распределение по высотам</div>';
                html += '<div class="heights-grid">';
                
                data.heights.forEach(h => {
                    const complexCount = parseInt(h.complex_filters) || 0;
                    const totalCount = parseInt(h.total_filters) || 0;
                    const complexPercent = totalCount > 0 ? Math.round((complexCount / totalCount) * 100) : 0;
                    
                    html += `
                        <div class="height-tile">
                            <div style="text-align:center;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #e5e7eb;">
                                <div style="font-size:20px;font-weight:700;color:#111827;line-height:1;">${h.height}</div>
                                <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;margin-top:2px;">высота</div>
                            </div>
                            <div style="text-align:center;margin-bottom:6px;">
                                <div style="font-size:18px;font-weight:700;color:#111827;">${totalCount}</div>
                                <div style="font-size:10px;color:#6b7280;">фильтров</div>
                            </div>
                            ${complexCount > 0 ? `
                                <div style="text-align:center;padding:4px;background:#ffffff;border-radius:4px;border:1px solid #e5e7eb;margin-bottom:6px;">
                                    <div style="font-size:11px;font-weight:600;color:#374151;">сложных: ${complexCount}</div>
                                    <div style="font-size:9px;color:#9ca3af;">${complexPercent}%</div>
                                </div>
                            ` : ''}
                            <div style="font-size:9px;color:#9ca3af;text-align:center;">
                                ${h.strips_count} полос • ${h.unique_filters} типов
                            </div>
                        </div>
                    `;
                });
                
                html += '</div></div>';
            }
            
            // Анализ сложности и период - в одной строке
            html += '<div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">';
            
            // Анализ сложности
            if (data.complexity && (data.complexity.simple_count > 0 || data.complexity.complex_count > 0)) {
                const total = parseInt(data.complexity.simple_count) + parseInt(data.complexity.complex_count);
                const simplePercent = total > 0 ? Math.round((data.complexity.simple_count / total) * 100) : 0;
                const complexPercent = total > 0 ? Math.round((data.complexity.complex_count / total) * 100) : 0;
                
                html += '<div class="section-block" style="margin-top:0;">';
                html += '<div class="section-title">Анализ сложности сборки</div>';
                html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">';
                html += `
                    <div style="background:#fafafa;padding:10px;border-radius:4px;border:1px solid #e5e7eb;text-align:center;">
                        <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;letter-spacing:0.5px;">Простые (≥600)</div>
                        <div style="font-size:20px;font-weight:700;color:#111827;line-height:1;">${data.complexity.simple_count}</div>
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${simplePercent}%</div>
                    </div>
                    <div style="background:#fafafa;padding:10px;border-radius:4px;border:1px solid #e5e7eb;text-align:center;">
                        <div style="font-size:9px;color:#9ca3af;text-transform:uppercase;margin-bottom:4px;letter-spacing:0.5px;">Сложные (<600)</div>
                        <div style="font-size:20px;font-weight:700;color:#111827;line-height:1;">${data.complexity.complex_count}</div>
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${complexPercent}%</div>
                    </div>
                `;
                html += '</div>';
                
                if (data.complexity.avg_complexity) {
                    html += '<div style="font-size:10px;color:#6b7280;padding:8px;background:#fafafa;border-radius:4px;border:1px solid #e5e7eb;text-align:center;">';
                    html += `<strong style="color:#374151;">Средняя:</strong> ${parseFloat(data.complexity.avg_complexity).toFixed(1)} `;
                    html += `<span style="color:#9ca3af;">(${data.complexity.min_complexity || 0}—${data.complexity.max_complexity || 0})</span>`;
                    html += '</div>';
                }
                html += '</div>';
            }
            
            // Период планирования
            if (data.dates && data.dates.start_date) {
                html += '<div class="section-block" style="margin-top:0;">';
                html += '<div class="section-title">Период планирования</div>';
                const startDate = new Date(data.dates.start_date).toLocaleDateString('ru-RU');
                const endDate = new Date(data.dates.end_date).toLocaleDateString('ru-RU');
                html += `<div style="font-size:13px;color:#374151;font-weight:500;text-align:center;padding:20px 10px;">${startDate}<br>—<br>${endDate}</div>`;
                html += '</div>';
            }
            
            html += '</div>';

            // --- Фильтры с каркасами ---
            html += '<div id="wfPlannerRoot" class="section-block wf-section"></div>';
            
            body.innerHTML = html;

            if (window.WireframePlanner) {
                window.WireframePlanner.mount(
                    document.getElementById('wfPlannerRoot'),
                    order,
                    data.wireframes || { filters_count: 0, positions_count: 0, positions: [] }
                );
            }
            
        } catch (error) {
            console.error('Ошибка загрузки анализа:', error);
            body.innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626;"><p>Ошибка: ' + error.message + '</p></div>';
        }
    }
    
    function closeAnalysis(){
        document.getElementById('analysisModal').style.display = 'none';
    }
    
    // Закрытие по клику вне модального окна
    document.getElementById('analysisModal').addEventListener('click', function(e){
        if (e.target === this) closeAnalysis();
    });

    // =====================================================================
    // Подсказка: фильтры с каркасами — пул + сетка смен «змейкой» (7 колонок)
    // =====================================================================
    window.WireframePlanner = (function () {
        const PLACES = 17;
        const DEFAULT_POURS = 50;
        const COLS = 7;
        const ROW_H = 18; // высота строки сетки (≈28/1.5)
        const LS_PREFIX = 'np_wireframe_hint:';

        let state = null;
        let rootEl = null;
        let dragPayload = null;

        function esc(s) {
            return String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function lsKey(order) {
            return LS_PREFIX + order;
        }

        /** Уникальный цвет на позицию: равномерно по кругу hue, без повторов */
        function colorFor(filter) {
            let idx = state.positions.findIndex(p => p.filter === filter);
            if (idx < 0) idx = 0;
            // золотое сечение — соседние позиции визуально дальше друг от друга
            const hue = Math.round((idx * 137.508) % 360);
            const sat = 62 + (idx % 3) * 6;
            const light = 72 - (idx % 4) * 3;
            return `hsl(${hue} ${sat}% ${light}%)`;
        }

        function getForms(filter) {
            const n = parseInt(state.forms[filter], 10);
            return n > 0 ? n : 1;
        }

        function shiftsNeeded(filter) {
            const pos = state.positions.find(p => p.filter === filter);
            if (!pos) return 0;
            const rate = getForms(filter) * state.pours;
            return rate > 0 ? Math.ceil(pos.count / rate) : 0;
        }

        function getPlacement(filter) {
            return state.placements.find(p => p.filter === filter) || null;
        }

        function isPlaced(filter) {
            return !!getPlacement(filter);
        }

        function orderEstimateShifts() {
            const total = state.positions.reduce((a, p) => a + p.count, 0);
            const den = state.pours * PLACES;
            return den > 0 ? Math.ceil(total / den) : 0;
        }

        function sumPositionShifts() {
            return state.positions.reduce((a, p) => a + shiftsNeeded(p.filter), 0);
        }

        function pluralRu(n, one, few, many) {
            const n10 = n % 10;
            const n100 = n % 100;
            if (n10 === 1 && n100 !== 11) return one;
            if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) return few;
            return many;
        }

        /** Статистика: сколько смен с какой суммарной загрузкой форм */
        function formsLoadStats() {
            let maxEnd = 0;
            state.placements.forEach(pl => {
                maxEnd = Math.max(maxEnd, pl.start + shiftsNeeded(pl.filter));
            });
            if (maxEnd <= 0) return [];

            const byForms = {};
            for (let t = 0; t < maxEnd; t++) {
                const f = loadAtShift(t, null);
                if (f <= 0) continue;
                byForms[f] = (byForms[f] || 0) + 1;
            }
            return Object.keys(byForms)
                .map(k => ({ forms: parseInt(k, 10), shifts: byForms[k] }))
                .sort((a, b) => b.forms - a.forms || b.shifts - a.shifts);
        }

        function renderStatsHtml() {
            const rows = formsLoadStats();
            if (!rows.length) {
                return '<div class="wf-stats wf-stats-empty">Статистика: разместите позиции в сетке</div>';
            }
            const parts = rows.map(r => {
                const dayWord = pluralRu(r.shifts, 'день', 'дня', 'дней');
                const fWord = pluralRu(r.forms, 'форма', 'формы', 'форм');
                return `<strong>${r.shifts}</strong> ${dayWord} по <strong>${r.forms}</strong> ${fWord}`;
            });
            return `<div class="wf-stats"><strong>Статистика:</strong> ${parts.join(' · ')}</div>`;
        }

        function timelineLen() {
            let maxEnd = state.extraShifts || COLS;
            state.placements.forEach(pl => {
                const len = shiftsNeeded(pl.filter);
                maxEnd = Math.max(maxEnd, pl.start + len);
            });
            // round up to full bands of 7
            return Math.max(COLS, Math.ceil(maxEnd / COLS) * COLS);
        }

        function loadAtShift(t, exceptFilter) {
            let used = 0;
            state.placements.forEach(pl => {
                if (exceptFilter && pl.filter === exceptFilter) return;
                const len = shiftsNeeded(pl.filter);
                if (t >= pl.start && t < pl.start + len) {
                    used += getForms(pl.filter);
                }
            });
            return used;
        }

        function canPlace(filter, start, exceptSelf) {
            const len = shiftsNeeded(filter);
            if (len <= 0 || start < 0) return false;
            const forms = getForms(filter);
            if (forms > PLACES) return false;
            for (let t = start; t < start + len; t++) {
                if (loadAtShift(t, exceptSelf ? filter : null) + forms > PLACES) return false;
            }
            return true;
        }

        function placeAt(filter, start) {
            const len = shiftsNeeded(filter);
            if (!canPlace(filter, start, true)) return false;
            const existing = getPlacement(filter);
            if (existing) {
                existing.start = start;
            } else {
                state.placements.push({ filter, start });
            }
            // ensure timeline covers end
            state.extraShifts = Math.max(state.extraShifts || COLS, start + len);
            return true;
        }

        function unplace(filter) {
            state.placements = state.placements.filter(p => p.filter !== filter);
        }

        function findEarliestStart(filter) {
            const len = shiftsNeeded(filter);
            if (len <= 0) return -1;
            const limit = timelineLen() + len + COLS * 4;
            for (let s = 0; s <= limit; s++) {
                if (canPlace(filter, s, true)) return s;
            }
            return -1;
        }

        function autoLayout() {
            state.placements = [];
            const ordered = state.positions
                .slice()
                .sort((a, b) => shiftsNeeded(b.filter) - shiftsNeeded(a.filter) || b.count - a.count);
            ordered.forEach(p => {
                const start = findEarliestStart(p.filter);
                if (start >= 0) placeAt(p.filter, start);
            });
            state.extraShifts = timelineLen();
        }

        function resetLayout() {
            state.placements = [];
            state.extraShifts = Math.max(COLS, orderEstimateShifts());
            state.extraShifts = Math.ceil(state.extraShifts / COLS) * COLS;
        }

        function saveLocal() {
            try {
                localStorage.setItem(lsKey(state.order), JSON.stringify({
                    v: 2,
                    pours: state.pours,
                    forms: state.forms,
                    placements: state.placements,
                    extraShifts: state.extraShifts,
                }));
                return true;
            } catch (e) {
                console.warn(e);
                return false;
            }
        }

        function loadLocal() {
            try {
                const raw = localStorage.getItem(lsKey(state.order));
                if (!raw) return false;
                const data = JSON.parse(raw);
                if (data.pours > 0) state.pours = parseInt(data.pours, 10) || DEFAULT_POURS;
                if (data.forms && typeof data.forms === 'object') {
                    state.forms = { ...state.forms, ...data.forms };
                }
                if (data.v === 2 && Array.isArray(data.placements)) {
                    const candidates = data.placements
                        .filter(p => p && p.filter && state.positions.some(x => x.filter === p.filter))
                        .map(p => ({
                            filter: p.filter,
                            start: Math.max(0, parseInt(p.start, 10) || 0),
                        }));
                    state.placements = [];
                    candidates.forEach(pl => {
                        if (canPlace(pl.filter, pl.start, false)) {
                            state.placements.push(pl);
                        }
                    });
                    if (data.extraShifts > 0) state.extraShifts = parseInt(data.extraShifts, 10);
                    state.extraShifts = timelineLen();
                    return true;
                }
                // old kanban format — ignore layout
                return !!(data.pours || data.forms);
            } catch (e) {
                console.warn(e);
                return false;
            }
        }

        function onPoursChange(val) {
            const next = Math.max(1, parseInt(val, 10) || DEFAULT_POURS);
            if (next === state.pours) return;
            if (state.placements.length &&
                !confirm('Изменение заливок сбросит раскладку по сменам. Продолжить?')) {
                render();
                return;
            }
            state.pours = next;
            resetLayout();
            render();
        }

        function onFormsChange(filter, val) {
            const next = Math.max(1, Math.min(PLACES, parseInt(val, 10) || 1));
            if (next === getForms(filter)) return;
            if (isPlaced(filter) &&
                !confirm('Изменение оснастки для ' + filter + ' уберёт её из сетки. Продолжить?')) {
                render();
                return;
            }
            unplace(filter);
            state.forms[filter] = next;
            render();
        }

        function clearDragClasses() {
            if (!rootEl) return;
            rootEl.querySelectorAll('.wf-drop-hover, .wf-drop-deny, .wf-dragging').forEach(el => {
                el.classList.remove('wf-drop-hover', 'wf-drop-deny', 'wf-dragging');
            });
        }

        /** Build lane matrix for a band: null | {filter,rowspan} | {skip:true}
         *  Высота блока = числу форм (оснастки). */
        function buildBandLanes(bandStart) {
            const covering = state.placements
                .map(pl => {
                    const len = shiftsNeeded(pl.filter);
                    return {
                        filter: pl.filter,
                        start: pl.start,
                        end: pl.start + len,
                        forms: Math.max(1, getForms(pl.filter)),
                    };
                })
                .filter(pl => pl.end > bandStart && pl.start < bandStart + COLS)
                .sort((a, b) => b.forms - a.forms || a.start - b.start || b.end - a.end);

            const lanes = [];

            function ensureHeight(h) {
                while (lanes.length < h) lanes.push(Array(COLS).fill(null));
            }

            function blockFree(laneStart, forms, pl) {
                ensureHeight(laneStart + forms);
                for (let r = 0; r < forms; r++) {
                    for (let c = 0; c < COLS; c++) {
                        const t = bandStart + c;
                        if (t < pl.start || t >= pl.end) continue;
                        if (lanes[laneStart + r][c] !== null) return false;
                    }
                }
                return true;
            }

            covering.forEach(pl => {
                const F = pl.forms;
                let laneIdx = -1;
                for (let li = 0; li < 500; li++) {
                    if (blockFree(li, F, pl)) {
                        laneIdx = li;
                        break;
                    }
                }
                if (laneIdx < 0) return;
                ensureHeight(laneIdx + F);
                for (let c = 0; c < COLS; c++) {
                    const t = bandStart + c;
                    if (t < pl.start || t >= pl.end) continue;
                    lanes[laneIdx][c] = { filter: pl.filter, rowspan: F };
                    for (let r = 1; r < F; r++) {
                        lanes[laneIdx + r][c] = { skip: true };
                    }
                }
            });

            if (!lanes.length) lanes.push(Array(COLS).fill(null));
            return lanes;
        }

        function bindDnD() {
            rootEl.querySelectorAll('[draggable="true"]').forEach(el => {
                el.addEventListener('dragstart', e => {
                    if (e.target && e.target.closest && e.target.closest('input,button,label')) {
                        e.preventDefault();
                        return;
                    }
                    const type = el.dataset.dragType;
                    dragPayload = {
                        type,
                        filter: el.dataset.filter,
                        grabOffset: type === 'seg' ? (parseInt(el.dataset.offset, 10) || 0) : 0,
                    };
                    el.classList.add('wf-dragging');
                    rootEl.querySelectorAll('.wf-seg').forEach(s => {
                        if (s.dataset.filter === el.dataset.filter) s.classList.add('wf-dragging');
                    });
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', el.dataset.filter || ''); } catch (_) {}
                });
                el.addEventListener('dragend', () => {
                    dragPayload = null;
                    clearDragClasses();
                });
            });

            rootEl.querySelectorAll('[data-drop]').forEach(zone => {
                zone.addEventListener('dragover', e => {
                    e.preventDefault();
                    if (!dragPayload) return;
                    let ok = false;
                    if (zone.dataset.drop === 'pool') {
                        ok = dragPayload.type === 'seg';
                    } else if (zone.dataset.drop === 'cell') {
                        const t = parseInt(zone.dataset.shift, 10);
                        const start = dragPayload.type === 'pool'
                            ? t
                            : t - dragPayload.grabOffset;
                        ok = canPlace(dragPayload.filter, start, dragPayload.type === 'seg');
                    }
                    zone.classList.toggle('wf-drop-hover', ok);
                    zone.classList.toggle('wf-drop-deny', !ok);
                    e.dataTransfer.dropEffect = ok ? 'move' : 'none';
                });
                zone.addEventListener('dragleave', () => {
                    zone.classList.remove('wf-drop-hover', 'wf-drop-deny');
                });
                zone.addEventListener('drop', e => {
                    e.preventDefault();
                    clearDragClasses();
                    if (!dragPayload) return;
                    let ok = false;
                    if (zone.dataset.drop === 'pool' && dragPayload.type === 'seg') {
                        unplace(dragPayload.filter);
                        ok = true;
                    } else if (zone.dataset.drop === 'cell') {
                        const t = parseInt(zone.dataset.shift, 10);
                        const start = dragPayload.type === 'pool'
                            ? t
                            : t - dragPayload.grabOffset;
                        ok = placeAt(dragPayload.filter, start);
                        if (!ok) {
                            alert('Нельзя поставить сюда: не хватает мест (лимит ' + PLACES + ' на смену) или старт < 0.');
                        }
                    }
                    dragPayload = null;
                    if (ok) render();
                });
            });
        }

        function renderGridHtml() {
            const len = timelineLen();
            const bands = Math.ceil(len / COLS);
            let html = '<div id="wfPrintRoot">';
            html += `<div class="wf-print-title">Предварительный план заливки фильтров с каркасами</div>`;
            html += `<div class="wf-print-meta">Заявка ${esc(state.order)} · заливок в смену: ${state.pours} · мест: ${PLACES}</div>`;
            html += '<div class="wf-grid-wrap">';
            for (let b = 0; b < bands; b++) {
                const bandStart = b * COLS;
                const lanes = buildBandLanes(bandStart);
                html += '<div class="wf-band"><table class="wf-band-table"><thead><tr>';
                for (let c = 0; c < COLS; c++) {
                    html += `<th>С${bandStart + c + 1}</th>`;
                }
                html += '</tr></thead><tbody>';
                lanes.forEach(lane => {
                    html += '<tr>';
                    for (let c = 0; c < COLS; c++) {
                        const t = bandStart + c;
                        const cell = lane[c];
                        if (cell && cell.skip) continue;

                        if (cell && cell.filter) {
                            const filter = cell.filter;
                            const rowspan = cell.rowspan || 1;
                            const pl = getPlacement(filter);
                            const offset = t - pl.start;
                            const lenPl = shiftsNeeded(filter);
                            const forms = getForms(filter);
                            const cls = [
                                'wf-seg',
                                rowspan > 1 ? 'wf-seg-tall' : '',
                                offset === 0 ? 'wf-seg-start' : '',
                                offset === lenPl - 1 ? 'wf-seg-end' : '',
                            ].filter(Boolean).join(' ');
                            const minH = rowspan * ROW_H;
                            html += `<td class="wf-cell" rowspan="${rowspan}" data-drop="cell" data-shift="${t}">`;
                            html += `<div class="${cls}" draggable="true" data-drag-type="seg"
                                data-filter="${esc(filter)}" data-offset="${offset}"
                                style="background:${colorFor(filter)};min-height:${minH}px"
                                title="${esc(filter)} · форм ${forms} · смены ${pl.start + 1}–${pl.start + lenPl}">${esc(filter)}</div>`;
                            html += '</td>';
                        } else {
                            html += `<td class="wf-cell" data-drop="cell" data-shift="${t}"></td>`;
                        }
                    }
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '</div></div>';
            return html;
        }

        function render() {
            if (!rootEl || !state) return;

            const poolEl = rootEl.querySelector('.wf-pool');
            const poolScroll = poolEl ? poolEl.scrollTop : 0;
            const modalBody = document.getElementById('modalBody');
            const modalScroll = modalBody ? modalBody.scrollTop : 0;

            const total = state.positions.reduce((a, p) => a + p.count, 0);
            const est = orderEstimateShifts();
            const sumSh = sumPositionShifts();

            let html = '<div class="section-title">Предварительный план заливки фильтров с каркасами</div>';

            if (!state.positions.length) {
                html += '<p class="wf-hint">В заявке нет позиций с каркасом (panel_filter_structure.wireframe).</p>';
                rootEl.innerHTML = html;
                return;
            }

            html += `<div class="wf-summary-grid">
                <div class="info-card">
                    <h4>С каркасами</h4>
                    <div class="info-value">${total}</div>
                    <div class="info-label">фильтров</div>
                </div>
                <div class="info-card">
                    <h4>Позиций</h4>
                    <div class="info-value">${state.positions.length}</div>
                    <div class="info-label">с каркасом</div>
                </div>
                <div class="info-card">
                    <h4>Смен (оценка)</h4>
                    <div class="info-value">${est}</div>
                    <div class="info-label">кол-во / (заливки × 17)</div>
                </div>
                <div class="info-card">
                    <h4>Сумма смен позиций</h4>
                    <div class="info-value">${sumSh}</div>
                    <div class="info-label">по оснастке</div>
                </div>
            </div>`;

            html += renderStatsHtml();

            html += `<div class="wf-toolbar">
                <label>Заливок в смену
                    <input type="number" id="wfPours" min="1" value="${state.pours}">
                </label>
                <span class="wf-hint" style="margin:0">Мест: ${PLACES} · ряд: ${COLS} смен</span>
                <button type="button" class="wf-btn" data-act="add-band">+7 смен</button>
                <button type="button" class="wf-btn wf-btn-primary" data-act="auto">Авторазложить</button>
                <button type="button" class="wf-btn" data-act="save">Сохранить</button>
                <button type="button" class="wf-btn" data-act="load">Загрузить</button>
                <button type="button" class="wf-btn" data-act="reset">Сбросить</button>
                <button type="button" class="wf-btn" data-act="print">Печать</button>
            </div>`;

            html += '<div class="wf-layout">';

            // Pool
            html += `<div class="wf-pool" data-drop="pool">
                <div class="wf-pool-title">Пул позиций</div>
                <div class="wf-hint" style="margin:0 0 0.4rem">Перетащите на ячейку сетки. Двигайте цветной блок змейкой по сменам.</div>`;

            state.positions.forEach(p => {
                const forms = getForms(p.filter);
                const need = shiftsNeeded(p.filter);
                const placed = isPlaced(p.filter);
                html += `<div class="wf-card${placed ? ' wf-done' : ''}"
                    draggable="${placed ? 'false' : 'true'}"
                    data-drag-type="pool"
                    data-filter="${esc(p.filter)}"
                    style="${placed ? '' : 'border-left:4px solid ' + colorFor(p.filter)}">
                    <div class="wf-card-name">${esc(p.filter)}</div>
                    <div class="wf-card-sub">${esc(p.wireframe || '')}</div>
                    <div class="wf-card-row">
                        <span>${p.count} шт · ${need} смен</span>
                        <label title="Комплекты оснастки">форм
                            <input type="number" min="1" max="${PLACES}" value="${forms}"
                                data-forms-for="${esc(p.filter)}">
                        </label>
                    </div>
                    <div class="wf-card-sub">${placed ? 'в сетке' : 'не размещена'} · норма ${forms * state.pours}/смену</div>
                </div>`;
            });
            html += '</div>';

            html += '<div>' + renderGridHtml() + '</div>';
            html += '</div>';

            html += '<p class="wf-hint">Сетка без дат: столбцы — смены (по 7 в ряд, дальше — следующий блок). Позиция занимает подряд ceil(qty/(форм×заливки)) смен. Перетаскивание двигает весь отрезок; сброс в пул — бросить на пул слева.</p>';

            rootEl.innerHTML = html;

            const poolAfter = rootEl.querySelector('.wf-pool');
            if (poolAfter) poolAfter.scrollTop = poolScroll;
            if (modalBody) modalBody.scrollTop = modalScroll;

            const poursInput = rootEl.querySelector('#wfPours');
            if (poursInput) {
                poursInput.addEventListener('change', () => onPoursChange(poursInput.value));
            }

            rootEl.querySelectorAll('[data-forms-for]').forEach(inp => {
                inp.addEventListener('click', e => e.stopPropagation());
                inp.addEventListener('mousedown', e => e.stopPropagation());
                inp.addEventListener('change', () => onFormsChange(inp.dataset.formsFor, inp.value));
            });

            rootEl.querySelectorAll('[data-act]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const act = btn.dataset.act;
                    if (act === 'add-band') {
                        state.extraShifts = timelineLen() + COLS;
                        render();
                    } else if (act === 'auto') {
                        autoLayout();
                        render();
                    } else if (act === 'save') {
                        alert(saveLocal() ? 'Сохранено в браузере.' : 'Не удалось сохранить.');
                    } else if (act === 'load') {
                        if (loadLocal()) {
                            render();
                            alert('Загружено из браузера.');
                        } else {
                            alert('Нет сохранённой раскладки для этой заявки.');
                        }
                    } else if (act === 'reset') {
                        if (confirm('Сбросить раскладку и параметры?')) {
                            state.pours = DEFAULT_POURS;
                            state.forms = {};
                            state.positions.forEach(p => { state.forms[p.filter] = 1; });
                            resetLayout();
                            try { localStorage.removeItem(lsKey(state.order)); } catch (_) {}
                            render();
                        }
                    } else if (act === 'print') {
                        saveLocal();
                        document.body.classList.add('wf-print-grid');
                        const cleanup = () => {
                            document.body.classList.remove('wf-print-grid');
                            window.removeEventListener('afterprint', cleanup);
                        };
                        window.addEventListener('afterprint', cleanup);
                        setTimeout(() => window.print(), 50);
                    }
                });
            });

            // double-click seg to unplace
            rootEl.querySelectorAll('.wf-seg').forEach(seg => {
                seg.addEventListener('dblclick', () => {
                    unplace(seg.dataset.filter);
                    render();
                });
            });

            bindDnD();
        }

        function mount(el, order, wireframes) {
            rootEl = el;
            const positions = (wireframes.positions || []).map(p => ({
                filter: String(p.filter || ''),
                count: parseInt(p.count, 10) || 0,
                wireframe: String(p.wireframe || ''),
            })).filter(p => p.filter && p.count > 0);

            state = {
                order: String(order || ''),
                pours: DEFAULT_POURS,
                forms: {},
                positions,
                placements: [],
                extraShifts: COLS,
            };
            positions.forEach(p => { state.forms[p.filter] = 1; });

            const loaded = loadLocal();
            if (!loaded) resetLayout();
            render();
        }

        return { mount };
    })();
</script>
</body>
</html>
