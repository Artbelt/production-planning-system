<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');

/**
 * Показываем только активные заявки (hide != 1).
 */
$sql = "
    SELECT
        bp.order_number,
        MAX(bp.assign_date) AS last_plan_date
    FROM build_plan bp
    INNER JOIN orders o ON o.order_number = bp.order_number
    WHERE (o.hide IS NULL OR o.hide != 1)
    GROUP BY bp.order_number
    ORDER BY last_plan_date DESC
";
$plans = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Планы производства</title>
    <style>
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1f2937;
            --muted:#6b7280;
            --accent:#22c55e;           /* зелёная */
            --accent-hover:#16a34a;
            --radius:12px;
            --shadow:0 2px 12px rgba(2,8,20,.06);
        }
        *{box-sizing:border-box}
        html,body{
            height:100%;
            margin:0;
            font-family:Arial, sans-serif;
            background:var(--bg);
            color:var(--ink);
        }
        .wrap{
            min-height:100%;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }
        .button-container{
            display:flex;
            flex-direction:column;
            gap:14px;
            background:var(--panel);
            padding:24px;
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            max-width:520px;
            width:100%;
        }
        .btn{
            appearance:none;
            border:0;
            border-radius:10px;
            padding:12px 20px;
            font-size:16px;
            line-height:1.2;
            cursor:pointer;
            background:var(--accent);
            color:#fff;
            transition:transform .08s ease, box-shadow .2s ease, background-color .2s ease;
            box-shadow:0 4px 10px rgba(34,197,94,.25);
            text-align:left;
        }
        .btn:hover{ background:var(--accent-hover) }
        .btn:active{ transform:translateY(1px) }

        /* маленькая подпись под номером заявки */
        .sub{
            display:block;
            font-size:12px;
            color:var(--muted);
            margin-top:4px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="button-container">
        <?php if (!empty($plans)): ?>
            <?php foreach ($plans as $row): ?>
                <?php $order = $row['order_number']; ?>
                <button class="btn" title="Открыть план по заявке"
                        onclick="window.open('view_production_plan.php?order=<?= urlencode($order) ?>','_blank')">
                    <?= htmlspecialchars($order) ?>
                    <span class="sub">статус: активная</span>
                </button>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Нет доступных планов.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
