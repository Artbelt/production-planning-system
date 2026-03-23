<?php
require_once('tools/tools.php');

require_once('settings.php');

require_once ('style/table_1.txt');

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Архив заявок</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6f9;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --active: #16a34a;
            --inactive: #f59e0b;
            --shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 1200px;
            margin: 28px auto;
            padding: 0 18px 24px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            padding: 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            justify-content: space-between;
            align-items: center;
        }

        .title {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }

        .subtitle {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            padding: 8px 12px;
            background: #f9fafb;
            white-space: nowrap;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .dot.active {
            background: var(--active);
        }

        .dot.inactive {
            background: var(--inactive);
        }

        .sections {
            padding: 18px;
            display: grid;
            gap: 16px;
        }

        .section {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fcfdff;
            padding: 16px;
        }

        .section-header {
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .section-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .section-count {
            font-size: 13px;
            color: var(--muted);
        }

        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 10px;
        }

        .order-btn {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            border: 1px solid #d1d5db;
            background: #ecfdf3;
            color: var(--text);
            border-radius: 10px;
            padding: 10px 12px;
            text-align: left;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.3;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .order-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(15, 23, 42, 0.08);
            border-color: #9ca3af;
        }

        .order-btn.alert {
            background: #fff8ee;
            border-color: #fdba74;
        }

        .order-btn.inactive {
            background: #fff9ea;
            border-color: #fcd34d;
        }

        .empty {
            color: var(--muted);
            font-size: 14px;
            margin: 2px 0 0;
        }

        details.section {
            padding: 0;
            overflow: hidden;
        }

        details .section-inner {
            padding: 16px;
            border-top: 1px solid var(--border);
        }

        details summary.section-header {
            list-style: none;
            cursor: pointer;
            margin: 0;
            padding: 16px;
        }

        details summary.section-header::-webkit-details-marker {
            display: none;
        }

        .toggle-icon {
            color: var(--muted);
            font-size: 13px;
        }

        details[open] .toggle-icon::before {
            content: "Скрыть";
        }

        details:not([open]) .toggle-icon::before {
            content: "Показать";
        }
    </style>
</head>
<body>
<?php
/** Подключаемся к БД */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan');
$rows = $pdo->query("SELECT DISTINCT order_number, workshop, hide FROM orders")->fetchAll(PDO::FETCH_ASSOC);

$activeOrders = [];
$inactiveOrders = [];

foreach ($rows as $orderData) {
    if ((int)$orderData['hide'] === 1) {
        $inactiveOrders[] = $orderData;
    } else {
        $activeOrders[] = $orderData;
    }
}
?>
<main class="page">
    <section class="card">
        <header class="header">
            <div>
                <h1 class="title">Архив заявок</h1>
                <p class="subtitle">Откройте нужную заявку в новой вкладке</p>
            </div>
            <div class="stats">
                <span class="pill"><span class="dot active"></span>Активные: <?php echo count($activeOrders); ?></span>
                <span class="pill"><span class="dot inactive"></span>Неактивные: <?php echo count($inactiveOrders); ?></span>
            </div>
        </header>

        <div class="sections">
            <section class="section">
                <div class="section-header">
                    <h2 class="section-title">Активные заявки</h2>
                    <span class="section-count"><?php echo count($activeOrders); ?> шт.</span>
                </div>

                <?php if (count($activeOrders) === 0): ?>
                    <p class="empty">Активных заявок нет.</p>
                <?php else: ?>
                    <form class="orders-grid" action="show_order.php" method="post" target="_blank">
                        <?php foreach ($activeOrders as $order): ?>
                            <?php
                                $orderNumber = htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8');
                                $isAlert = str_contains((string)$order['order_number'], '[!]');
                                $buttonClass = $isAlert ? 'order-btn alert' : 'order-btn';
                            ?>
                            <button type="submit" class="<?php echo $buttonClass; ?>" name="order_number" value="<?php echo $orderNumber; ?>">
                                <?php echo $orderNumber; ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                <?php endif; ?>
            </section>

            <details class="section">
                <summary class="section-header">
                    <h2 class="section-title">Неактивные заявки</h2>
                    <span class="section-count"><?php echo count($inactiveOrders); ?> шт. · <span class="toggle-icon"></span></span>
                </summary>
                <div class="section-inner">
                    <?php if (count($inactiveOrders) === 0): ?>
                        <p class="empty">Неактивных заявок нет.</p>
                    <?php else: ?>
                        <form class="orders-grid" action="show_order.php" method="post" target="_blank">
                            <?php foreach ($inactiveOrders as $order): ?>
                                <?php $orderNumber = htmlspecialchars((string)$order['order_number'], ENT_QUOTES, 'UTF-8'); ?>
                                <button type="submit" class="order-btn inactive" name="order_number" value="<?php echo $orderNumber; ?>">
                                    <?php echo $orderNumber; ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </section>
</main>
</body>
</html>