<?php
/**
 * Менеджер планирования: витрина этапов под контур
 * active_positions → plan_roll_cutting → gofro_build_plan (+ раскрой как раньше).
 */

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

/**
 * Номер заявки в БД иногда дублируется с пробелами / разным регистром визуально;
 * для списка и флагов используем один канонический ключ.
 */
function planningManagerOrderKey(?string $orderNumber): string
{
    $s = preg_replace('/\s+/u', ' ', trim((string) $orderNumber));

    return $s;
}

/** Множество order_number => true с нормализованными ключами. */
function planningManagerFlipOrderColumn(array $column): array
{
    $out = [];
    foreach ($column as $v) {
        $k = planningManagerOrderKey(is_string($v) || is_numeric($v) ? (string) $v : '');
        if ($k !== '') {
            $out[$k] = true;
        }
    }

    return $out;
}

/** Пороги % выполнения для ссылок (как на боевых страницах). */
$maxPctAssembly = 94;
$maxPctGofro = 95;
if (isset($_GET['max_pct_assembly']) && $_GET['max_pct_assembly'] !== '') {
    $maxPctAssembly = max(0, min(100, (int) $_GET['max_pct_assembly']));
}
if (isset($_GET['max_pct_gofro']) && $_GET['max_pct_gofro'] !== '') {
    $maxPctGofro = max(0, min(100, (int) $_GET['max_pct_gofro']));
}

$orders = [];
$buildDone = [];
$rollDone = [];
$gofroV2Done = [];
$loadErr = '';

/** Заявки с хотя бы одной «активной» позицией — как на active_positions (остаток > 0, % выполнения ≤ порога). */
$maxPctActive = max(0, min(100, (int) $maxPctAssembly));
try {
    $sqlActive = "
        SELECT DISTINCT agg.order_number
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
          AND COALESCE(prod.produced, 0) * 100 <= {$maxPctActive} * agg.ordered
        ORDER BY agg.order_number
    ";
    $activeNums = $pdo->query($sqlActive)->fetchAll(PDO::FETCH_COLUMN);
    $activeCanon = [];
    foreach ($activeNums as $n) {
        $k = planningManagerOrderKey(is_string($n) || is_numeric($n) ? (string) $n : '');
        if ($k !== '') {
            $activeCanon[$k] = true;
        }
    }
    $activeList = array_keys($activeCanon);
    sort($activeList, SORT_STRING);

    $orders = [];
    if ($activeList !== []) {
        $ph = implode(',', array_fill(0, count($activeList), '?'));
        $st = $pdo->prepare("
            SELECT TRIM(order_number) AS order_number, MAX(COALESCE(cut_ready, 0)) AS cut_ready
            FROM orders
            WHERE TRIM(order_number) IN ({$ph})
              AND (hide IS NULL OR hide != 1)
            GROUP BY TRIM(order_number)
            ORDER BY TRIM(order_number)
        ");
        $st->execute($activeList);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as $i => $row) {
            $orders[$i]['order_number'] = planningManagerOrderKey($row['order_number'] ?? '');
        }
    }
} catch (Throwable $e) {
    $orders = [];
    $loadErr = $e->getMessage();
}

try {
    $stmt = $pdo->query('SELECT DISTINCT TRIM(order_number) AS order_number FROM build_plans WHERE COALESCE(qty, 0) > 0');
    $buildDone = planningManagerFlipOrderColumn($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $buildDone = [];
}

try {
    $stmt = $pdo->query('SELECT DISTINCT TRIM(order_number) AS order_number FROM roll_plans');
    $rollTbl = planningManagerFlipOrderColumn($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $rollTbl = [];
}

try {
    $stmt = $pdo->query('SELECT TRIM(order_number) AS order_number, MAX(COALESCE(plan_ready, 0)) AS pr FROM orders WHERE (hide IS NULL OR hide != 1) GROUP BY TRIM(order_number)');
    $planReady = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = planningManagerOrderKey($r['order_number'] ?? '');
        if ($k !== '') {
            $planReady[$k] = max($planReady[$k] ?? 0, (int) ($r['pr'] ?? 0));
        }
    }
} catch (Throwable $e) {
    $planReady = [];
}

foreach ($orders as $o) {
    $on = $o['order_number'];
    $rollDone[$on] = !empty($rollTbl[$on]) || (($planReady[$on] ?? 0) > 0);
}

try {
    $stmt = $pdo->query('SELECT DISTINCT TRIM(order_number) AS order_number FROM corrugation_plan_v2 WHERE COALESCE(qty, 0) > 0');
    $gofroV2Done = planningManagerFlipOrderColumn($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $gofroV2Done = [];
}

$pageTitle = 'Менеджер планирования';
$hAssembly = (int) $maxPctAssembly;
$hGofro = (int) $maxPctGofro;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
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

        * { box-sizing: border-box; }

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

        .toolbar-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            box-shadow: 0 1px 2px 0 hsla(220, 15%, 15%, 0.05);
        }

        .pct-form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            font-size: 0.8125rem;
            color: var(--muted-foreground);
        }

        .pct-form input[type="number"] {
            width: 4rem;
            padding: 0.375rem 0.5rem;
            border-radius: calc(var(--radius) - 2px);
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 0.8125rem;
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

        @media (max-width: 1023px) {
            .card-grid {
                grid-template-columns: 1fr !important;
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
            flex-wrap: wrap;
        }

        .app-title {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--foreground);
            margin: 0;
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
            margin: 0;
        }

        button, a.btn-primary, a.btn-secondary {
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

        .btn-sm {
            padding: 0.3rem 0.625rem;
            font-size: 0.75rem;
        }

        .btn-full {
            width: 100%;
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

        .empty-msg, .err-msg {
            font-size: 0.875rem;
            color: var(--muted-foreground);
            margin: 0;
        }

        .err-msg {
            color: var(--destructive);
            background: hsla(0, 84%, 60%, 0.08);
            border: 1px solid hsla(0, 84%, 60%, 0.25);
            border-radius: var(--radius);
            padding: 1rem;
        }

        .stage-actions {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }
    </style>
</head>
<body>
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
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="subtitle">Управление производственными заявками и планами</p>
            </div>
        </div>
    </div>
</header>

<main>
    <div class="container">
        <div class="applications-list">

    <?php if (!empty($loadErr)): ?>
        <div class="err-msg">Не удалось загрузить заявки: <?= htmlspecialchars($loadErr, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="toolbar-card">
        <form class="pct-form" method="get" action="">
            <span>Сборка ≤%</span>
            <input type="number" name="max_pct_assembly" min="0" max="100" value="<?= $hAssembly ?>">
            <span>Гофро ≤%</span>
            <input type="number" name="max_pct_gofro" min="0" max="100" value="<?= $hGofro ?>">
            <button type="submit" class="btn-primary btn-sm">Применить к списку заявок</button>
        </form>
    </div>

    <?php foreach ($orders as $o):
        $ord = $o['order_number'];
        $cut = !empty($o['cut_ready']);
        $build = !empty($buildDone[$ord]);
        $roll = !empty($rollDone[$ord]);
        $gofro = !empty($gofroV2Done[$ord]);
        $enc = rawurlencode($ord);
        ?>
        <div class="application-card">
            <div class="card-grid">
                <div class="app-info">
                    <div class="app-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--primary); width: 1.25rem; height: 1.25rem; flex-shrink: 0;">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        <h3 class="app-title"><?= htmlspecialchars($ord, ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                    <?php if ($cut && $build && $roll && $gofro): ?>
                        <span class="badge badge-success">Все этапы с данными</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Есть незакрытые этапы</span>
                    <?php endif; ?>
                </div>

                <div class="stage-section">
                    <h4 class="stage-title">Раскрой (подготовка)</h4>
                    <?php if ($cut): ?>
                        <span class="badge badge-success">Готово</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Не готов</span>
                    <?php endif; ?>
                    <div class="stage-actions">
                        <a class="btn-secondary btn-sm btn-full" href="NP_cut_plan.php?order_number=<?= $enc ?>" target="_blank" rel="noopener">Сделать / изменить раскрой</a>
                    </div>
                </div>

                <div class="stage-section">
                    <h4 class="stage-title">План сборки</h4>
                    <?php if (!$cut): ?>
                        <span class="badge badge-muted">Раскрой не готов</span>
                    <?php elseif ($build): ?>
                        <span class="badge badge-success">Готово</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Нет плана</span>
                    <?php endif; ?>
                    <div class="stage-actions">
                        <a class="btn-secondary btn-sm btn-full" href="active_positions.php?max_pct=<?= $hAssembly ?>" target="_blank" rel="noopener">Активные позиции</a>
                    </div>
                </div>

                <div class="stage-section">
                    <h4 class="stage-title">План порезки бухт</h4>
                    <?php if (!$build): ?>
                        <span class="badge badge-muted">Нет плана сборки</span>
                    <?php elseif ($roll): ?>
                        <span class="badge badge-success">Готово</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Нет порезки</span>
                    <?php endif; ?>
                    <div class="stage-actions">
                        <a class="btn-secondary btn-sm btn-full" href="plan_roll_cutting.php?order=<?= $enc ?>" target="_blank" rel="noopener">План порезки</a>
                    </div>
                </div>

                <div class="stage-section">
                    <h4 class="stage-title">План гофрирования</h4>
                    <?php if (!$roll): ?>
                        <span class="badge badge-muted">Нет плана порезки</span>
                    <?php elseif ($gofro): ?>
                        <span class="badge badge-success">Готово</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Нет плана</span>
                    <?php endif; ?>
                    <div class="stage-actions">
                        <a class="btn-secondary btn-sm btn-full" href="gofro_build_plan.php?max_pct=<?= $hGofro ?>" target="_blank" rel="noopener">План гофро</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($orders) && empty($loadErr)): ?>
        <p class="empty-msg">Нет активных заявок при пороге выполнения ≤ <?= (int) $maxPctActive ?>% (или все позиции закрыты).</p>
    <?php endif; ?>

        </div>
    </div>
</main>
</body>
</html>
