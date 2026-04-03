<?php
/**
 * Сохранение панельного фильтра — подключение как на остальных страницах plan (PDO + env.php).
 */

/** Скрипт принимает только POST-запросы */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die('Неверный метод запроса. Используйте форму добавления фильтра.');
}

/** Пустая строка в INT/FLOAT при STRICT MySQL даёт ошибку — в БД передаём NULL или число */
$intOrNull = static function ($v): ?int {
    if ($v === null) {
        return null;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return null;
    }
    $n = str_replace(',', '.', $s);
    if (!is_numeric($n)) {
        return null;
    }
    return (int)round((float)$n);
};
$floatOrNull = static function ($v): ?float {
    if ($v === null) {
        return null;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return null;
    }
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) {
        return null;
    }
    return (float)$s;
};

$filter_name = $_POST['filter_name'] ?? '';
$category    = $_POST['category'] ?? '';

/** ГОФРОПАКЕТ */
$p_p_name          = 'гофропакет ' . $filter_name;
$p_p_length        = $floatOrNull($_POST['p_p_length'] ?? '');
$p_p_width         = $floatOrNull($_POST['p_p_width'] ?? '');
$p_p_height        = $floatOrNull($_POST['p_p_height'] ?? '');
$p_p_pleats_count  = $intOrNull($_POST['p_p_pleats_count'] ?? '');
$p_p_amplifier     = $intOrNull($_POST['p_p_amplifier'] ?? '');
$p_p_supplier      = $_POST['p_p_supplier'] ?? '';
$p_p_material      = $_POST['p_p_material'] ?? '';
$p_p_remark        = $_POST['p_p_remark'] ?? '';

/** КАРКАС (имя блока — по непустым полям формы; 0 в размере допустим) */
$wf_length_raw = $_POST['wf_length'] ?? '';
$wf_width_raw  = $_POST['wf_width'] ?? '';
$wf_material   = $_POST['wf_material'] ?? '';
$wf_supplier   = $_POST['wf_supplier'] ?? '';
$wf_name       = (trim((string)$wf_length_raw) !== '' && trim((string)$wf_width_raw) !== '' && $wf_material && $wf_supplier)
    ? 'каркас ' . $filter_name
    : '';
$wf_length = $floatOrNull($wf_length_raw);
$wf_width  = $floatOrNull($wf_width_raw);

/** ПРЕДФИЛЬТР */
$pf_length_raw = $_POST['pf_length'] ?? '';
$pf_width_raw  = $_POST['pf_width'] ?? '';
$pf_material   = $_POST['pf_material'] ?? '';
$pf_supplier   = $_POST['pf_supplier'] ?? '';
$pf_remark     = $_POST['pf_remark'] ?? '';
$pf_name       = (trim((string)$pf_length_raw) !== '' && trim((string)$pf_width_raw) !== '' && $pf_material && $pf_supplier)
    ? 'предфильтр ' . $filter_name
    : '';
$pf_length = $floatOrNull($pf_length_raw);
$pf_width  = $floatOrNull($pf_width_raw);

/** ПРОЛИВКА (glueing — INTEGER, пустое => NULL) */
$glueing_raw     = $_POST['glueing'] ?? '';
$glueing         = ($glueing_raw === '' || $glueing_raw === null) ? null : (int)$glueing_raw;
$glueing_remark  = $_POST['glueing_remark'] ?? '';

/** ФОРМ-ФАКТОР (form_factor_id — INTEGER, пустое => NULL) */
$form_factor_raw = $_POST['form_factor'] ?? '';
$form_factor_id    = ($form_factor_raw === '' || $form_factor_raw === null) ? null : (int)$form_factor_raw;

$form_factor_remark = $_POST['form_factor_remark'] ?? '';

/** УПАКОВКА */
$box    = $_POST['box'] ?? '';
$g_box  = $_POST['g_box'] ?? '';
$remark = $_POST['remark'] ?? '';

/** ЛОГ ИЗМЕНЕНИЙ */
$changes_log = isset($_POST['changes_log']) ? (string)$_POST['changes_log'] : '';
$ip_address  = $_SERVER['REMOTE_ADDR'] ?? '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_name = $_SESSION['user_name'] ?? 'Гость';

try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan');

    $stmtExists = $pdo->prepare('SELECT COUNT(*) FROM panel_filter_structure WHERE filter = ?');
    $stmtExists->execute([$filter_name]);
    $a = (int)$stmtExists->fetchColumn();

    $pdo->beginTransaction();

    $sqlPfs = "INSERT INTO panel_filter_structure
        (filter, category, paper_package, wireframe, prefilter, glueing, glueing_remark,
         form_factor_id, form_factor_remark, box, g_box, comment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            category = VALUES(category),
            paper_package = VALUES(paper_package),
            wireframe = VALUES(wireframe),
            prefilter = VALUES(prefilter),
            glueing = VALUES(glueing),
            glueing_remark = VALUES(glueing_remark),
            form_factor_id = VALUES(form_factor_id),
            form_factor_remark = VALUES(form_factor_remark),
            box = VALUES(box),
            g_box = VALUES(g_box),
            comment = VALUES(comment)";
    $stmtPfs = $pdo->prepare($sqlPfs);
    $stmtPfs->execute([
        $filter_name,
        $category,
        $p_p_name,
        $wf_name,
        $pf_name,
        $glueing,
        $glueing_remark,
        $form_factor_id,
        $form_factor_remark,
        $box,
        $g_box,
        $remark,
    ]);

    $sqlPp = "INSERT INTO paper_package_panel
        (p_p_name, p_p_length, p_p_height, p_p_width, p_p_pleats_count, p_p_amplifier, supplier, p_p_material, p_p_remark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            p_p_length = VALUES(p_p_length),
            p_p_height = VALUES(p_p_height),
            p_p_width = VALUES(p_p_width),
            p_p_pleats_count = VALUES(p_p_pleats_count),
            p_p_amplifier = VALUES(p_p_amplifier),
            supplier = VALUES(supplier),
            p_p_material = VALUES(p_p_material),
            p_p_remark = VALUES(p_p_remark)";
    $stmtPp = $pdo->prepare($sqlPp);
    $stmtPp->execute([
        $p_p_name,
        $p_p_length,
        $p_p_height,
        $p_p_width,
        $p_p_pleats_count,
        $p_p_amplifier,
        $p_p_supplier,
        $p_p_material,
        $p_p_remark,
    ]);

    if ($wf_name !== '') {
        $sqlWf = "INSERT INTO wireframe_panel (w_name, w_length, w_width, w_material, w_supplier)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                w_length = VALUES(w_length),
                w_width = VALUES(w_width),
                w_material = VALUES(w_material),
                w_supplier = VALUES(w_supplier)";
        $stmtWf = $pdo->prepare($sqlWf);
        $stmtWf->execute([$wf_name, $wf_length, $wf_width, $wf_material, $wf_supplier]);
    }

    if ($pf_name !== '') {
        $sqlPf = "INSERT INTO prefilter_panel (p_name, p_length, p_width, p_material, p_supplier, p_remark)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                p_length = VALUES(p_length),
                p_width = VALUES(p_width),
                p_material = VALUES(p_material),
                p_supplier = VALUES(p_supplier),
                p_remark = VALUES(p_remark)";
        $stmtPf = $pdo->prepare($sqlPf);
        $stmtPf->execute([$pf_name, $pf_length, $pf_width, $pf_material, $pf_supplier, $pf_remark]);
    }

    if (trim($changes_log) !== '') {
        $stmtLog = $pdo->prepare(
            'INSERT INTO changes_log (filter_name, user_name, changes, ip_address) VALUES (?, ?, ?, ?)'
        );
        $stmtLog->execute([$filter_name, $user_name, $changes_log, $ip_address]);
    }

    $pdo->commit();

    if ($a > 0) {
        echo "Фильтр {$filter_name} обновлён в БД";
    } else {
        echo "Фильтр {$filter_name} успешно добавлен в БД";
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ошибка сохранения фильтра: ' . $e->getMessage());
}
