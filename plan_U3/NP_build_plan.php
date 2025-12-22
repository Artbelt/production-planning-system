<?php
/* NP_build_plan.php — единая таблица (дневные смены)
   Клики по ячейкам: ЛКМ +50 (или остаток), Ctrl+ЛКМ/ПКМ −50.
   В шапке дня: Σ (наш+чужой) / лимит; ниже — [чужой].
   В списке фильтров: Название [высота валов × ширина бумаги мм] + строкой H×W шторы.
*/

$dsn='mysql:host=127.0.0.1;dbname=plan_U3;charset=utf8mb4';
$user='root'; $pass='';

$action = $_GET['action'] ?? '';

/* === AJAX ================================================================= */
if (in_array($action, ['save_plan','load_plan','load_foreign','load_meta','list_orders','load_left_rows','plan_bounds'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try{
        $pdo = new PDO($dsn,$user,$pass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);

        // Таблица плана (дневные смены)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS build_plans(
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number  VARCHAR(64) NOT NULL,
                filter        VARCHAR(128) NOT NULL,
                day_date      DATE NOT NULL,
                shift         ENUM('D','N') NOT NULL,
                qty           INT NOT NULL,
                saved_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY idx_order_date (order_number, day_date),
                KEY idx_order_filter (order_number, filter)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $raw = file_get_contents('php://input');
        $payload = $raw ? json_decode($raw, true) : [];

        $normalizeFilter = function($s) use ($pdo) {
            $s = str_replace("\xC2\xA0", ' ', (string)$s);      // NBSP -> обычный пробел
            $s = preg_replace('/\s+/u', ' ', $s);               // схлопываем повторяющиеся пробелы/табуляции
            $s = trim($s);
            
            // Проверяем в таблице round_filter_structure для получения правильного регистра
            if ($s !== '') {
                $stmt = $pdo->prepare("SELECT filter FROM round_filter_structure WHERE UPPER(filter) = UPPER(?) LIMIT 1");
                $stmt->execute([$s]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    return $result['filter']; // Возвращаем название с правильным регистром
                }
            }
            
            return $s;
        };

        /* save_plan ---------------------------------------------------------- */
        if ($action==='save_plan'){
            $order = (string)($payload['order'] ?? '');
            $start = (string)($payload['start'] ?? '');
            $days  = (int)($payload['days'] ?? 7);
            $items = $payload['items'] ?? [];
            if ($order==='' || $start==='' || $days<=0 || !is_array($items)) {
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad payload']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM build_plans WHERE order_number=? AND day_date BETWEEN ? AND ?")
                ->execute([$order, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')]);

            $ins = $pdo->prepare("INSERT INTO build_plans(order_number, filter, day_date, shift, qty) VALUES(?,?,?,?,?)");
            $saved = 0;
            foreach ($items as $it){
                $f = $normalizeFilter($it['filter'] ?? '');
                $d = (string)($it['date'] ?? '');
                $q = (int)($it['qty'] ?? 0);
                if ($f==='' || $q<=0) continue;
                $dd = DateTime::createFromFormat('Y-m-d', $d); if(!$dd) continue;
                if ($dd < $dtStart || $dd > $dtEnd) continue;
                $ins->execute([$order, $f, $dd->format('Y-m-d'), 'D', $q]);
                $saved++;
            }
            $pdo->commit();
            // Помечаем заявку как готовую к сборке
            $u = $pdo->prepare("UPDATE orders
                                SET build_ready=1
                                WHERE order_number=?");
            $u->execute([$order]);
            echo json_encode(['ok'=>true,'saved'=>$saved]); exit;
        }

        /* load_plan ---------------------------------------------------------- */
        if ($action==='load_plan'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            $start = (string)($payload['start'] ?? ($_GET['start'] ?? ''));
            $days  = (int)($payload['days']  ?? ($_GET['days'] ?? 7));
            if ($order==='' || $start==='' || $days<=0){
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad params']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');
            $st = $pdo->prepare("SELECT filter, day_date, qty
                                 FROM build_plans
                                 WHERE order_number=? AND day_date BETWEEN ? AND ? AND shift='D'
                                 ORDER BY day_date, filter");
            $st->execute([$order, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')]);
            $rows = $st->fetchAll();
            echo json_encode(['ok'=>true,'items'=>$rows]); exit;
        }

        /* load_foreign ------------------------------------------------------- */
        /* load_foreign ------------------------------------------------------- */
        if ($action==='load_foreign'){
            // Суммы по датам и список "чужих" заявок (кроме текущей)
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            $start = (string)($payload['start'] ?? ($_GET['start'] ?? ''));
            $days  = (int)($payload['days']  ?? ($_GET['days'] ?? 7));
            if ($start==='' || $days<=0){
                http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad params']); exit;
            }
            $dtStart = new DateTime($start);
            $dtEnd   = (clone $dtStart)->modify('+'.($days-1).' day');

            // Сначала получаем суммы и список заявок
            $sql = "SELECT day_date,
                   SUM(qty) AS qty,
                   GROUP_CONCAT(DISTINCT order_number ORDER BY order_number SEPARATOR ',') AS orders
            FROM build_plans
            WHERE shift='D' AND day_date BETWEEN ? AND ?"
                . ($order!=='' ? " AND order_number<>?" : "")
                . " GROUP BY day_date";
            $args = [$dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d')];
            if ($order!=='') $args[] = $order;

            $st = $pdo->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll();

            // Нормализуем типы: qty → int, orders → array
            foreach ($rows as &$r){
                $r['qty'] = (int)$r['qty'];
                $r['orders'] = $r['orders'] !== null
                    ? array_values(array_filter(array_map('trim', explode(',', $r['orders']))))
                    : [];
            } unset($r);

            // Теперь получаем детальную информацию о фильтрах для каждой даты
            $sql2 = "SELECT day_date, filter, SUM(qty) AS qty
            FROM build_plans
            WHERE shift='D' AND day_date BETWEEN ? AND ?"
                . ($order!=='' ? " AND order_number<>?" : "")
                . " GROUP BY day_date, filter";
            $st2 = $pdo->prepare($sql2);
            $st2->execute($args);
            $filterRows = $st2->fetchAll();

            // Группируем фильтры по датам
            $filtersByDate = [];
            foreach ($filterRows as $fr) {
                $date = $fr['day_date'];
                if (!isset($filtersByDate[$date])) {
                    $filtersByDate[$date] = [];
                }
                $filtersByDate[$date][] = [
                    'filter' => $fr['filter'],
                    'qty' => (int)$fr['qty']
                ];
            }

            // Добавляем информацию о фильтрах к каждой строке
            foreach ($rows as &$r) {
                $r['filters'] = $filtersByDate[$r['day_date']] ?? [];
            } unset($r);

            echo json_encode(['ok'=>true,'items'=>$rows]); exit;
        }


        /* load_meta ---------------------------------------------------------- */
        if ($action==='load_meta'){
            // Берём: [высота валов × ширина бумаги] из round_filter_structure → paper_package_round
            $filtersIn = $payload['filters'] ?? [];
            if (!is_array($filtersIn) || empty($filtersIn)) { echo json_encode(['ok'=>true,'items'=>[]]); exit; }

            $in  = implode(',', array_fill(0, count($filtersIn), '?'));
            $res = [];

            // Предзаполним, чтобы фронт всегда мог отрисовать скобки
            foreach ($filtersIn as $f) {
                $res[$f] = [
                    'val_height_mm'  => null,  // p_p_fold_height
                    'paper_width_mm' => null,  // p_p_paper_width
                    'press'          => null,  // нужно ли ставить под пресс
                    'plastic_insertion' => null,  // пластиковая вставка
                    // опционально, если захочешь в будущем показывать размеры "шторы":
                    'height_mm' => null,
                    'width_mm'  => null,
                ];
            }

            // Основной JOIN: фильтр → пакет → размеры валов/бумаги
            $sql = "
        SELECT
            rfs.filter                                        AS filter,
            ppr.p_p_fold_height                               AS val_height_mm,
            ppr.p_p_paper_width                               AS paper_width_mm,
            rfs.press                                         AS press,
            rfs.plastic_insertion                             AS plastic_insertion
        FROM round_filter_structure rfs
        LEFT JOIN paper_package_round ppr
               ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
        WHERE rfs.filter IN ($in)
    ";
            $pdo = new PDO($dsn,$user,$pass,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            ]);
            $st = $pdo->prepare($sql);
            $st->execute($filtersIn);

            foreach ($st as $row) {
                $f = (string)$row['filter'];
                if (!array_key_exists($f, $res)) continue;
                $res[$f]['val_height_mm']  = $row['val_height_mm']  !== null ? (float)$row['val_height_mm']  : null;
                $res[$f]['paper_width_mm'] = $row['paper_width_mm'] !== null ? (float)$row['paper_width_mm'] : null;
                $res[$f]['press']          = isset($row['press']) && ($row['press'] == 1 || $row['press'] === '1' || $row['press'] === true) ? 1 : 0;
                $res[$f]['plastic_insertion'] = isset($row['plastic_insertion']) && $row['plastic_insertion'] !== null && trim($row['plastic_insertion']) !== '' ? trim($row['plastic_insertion']) : null;
            }

            echo json_encode(['ok'=>true,'items'=>$res]); exit;
        }


        /* list_orders -------------------------------------------------------- */
        if ($action==='list_orders'){
            $q = trim((string)($payload['q'] ?? ''));
            $limit = max(5, min(200, (int)($payload['limit'] ?? 100)));
            if ($q!==''){
                $st = $pdo->prepare("SELECT o.order_number, COUNT(*) AS positions, SUM(o.count) AS qty
                                     FROM orders o
                                     WHERE o.order_number LIKE ?
                                     GROUP BY o.order_number
                                     ORDER BY o.order_number DESC
                                     LIMIT $limit");
                $st->execute(['%'.$q.'%']);
            } else {
                $st = $pdo->query("SELECT o.order_number, COUNT(*) AS positions, SUM(o.count) AS qty
                                   FROM orders o
                                   GROUP BY o.order_number
                                   ORDER BY o.order_number DESC
                                   LIMIT $limit");
            }
            $rows = $st->fetchAll(); echo json_encode(['ok'=>true,'items'=>$rows]); exit;
        }

        /* load_left_rows ----------------------------------------------------- */
        if ($action==='load_left_rows'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            if ($order===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no order']); exit; }
            $st = $pdo->prepare("
                                        SELECT TRIM(REPLACE(o.filter, CHAR(160), ' ')) AS filter,
                                               SUM(o.count) AS ordered_qty
                                        FROM orders o
                                        WHERE o.order_number=?
                                        GROUP BY TRIM(REPLACE(o.filter, CHAR(160), ' '))
                                        ORDER BY filter
                                    ");

            $st->execute([$order]);
            $rows=$st->fetchAll();
            echo json_encode(['ok'=>true,'items'=>$rows]); exit;
        }

        /* plan_bounds -------------------------------------------------------- */
        if ($action==='plan_bounds'){
            $order = (string)($payload['order'] ?? ($_GET['order'] ?? ''));
            // Пустой номер — пустой ответ (новая заявка без плана)
            if ($order===''){ echo json_encode(['ok'=>true,'min'=>null,'max'=>null,'days'=>0]); exit; }

            $st = $pdo->prepare("
        SELECT MIN(day_date) AS dmin, MAX(day_date) AS dmax
        FROM build_plans
        WHERE shift='D' AND order_number=?
    ");
            $st->execute([$order]);
            $row = $st->fetch();

            $min = $row && $row['dmin'] ? $row['dmin'] : null;
            $max = $row && $row['dmax'] ? $row['dmax'] : null;

            $days = 0;
            if ($min && $max) {
                $dmin = new DateTime($min);
                $dmax = new DateTime($max);
                $days = $dmin->diff($dmax)->days + 1; // включительно
            }

            echo json_encode(['ok'=>true,'min'=>$min,'max'=>$max,'days'=>$days]); exit;
        }

        // если сюда дошли — неизвестное действие
        echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;

    } catch(Throwable $e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* === СТРАНИЦА =========================================================== */

$orderNumber = $_GET['order_number'] ?? '';
if ($orderNumber===''){ http_response_code(400); exit('Укажите ?order_number=...'); }

try{
    $pdo=new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    $st = $pdo->prepare("
                    SELECT TRIM(REPLACE(o.filter, CHAR(160), ' ')) AS filter,
                           SUM(o.count) AS ordered_qty
                    FROM orders o
                    WHERE o.order_number = :order_number
                    GROUP BY TRIM(REPLACE(o.filter, CHAR(160), ' '))
                    ORDER BY filter
                ");

    $st->execute([':order_number'=>$orderNumber]);
    $filters = $st->fetchAll();
}catch(Throwable $e){
    http_response_code(500); echo 'Ошибка: '.$e->getMessage(); exit;
}
?>
<!doctype html><meta charset="utf-8">
<title>План сборки (дневные смены) — заявка #<?=htmlspecialchars($orderNumber)?></title>
<style>
    :root{
        --bg:#f6f7fb; --card:#fff; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280;
        --accent:#2563eb; --accent-2:#10b981; --danger:#ef4444; --warning:#f59e0b;
        --chip:#eef2ff; --chip-text:#4338ca;
        --wFilter:240px; --wOrd:60px; --wPlan:52px; --wDay:112px; /* min ширина дня x2 */
        --radius:12px; --shadow:0 6px 20px rgba(0,0,0,.06);
        --weekend-bg:#f3f4f6;
    }
    *{box-sizing:border-box}
    body{font:13px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Arial,Helvetica,sans-serif;margin:0;background:var(--bg);color:var(--text)}
    .topbar{position:sticky;top:0;z-index:20;backdrop-filter:saturate(140%) blur(6px);background:rgba(246,247,251,.8);border-bottom:1px solid var(--border)}
    .topbar-inner{max-width:1400px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;gap:12px;justify-content:space-between}
    .title{font:700 16px/1.2 system-ui;margin:0}
    .chips{display:flex;gap:8px;flex-wrap:wrap}
    .chip{background:var(--chip);color:var(--chip-text);padding:4px 8px;border-radius:999px;border:1px solid #e0e7ff;font-weight:600}

    .toolbar-wrap{max-width:1400px;margin:10px auto 0;padding:0 16px}
    .toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)}
    .toolbar label{display:flex;align-items:center;gap:6px;color:var(--muted)}
    .toolbar input[type=date], .toolbar input[type=number]{padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;outline:none}
    .toolbar input:focus{border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.12)}
    .btn{padding:7px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;transition:.15s ease;font-weight:600}
    .btn:hover{transform:translateY(-1px); box-shadow:0 4px 16px rgba(0,0,0,.08)}
    .btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
    .btn-ghost{background:transparent}
    .help{color:var(--muted);font-size:12px}

    .panel-wrap{max-width:1400px;margin:10px auto 20px;padding:0 16px}
    .panel{height:calc(100vh - 220px);border:1px solid var(--border);border-radius:var(--radius);background:var(--card);overflow:auto;box-shadow:var(--shadow)}
    .head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--card);z-index:5}

    table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;table-layout:fixed}
    th,td{border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:6px 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:#fff;font-weight:400}
    thead th{background:#f8fafc;position:sticky;top:48px;z-index:10}
    tbody tr:nth-child(even) td{background:#fcfcff}
    thead th.sticky{position:sticky;top:48px;z-index:11;background:#f8fafc}
    tbody td.sticky{position:sticky;z-index:4;background:#fff}

    /* Sticky колонки - убираем границы и добавляем визуальные разделители */
    thead th.col-filter, tbody td.col-filter,
    thead th.col-ord, tbody td.col-ord,
    thead th.col-plan, tbody td.col-plan{border-right:none !important}
    
    .col-filter{left:0 !important;width:var(--wFilter) !important;min-width:var(--wFilter) !important;max-width:var(--wFilter) !important}
    .col-ord{left:var(--wFilter) !important;width:var(--wOrd) !important;min-width:var(--wOrd) !important;max-width:var(--wOrd) !important;text-align:right;color:var(--muted);box-shadow:1px 0 0 0 var(--border)}
    .col-plan{left:calc(var(--wFilter) + var(--wOrd)) !important;width:var(--wPlan) !important;min-width:var(--wPlan) !important;max-width:var(--wPlan) !important;text-align:right;box-shadow:1px 0 0 0 var(--border)}

    .dayHead{width:var(--wDay);min-width:var(--wDay);max-width:var(--wDay);text-align:center;border-top:1px solid var(--border)}
    thead th.dayHead{position:sticky;top:48px;z-index:10;background:#f8fafc}
    thead th.dayHead.weekend{background:var(--weekend-bg) !important}
    .dayHead{position:relative}
    .dayHead .d{display:block;font-weight:400}
    .dayHead .sum{display:block;color:var(--muted);font-size:11px}
    .dayHead.over{background:#fff2f2}
    .press-marker{position:absolute;top:4px;right:4px;width:24px;height:24px;border-radius:50%;line-height:20px;text-align:center;font-size:12px;font-weight:700;border:2px solid #9ca3af;background:transparent;color:#9ca3af;transition:border-color .2s ease, color .2s ease;z-index:12}
    .press-marker.active{border-color:#ef4444;color:#ef4444}
    .press-marker.inline{position:relative;top:auto;right:auto;display:inline-block;margin-left:6px;vertical-align:middle;z-index:auto;border-color:#f59e0b;color:#f59e0b}
    .plastic-marker{position:absolute;top:4px;right:30px;width:24px;height:24px;border-radius:50%;line-height:20px;text-align:center;font-size:12px;font-weight:700;border:2px solid #34d399;background:transparent;color:#34d399;transition:border-color .2s ease, color .2s ease;z-index:12}
    .plastic-marker.active{border-color:#34d399;color:#34d399}
    .plastic-marker.inline{position:relative;top:auto;right:auto;display:inline-block;margin-left:6px;vertical-align:middle;z-index:auto}
    .width-marker{display:inline-block;width:24px;height:24px;border-radius:50%;line-height:20px;text-align:center;font-size:10px;font-weight:700;border:2px solid #3b82f6;background:transparent;color:#3b82f6;margin-left:6px;vertical-align:middle}
    .width-marker-header-wrapper{position:absolute;top:32px;right:4px;width:32px;height:32px;z-index:12;display:flex;align-items:center;justify-content:center}
    .width-marker-header{position:absolute;width:24px;height:24px;border-radius:50%;line-height:20px;text-align:center;font-size:10px;font-weight:700;border:2px solid #9ca3af;background:transparent;color:#9ca3af;transition:border-color .2s ease, color .2s ease;display:flex;align-items:center;justify-content:center;z-index:1}
    .width-marker-header.active{border-color:#3b82f6;color:#3b82f6}
    .width-marker-progress{position:absolute;top:0;left:0;width:32px;height:32px;transform:rotate(-90deg)}
    .width-marker-progress svg{width:100%;height:100%}
    .width-marker-progress-circle{fill:none;stroke:#ef4444;stroke-width:4;stroke-linecap:round;transition:stroke-dashoffset .3s ease}

    .dayCell{width:var(--wDay);min-width:var(--wDay);max-width:var(--wDay);text-align:center;cursor:pointer;user-select:none}
    .dayCell.weekend{background:var(--weekend-bg) !important}
    .qty{display:block;padding:2px 0;margin:0;border-radius:8px;transition:background-color .2s ease}
    .qty.zero{ color:#cbd5e1; opacity:.65; }

    /* подсветка строки целиком, включая sticky */
    tbody tr:hover td{background:#eef6ff}
    tbody tr:hover td.sticky{background:#eef6ff !important}
    tbody tr:hover td.dayCell.weekend{ background: var(--weekend-bg) !important }

    .sumOk{background:linear-gradient(90deg, rgba(16,185,129,.12), rgba(16,185,129,.12));}
    .sumOver{background:linear-gradient(90deg, rgba(239,68,68,.12), rgba(239,68,68,.12));}

    .totline{padding:10px 12px;border-top:1px solid var(--border);color:#111827;font-size:13px;background:linear-gradient(180deg, rgba(248,250,252,0.9), #ffffff);position:sticky;bottom:0}
    .totline b{font-weight:800}

    /* тосты */
    .toasts{position:fixed;right:16px;bottom:16px;display:flex;flex-direction:column;gap:8px;z-index:9999}
    .toast{background:#fff;border:1px solid var(--border);border-left:4px solid var(--accent);padding:10px 12px;border-radius:12px;box-shadow:var(--shadow);min-width:260px}
    .toast.ok{border-left-color:var(--accent-2)}
    .toast.err{border-left-color:var(--danger)}
    .dot{display:inline-block;width:8px;height:8px;background:var(--accent-2);border-radius:999px;margin-right:6px}
    .dirty .dot{background:var(--warning)}

    /* модалка выбора заявки */
    .modal.hidden{display:none}
    .modal{position:fixed;inset:0;z-index:50}
    .modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.35)}
    .modal__card{position:relative;margin:6vh auto;max-width:700px;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);display:flex;flex-direction:column}
    .modal__head{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border)}
    .modal__title{font-weight:700}
    .modal__body{padding:10px 12px}
    .ordsearch{display:flex;gap:8px;margin-bottom:10px}
    .ordsearch input{flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:8px}
    .ordlist{max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:10px}
    .orditem{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer}
    .orditem:hover{background:#f8fafc}

    /* вывод скобок после названия фильтра */
    /* больше не используем ::after — рисуем теги прямо в HTML */
    .filterTitle::after{ content: none !important; }

    /* компактные квадратные теги вида [50] [600] */
    .fTag{
        display:inline-block;
        margin-left:6px;
        font-size:12px;
        color:#374151; /* серо-тёмный */
    }
    .dayHead .subF{display:block;color:var(--muted);font-size:11px;margin-top:2px}
    /* === Tips (правый блок) =============================================== */
    .tips{
        position: fixed;
        right: 16px;
        top: 74px;              /* ниже липкой топ-панели */
        width: 320px;
        z-index: 25;
    }
    .tips__card{
        background: var(--card);
        border:1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .tips__head{
        padding:10px 12px;
        border-bottom:1px solid var(--border);
        font-weight:700;
    }
    .tips__body{
        padding:10px 12px;
        font-size:13px;
        color:var(--text);
    }
    .tips__body ul{
        margin:6px 0 0;
        padding-left:18px;
    }
    .tips__body li{
        margin:6px 0;
        line-height:1.35;
    }

    /* На узких экранах прячем, чтобы не мешал горизонтальному скроллу */
    @media (max-width: 1280px){
        .tips{ display:none; }
    }
    /* ===== Печать ====================================================== */
    @media print {
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        body { background:#fff !important; }
        /* Скрываем все интерактивные части */
        .topbar, .toolbar-wrap, .panel-wrap, .tips, .toasts { display:none !important; }
        /* Показываем только печатную разметку */
        #printArea { display:block !important; }

        .print-sheets table {
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .print-sheets thead th {
            position: static !important;
            background:#f2f4f7 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-sheets th,
        .print-sheets td {
            border:1px solid #cbd5e1;
            padding:4px 6px;
            font-size:10px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        /* Повтор заголовков при переносе на новую страницу */
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }

        /* Избегаем разрыва строки таблицы посередине */
        tr, td, th { break-inside: avoid; page-break-inside: avoid; }

        /* Каждая «простыня» (срез по дням) — с разрывом страницы после */
        .sheet { page-break-after: always; }

        /* На печати отключаем липкие и фоновые эффекты */
        .print-sheets .sticky,
        .print-sheets .dayCell { position: static !important; }
    }

    /* Немного косметики для предпросмотра печатных листов (если захочешь смотреть перед печатью) */
    .print-sheets .sheet {
        margin: 8px 0;
        background:#fff;
        border:1px dashed #e5e7eb;
    }
    .print-sheets .sheet h3 {
        margin:6px 0 8px;
        font:700 12px/1.2 system-ui;
    }

</style>

<div class="topbar">
    <div class="topbar-inner">
        <h1 class="title">План сборки (только дневные смены) — заявка #<span id="titleOrder"><?=htmlspecialchars($orderNumber)?></span></h1>
        <div class="chips">
            <span class="chip" id="chipFilters">Фильтров: 0</span>
            <span class="chip" id="chipDays">Дней: 0</span>
            <span class="chip" id="chipCap">Лимит/смену: 0</span>
        </div>
    </div>
</div>

<div class="toolbar-wrap">
    <div class="toolbar" id="toolbar">
        <button class="btn" id="btnPickOrder" title="Быстрый выбор другой заявки">Заявка…</button>
        <label>Стартовая дата: <input type="date" id="startDate"></label>
        <label>Дней: <input type="number" id="days" min="1" step="1" value="7" style="width:92px"></label>
        <label>Лимит/смену, шт: <input type="number" id="cap" min="0" step="10" value="0" style="width:110px"></label>
        <button class="btn" id="btnRebuild" title="Перестроить таблицу под указанные параметры">Построить</button>
        <button class="btn" id="btnLoad" title="Загрузить ранее сохранённый план">Загрузить</button>
        <button class="btn" id="btnSave" title="Сохранить план"><span class="dot"></span>Сохранить</button>
        <button class="btn btn-ghost" id="btnClear" title="Очистить текущие значения">Очистить</button>
        <p>
            <button class="btn" id="btnPrint" title="Подготовить листы и открыть диалог печати">Печать</button>
            Дней/лист (печать): <input type="number" id="printCols" min="3" max="20" value="8" style="width:64px">



        <span class="help">ЛКМ: +50 (или остаток) · Ctrl+ЛКМ / ПКМ: −50</span>
    </div>
</div>

<!-- Печатные листы формируются сюда -->
<div id="printArea" class="print-sheets" style="display:none"></div>


<!-- Modal выбора заявки -->
<div id="orderModal" class="modal hidden">
    <div class="modal__backdrop"></div>
    <div class="modal__card">
        <div class="modal__head">
            <div class="modal__title">Выбор заявки</div>
            <button class="btn" id="ordClose" title="Закрыть">×</button>
        </div>
        <div class="modal__body">
            <div class="ordsearch">
                <input type="text" id="ordQuery" placeholder="Номер или часть номера…">
                <button class="btn" id="ordFind">Найти</button>
            </div>
            <div class="ordlist" id="ordList"></div>
        </div>
    </div>
</div>

<div class="panel-wrap">
    <div class="panel" id="mainPanel">
        <div class="head">
            <div class="help">Прокрутка по горизонтали; первые три колонки закреплены.</div>
            <div class="summary" id="summary"></div>
        </div>
        <div id="tableWrap"></div>
        <div class="totline" id="totals">Всего распределено: <b>0</b> шт</div>
    </div>
</div>

<div class="toasts" id="toasts"></div>
<aside class="tips" aria-label="Подсказки">
    <div class="tips__card">
        <div class="tips__head">Подсказки</div>
        <div class="tips__body">
            <ul>
                <li>На 600 просечник ставим менее 150 фильтров в смену.</li>
                <li>Планируем в смену 300 фильтров с ППУ.</li>
                <li>Планируем в смену 150 фильтров с крышками.</li>
            </ul>
        </div>
    </div>
</aside>


<script>

    // NBSP -> ' ', схлопываем пробелы, trim, в верхний регистр
    const canonF = s => (s||'')
        .replace(/\u00A0/g,' ')
        .replace(/\s+/g,' ')
        .trim()
        .toUpperCase();

    const key = (f, d) => `${canonF(f)}|${d}`;

    let ORDER = <?=json_encode($orderNumber)?>;

    // Данные слева (PHP → JS на старте)
    let LEFT_ROWS = [
        <?php foreach($filters as $r): ?>
        { filter: <?=json_encode($r['filter'])?>, ord: <?= (int)$r['ordered_qty']?> },
        <?php endforeach; ?>
    ];

    // ==== state ====
    const STEP = 25;
    let startDateISO; let days = 7; let dates = [];
    let plan = new Map(); let dayCap = 0; let DIRTY = false; let META = {};
    let FOREIGN = new Map();

    // ==== helpers ====
    const el = id => document.getElementById(id);
    const fmtDM = iso => { const dt=new Date(iso);
        const dd=String(dt.getDate()).padStart(2,'0');
        const mm=String(dt.getMonth()+1).padStart(2,'0');
        const w=['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][dt.getDay()];
        return `${dd}.${mm}<br><small>${w}</small>`;
    };
    const addDays = (iso, k)=>{ const dt=new Date(iso); dt.setDate(dt.getDate()+k); return dt.toISOString().slice(0,10); };

    const isWeekend=iso=>{ const d=new Date(iso).getDay(); return d===0||d===6; };
    const debounce=(fn,ms)=>{ let t=null; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; };

    function rebuildDates(){ dates=[]; for(let i=0;i<days;i++) dates.push(addDays(startDateISO, i)); }

    // ==== UI utils ====
    function toast(msg,type='ok'){ const box=el('toasts'); const div=document.createElement('div'); div.className=`toast ${type}`; div.textContent=msg; box.appendChild(div); setTimeout(()=>{ div.style.opacity='0'; div.style.transform='translateY(-6px)'; },2600); setTimeout(()=>div.remove(),3300); }
    function markDirty(v=true){ DIRTY=v; const btn=el('btnSave'); btn.classList.toggle('btn-primary',DIRTY); btn.parentElement.classList.toggle('dirty',DIRTY); }
    window.addEventListener('beforeunload', e=>{ if(!DIRTY) return; e.preventDefault(); e.returnValue=''; });

    // ==== измерение ширин ====
    function measureAndSetWidths(){
        const font=getComputedStyle(document.body).font; const canvas=document.createElement('canvas'); const ctx=canvas.getContext('2d'); ctx.font=font;
        let maxFilter = 'Фильтр';
        for (const r of LEFT_ROWS) {
            const meta = META[r.filter] || {};
            const vh = (meta.val_height_mm != null) ? Math.round(meta.val_height_mm) : null;
            const pw = (meta.paper_width_mm != null) ? Math.round(meta.paper_width_mm) : null;
            const label =
                r.filter +
                (vh != null ? ` [${vh}]` : ``) +
                (pw != null && pw > 450 ? ` [${pw}]` : ``);
            if (String(label).length > maxFilter.length) maxFilter = String(label);
        }


        let wFilter=Math.ceil(ctx.measureText(maxFilter).width)+28; wFilter=Math.min(Math.max(wFilter,160),560);
        // Учитываем заголовки "Заказано" и "В плане" для правильной ширины
        let wOrdHeader=Math.ceil(ctx.measureText('Заказано').width)+18;
        let wOrdNumber=Math.ceil(ctx.measureText('00000').width)+18;
        let wOrd=Math.max(wOrdHeader, wOrdNumber); wOrd=Math.min(Math.max(wOrd,70),140);
        let wPlanHeader=Math.ceil(ctx.measureText('В плане').width)+18;
        let wPlanNumber=Math.ceil(ctx.measureText('0000').width)+18;
        let wPlan=Math.max(wPlanHeader, wPlanNumber); wPlan=Math.min(Math.max(wPlan,70),140);
        let wDay=Math.ceil(ctx.measureText('00.00').width)+18; wDay=Math.min(Math.max(wDay,112),192);

        const rs=document.documentElement.style;
        rs.setProperty('--wFilter',wFilter+'px');
        rs.setProperty('--wOrd',wOrd+'px');
        rs.setProperty('--wPlan',wPlan+'px');
        rs.setProperty('--wDay',wDay+'px');
    }

    // ==== table build ====
    function buildMainTable(){
        const wrap = el('tableWrap');
        let html = '<table id="mainTbl"><thead><tr>';
        html += '<th class="sticky col-filter">Фильтр</th>';
        html += '<th class="sticky col-ord">Заказано</th>';
        html += '<th class="sticky col-plan">В&nbsp;плане</th>';

        for (const d of dates) {

            const wk = isWeekend(d) ? ' weekend' : '';
            const fObj = FOREIGN.get(d) || {qty:0, orders:[]};
            const capHtml = dayCap > 0 ? ` / <span class="capVal">${dayCap}</span>` : '';
            html += `<th class="dayHead${wk}" data-date="${d}" title="Заявки: ${fObj.orders.length?fObj.orders.join(', '):'—'}">
  <span class="press-marker" data-date="${d}" title="Позиции под пресс">П</span>
  <div class="width-marker-header-wrapper" data-date="${d}">
    <div class="width-marker-progress" data-date="${d}">
      <svg viewBox="0 0 32 32">
        <circle class="width-marker-progress-circle" cx="16" cy="16" r="13" data-date="${d}" stroke-dasharray="81.68" stroke-dashoffset="81.68"></circle>
      </svg>
    </div>
    <span class="width-marker-header" data-date="${d}" title="Позиции шириной 600">600</span>
  </div>
  <span class="d">${fmtDM(d)}</span>
  <span class="sum">
    <span class="dayTotalCombined" data-date="${d}">0</span>${capHtml}
    <div class="subF">${fObj.qty>0 ? `[<span class="foreign">${fObj.qty}</span>]` : ''}</div>
  </span>
</th>`;


        }

        html += '</tr></thead><tbody>';

        for (const r of LEFT_ROWS) {
            const meta = META[r.filter] || {};
            const vh = (meta.val_height_mm != null) ? Math.round(meta.val_height_mm) : null;          // высота валов
            const pw = (meta.paper_width_mm != null) ? Math.round(meta.paper_width_mm) : null;        // ширина бумаги
            const showWidth = (pw != null && pw > 450);

            // строка ниже — размеры шторы (если нужны, оставляем как было)
            const panelLine = (meta.height_mm != null || meta.width_mm != null)
                ? `<div style="color:#6b7280;font-size:11px">${meta.height_mm ?? '-'}×${meta.width_mm ?? '-'} мм</div>`
                : '';

            const hasPress = (meta.press === 1);
            const hasPlastic = (meta.plastic_insertion != null && meta.plastic_insertion !== '');
            html += `<tr data-filter="${r.filter}">
            <td class="sticky col-filter">
              <span class="filterTitle">${r.filter}</span>
              ${vh != null ? `<span class="fTag">[${vh}]</span>` : ``}
              ${hasPress ? `<span class="press-marker inline">П</span>` : ``}
              ${hasPlastic ? `<span class="plastic-marker inline">В</span>` : ``}
              ${showWidth ? `<span class="width-marker">600</span>` : ``}
              ${panelLine}
            </td>
            <td class="sticky col-ord ord right" title="Заказано по фильтру">${r.ord}</td>
            <td class="sticky col-plan plan right" title="Сумма распределённого по фильтру">0</td>`;
                    // ... дальше ячейки дней как у тебя



            for(const d of dates){
                const q=plan.get(key(r.filter,d))||0; const wk=isWeekend(d)?' weekend':'';
                html += `<td class="dayCell${wk}" data-filter="${r.filter}" data-date="${d}" title="ЛКМ +50 / Ctrl+ЛКМ или ПКМ −50">
                        <span class="qty${q===0 ? ' zero' : ''}">${q}</span>
                     </td>`;
            }
            html += '</tr>';
        }

        html += '</tbody></table>';
        wrap.innerHTML = html;

        // события по ячейкам
        wrap.querySelectorAll('.dayCell').forEach(td=>{
            td.addEventListener('click', e=>changeCell(td, e.ctrlKey ? -STEP : +STEP));
            td.addEventListener('contextmenu', e=>{ e.preventDefault(); changeCell(td, -STEP); });
        });

        recalcPlannedSums(); recalcDayTotals(); recalcTotals(); recalcPressMarkers(); recalcWidth600Markers();
        el('summary').innerHTML = `<span class="help">Всего строк: <b>${LEFT_ROWS.length}</b></span>`;
        el('chipFilters').textContent = `Фильтров: ${LEFT_ROWS.length}`;
        el('chipDays').textContent = `Дней: ${dates.length}`;
        el('chipCap').textContent = `Лимит/смену: ${dayCap}`;
    }

    // === логика изменения ячейки ===
    function changeCell(td, delta){
        const filter=td.dataset.filter; const date=td.dataset.date; const k=key(filter,date); const ord=getOrdered(filter);
        let q=plan.get(k)||0; const oldQ=q;

        if(delta>0){
            // Проверяем ограничение: в смену можно добавить только одну позицию с прессом
            const meta = META[filter] || {};
            const currentFilterHasPress = (meta.press === 1);
            
            if(currentFilterHasPress){
                // Проверяем, есть ли уже в этой смене другая позиция с прессом
                let hasOtherPressInShift = false;
                for(const [k, qty] of plan.entries()){
                    if(qty <= 0) continue;
                    const [otherFilter, otherDate] = k.split('|');
                    if(otherDate !== date) continue; // другая смена - пропускаем
                    if(otherFilter === filter) continue; // та же позиция - пропускаем
                    
                    const otherMeta = META[otherFilter] || {};
                    if(otherMeta.press === 1){
                        hasOtherPressInShift = true;
                        break;
                    }
                }
                
                if(hasOtherPressInShift){
                    flashOver(td);
                    toast('В смену можно добавить только одну позицию с прессом', 'err');
                    return;
                }
            }
            
            // Вычисляем сколько уже распределено по всем дням (включая текущую ячейку)
            const curSumTotal=getPlannedSum(filter);
            // Вычисляем сколько осталось до заказанного количества
            let free=Math.max(0, ord-curSumTotal);
            if(free<=0){ flashOver(td); return; }
            // Добавляем либо STEP (25), либо остаток, если остаток меньше STEP
            // Это позволяет правильно обработать случаи, когда заказано не кратное 25
            // Например: заказано 30, первый клик добавляет 25, второй клик добавляет оставшиеся 5
            const add=Math.min(STEP, free);
            q=oldQ+add;
        } else {
            q=Math.max(0, oldQ+delta);
        }

        plan.set(k,q);
        const qEl = td.querySelector('.qty');
        qEl.textContent = String(q);
        qEl.classList.toggle('zero', q===0);
        qEl.style.background = 'rgba(37,99,235,.08)';
        setTimeout(()=>{ qEl.style.background=''; }, 140);

        markDirty(true);
        recalcPlannedSumsDebounced();
        recalcDayTotalsDebounced();
        recalcTotalsDebounced();
        recalcPressMarkersDebounced();
        recalcWidth600MarkersDebounced();
    }

    function getOrdered(filter){
        const cf = canonF(filter);
        const r = LEFT_ROWS.find(x => canonF(x.filter) === cf);
        return r ? r.ord : 0;
    }

    function getPlannedSum(filter){
        let s=0; for(const d of dates){ s += (plan.get(key(filter,d))||0); }
        return s;
    }

    function recalcPlannedSums(){
        document.querySelectorAll('#mainTbl tr[data-filter]').forEach(tr=>{
            const f=tr.dataset.filter;
            const s=getPlannedSum(f);
            const ord=getOrdered(f);
            const cell=tr.querySelector('.plan');
            cell.textContent = String(s);
            const pct=ord>0?Math.min(100, Math.round((s/ord)*100)):0;
            cell.style.backgroundImage=ord>0?`linear-gradient(90deg, rgba(16,185,129,.12) ${pct}%, transparent ${pct}%)`:'none';
            cell.title=ord>0?`Распределено ${s} из ${ord} (${pct}%)`:`Распределено ${s}`;
            cell.classList.toggle('sumOk', s===ord && ord>0);
            cell.classList.toggle('sumOver', s>ord);
        });
    }
    const recalcPlannedSumsDebounced = debounce(recalcPlannedSums, 40);

    // Итоги по дням (Σ наш+чужой) + сравнение с лимитом/смену
    function recalcDayTotals(){
        const totals=new Map(); dates.forEach(d=>totals.set(d,0));
        plan.forEach((q,k)=>{ const date=k.split('|')[1]; if(totals.has(date)) totals.set(date,(totals.get(date)||0)+(q||0)); });

        dates.forEach(d=>{
            const th=document.querySelector(`.dayHead[data-date="${d}"]`); if(!th) return;

            const ours=totals.get(d)||0;
            const fObj = FOREIGN.get(d) || {qty:0, orders:[]};
            const combined = ours + fObj.qty;

            // число вверху (только наша заявка)
            const main=th.querySelector('.dayTotalCombined');
            if(main) main.textContent=String(ours);

            // строка в скобках (суммарно по всем заявкам)
            const sub=th.querySelector('.subF');
            if(sub) sub.innerHTML = combined>0 ? `[<span class="foreign">${combined}</span>]` : '';

            // тултип: список заявок; если на дату есть наши количества — добавим и текущую заявку
            const ordersList = fObj.orders ? [...fObj.orders] : [];
            if (ours > 0) ordersList.unshift(String(ORDER));
            th.title = ordersList.length ? `Заявки: ${ordersList.join(', ')}` : 'Заявки: —';

            // подсветка превышения лимита (проверяем комбинированную сумму)
            th.classList.toggle('over', dayCap>0 && combined>dayCap);
        });
    }

    const recalcDayTotalsDebounced = debounce(recalcDayTotals, 40);

    // Проверка наличия позиций под пресс в смене
    function recalcPressMarkers(){
        dates.forEach(d=>{
            const marker = document.querySelector(`.press-marker[data-date="${d}"]`);
            if(!marker) return;

            let hasPress = false;
            // Проверяем все фильтры в плане на эту дату (текущая заявка)
            for(const [k, qty] of plan.entries()){
                if(qty <= 0) continue;
                const [filter, date] = k.split('|');
                if(date !== d) continue;
                
                const meta = META[filter] || {};
                if(meta.press === 1){
                    hasPress = true;
                    break;
                }
            }
            
            // Проверяем фильтры из других заявок (FOREIGN)
            if(!hasPress){
                const fObj = FOREIGN.get(d) || {filters:[]};
                for(const filterItem of (fObj.filters || [])){
                    const meta = META[filterItem.filter] || {};
                    if(meta.press === 1){
                        hasPress = true;
                        break;
                    }
                }
            }

            // Обновляем маркер
            marker.textContent = 'П';
            marker.classList.toggle('active', hasPress);
            marker.title = hasPress ? 'Есть позиции под пресс' : 'Нет позиций под пресс';
        });
    }
    const recalcPressMarkersDebounced = debounce(recalcPressMarkers, 40);

    // Проверка наличия позиций шириной 600 в смене
    function recalcWidth600Markers(){
        const MAX_WIDTH600 = 150; // максимум для 100% заполнения
        const CIRCLE_LENGTH = 81.68; // 2 * π * 13 (радиус окружности)
        dates.forEach(d=>{
            const marker = document.querySelector(`.width-marker-header[data-date="${d}"]`);
            const progressCircle = document.querySelector(`.width-marker-progress-circle[data-date="${d}"]`);
            if(!marker || !progressCircle) return;

            let totalWidth600 = 0;
            let hasWidth600 = false;
            // Считаем общее количество фильтров шириной 600 в смене (текущая заявка)
            for(const [k, qty] of plan.entries()){
                if(qty <= 0) continue;
                const [filter, date] = k.split('|');
                if(date !== d) continue;
                
                const meta = META[filter] || {};
                if(meta.paper_width_mm != null && meta.paper_width_mm > 450){
                    hasWidth600 = true;
                    totalWidth600 += qty;
                }
            }
            
            // Добавляем фильтры из других заявок (FOREIGN)
            const fObj = FOREIGN.get(d) || {filters:[]};
            for(const filterItem of (fObj.filters || [])){
                const meta = META[filterItem.filter] || {};
                if(meta.paper_width_mm != null && meta.paper_width_mm > 450){
                    hasWidth600 = true;
                    totalWidth600 += (filterItem.qty || 0);
                }
            }

            // Обновляем маркер
            marker.textContent = '600';
            marker.classList.toggle('active', hasWidth600);
            
            // Обновляем радиальный прогресс-бар (максимум 150 = 100%)
            const percent = Math.min(100, (totalWidth600 / MAX_WIDTH600) * 100);
            const offset = CIRCLE_LENGTH - (CIRCLE_LENGTH * percent / 100);
            progressCircle.style.strokeDashoffset = offset;
            
            marker.title = hasWidth600 
                ? `Позиции шириной 600: ${totalWidth600} шт (${Math.round(percent)}%)` 
                : 'Нет позиций шириной 600';
        });
    }
    const recalcWidth600MarkersDebounced = debounce(recalcWidth600Markers, 40);

    function recalcTotals(){
        let totalAssigned=0;
        plan.forEach(v=>{ totalAssigned += v||0; });
        el('totals').innerHTML = `Всего распределено: <b>${totalAssigned}</b> шт`;
    }
    const recalcTotalsDebounced = debounce(recalcTotals, 40);

    function flashOver(td){ td.style.background = '#fff1f2'; setTimeout(()=>td.style.background = '', 260); }

    // ==== AJAX helpers ====
    async function savePlan(){
        const items=[];
        for(const [k,q] of plan.entries()){
            if(!q) continue;
            const [filter,date]=k.split('|');
            items.push({filter,date,qty:q});
        }
        const body=JSON.stringify({order:ORDER, start:startDateISO, days, items});
        const res=await fetch(location.pathname+'?action=save_plan',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка сохранения');
    }
    async function loadPlan(){
        const body=JSON.stringify({order:ORDER, start:startDateISO, days});
        const res=await fetch(location.pathname+'?action=load_plan',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка загрузки');
        plan.clear();
        for(const r of (data.items||[])){
            plan.set(key(r.filter, r.day_date), parseInt(r.qty,10)||0);
        }
        markDirty(false);
    }
    async function loadForeign(){
        const body=JSON.stringify({order:ORDER, start:startDateISO, days});
        const res=await fetch(location.pathname+'?action=load_foreign',{
            method:'POST', headers:{'Content-Type':'application/json'}, body
        });
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка FOREIGN');

        FOREIGN.clear();
        const allForeignFilters = new Set();
        for(const r of (data.items||[])){
            FOREIGN.set(r.day_date, {
                qty: parseInt(r.qty,10)||0,
                orders: Array.isArray(r.orders) ? r.orders : [],
                filters: Array.isArray(r.filters) ? r.filters : [] // детальная информация о фильтрах
            });
            // Собираем все уникальные фильтры из FOREIGN
            for(const filterItem of (r.filters || [])){
                if(filterItem.filter) allForeignFilters.add(filterItem.filter);
            }
        }
        
        // Загружаем метаданные для всех фильтров из FOREIGN
        if(allForeignFilters.size > 0){
            const foreignFiltersArray = Array.from(allForeignFilters);
            try{
                await loadMetaForFilters(foreignFiltersArray);
            }catch(e){
                console.warn('Не удалось загрузить метаданные для FOREIGN фильтров:', e);
            }
        }
    }
    
    async function loadMetaForFilters(filters){
        if(!filters.length) return;
        const body=JSON.stringify({filters});
        const res=await fetch(location.pathname+'?action=load_meta',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка META');
        // Объединяем с существующими метаданными
        Object.assign(META, data.items || {});
    }

    async function loadMeta(){
        const filters=LEFT_ROWS.map(r=>r.filter);
        if(!filters.length){ META={}; return; }
        const body=JSON.stringify({filters});
        const res=await fetch(location.pathname+'?action=load_meta',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка META');
        META = data.items || {};
    }
    async function listOrders(q='',limit=100){
        const body=JSON.stringify({q,limit});
        const res=await fetch(location.pathname+'?action=list_orders',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка поиска заявок');
        return data.items||[];
    }
    async function loadLeftRows(order){
        const body=JSON.stringify({order});
        const res=await fetch(location.pathname+'?action=load_left_rows',{method:'POST', headers:{'Content-Type':'application/json'}, body});
        const data=await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка фильтров');
        return data.items||[];
    }
    async function planBounds(order){
        const body = JSON.stringify({order});
        const res = await fetch(location.pathname+'?action=plan_bounds', {
            method:'POST', headers:{'Content-Type':'application/json'}, body
        });
        const data = await res.json();
        if(!data.ok) throw new Error(data.error||'Ошибка диапазона');
        return data; // {ok:true, min, max, days}
    }


    // ==== Order picker ====
    function openOrderModal(){ el('orderModal').classList.remove('hidden'); el('ordQuery').focus(); renderOrderList([]); (async()=>{ try{ const rows=await listOrders('',50); renderOrderList(rows); }catch(e){ toast(e.message,'err'); } })(); }
    function closeOrderModal(){ el('orderModal').classList.add('hidden'); }
    function renderOrderList(rows){
        const box=el('ordList');
        if(!rows.length){ box.innerHTML='<div class="orditem" style="justify-content:center;color:#6b7280">Ничего не найдено</div>'; return; }
        box.innerHTML='';
        rows.forEach(r=>{
            const div=document.createElement('div');
            div.className='orditem';
            div.innerHTML=`<div class="ordnum">${r.order_number}</div><div class="help">позиций: ${r.positions||0} · всего: ${r.qty||0}</div>`;
            div.addEventListener('click', ()=>selectOrder(r.order_number));
            box.appendChild(div);
        });
    }
    async function selectOrder(order){
        if(DIRTY && !confirm('Есть несохранённые изменения. Переключить заявку?')) return;
        try{

            ORDER = String(order);
            history.replaceState(null,'', location.pathname+'?order_number='+encodeURIComponent(ORDER));
            el('titleOrder').textContent = ORDER;
            // Перезагрузить левый столбец и мету
            const rows = await loadLeftRows(ORDER);
            LEFT_ROWS = rows.map(r=>({filter:r.filter, ord:parseInt(r.ordered_qty,10)||0}));
            await loadMeta(); measureAndSetWidths();
            // Перестроить даты и загрузить данные
            const todayISO2 = new Date().toISOString().slice(0,10);

            // Авто-диапазон по сохранённому плану новой заявки
            let bounds = await planBounds(ORDER);
            if (bounds.min && bounds.max && bounds.days > 0) {
                startDateISO = bounds.min;
                days = bounds.days;
                el('startDate').value = startDateISO;
                el('days').value = String(days);
            } else {
                startDateISO = el('startDate').value || todayISO2;
                days = Math.max(1, parseInt(el('days').value,10) || 1);
            }

            dayCap = Math.max(0, parseInt(el('cap').value,10) || 0);
            rebuildDates();
            await Promise.all([loadForeign(), loadPlan()]);
            buildMainTable();
            el('chipDays').textContent = `Дней: ${days}`;

            toast('Заявка переключена: #'+ORDER,'ok');
            closeOrderModal();
        }catch(e){ toast('Не удалось переключить: '+e.message,'err'); }
    }

    // ==== init ====
    (async function init(){
        const todayISO = new Date().toISOString().slice(0,10);
        el('startDate').value = todayISO; el('days').value = '7'; el('cap').value = '0';
        try{ await loadMeta(); }catch(e){ console.warn('META load failed',e); }
        measureAndSetWidths();

        el('btnPickOrder').addEventListener('click', openOrderModal);
        el('ordFind').addEventListener('click', async ()=>{ try{ const rows=await listOrders(el('ordQuery').value.trim(), 100); renderOrderList(rows);}catch(e){toast(e.message,'err');} });
        el('ordQuery').addEventListener('keydown', async (e)=>{ if(e.key==='Enter'){ try{ const rows=await listOrders(el('ordQuery').value.trim(), 100); renderOrderList(rows);}catch(err){toast(err.message,'err');} } });
        el('ordClose').addEventListener('click', closeOrderModal);
        el('orderModal').addEventListener('click', (e)=>{ if(e.target.classList.contains('modal__backdrop')) closeOrderModal(); });

        el('btnRebuild').addEventListener('click', async ()=>{
            const todayISO2 = new Date().toISOString().slice(0,10);
            startDateISO = el('startDate').value || todayISO2;
            days = Math.max(1, parseInt(el('days').value,10) || 1);
            dayCap = Math.max(0, parseInt(el('cap').value,10) || 0);
            rebuildDates();
            try{ await loadForeign(); }catch(e){ console.warn('FOREIGN error',e); }
            buildMainTable(); markDirty(true);
        });
        el('cap').addEventListener('input', ()=>{
            dayCap = Math.max(0, parseInt(el('cap').value,10) || 0);
            recalcDayTotals();
            el('chipCap').textContent = `Лимит/смену: ${dayCap}`;
            markDirty(true);
        });
        el('btnSave').addEventListener('click', async ()=>{
            try{ await savePlan(); markDirty(false); toast('План сохранён','ok'); }
            catch(e){ toast('Не удалось сохранить: '+e.message,'err'); }
        });
        el('btnLoad').addEventListener('click', async ()=>{
            try{
                const todayISO = new Date().toISOString().slice(0,10);

                // 1) пробуем подтянуть диапазон из сохранённого плана
                let bounds = await planBounds(ORDER);
                if (bounds.min && bounds.max && bounds.days > 0) {
                    startDateISO = bounds.min;
                    days = bounds.days;
                    el('startDate').value = startDateISO;
                    el('days').value = String(days);
                } else {
                    // если плана ещё нет — оставляем то, что в инпутах
                    startDateISO = el('startDate').value || todayISO;
                    days = Math.max(1, parseInt(el('days').value,10) || 1);
                }

                dayCap = Math.max(0, parseInt(el('cap').value,10) || 0);
                rebuildDates();
                await Promise.all([loadForeign(), loadPlan()]);
                buildMainTable();
                el('chipDays').textContent = `Дней: ${days}`;
                toast('План загружен','ok');
            }catch(e){ toast('Не удалось загрузить: '+e.message,'err'); }
        });
        el('btnClear').addEventListener('click', ()=>{
            if(confirm('Очистить текущий план?')){
                plan.clear(); buildMainTable(); markDirty(true);
            }
        });

        // первичный рендер
        startDateISO = todayISO; days = 7; dayCap = 0; rebuildDates();
        try{ await loadForeign(); }catch(e){}
        buildMainTable();

        window.addEventListener('resize', debounce(()=>{ measureAndSetWidths(); }, 200));
        el('chipFilters').textContent = `Фильтров: ${LEFT_ROWS.length}`;
        el('chipDays').textContent = `Дней: ${dates.length}`;
    })();
</script>
<script>
    /* ===== Печать: генерация «листов» с нарезкой по дням ===================== */

    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    }
    function fmtDMText(iso){
        const dt=new Date(iso);
        const dd=String(dt.getDate()).padStart(2,'0');
        const mm=String(dt.getMonth()+1).padStart(2,'0');
        const w=['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][dt.getDay()];
        return `${dd}.${mm} (${w})`;
    }

    function computeOursTotalsMap() {
        const totals=new Map(); dates.forEach(d=>totals.set(d,0));
        plan.forEach((q,k)=>{
            const date=k.split('|')[1];
            if (!totals.has(date)) totals.set(date,0);
            totals.set(date, (totals.get(date)||0) + (q||0));
        });
        return totals;
    }

    function buildPrintArea(colsPerPage){
        const area = document.getElementById('printArea');
        area.innerHTML = '';

        const oursTotals = computeOursTotalsMap();

        // вспомогательная отрисовка одного листа
        const renderSheet = (sliceDates) => {
            let html = `<div class="sheet">`;
            html += `<h3>План сборки — заявка #${escapeHtml(String(ORDER))} · ${escapeHtml(sliceDates[0])} — ${escapeHtml(sliceDates[sliceDates.length-1])}</h3>`;
            html += `<table><thead><tr>`;
            html += `<th style="min-width:160px;text-align:left">Фильтр</th>`;
            html += `<th style="width:70px;text-align:right">Заказано</th>`;
            html += `<th style="width:70px;text-align:right">В плане</th>`;

            for (const d of sliceDates){
                const fObj = FOREIGN.get(d) || {qty:0};
                const combined = (oursTotals.get(d)||0) + (fObj.qty||0);
                const foreignStr = fObj.qty ? ` [${fObj.qty}]` : '';
                html += `<th style="text-align:center">${escapeHtml(fmtDMText(d))}<div style="font-size:10px;color:#64748b">Σ ${combined}${foreignStr}</div></th>`;
            }
            html += `</tr></thead><tbody>`;

            for (const r of LEFT_ROWS){
                const meta = META[r.filter] || {};
                const vh = (meta.val_height_mm != null) ? Math.round(meta.val_height_mm) : null;
                const pw = (meta.paper_width_mm != null) ? Math.round(meta.paper_width_mm) : null;
                const showWidth = (pw != null && pw > 450);

                const plannedTotal = getPlannedSum(r.filter);
                html += `<tr>`;
                html += `<td style="text-align:left">${escapeHtml(r.filter)}${vh!=null?` <span>[${vh}]</span>`:''}${showWidth?` <span>[600]</span>`:''}</td>`;
                html += `<td style="text-align:right">${r.ord||0}</td>`;
                html += `<td style="text-align:right">${plannedTotal||0}</td>`;

                for (const d of sliceDates){
                    const q = plan.get(key(r.filter, d)) || 0;
                    html += `<td style="text-align:center">${q? q : ''}</td>`;
                }
                html += `</tr>`;
            }

            html += `</tbody></table></div>`;
            area.insertAdjacentHTML('beforeend', html);
        };

        // режем массив дат на порции по colsPerPage и рисуем листы
        for (let i=0; i<dates.length; i+=colsPerPage){
            const slice = dates.slice(i, i+colsPerPage);
            renderSheet(slice);
        }
    }

    function openPrint(){
        // Кол-во колонок на лист
        const colsInput = document.getElementById('printCols');
        let colsPerPage = parseInt(colsInput?.value, 10);
        if (!Number.isFinite(colsPerPage) || colsPerPage < 3) colsPerPage = 8;
        colsPerPage = Math.min(20, Math.max(3, colsPerPage));

        // Строим печатные листы и запускаем диалог печати
        buildPrintArea(colsPerPage);
        // Небольшая задержка — чтобы DOM успел отрисоваться
        setTimeout(()=> window.print(), 0);
    }

    // Очистка контейнера после печати (чтобы не мешал экранной версии)
    window.addEventListener('afterprint', ()=> {
        const area = document.getElementById('printArea');
        if (area) area.innerHTML = '';
    });

    // Кнопка «Печать»
    document.getElementById('btnPrint')?.addEventListener('click', openPrint);
</script>
