<?php
/**
 * Активные позиции: строки по незакрытым позициям (заказано > изготовлено) в активных заявках.
 */

/** Макс. % выполнения для показа (включительно). Строго выше — строка не выводится; порог потом можно завязать на настройки. */
$activePositionsMaxCompletionPct = 80;

require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan_u3');

$maxPct = max(0, min(100, (int) $activePositionsMaxCompletionPct));

$sql = "
SELECT
    agg.order_number,
    agg.filter_name,
    agg.ordered,
    COALESCE(prod.produced, 0) AS produced
FROM (
    SELECT order_number, `filter` AS filter_name, SUM(`count`) AS ordered
    FROM orders
    WHERE (hide IS NULL OR hide != 1)
    GROUP BY order_number, `filter`
) agg
LEFT JOIN (
    SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
    FROM manufactured_production
    GROUP BY name_of_order, name_of_filter
) prod
    ON prod.name_of_order = agg.order_number
   AND prod.name_of_filter = agg.filter_name
WHERE agg.ordered > COALESCE(prod.produced, 0)
  AND agg.ordered > 0
  AND COALESCE(prod.produced, 0) * 100 <= {$maxPct} * agg.ordered
ORDER BY agg.order_number, agg.filter_name
";

$rows = [];
try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$pageTitle = 'Активные позиции';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — U3</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2457e6;
            --radius: 12px;
            --shadow: 0 2px 12px rgba(2, 8, 20, .06);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
        }
        .wrap {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        .top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .top h1 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 600;
        }
        .top a.back {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .top a.back:hover { text-decoration: underline; }
        .muted { color: var(--muted); font-size: 13px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            line-height: 1.35;
        }
        th, td {
            padding: 5px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 12px;
            color: #374151;
        }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: #fafbfc; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* Гистограмма выполнения в ячейке «Позиция» */
        td.pos-cell {
            position: relative;
            vertical-align: middle;
            min-width: 200px;
            overflow: hidden;
        }
        td.pos-cell .pos-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--pct, 0%);
            pointer-events: none;
            border-radius: 0 4px 4px 0;
            transition: width 0.2s ease, opacity 0.15s;
        }
        td.pos-cell .pos-meta {
            position: relative;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 6px;
        }
        td.pos-cell .pos-name {
            font-weight: 500;
            word-break: break-word;
        }
        td.pos-cell .pos-pct {
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.75);
            padding: 1px 4px;
            border-radius: 4px;
            border: 1px solid var(--border);
        }
        .order-cell form { display: inline; margin: 0; }
        .order-cell button {
            appearance: none;
            border: 0;
            background: none;
            color: var(--accent);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .order-cell button:hover { color: #1e47c5; }
        tr.pos-row { cursor: pointer; }
        tr.pos-row td { user-select: none; }
        tr.pos-row td.num { user-select: text; }
        tr.pos-row td.order-cell,
        tr.pos-row td.order-cell * { user-select: auto; cursor: auto; }
        tr.pos-row td.order-cell button { cursor: pointer; }
        tr.pos-row:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
        tr.pos-row td.pos-cell::before {
            content: '▸';
            display: inline-block;
            width: 0.85em;
            margin-right: 4px;
            color: var(--muted);
            font-size: 11px;
            vertical-align: middle;
            position: relative;
            z-index: 2;
        }
        tr.pos-row.expanded td.pos-cell::before { content: '▾'; }
        tr.pos-detail-row td {
            padding: 0;
            border-bottom: 1px solid var(--border);
            background: #fafbfc;
            vertical-align: top;
        }
        tr.pos-detail-row[hidden] { display: none; }
        .pos-detail-inner {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px 16px;
            padding: 8px 10px 10px 28px;
            font-size: 12px;
            line-height: 1.4;
            color: var(--ink);
        }
        .plan-block-title {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #4b5563;
            margin: 0 0 4px;
        }
        .plan-block ul {
            margin: 0;
            padding-left: 1.15em;
        }
        .plan-block li { margin: 2px 0; }
        .plan-block .empty { color: var(--muted); font-style: italic; }
        .pos-detail-loading { padding: 10px 28px; font-size: 12px; color: var(--muted); }
        .pos-detail-error { padding: 10px 28px; font-size: 12px; color: #b91c1c; }
        .alert {
            padding: 14px 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius);
            color: #991b1b;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <a class="back" href="main.php">← На главную</a>
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </div>
    <p class="muted" style="margin: 0 0 16px;">
        Позиции по заявкам без признака «скрыта», у которых заказано больше, чем изготовлено фильтров по данным выпуска.
        Показаны позиции с процентом выполнения не выше <?= (int) $maxPct ?>% (включительно).
        Нажмите на строку (кроме номера заявки), чтобы раскрыть планы: раскрой, гофрирование, сборка.
    </p>

    <?php if (!empty($loadError)): ?>
        <div class="alert">Ошибка загрузки: <?= htmlspecialchars($loadError) ?></div>
    <?php else: ?>
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Позиция</th>
                        <th class="num">Заказано</th>
                        <th class="num">Изготовлено</th>
                        <th>Заявка</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="4" class="muted" style="text-align:center;padding:12px;">
                            Нет незакрытых позиций по активным заявкам.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $ord = htmlspecialchars((string)($r['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $fil = htmlspecialchars((string)($r['filter_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $rawOrder = (string)($r['order_number'] ?? '');
                        $rawFilter = (string)($r['filter_name'] ?? '');
                        $ordered = (int)($r['ordered'] ?? 0);
                        $produced = (int)($r['produced'] ?? 0);
                        $pct = $ordered > 0 ? min(100, ($produced / $ordered) * 100) : 0;
                        $pctStr = number_format(round($pct, 1), 1, ',', ' ');
                        $hue = (int) round($pct * 1.2);
                    ?>
                    <tr
                        class="pos-row"
                        tabindex="0"
                        role="button"
                        aria-expanded="false"
                        data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                        data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <td
                            class="pos-cell"
                            style="--pct: <?= htmlspecialchars((string) round($pct, 4), ENT_QUOTES, 'UTF-8') ?>%;"
                            title="Выполнение позиции: <?= htmlspecialchars($pctStr, ENT_QUOTES, 'UTF-8') ?>% (<?= (int) $produced ?> из <?= (int) $ordered ?>)"
                        >
                            <span class="pos-fill" style="background: hsla(<?= $hue ?>, 65%, 52%, 0.28);"></span>
                            <div class="pos-meta">
                                <span class="pos-name"><?= $fil !== '' ? $fil : '—' ?></span>
                                <span class="pos-pct"><?= htmlspecialchars($pctStr, ENT_QUOTES, 'UTF-8') ?>%</span>
                            </div>
                        </td>
                        <td class="num"><?= $ordered ?></td>
                        <td class="num"><?= $produced ?></td>
                        <td class="order-cell">
                            <form action="show_order.php" method="post" target="_blank" rel="noopener">
                                <input type="hidden" name="order_number" value="<?= $ord ?>">
                                <button type="submit"><?= $ord ?></button>
                            </form>
                        </td>
                    </tr>
                    <tr class="pos-detail-row" hidden data-loaded="0">
                        <td colspan="4">
                            <div class="pos-detail-loading">Загрузка планов…</div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    function fmtDate(iso) {
        if (!iso || typeof iso !== 'string') return iso;
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        return p[2] + '.' + p[1] + '.' + p[0];
    }
    function renderPlans(data) {
        function block(title, items, withQty) {
            var h = '<div class="plan-block"><div class="plan-block-title">' + title + '</div>';
            if (!items || !items.length) {
                return h + '<div class="empty">Нет данных в плане</div></div>';
            }
            h += '<ul>';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var line = fmtDate(it.date);
                if (withQty && typeof it.qty === 'number' && it.qty > 0) {
                    line += ' — ' + it.qty + ' шт.';
                }
                h += '<li>' + line + '</li>';
            }
            h += '</ul></div>';
            return h;
        }
        return (
            block('Раскрой', data.cut, false) +
            block('Гофрирование', data.corrugation, true) +
            block('Сборка (дневная смена)', data.build, true)
        );
    }
    function toggleRow(row, open) {
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('pos-detail-row')) return;
        var wantOpen = open !== undefined ? open : detail.hidden;
        detail.hidden = !wantOpen;
        row.classList.toggle('expanded', wantOpen);
        row.setAttribute('aria-expanded', wantOpen ? 'true' : 'false');
        return detail;
    }
    function loadPlans(row, detail) {
        var inner = detail.querySelector('td');
        inner.innerHTML = '<div class="pos-detail-loading">Загрузка планов…</div>';
        var fd = new FormData();
        fd.append('order_number', row.getAttribute('data-order') || '');
        fd.append('filter', row.getAttribute('data-filter') || '');
        fetch('active_position_plans.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    inner.innerHTML = '<div class="pos-detail-error">' +
                        (data && data.error ? String(data.error) : 'Не удалось загрузить планы') + '</div>';
                    return;
                }
                inner.innerHTML = '<div class="pos-detail-inner">' + renderPlans(data) + '</div>';
                detail.setAttribute('data-loaded', '1');
            })
            .catch(function () {
                inner.innerHTML = '<div class="pos-detail-error">Ошибка сети при загрузке планов</div>';
            });
    }
    document.querySelectorAll('tr.pos-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.order-cell')) return;
            var detail = row.nextElementSibling;
            if (!detail || !detail.classList.contains('pos-detail-row')) return;
            var willOpen = detail.hidden;
            if (!willOpen) {
                toggleRow(row, false);
                return;
            }
            toggleRow(row, true);
            if (detail.getAttribute('data-loaded') === '1') return;
            loadPlans(row, detail);
        });
        row.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (e.target.closest('.order-cell')) return;
            e.preventDefault();
            row.click();
        });
    });
})();
</script>
</body>
</html>
