<?php
// NP_view_corrugation_plan.php — просмотр/печать плана гофрирования по дням
// GET: ?order=XXXX
require_once __DIR__ . '/settings.php';

$order = $_GET['order'] ?? '';
if ($order === '') { http_response_code(400); exit('Укажите ?order=...'); }

try{
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    // Достаём план по заявке
    $st = $pdo->prepare("
        SELECT id, plan_date, filter_label, `count`, fact_count
        FROM corrugation_plan
        WHERE order_number = ?
        ORDER BY plan_date, filter_label
    ");
    $st->execute([$order]);
    $rows = $st->fetchAll();

    // Группировка по дате
    $byDate = [];
    foreach($rows as $r){
        $d = $r['plan_date'] ?: '';
        if (!isset($byDate[$d])) $byDate[$d] = [];
        $byDate[$d][] = $r;
    }
    ksort($byDate);

    // агрегаты
    $grandPlan = 0; $grandFact = 0;

    // диапазон дат (мин/макс) — пригодится для заголовка
    $dates = array_keys($byDate);
    $minDate = $dates ? min($dates) : null;
    $maxDate = $dates ? max($dates) : null;

    function ruDow($isoDate){
        if (!$isoDate) return '';
        $ts = strtotime($isoDate);
        $d = (int)date('w',$ts); // 0..6
        $names = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
        return $names[$d] ?? '';
    }

} catch(Throwable $e){
    http_response_code(500);
    echo "Ошибка БД: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Просмотр гофроплана — заявка <?=htmlspecialchars($order)?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280;
            --line:#e5e7eb; --ok:#16a34a; --warn:#ef4444; --accent:#2563eb;
        }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:12px;color:var(--text)}
        .wrap{max-width:1100px;margin:0 auto}
        h2{margin:0 0 4px;font-size:18px}
        .sub{color:var(--muted); margin-bottom:6px;font-size:12px}

        .toolbar{display:flex;gap:8px;align-items:center;margin:6px 0 10px}
        .btn{padding:10px 16px;border-radius:8px;background:var(--accent);color:#fff;border:1px solid var(--accent);cursor:pointer;text-decoration:none;font-weight:600;font-size:15px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(37,99,235,0.2)}
        .btn:hover{filter:brightness(.95);box-shadow:0 2px 6px rgba(37,99,235,0.3)}
        .btn:before{content:"🖨️"}
        .btn-ghost{padding:8px 12px;border-radius:8px;background:#eef2ff;color:#374151;border:1px solid #c7d2fe;text-decoration:none}
        .totals{margin:6px 0 12px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;display:flex;gap:14px;flex-wrap:wrap;font-size:13px}

        .days-container{display:grid;grid-template-columns:repeat(auto-fit, minmax(350px, 1fr));gap:12px;margin:8px 0}
        .day-card{background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;min-width:0;display:flex;flex-direction:column}
        .day-head{display:flex;flex-direction:column;padding:6px 8px;background:#f3f4f6;border-bottom:1px solid var(--line);gap:4px}
        .day-title{font-weight:600;font-size:13px}
        .day-sub{font-size:10px;color:var(--muted);line-height:1.3}
        .day-card table{width:100%;border-collapse:collapse;table-layout:fixed}
        th,td{border:1px solid var(--line);padding:5px 6px;text-align:left;font-size:11px;word-wrap:break-word}
        th{background:#fafafa;font-weight:600;font-size:11px}
        td.num, th.num{text-align:right}
        th:first-child{width:35px}
        th:last-child{width:90px}

        .done{color:var(--ok); font-weight:600;font-size:11px}
        .warn{color:var(--warn); font-weight:600;font-size:11px}

        @media (max-width: 768px){
            .days-container{grid-template-columns:1fr}
        }
        @media print{
            @page{ size: A4 portrait; margin: 8mm; }
            body{background:#fff;margin:0}
            .toolbar{display:none}
            .wrap{max-width:none}
            .days-container{grid-template-columns:repeat(2, 1fr);gap:8px}
            .day-card{page-break-inside:avoid;break-inside:avoid}
            th,td{font-size:9px;padding:2px 4px;line-height:1.2}
            .day-head{padding:4px 6px}
            .day-title{font-size:11px}
            .day-sub{font-size:8px;line-height:1.2}
            table{border-spacing:0}
        }
    </style>
</head>
<body>
<div class="wrap">
    <h2>Просмотр гофроплана — заявка <?=htmlspecialchars($order)?></h2>
    <div class="sub">
        <?= $minDate && $maxDate
            ? 'Диапазон: <b>'.htmlspecialchars($minDate).'</b> — <b>'.htmlspecialchars($maxDate).'</b>'
            : 'Даты не заданы' ?>
    </div>

    <div class="toolbar">
        <a class="btn" href="#" onclick="window.print();return false;">Печать</a>
    </div>

    <?php if (empty($byDate)): ?>
        <div class="day-card">
            <div class="day-head">
                <div class="day-title">Нет данных по плану</div>
            </div>
            <div style="padding:8px 10px;font-size:12px">Для этой заявки нет записей в <code>corrugation_plan</code>.</div>
        </div>
    <?php else: ?>
        <div class="days-container">
        <?php foreach($byDate as $date => $items): ?>
            <?php
            $sumPlan = 0; $sumFact = 0;
            foreach($items as $it){ $sumPlan += (int)$it['count']; $sumFact += (int)$it['fact_count']; }
            $grandPlan += $sumPlan; $grandFact += $sumFact;
            $remain = max(0, $sumPlan - $sumFact);
            ?>
            <div class="day-card">
                <div class="day-head">
                    <div class="day-title"><?=htmlspecialchars($date)?> <span class="day-sub">/ <?=ruDow($date)?></span></div>
                    <div class="day-sub">
                        План: <b><?=number_format($sumPlan,0,'.',' ')?></b> |
                        Осталось: <b><?=number_format($remain,0,'.',' ')?></b>
                    </div>
                </div>
                <table>
                    <tr>
                        <th style="width:30px">№</th>
                        <th>Фильтр</th>
                        <th class="num" style="width:85px">План, шт</th>
                    </tr>
                    <?php foreach($items as $i=>$it): ?>
                        <?php
                        $pl = (int)$it['count'];
                        ?>
                        <tr>
                            <td><?=($i+1)?></td>
                            <td><?=htmlspecialchars($it['filter_label'] ?? '')?></td>
                            <td class="num"><?=number_format($pl,0,'.',' ')?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th colspan="2" class="num">ИТОГО за день:</th>
                        <th class="num"><?=number_format($sumPlan,0,'.',' ')?></th>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="totals">
            <div><b>Итого по заявке:</b></div>
            <div>План, шт: <b><?=number_format($grandPlan,0,'.',' ')?></b></div>
            <div>Осталось, шт: <b><?=number_format(max(0, $grandPlan-$grandFact),0,'.',' ')?></b></div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
