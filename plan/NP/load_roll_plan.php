<?php
header('Content-Type: text/plain; charset=utf-8');

try{
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4","root","",[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);

    $raw = file_get_contents('php://input');
    $payload = $raw ? json_decode($raw, true) : [];
    $order = (string)($payload['order'] ?? '');
    $plan  = $payload['plan'] ?? [];

    if ($order===''){ http_response_code(400); echo "no order"; exit; }
    if (!is_array($plan)){ http_response_code(400); echo "bad plan"; exit; }

    // Сформируем карту: bale_id => plan_date
    $newMap = [];
    foreach ($plan as $date => $bales){
        $dd = DateTime::createFromFormat('Y-m-d', (string)$date);
        if (!$dd || $dd->format('Y-m-d') !== (string)$date) continue;
        if (!is_array($bales)) continue;
        foreach ($bales as $bid){
            $b = trim((string)$bid);
            if ($b==='') continue;
            // каждая бухта может быть только в один день
            $newMap[$b] = $dd->format('Y-m-d');
        }
    }

    $pdo->beginTransaction();

    // Текущие бухты по заявке
    $st = $pdo->prepare("SELECT bale_id FROM roll_plan WHERE order_number=?");
    $st->execute([$order]);
    $existing = [];
    foreach ($st as $r) $existing[] = (string)$r['bale_id'];

    // Те, что были, но не присланы сейчас — обнулим дату (не удаляем, чтобы не потерять done)
    if ($existing){
        $toNull = array_diff($existing, array_keys($newMap));
        if ($toNull){
            foreach (array_chunk($toNull, 500) as $part){
                $in = implode(',', array_fill(0, count($part), '?'));
                $sql = "UPDATE roll_plan SET plan_date=NULL WHERE order_number=? AND bale_id IN ($in)";
                $args = array_merge([$order], $part);
                $pdo->prepare($sql)->execute($args);
            }
        }
    }

    // Upsert присланных: вставить/обновить plan_date, поле done не трогаем
    $ins = $pdo->prepare("
        INSERT INTO roll_plan(order_number, bale_id, plan_date)
        VALUES(?,?,?)
        ON DUPLICATE KEY UPDATE plan_date=VALUES(plan_date)
    ");
    foreach ($newMap as $baleId => $date){
        $ins->execute([$order, $baleId, $date]);
    }

    $pdo->commit();
    echo "ok";

} catch(Throwable $e){
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo $e->getMessage();
}
