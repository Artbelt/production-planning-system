<?php
// NP_cut_index.php
$dsn = "mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4";
$user = "root"; $pass = "";

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

        $pdo = new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
        ]);

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

        $pdo = new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
        ]);
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

        $pdo = new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
        ]);
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
    $pdo = new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    // Статусы заявок (для plan нет поля status, убираем его)
$orders = $pdo->query("
    SELECT DISTINCT order_number, cut_ready, cut_confirmed, plan_ready, corr_ready, build_ready
    FROM orders
    WHERE hide IS NULL OR hide != 1
    ORDER BY order_number
")->fetchAll(PDO::FETCH_ASSOC);

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
            max-width: 900px;
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
                <div class="application-card">
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
                                    <button class="btn-outline btn-sm btn-icon" onclick="window.open('show_bill.php?order=<?= urlencode($ord) ?>', '_blank')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
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
    
    // Редактирование раскроя с предупреждением
    function editCutPlan(order){
        if (confirm(
            '⚠️ ВНИМАНИЕ!\n\n' +
            'При редактировании раскроя нарушится синхронизация с остальными частями плана:\n\n' +
            '• План раскроя рулона\n' +
            '• План гофрирования\n' +
            '• План сборки\n\n' +
            'Вероятно, их придется переделывать заново.\n\n' +
            'Продолжить редактирование?'
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
            
            // Прогресс по этапам
            if (data.progress) {
                html += `
                    <div class="info-card">
                        <h4>Раскрой</h4>
                        <div class="info-value">${data.progress.cut || 0}%</div>
                        <div class="info-label">выполнено</div>
                    </div>
                    <div class="info-card">
                        <h4>Гофрирование</h4>
                        <div class="info-value">${data.progress.corr || 0}%</div>
                        <div class="info-label">выполнено</div>
                    </div>
                    <div class="info-card">
                        <h4>Сборка</h4>
                        <div class="info-value">${data.progress.build || 0}%</div>
                        <div class="info-label">выполнено</div>
                    </div>
                `;
            }
            
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
            
            body.innerHTML = html;
            
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
</script>
</body>
</html>
