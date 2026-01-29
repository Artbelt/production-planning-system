<?php
// buffer_stock.php — буфер гофропакетов (что сгофрировано и еще не собрано)

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$dsn  = "mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4";
$user = "root";
$pass = "";

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Возвращает массив остатков буфера по (order_number, filter_label)
 * Поля строки: order_number, filter_label, corrugated, assembled, buffer, last_corr_date, last_ass_date, is_ignored
 *
 * Фильтры:
 * - date_from (Y-m-d)  — нижняя граница для план/факт (опционально)
 * - date_to   (Y-m-d)  — верхняя граница (опционально)
 * - order               — конкретная заявка (опционально)
 * - filter              — конкретный фильтр (опционально)
 * - include_zero        — если true, показывать и buffer<=0
 * - show_ignored        — если true, показывать игнорируемые позиции
 */
function get_buffer(PDO $pdo, array $opts = []): array {
    $date_from   = $opts['date_from'] ?? null;   // применяем отдельно к каждой подвыборке
    $date_to     = $opts['date_to']   ?? null;
    $order       = $opts['order']     ?? null;
    $filter      = $opts['filter']    ?? null;
    $includeZero = !empty($opts['include_zero']);
    $showIgnored = !empty($opts['show_ignored']);

    // --- подзапрос по гофре (что произведено) ---
    // Теперь используем manufactured_corrugated_packages вместо fact_count из corrugation_plan
    $wCorr = ["mcp.count > 0"];
    $paramsCorr = [];
    if ($date_from) { $wCorr[] = "mcp.date_of_production >= ?"; $paramsCorr[] = $date_from; }
    if ($date_to)   { $wCorr[] = "mcp.date_of_production <= ?"; $paramsCorr[] = $date_to; }
    if ($order)     { $wCorr[] = "mcp.order_number = ?"; $paramsCorr[] = $order; }
    if ($filter)    { $wCorr[] = "mcp.filter_label = ?"; $paramsCorr[] = $filter; }
    $whereCorr = $wCorr ? ("WHERE ".implode(" AND ", $wCorr)) : "";

    $corrSub = "
        SELECT
            mcp.order_number,
            mcp.filter_label,
            SUM(COALESCE(mcp.count,0)) AS corrugated,
            MAX(mcp.date_of_production) AS last_corr_date,
            COALESCE(
                GROUP_CONCAT(
                    CONCAT(mcp.date_of_production, ':', mcp.count) 
                    ORDER BY mcp.date_of_production DESC 
                    SEPARATOR '|'
                ),
                ''
            ) AS corr_details
        FROM manufactured_corrugated_packages mcp
        $whereCorr
        GROUP BY mcp.order_number, mcp.filter_label
    ";

    // --- подзапрос по сборке (что уже забрали) ---
    $wAsm = [];
    $paramsAsm = [];
    if ($date_from) { $wAsm[] = "m.date_of_production >= ?"; $paramsAsm[] = $date_from; }
    if ($date_to)   { $wAsm[] = "m.date_of_production <= ?"; $paramsAsm[] = $date_to; }
    if ($order)     { $wAsm[] = "m.name_of_order = ?";       $paramsAsm[] = $order; }
    if ($filter)    { $wAsm[] = "m.name_of_filter = ?";      $paramsAsm[] = $filter; }
    $whereAsm = $wAsm ? ("WHERE ".implode(" AND ", $wAsm)) : "";

    $asmSub = "
        SELECT
            m.name_of_order  AS order_number,
            m.name_of_filter AS filter_label,
            SUM(COALESCE(m.count_of_filters,0)) AS assembled,
            MAX(m.date_of_production)           AS last_ass_date,
            COALESCE(
                GROUP_CONCAT(
                    CONCAT(m.date_of_production, ':', m.count_of_filters) 
                    ORDER BY m.date_of_production DESC 
                    SEPARATOR '|'
                ),
                ''
            ) AS asm_details
        FROM manufactured_production m
        $whereAsm
        GROUP BY m.name_of_order, m.name_of_filter
    ";

    // --- объединение и расчёт буфера ---
    // берём LEFT JOIN, чтобы видеть даже то, что еще ни разу не собирали
    // Фильтруем только активные заявки (hide IS NULL OR hide != 1)
    $sql = "
        SELECT
            c.order_number,
            c.filter_label,
            c.corrugated,
            c.corr_details,
            COALESCE(a.assembled, 0) AS assembled,
            COALESCE(a.asm_details, '') AS asm_details,
            (c.corrugated - COALESCE(a.assembled, 0)) AS buffer,
            c.last_corr_date,
            a.last_ass_date,
            COALESCE(
                CAST(pps.p_p_height AS DECIMAL(10,3)),
                CAST(cp.height AS DECIMAL(10,3))
            ) AS height,
            IF(ign.id IS NOT NULL, 1, 0) AS is_ignored,
            GROUP_CONCAT(DISTINCT bp.brigade ORDER BY bp.plan_date SEPARATOR ', ') AS machines,
            COALESCE(ord.count, 0) AS order_count
        FROM ($corrSub) AS c
        LEFT JOIN ($asmSub) AS a
          ON a.order_number = c.order_number
         AND a.filter_label = c.filter_label
        LEFT JOIN orders ord
          ON ord.order_number = c.order_number
         AND ord.filter = c.filter_label
         AND (ord.hide IS NULL OR ord.hide != 1)
        LEFT JOIN salon_filter_structure sfs 
          ON sfs.filter = c.filter_label
        LEFT JOIN paper_package_salon pps 
          ON pps.p_p_name = sfs.paper_package
        LEFT JOIN (
            SELECT filter, height 
            FROM cut_plans 
            WHERE height IS NOT NULL 
            GROUP BY filter 
            HAVING COUNT(*) > 0
        ) cp ON cp.filter = c.filter_label
        LEFT JOIN buffer_ignored_items ign
          ON ign.order_number = c.order_number
         AND ign.filter_label = c.filter_label
        LEFT JOIN build_plan bp
          ON bp.order_number = c.order_number
         AND bp.filter = c.filter_label
         AND bp.count > 0
        WHERE EXISTS (
            SELECT 1 
            FROM orders o 
            WHERE o.order_number = c.order_number 
            AND (o.hide IS NULL OR o.hide != 1)
            LIMIT 1
        )
    ";

    $sql .= " GROUP BY c.order_number, c.filter_label";
    
    $havingConds = [];
    if (!$includeZero) {
        $havingConds[] = "buffer > 0";
    }
    if (!$showIgnored) {
        $havingConds[] = "is_ignored = 0";
    }
    
    if ($havingConds) {
        $sql .= " HAVING " . implode(" AND ", $havingConds);
    }

    $sql .= " ORDER BY height DESC, buffer DESC, c.order_number, c.filter_label";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($paramsCorr, $paramsAsm));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// === API для управления игнорируемыми позициями ===
if (isset($_POST['action']) && in_array($_POST['action'], ['ignore_item', 'unignore_item'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $action = $_POST['action'];
        $order_number = $_POST['order_number'] ?? '';
        $filter_label = $_POST['filter_label'] ?? '';

        if ($order_number === '' || $filter_label === '') {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'error'=>'Не указаны order_number или filter_label']);
            exit;
        }

        if ($action === 'ignore_item') {
            // Добавляем в игнор
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO buffer_ignored_items (order_number, filter_label, created_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$order_number, $filter_label, $_SERVER['REMOTE_USER'] ?? 'unknown']);
            echo json_encode(['ok'=>true, 'action'=>'ignored']);
        } elseif ($action === 'unignore_item') {
            // Удаляем из игнора
            $stmt = $pdo->prepare("
                DELETE FROM buffer_ignored_items 
                WHERE order_number = ? AND filter_label = ?
            ");
            $stmt->execute([$order_number, $filter_label]);
            echo json_encode(['ok'=>true, 'action'=>'unignored']);
        }
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        exit;
    }
}

// ---------- контроллер ----------
$format     = $_GET['format']     ?? null;      // 'json' | (html)
$date_from  = $_GET['date_from']  ?? null;
$date_to    = $_GET['date_to']    ?? null;
$order      = $_GET['order']      ?? null;
$filter     = $_GET['filter']     ?? null;
$includeZero= isset($_GET['include_zero']) && $_GET['include_zero'] == '1';
$showIgnored= isset($_GET['show_ignored']) && $_GET['show_ignored'] == '1';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // === Автомиграция: создаем таблицу для игнорируемых позиций ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS buffer_ignored_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL,
            filter_label VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(255) NULL,
            note TEXT NULL,
            UNIQUE KEY uniq_order_filter (order_number, filter_label),
            KEY idx_order (order_number),
            KEY idx_filter (filter_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $rows = get_buffer($pdo, [
        'date_from'    => $date_from,
        'date_to'      => $date_to,
        'order'        => $order,
        'filter'       => $filter,
        'include_zero' => $includeZero,
        'show_ignored' => $showIgnored,
    ]);

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true, 'count'=>count($rows), 'items'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Простая HTML-таблица для быстрых глаз
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
        <title>Буфер гофропакетов</title>
        <style>
            /* ===== Pro UI (neutral + single accent) ===== */
            :root{
                --bg:#f6f7f9;
                --panel:#ffffff;
                --ink:#1f2937;
                --muted:#6b7280;
                --border:#e5e7eb;
                --accent:#2457e6;
                --accent-ink:#ffffff;
                --radius:12px;
                --shadow:0 2px 12px rgba(2,8,20,.06);
                --shadow-soft:0 1px 8px rgba(2,8,20,.05);
            }
            html,body{height:100%}
            body{
                margin:0; background:var(--bg); color:var(--ink);
                font:14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
                -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
            }
            a{color:var(--accent); text-decoration:none}
            a:hover{text-decoration:underline}

            /* контейнер и сетка */
            .container{ max-width:1280px; margin:0 auto; padding:16px; }

            /* панели */
            .panel{
                background:var(--panel);
                border:1px solid var(--border);
                border-radius:var(--radius);
                box-shadow:var(--shadow);
                padding:16px;
                margin-bottom:16px;
            }
            .section-title{
                font-size:15px; font-weight:600; color:#111827;
                margin:0 0 12px; padding-bottom:6px; border-bottom:1px solid var(--border);
            }

            /* таблицы внутри панелей */
            .panel table{
                width:100%;
                border-collapse:collapse;
                background:#fff;
                border:1px solid var(--border);
                border-radius:10px;
                box-shadow:var(--shadow-soft);
                overflow:hidden;
            }
            .panel td,.panel th{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
            .panel tr:last-child td{border-bottom:0}
            th{background:#f8fafc; text-align:left; font-weight:600; color:var(--ink)}
            tr.highlight td{background:#fffbe6;}

            /* кнопки (единый стиль) */
            button, input[type="submit"]{
                appearance:none;
                border:1px solid transparent;
                cursor:pointer;
                background:var(--accent);
                color:var(--accent-ink);
                padding:7px 14px;
                border-radius:9px;
                font-weight:600;
                transition:background .2s, box-shadow .2s, transform .04s, border-color .2s;
                box-shadow:0 3px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08);
            }
            button:hover, input[type="submit"]:hover{ background:#1e47c5; box-shadow:0 2px 8px rgba(2,8,20,.10); transform:translateY(-1px); }
            button:active, input[type="submit"]:active{ transform:translateY(0); }
            button:disabled, input[type="submit"]:disabled{
                background:#e5e7eb; color:#9ca3af; border-color:#e5e7eb; box-shadow:none; cursor:not-allowed;
            }

            /* поля ввода/селекты */
            input[type="text"], input[type="date"], input[type="number"], input[type="password"],
            textarea, select{
                min-width:180px; padding:7px 10px;
                border:1px solid var(--border); border-radius:9px;
                background:#fff; color:var(--ink); outline:none;
                transition:border-color .2s, box-shadow .2s;
            }
            input:focus, textarea:focus, select:focus{
                border-color:#c7d2fe; box-shadow:0 0 0 3px #e0e7ff;
            }

            /* инфоблоки */
            .alert{
                background:#fffbe6; border:1px solid #f4e4a4; color:#634100;
                padding:10px; border-radius:9px; margin:12px 0; font-weight:600;
            }
            .muted{color:var(--muted); font-size:12px}

            /* специфичные стили */
            .num{text-align:right; font-weight:500}
            .col-machine{text-align:center; font-weight:600; color:var(--ink);}
            .filters{
                display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; 
                align-items:center; padding:12px; background:var(--panel); 
                border-radius:var(--radius); border:1px solid var(--border);
                box-shadow:var(--shadow-soft);
            }
            .filters input{padding:7px 10px; border:1px solid var(--border); border-radius:9px}
            .filters button{padding:7px 14px; border-radius:9px; font-weight:600}
            .tag{font-size:12px; color:var(--muted); display:flex; align-items:center; gap:6px}
            .tag input[type="checkbox"]{margin:0}
            
            /* игнорируемые позиции */
            tr.ignored-row{
                background:#f3f4f6 !important; 
                opacity:0.6; 
                text-decoration:line-through;
            }
            
            /* кнопки действий */
            .btn-ignore, .btn-unignore{
                padding:4px 10px; 
                font-size:14px; 
                border-radius:6px; 
                border:1px solid; 
                cursor:pointer; 
                transition:all 0.2s;
                background:transparent;
            }
            .btn-ignore{
                color:#ef4444; 
                border-color:#ef4444;
            }
            .btn-ignore:hover{
                background:#ef4444; 
                color:white;
            }
            .btn-unignore{
                color:#10b981; 
                border-color:#10b981;
            }
            .btn-unignore:hover{
                background:#10b981; 
                color:white;
            }
            
            /* сортировка */
            .sortable{cursor:pointer; user-select:none; position:relative; transition:background-color 0.2s}
            .sortable:hover{background-color:#f1f5f9}
            .sortable::after{content:''; position:absolute; right:8px; top:50%; transform:translateY(-50%); width:0; height:0; border-left:4px solid transparent; border-right:4px solid transparent; opacity:0.5}
            .sortable.asc::after{border-bottom:6px solid var(--accent); opacity:1}
            .sortable.desc::after{border-top:6px solid var(--accent); opacity:1}

            /* итоги */
            #totals{
                margin-top:16px; font-weight:600; padding:12px; 
                background:var(--panel); border-radius:var(--radius); 
                border:1px solid var(--border); box-shadow:var(--shadow-soft);
                color:var(--ink);
            }

            /* Стили для кнопки детализации */
            .btn-details{
                background:transparent;
                border:1px solid var(--border);
                padding:4px 8px;
                border-radius:6px;
                cursor:pointer;
                font-size:16px;
                transition:all 0.2s;
            }
            .btn-details:hover{
                background:var(--accent);
                border-color:var(--accent);
                transform:scale(1.1);
            }

            /* Стили для tooltip при наведении на столбец "Сгофрировано" */
            .col-corrugated{
                position:relative;
                cursor:help;
            }
            .corr-tooltip{
                position:absolute;
                bottom:100%;
                left:50%;
                transform:translateX(-50%);
                margin-bottom:8px;
                background:#1f2937;
                color:#fff;
                padding:10px 14px;
                border-radius:8px;
                font-size:12px;
                z-index:10000;
                box-shadow:0 4px 12px rgba(0,0,0,0.3);
                opacity:0;
                pointer-events:none;
                transition:opacity 0.2s;
                max-width:300px;
                min-width:200px;
                white-space:normal;
            }
            .corr-tooltip::after{
                content:'';
                position:absolute;
                top:100%;
                left:50%;
                transform:translateX(-50%);
                border:6px solid transparent;
                border-top-color:#1f2937;
            }
            .col-corrugated:hover .corr-tooltip{
                opacity:1;
            }
            .corr-tooltip-title{
                font-weight:600;
                margin-bottom:6px;
                padding-bottom:6px;
                border-bottom:1px solid rgba(255,255,255,0.2);
            }
            .corr-tooltip-item{
                margin:4px 0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .corr-tooltip-date{
                color:#d1d5db;
            }
            .corr-tooltip-count{
                font-weight:600;
                color:#fff;
            }
            
            /* Стили для tooltip при наведении на столбец "Собрано" */
            .col-assembled{
                position:relative;
                cursor:help;
            }
            .asm-tooltip{
                position:absolute;
                bottom:100%;
                left:50%;
                transform:translateX(-50%);
                margin-bottom:8px;
                background:#1f2937;
                color:#fff;
                padding:10px 14px;
                border-radius:8px;
                font-size:12px;
                z-index:10000;
                box-shadow:0 4px 12px rgba(0,0,0,0.3);
                opacity:0;
                pointer-events:none;
                transition:opacity 0.2s;
                max-width:300px;
                min-width:200px;
                white-space:normal;
            }
            .asm-tooltip::after{
                content:'';
                position:absolute;
                top:100%;
                left:50%;
                transform:translateX(-50%);
                border:6px solid transparent;
                border-top-color:#1f2937;
            }
            .col-assembled:hover .asm-tooltip{
                opacity:1;
            }
            .asm-tooltip-title{
                font-weight:600;
                margin-bottom:6px;
                padding-bottom:6px;
                border-bottom:1px solid rgba(255,255,255,0.2);
            }
            .asm-tooltip-item{
                margin:4px 0;
                display:flex;
                justify-content:space-between;
                gap:12px;
            }
            .asm-tooltip-date{
                color:#d1d5db;
            }
            .asm-tooltip-count{
                font-weight:600;
                color:#fff;
            }
            
            /* На мобильных устройствах tooltip показываем только при клике */
            @media (max-width:768px){
                .col-corrugated:hover .corr-tooltip{
                    opacity:0;
                }
                .col-corrugated.active .corr-tooltip{
                    opacity:1;
                }
                .col-assembled:hover .asm-tooltip{
                    opacity:0;
                }
                .col-assembled.active .asm-tooltip{
                    opacity:1;
                }
            }

            /* Модальное окно детальной информации */
            .modal-details{
                position:fixed;
                top:0;
                left:0;
                right:0;
                bottom:0;
                z-index:9999;
                display:flex;
                align-items:center;
                justify-content:center;
            }
            .modal-details-overlay{
                position:absolute;
                top:0;
                left:0;
                right:0;
                bottom:0;
                background:rgba(0,0,0,0.5);
                backdrop-filter:blur(2px);
            }
            .modal-details-content{
                position:relative;
                background:var(--panel);
                border-radius:12px;
                box-shadow:0 10px 40px rgba(0,0,0,0.3);
                width:90%;
                max-width:500px;
                max-height:85vh;
                overflow-y:auto;
                z-index:10000;
                animation:modalSlideIn 0.3s ease-out;
            }
            @keyframes modalSlideIn{
                from{
                    opacity:0;
                    transform:translateY(30px) scale(0.95);
                }
                to{
                    opacity:1;
                    transform:translateY(0) scale(1);
                }
            }
            .modal-details-header{
                display:flex;
                justify-content:space-between;
                align-items:center;
                padding:16px 20px;
                border-bottom:1px solid var(--border);
            }
            .modal-details-header h3{
                margin:0;
                font-size:18px;
                font-weight:600;
                color:var(--ink);
            }
            .modal-close-btn{
                background:transparent;
                border:none;
                font-size:24px;
                color:var(--muted);
                cursor:pointer;
                padding:0;
                width:32px;
                height:32px;
                display:flex;
                align-items:center;
                justify-content:center;
                border-radius:6px;
                transition:all 0.2s;
            }
            .modal-close-btn:hover{
                background:#f3f4f6;
                color:var(--ink);
            }
            .modal-details-body{
                padding:20px;
            }
            .detail-row{
                display:flex;
                justify-content:space-between;
                align-items:center;
                padding:12px 0;
                border-bottom:1px solid #f0f0f0;
            }
            .detail-row:last-child{
                border-bottom:none;
            }
            .detail-label{
                font-weight:600;
                color:var(--muted);
                font-size:14px;
            }
            .detail-value{
                font-weight:500;
                color:var(--ink);
                font-size:15px;
                text-align:right;
            }
            .detail-value-big{
                font-weight:700;
                color:var(--accent);
                font-size:24px;
                text-align:right;
            }
            .highlight-row{
                background:#f0f9ff;
                margin:0 -10px;
                padding:16px 10px !important;
                border-radius:8px;
                border:2px solid var(--accent) !important;
            }
            .detail-separator{
                height:1px;
                background:var(--border);
                margin:8px 0;
            }
            .modal-details-footer{
                padding:16px 20px;
                border-top:1px solid var(--border);
            }

            /* адаптив для мобильных устройств */
            @media (max-width:768px){
                /* Контейнер */
                .container{padding:8px}
                
                /* Панели */
                .panel{padding:12px; margin-bottom:12px; border-radius:8px}
                .section-title{font-size:14px; margin-bottom:8px}
                
                /* СКРЫВАЕМ кнопку назад на мобильных */
                .back-button{
                    display:none !important;
                }
                
                /* СКРЫВАЕМ панель фильтров на мобильных */
                .filters-panel{
                    display:none !important;
                }
                
                /* СКРЫВАЕМ подсказку о прокрутке */
                #scrollHint{
                    display:none !important;
                }
                
                /* Таблица - упрощенная версия для мобильных */
                .panel table{
                    font-size:13px;
                    width:100%;
                }
                .panel td, .panel th{
                    padding:10px 6px;
                    font-size:13px;
                }
                .panel th{
                    font-size:12px;
                    padding:8px 4px;
                }
                
                /* Показываем только нужные колонки на мобильных */
                /* Скрываем: Заявка, Сгофрировано, Собрано, Последняя гофра, Последняя сборка, Действия */
                .col-order,
                .col-corrugated, 
                .col-assembled, 
                .col-last-corr, 
                .col-last-asm,
                .col-actions{
                    display:none !important;
                }
                
                /* Колонка с деталями */
                .col-details{
                    width:50px;
                    text-align:center;
                }
                
                /* Кнопка детализации */
                .btn-details{
                    padding:8px 10px;
                    font-size:18px;
                    min-width:44px;
                    min-height:44px;
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                }
                
                /* Колонка фильтра - расширяем, так как убрали заявку */
                .col-filter{
                    min-width:120px;
                }
                
                /* Колонка высоты */
                .col-height{
                    width:60px;
                    text-align:center;
                }
                /* Сокращенные заголовки для мобильных */
                th.col-height::before{
                    content:'H, мм';
                    display:block;
                }
                th.col-height{
                    font-size:0;
                }
                th.col-height::before{
                    font-size:11px;
                }
                
                /* Колонка машины */
                .col-machine{
                    width:50px;
                    text-align:center;
                    font-size:13px;
                    font-weight:700;
                    color:var(--ink);
                }
                
                th.col-buffer::before{
                    content:'📦 Буфер';
                    display:block;
                    font-size:12px;
                }
                
                /* Колонка буфера - самая важная */
                .col-buffer{
                    min-width:80px;
                    text-align:center;
                    background:#f0f9ff;
                }
                .col-buffer strong{
                    font-size:16px;
                    color:var(--accent);
                }
                
                /* Итоги */
                #totals{
                    font-size:16px;
                    padding:14px;
                    text-align:center;
                    font-weight:700;
                }
                
                /* Увеличиваем размер текста для лучшей читаемости */
                body{font-size:14px}
                
                /* Модальное окно на мобильных */
                .modal-details-content{
                    width:95%;
                    max-height:90vh;
                }
                .modal-details-body{
                    padding:16px;
                }
                .detail-row{
                    padding:10px 0;
                }
                .detail-value-big{
                    font-size:28px;
                }
            }
            
            /* Дополнительные улучшения для сенсорных устройств */
            @media (hover: none) and (pointer: coarse){
                /* Увеличиваем размер кликабельных элементов */
                button, input[type="submit"], .btn-ignore, .btn-unignore{
                    min-height:44px;
                    min-width:44px;
                }
                
                /* Увеличиваем размер чекбоксов */
                input[type="checkbox"]{
                    width:20px;
                    height:20px;
                    cursor:pointer;
                }
                
                /* Улучшаем интерактивность кнопок */
                button:active, input[type="submit"]:active{
                    transform:scale(0.98);
                }
            }
            
            /* Ориентация альбом для планшетов */
            @media (min-width:769px) and (max-width:1024px){
                .container{max-width:100%; padding:12px}
                .panel table{font-size:13px}
            }
        </style>

    </head>
    <body>
    <div class="container">
        <div class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                <div>
                    <div class="section-title" style="margin-bottom:4px;">Буфер гофропакетов</div>
                    <p class="muted" style="margin:0;">Наличие заготовок в буфере (гофра → сборка)</p>
                </div>
                <a href="main.php" style="text-decoration:none;" class="back-button">
                    <button type="button" style="padding:8px 16px; font-size:14px; white-space:nowrap;">
                        ← Назад
                    </button>
                </a>
            </div>
        </div>

        <div class="panel filters-panel">
            <div class="section-title">Фильтры</div>
            <?php
            // Получаем список заявок для выпадающего списка
            // Только те заявки, у которых есть положительные остатки в буфере
            // И только активные заявки (hide IS NULL OR hide != 1)
            $ordersList = [];
            try {
                $ordersStmt = $pdo->query("
                    SELECT DISTINCT c.order_number
                    FROM (
                        SELECT 
                            mcp.order_number,
                            mcp.filter_label,
                            SUM(COALESCE(mcp.count, 0)) AS corrugated
                        FROM manufactured_corrugated_packages mcp
                        GROUP BY mcp.order_number, mcp.filter_label
                    ) AS c
                    LEFT JOIN (
                        SELECT 
                            m.name_of_order AS order_number,
                            m.name_of_filter AS filter_label,
                            SUM(COALESCE(m.count_of_filters, 0)) AS assembled
                        FROM manufactured_production m
                        GROUP BY m.name_of_order, m.name_of_filter
                    ) AS a ON a.order_number = c.order_number AND a.filter_label = c.filter_label
                    WHERE (c.corrugated - COALESCE(a.assembled, 0)) > 0
                    AND EXISTS (
                        SELECT 1 
                        FROM orders o 
                        WHERE o.order_number = c.order_number 
                        AND (o.hide IS NULL OR o.hide != 1)
                        LIMIT 1
                    )
                    ORDER BY c.order_number ASC
                ");
                $ordersList = $ordersStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) {
                // Если ошибка, оставляем пустой список
            }
            ?>
            <form class="filters" method="get">
                <input type="date" name="date_from" value="<?=h($date_from)?>" placeholder="От даты">
                <input type="date" name="date_to"   value="<?=h($date_to)?>" placeholder="До даты">
                <select name="order">
                    <option value="">Все заявки</option>
                    <?php foreach ($ordersList as $orderItem): ?>
                        <option value="<?=h($orderItem)?>" <?= $order === $orderItem ? 'selected' : '' ?>><?=h($orderItem)?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="filter"    value="<?=h($filter)?>"  placeholder="Фильтр (filter_label)">
                <label class="tag"><input type="checkbox" name="include_zero" value="1" <?= $includeZero?'checked':''; ?>> показывать нули/минусы</label>
                <label class="tag"><input type="checkbox" name="show_ignored" value="1" <?= $showIgnored?'checked':''; ?>> показать игнорируемые</label>
                <button type="submit">Показать</button>
                <label class="tag">
                    <input type="checkbox" id="hideSmall"> Скрывать остатки меньше 30
                </label>
            </form>
        </div>

        <div class="panel">
            <div class="section-title">Данные буфера</div>
            <div id="scrollHint" style="display:none; background:#e0e7ff; border:1px solid #c7d2fe; color:#3730a3; padding:8px 12px; border-radius:6px; margin-bottom:12px; font-size:13px; text-align:center;">
                👈 Прокрутите таблицу влево-вправо для просмотра всех данных 👉
            </div>
            <table>
                <thead>
                <tr>
                    <th class="col-details">👁</th>
                    <th class="sortable col-order" data-column="1">Заявка</th>
                    <th class="sortable col-filter" data-column="2">Фильтр</th>
                    <th class="sortable num col-height" data-column="3">Высота (мм)</th>
                    <th class="sortable col-machine" data-column="4">Машина</th>
                    <th class="sortable num col-corrugated" data-column="5">Сгофрировано</th>
                    <th class="sortable num col-assembled" data-column="6">Собрано</th>
                    <th class="sortable num col-buffer" data-column="7">Буфер</th>
                    <th class="sortable col-last-corr" data-column="8">Последняя гофра</th>
                    <th class="sortable col-last-asm" data-column="9">Последняя сборка</th>
                    <th class="col-actions">Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="tag">Нет данных под выбранные фильтры.</td></tr>
                <?php else: foreach ($rows as $r): 
                    $isIgnored = !empty($r['is_ignored']);
                    $rowClass = '';
                    if ($isIgnored) {
                        $rowClass = 'ignored-row';
                    } elseif ($r['buffer'] > 0) {
                        $rowClass = 'highlight';
                    }
                ?>
                    <tr class="<?=$rowClass?>" 
                        data-order="<?=h($r['order_number'])?>" 
                        data-filter="<?=h($r['filter_label'])?>"
                        data-height="<?= $r['height'] ? rtrim(rtrim(number_format((float)$r['height'], 1, '.', ' '), '0'), '.') : '-' ?>"
                        data-corrugated="<?=number_format((float)$r['corrugated'], 0, '.', ' ')?>"
                        data-assembled="<?=number_format((float)$r['assembled'], 0, '.', ' ')?>"
                        data-buffer="<?=number_format((float)$r['buffer'], 0, '.', ' ')?>"
                        data-last-corr="<?=h($r['last_corr_date'] ?? 'Не указано')?>"
                        data-last-asm="<?=h($r['last_ass_date'] ?? 'Не указано')?>"
                        data-machines="<?=h($r['machines'] ?? 'Не назначено')?>"
                        data-order-count="<?=number_format((float)$r['order_count'], 0, '.', ' ')?>"
                        data-corr-details="<?=htmlspecialchars($r['corr_details'] ?? '', ENT_QUOTES, 'UTF-8')?>"
                        data-asm-details="<?=htmlspecialchars($r['asm_details'] ?? '', ENT_QUOTES, 'UTF-8')?>"
                        data-ignored="<?=$isIgnored?'1':'0'?>">
                        <td class="col-details">
                            <button type="button" class="btn-details" onclick="showDetails(this)" title="Подробнее">
                                👁
                            </button>
                        </td>
                        <td class="col-order"><?=h($r['order_number'])?></td>
                        <td class="col-filter"><strong><?=h($r['filter_label'])?></strong></td>
                        <td class="num col-height"><?= $r['height'] ? rtrim(rtrim(number_format((float)$r['height'], 1, '.', ' '), '0'), '.') : '-' ?></td>
                        <td class="col-machine"><?=h($r['machines'] ?? '-')?></td>
                        <td class="num col-corrugated">
                            <?=number_format((float)$r['corrugated'], 0, '.', ' ')?>
                            <div class="corr-tooltip"></div>
                        </td>
                        <td class="num col-assembled">
                            <?=number_format((float)$r['assembled'],   0, '.', ' ')?>
                            <div class="asm-tooltip"></div>
                        </td>
                        <td class="num col-buffer"><strong><?=number_format((float)$r['buffer'], 0, '.', ' ')?></strong></td>
                        <td class="col-last-corr"><?=h($r['last_corr_date'] ?? '')?></td>
                        <td class="col-last-asm"><?=h($r['last_ass_date']  ?? '')?></td>
                        <td class="col-actions" style="text-align:center;">
                            <?php if ($isIgnored): ?>
                                <button type="button" class="btn-unignore" data-order="<?=h($r['order_number'])?>" data-filter="<?=h($r['filter_label'])?>" title="Вернуть в буфер">↩️</button>
                            <?php else: ?>
                                <button type="button" class="btn-ignore" data-order="<?=h($r['order_number'])?>" data-filter="<?=h($r['filter_label'])?>" title="Игнорировать">✖</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="panel">
            <div id="totals">
                Итого буфер: 0
            </div>
        </div>
    </div>

    <!-- Модальное окно для детальной информации -->
    <div id="detailsModal" class="modal-details" style="display:none;">
        <div class="modal-details-overlay" onclick="closeDetailsModal()"></div>
        <div class="modal-details-content">
            <div class="modal-details-header">
                <h3 id="modalTitle">Детали позиции</h3>
                <button type="button" class="modal-close-btn" onclick="closeDetailsModal()">✖</button>
            </div>
            <div class="modal-details-body">
                <div class="detail-row">
                    <span class="detail-label">Заявка:</span>
                    <span class="detail-value" id="detailOrder">-</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Высота:</span>
                    <span class="detail-value" id="detailHeight">-</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">В заявке заказано:</span>
                    <span class="detail-value" id="detailOrderCount">-</span>
                </div>
                <div class="detail-row" style="background:#f0fdf4; margin:0 -10px; padding:12px 10px; border-radius:8px; border:1px solid #86efac;">
                    <span class="detail-label" style="color:#15803d;">Назначено машине:</span>
                    <span class="detail-value" id="detailMachines" style="font-weight:700; color:#15803d; font-size:16px;">-</span>
                </div>
                <div class="detail-separator"></div>
                <div class="detail-row highlight-row">
                    <span class="detail-label">В буфере:</span>
                    <span class="detail-value-big" id="detailBuffer">-</span>
                </div>
                <div class="detail-separator"></div>
                <div class="detail-row">
                    <span class="detail-label">Сгофрировано:</span>
                    <span class="detail-value" id="detailCorrugated">-</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Последняя гофра:</span>
                    <span class="detail-value" id="detailLastCorr">-</span>
                </div>
                <div class="detail-separator"></div>
                <div class="detail-row">
                    <span class="detail-label">Собрано:</span>
                    <span class="detail-value" id="detailAssembled">-</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Последняя сборка:</span>
                    <span class="detail-value" id="detailLastAsm">-</span>
                </div>
            </div>
            <div class="modal-details-footer">
                <button type="button" onclick="closeDetailsModal()" style="width:100%; padding:12px; background:var(--accent); color:white; border:none; border-radius:8px; font-size:15px; font-weight:600;">
                    Закрыть
                </button>
            </div>
        </div>
    </div>

    <script>
        // === ФУНКЦИЯ ОТКРЫТИЯ МОДАЛЬНОГО ОКНА С ДЕТАЛЯМИ ===
        function showDetails(btn) {
            const row = btn.closest('tr');
            if (!row) return;
            
            // Получаем данные из data-атрибутов строки
            const order = row.dataset.order || '-';
            const filter = row.dataset.filter || '-';
            const height = row.dataset.height || '-';
            const buffer = row.dataset.buffer || '0';
            const corrugated = row.dataset.corrugated || '0';
            const assembled = row.dataset.assembled || '0';
            const lastCorr = row.dataset.lastCorr || 'Не указано';
            const lastAsm = row.dataset.lastAsm || 'Не указано';
            const machines = row.dataset.machines || 'Не назначено';
            const orderCount = row.dataset.orderCount || '0';
            
            // Заполняем модальное окно данными
            document.getElementById('detailOrder').textContent = order;
            document.getElementById('detailHeight').textContent = height + (height !== '-' ? ' мм' : '');
            document.getElementById('detailBuffer').textContent = buffer;
            document.getElementById('detailCorrugated').textContent = corrugated;
            document.getElementById('detailAssembled').textContent = assembled;
            document.getElementById('detailLastCorr').textContent = lastCorr;
            document.getElementById('detailLastAsm').textContent = lastAsm;
            document.getElementById('detailMachines').textContent = machines;
            document.getElementById('detailOrderCount').textContent = orderCount;
            
            // Обновляем заголовок модального окна
            document.getElementById('modalTitle').textContent = filter;
            
            // Показываем модальное окно
            document.getElementById('detailsModal').style.display = 'flex';
            
            // Блокируем прокрутку body
            document.body.style.overflow = 'hidden';
        }
        
        // === ФУНКЦИЯ ЗАКРЫТИЯ МОДАЛЬНОГО ОКНА ===
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
            // Разблокируем прокрутку body
            document.body.style.overflow = '';
        }
        
        // Закрытие по клавише Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDetailsModal();
            }
        });
        
        // Показываем подсказку о прокрутке на мобильных устройствах (только на десктопе)
        if (window.innerWidth > 768) {
            const hint = document.getElementById('scrollHint');
            if (hint) {
                hint.style.display = 'block';
                // Скрываем подсказку через 5 секунд
                setTimeout(() => {
                    hint.style.opacity = '0';
                    hint.style.transition = 'opacity 1s';
                    setTimeout(() => { hint.style.display = 'none'; }, 1000);
                }, 5000);
            }
        }
        
        document.getElementById('hideSmall').addEventListener('change', function() {
            const rows = document.querySelectorAll("table tbody tr");
            rows.forEach(tr => {
                const bufCell = tr.querySelector("td.col-buffer"); // Колонка буфера
                if (!bufCell) return;
                const val = parseInt(bufCell.textContent.replace(/\s+/g,'')) || 0;
                if (this.checked && val < 30) {
                    tr.style.display = "none";
                } else {
                    tr.style.display = "";
                }
            });
        });
    </script>
    <script>
        function refreshTotals() {
            let sum = 0;
            document.querySelectorAll("table tbody tr").forEach(tr => {
                if (tr.style.display === "none") return;
                // Не учитываем игнорируемые позиции в подсчете
                if (tr.classList.contains('ignored-row')) return;
                
                const bufCell = tr.querySelector("td.col-buffer"); // Колонка буфера
                if (bufCell) {
                    const val = parseInt(bufCell.textContent.replace(/\s+/g,'')) || 0;
                    sum += val;
                }
            });
            document.getElementById("totals").textContent = "Итого буфер: " + sum.toLocaleString("ru-RU");
        }

        document.getElementById('hideSmall').addEventListener('change', function() {
            const rows = document.querySelectorAll("table tbody tr");
            rows.forEach(tr => {
                const bufCell = tr.querySelector("td.col-buffer"); // Колонка буфера
                if (!bufCell) return;
                const val = parseInt(bufCell.textContent.replace(/\s+/g,'')) || 0;
                // Не скрываем игнорируемые строки, они уже отмечены визуально
                const isIgnored = tr.classList.contains('ignored-row');
                if (this.checked && val < 30 && !isIgnored) {
                    tr.style.display = "none";
                } else {
                    tr.style.display = "";
                }
            });
            refreshTotals();
        });

        // посчитать один раз при загрузке
        refreshTotals();
        
        // Функциональность сортировки таблицы
        let currentSort = { column: -1, direction: 'asc' };
        
        function sortTable(columnIndex) {
            const tbody = document.querySelector('table tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Определяем направление сортировки
            if (currentSort.column === columnIndex) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.direction = 'asc';
            }
            currentSort.column = columnIndex;
            
            // Сортируем строки
            rows.sort((a, b) => {
                const aCell = a.cells[columnIndex];
                const bCell = b.cells[columnIndex];
                
                if (!aCell || !bCell) return 0;
                
                let aValue = aCell.textContent.trim();
                let bValue = bCell.textContent.trim();
                
                // Обработка числовых значений
                if (columnIndex === 3 || (columnIndex >= 5 && columnIndex <= 7)) { // числовые колонки (высота, сгофрировано, собрано, буфер)
                    aValue = parseFloat(aValue.replace(/\s+/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/\s+/g, '')) || 0;
                } else if (columnIndex === 8 || columnIndex === 9) { // даты (последняя гофра, последняя сборка)
                    aValue = aValue === '' ? '0000-00-00' : aValue;
                    bValue = bValue === '' ? '0000-00-00' : bValue;
                }
                
                let comparison = 0;
                if (aValue < bValue) comparison = -1;
                else if (aValue > bValue) comparison = 1;
                
                return currentSort.direction === 'asc' ? comparison : -comparison;
            });
            
            // Перестраиваем таблицу
            rows.forEach(row => tbody.appendChild(row));
            
            // Обновляем визуальные индикаторы сортировки
            document.querySelectorAll('th.sortable').forEach((th, index) => {
                th.classList.remove('asc', 'desc');
                if (index === columnIndex) {
                    th.classList.add(currentSort.direction);
                }
            });
            
            // Пересчитываем итоги после сортировки
            refreshTotals();
        }
        
        // Добавляем обработчики кликов на заголовки
        document.querySelectorAll('th.sortable').forEach((th, index) => {
            th.addEventListener('click', () => sortTable(index));
        });
        
        // === ФУНКЦИОНАЛ ИГНОРИРОВАНИЯ ПОЗИЦИЙ ===
        
        // Функция для отправки запроса на игнорирование/разигнорирование
        async function toggleIgnore(orderNumber, filterLabel, action) {
            try {
                // Сохраняем текущую позицию прокрутки
                sessionStorage.setItem('buffer_scroll_position', window.scrollY);
                
                const formData = new FormData();
                formData.append('action', action);
                formData.append('order_number', orderNumber);
                formData.append('filter_label', filterLabel);
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    // Перезагружаем страницу для обновления данных
                    window.location.reload();
                } else {
                    alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Ошибка при выполнении операции: ' + error.message);
            }
        }
        
        // Восстанавливаем позицию прокрутки после перезагрузки
        window.addEventListener('load', function() {
            const savedPosition = sessionStorage.getItem('buffer_scroll_position');
            if (savedPosition !== null) {
                window.scrollTo(0, parseInt(savedPosition));
                // Очищаем сохраненную позицию
                sessionStorage.removeItem('buffer_scroll_position');
            }
        });
        
        // Обработчики для кнопок игнорирования
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-ignore')) {
                const order = e.target.dataset.order;
                const filter = e.target.dataset.filter;
                toggleIgnore(order, filter, 'ignore_item');
            } else if (e.target.classList.contains('btn-unignore')) {
                const order = e.target.dataset.order;
                const filter = e.target.dataset.filter;
                toggleIgnore(order, filter, 'unignore_item');
            }
        });
        
        // === ФУНКЦИОНАЛ TOOLTIP ДЛЯ СТОЛБЦА "СГОФРИРОВАНО" ===
        function formatCorrTooltip(corrDetails) {
            if (!corrDetails || corrDetails === '' || corrDetails === 'null' || corrDetails === 'NULL') {
                return '<div class="corr-tooltip-title">Нет данных о гофре</div>';
            }
            
            const items = corrDetails.split('|').filter(item => item && item.trim() !== '');
            if (items.length === 0) {
                return '<div class="corr-tooltip-title">Нет данных о гофре</div>';
            }
            
            let html = '<div class="corr-tooltip-title">Детализация по датам:</div>';
            let hasValidItems = false;
            
            items.forEach(item => {
                const parts = item.split(':');
                if (parts.length >= 2) {
                    const date = parts[0].trim();
                    const count = parseInt(parts[1]) || 0;
                    
                    if (date && !isNaN(count) && count > 0) {
                        hasValidItems = true;
                        // Форматируем дату в читаемый вид
                        try {
                            const dateObj = new Date(date + 'T00:00:00');
                            if (!isNaN(dateObj.getTime())) {
                                const formattedDate = dateObj.toLocaleDateString('ru-RU', { 
                                    year: 'numeric', 
                                    month: '2-digit', 
                                    day: '2-digit' 
                                });
                                html += `<div class="corr-tooltip-item">
                                    <span class="corr-tooltip-date">${formattedDate}</span>
                                    <span class="corr-tooltip-count">${count.toLocaleString('ru-RU')}</span>
                                </div>`;
                            }
                        } catch (e) {
                            // Если не удалось распарсить дату, пропускаем
                        }
                    }
                }
            });
            
            if (!hasValidItems) {
                return '<div class="corr-tooltip-title">Нет данных о гофре</div>';
            }
            
            return html;
        }
        
        // Функция для получения данных о гофре из строки таблицы
        function getCorrDetails(cell) {
            try {
                const row = cell.closest('tr');
                if (!row) return '';
                
                // Пробуем получить данные разными способами
                let corrDetails = row.getAttribute('data-corr-details');
                if (!corrDetails && row.dataset) {
                    corrDetails = row.dataset.corrDetails || '';
                }
                
                return corrDetails || '';
            } catch (e) {
                console.error('Ошибка при получении данных о гофре:', e);
                return '';
            }
        }
        
        // Инициализация tooltip для всех ячеек столбца "Сгофрировано"
        function initCorrTooltips() {
            try {
                const cells = document.querySelectorAll('.col-corrugated');
                if (!cells || cells.length === 0) return;
                
                cells.forEach(cell => {
                    const corrDetails = getCorrDetails(cell);
                    const tooltip = cell.querySelector('.corr-tooltip');
                    
                    if (tooltip) {
                        // Заполняем tooltip данными
                        tooltip.innerHTML = formatCorrTooltip(corrDetails);
                    }
                    
                    // Для мобильных устройств: показываем tooltip при клике
                    if (window.innerWidth <= 768) {
                        cell.addEventListener('click', function(e) {
                            e.stopPropagation();
                            // Закрываем другие открытые tooltip
                            document.querySelectorAll('.col-corrugated.active').forEach(activeCell => {
                                if (activeCell !== cell) {
                                    activeCell.classList.remove('active');
                                }
                            });
                            // Переключаем текущий tooltip
                            cell.classList.toggle('active');
                        });
                        
                        // Закрываем tooltip при клике вне ячейки
                        document.addEventListener('click', function(e) {
                            if (!cell.contains(e.target)) {
                                cell.classList.remove('active');
                            }
                        });
                    }
                });
            } catch (e) {
                console.error('Ошибка при инициализации tooltip:', e);
            }
        }
        
        // Инициализируем tooltip при загрузке страницы
        (function() {
            function tryInit() {
                try {
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function() {
                            setTimeout(initCorrTooltips, 50);
                            setTimeout(initAsmTooltips, 50);
                        });
                    } else {
                        setTimeout(initCorrTooltips, 50);
                        setTimeout(initAsmTooltips, 50);
                    }
                } catch (e) {
                    // Игнорируем ошибки расширений браузера
                    if (e.message && e.message.indexOf('runtime.lastError') === -1) {
                        console.error('Ошибка при загрузке скрипта tooltip:', e);
                    }
                    // Пробуем инициализировать через небольшую задержку
                    setTimeout(initCorrTooltips, 200);
                    setTimeout(initAsmTooltips, 200);
                }
            }
            
            // Пробуем инициализировать сразу
            tryInit();
            
            // Также пробуем после полной загрузки страницы
            window.addEventListener('load', function() {
                setTimeout(initCorrTooltips, 100);
                setTimeout(initAsmTooltips, 100);
            });
        })();
        
        // === ФУНКЦИОНАЛ TOOLTIP ДЛЯ СТОЛБЦА "СОБРАНО" ===
        function formatAsmTooltip(asmDetails) {
            if (!asmDetails || asmDetails === '' || asmDetails === 'null' || asmDetails === 'NULL') {
                return '<div class="asm-tooltip-title">Нет данных о сборке</div>';
            }
            
            const items = asmDetails.split('|').filter(item => item && item.trim() !== '');
            if (items.length === 0) {
                return '<div class="asm-tooltip-title">Нет данных о сборке</div>';
            }
            
            let html = '<div class="asm-tooltip-title">Детализация по датам:</div>';
            let hasValidItems = false;
            
            items.forEach(item => {
                const parts = item.split(':');
                if (parts.length >= 2) {
                    const date = parts[0].trim();
                    const count = parseInt(parts[1]) || 0;
                    
                    if (date && !isNaN(count) && count > 0) {
                        hasValidItems = true;
                        // Форматируем дату в читаемый вид
                        try {
                            const dateObj = new Date(date + 'T00:00:00');
                            if (!isNaN(dateObj.getTime())) {
                                const formattedDate = dateObj.toLocaleDateString('ru-RU', { 
                                    year: 'numeric', 
                                    month: '2-digit', 
                                    day: '2-digit' 
                                });
                                html += `<div class="asm-tooltip-item">
                                    <span class="asm-tooltip-date">${formattedDate}</span>
                                    <span class="asm-tooltip-count">${count.toLocaleString('ru-RU')}</span>
                                </div>`;
                            }
                        } catch (e) {
                            // Если не удалось распарсить дату, пропускаем
                        }
                    }
                }
            });
            
            if (!hasValidItems) {
                return '<div class="asm-tooltip-title">Нет данных о сборке</div>';
            }
            
            return html;
        }
        
        // Функция для получения данных о сборке из строки таблицы
        function getAsmDetails(cell) {
            try {
                const row = cell.closest('tr');
                if (!row) return '';
                
                // Пробуем получить данные разными способами
                let asmDetails = row.getAttribute('data-asm-details');
                if (!asmDetails && row.dataset) {
                    asmDetails = row.dataset.asmDetails || '';
                }
                
                return asmDetails || '';
            } catch (e) {
                console.error('Ошибка при получении данных о сборке:', e);
                return '';
            }
        }
        
        // Инициализация tooltip для всех ячеек столбца "Собрано"
        function initAsmTooltips() {
            try {
                const cells = document.querySelectorAll('.col-assembled');
                if (!cells || cells.length === 0) return;
                
                cells.forEach(cell => {
                    const asmDetails = getAsmDetails(cell);
                    const tooltip = cell.querySelector('.asm-tooltip');
                    
                    if (tooltip) {
                        // Заполняем tooltip данными
                        tooltip.innerHTML = formatAsmTooltip(asmDetails);
                    }
                    
                    // Для мобильных устройств: показываем tooltip при клике
                    if (window.innerWidth <= 768) {
                        cell.addEventListener('click', function(e) {
                            e.stopPropagation();
                            // Закрываем другие открытые tooltip
                            document.querySelectorAll('.col-assembled.active').forEach(activeCell => {
                                if (activeCell !== cell) {
                                    activeCell.classList.remove('active');
                                }
                            });
                            // Переключаем текущий tooltip
                            cell.classList.toggle('active');
                        });
                        
                        // Закрываем tooltip при клике вне ячейки
                        document.addEventListener('click', function(e) {
                            if (!cell.contains(e.target)) {
                                cell.classList.remove('active');
                            }
                        });
                    }
                });
            } catch (e) {
                console.error('Ошибка при инициализации tooltip для сборки:', e);
            }
        }
    </script>


    </body>
    </html>
    <?php

} catch (Throwable $e) {
    http_response_code(500);
    if (($format ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<pre style='color:#b00'>Ошибка: ".h($e->getMessage())."</pre>";
    }
}
