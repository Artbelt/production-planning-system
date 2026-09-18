<?php
/**
 * Поиск позиции по тексту с подписи бухты (OCR / ручной ввод).
 * POST/GET: text = "AF 1956 48 203 33-38"
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../auth/includes/db.php';

/**
 * Нормализация: верхний регистр, латиница/цифры, схлопывание пробелов.
 */
function scan_normalize_text(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    // Частые OCR-замены кириллицы на латиницу-lookalikes
    $map = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M',
        'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T',
        'Х' => 'X', 'а' => 'A', 'е' => 'E', 'о' => 'O', 'р' => 'P',
        'с' => 'C', 'у' => 'Y', 'х' => 'X',
    ];
    $text = strtr($text, $map);
    $text = mb_strtoupper($text, 'UTF-8');
    $text = preg_replace('/[^A-Z0-9\s\-\.\/\[\]]+/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Кандидаты базового имени фильтра из строки подписи.
 * "AF 1956 48 203 33-38" → ["AF1956", "AF 1956"]
 */
function scan_extract_filter_candidates(string $normalized): array
{
    $candidates = [];
    $add = static function (string $s) use (&$candidates): void {
        $s = trim($s);
        if ($s === '' || strlen($s) < 2) {
            return;
        }
        $candidates[$s] = true;
        $compact = preg_replace('/\s+/', '', $s);
        if ($compact !== '' && $compact !== $s) {
            $candidates[$compact] = true;
        }
    };

    // Буквы + цифры с опциональным пробелом и суффиксом (s, S, и т.п.)
    if (preg_match_all('/\b([A-Z]{1,6})\s*([0-9]{2,6}[A-Z]?)\b/u', $normalized, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $add($hit[1] . $hit[2]);
            $add($hit[1] . ' ' . $hit[2]);
        }
    }

    // Чисто цифровые коды (1601 и т.п.)
    if (preg_match_all('/\b([0-9]{3,6}[A-Z]?)\b/u', $normalized, $m2)) {
        foreach ($m2[1] as $num) {
            $add($num);
        }
    }

    // Первое «слово» целиком, если похоже на имя
    $parts = preg_split('/\s+/', $normalized);
    if (!empty($parts[0]) && preg_match('/^[A-Z0-9]{2,}$/', $parts[0])) {
        $add($parts[0]);
        if (isset($parts[1]) && preg_match('/^[0-9]{2,6}[A-Z]?$/', $parts[1])) {
            $add($parts[0] . $parts[1]);
            $add($parts[0] . ' ' . $parts[1]);
        }
    }

    return array_keys($candidates);
}

/**
 * Базовое имя из filter_label: "AF1956 [48] 203" → "AF1956"
 */
function scan_base_filter(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    $beforeBracket = trim(explode(' [', $label, 2)[0]);
    return $beforeBracket !== '' ? $beforeBracket : $label;
}

try {
    $pdo = getPdo('plan');

    $rawText = $_POST['text'] ?? $_GET['text'] ?? '';
    $rawText = is_string($rawText) ? $rawText : '';
    $normalized = scan_normalize_text($rawText);

    if ($normalized === '' || mb_strlen($normalized) < 2) {
        echo json_encode([
            'success' => false,
            'message' => 'Введите или распознайте минимум 2 символа',
            'recognized' => $normalized,
            'results' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $candidates = scan_extract_filter_candidates($normalized);
    if (empty($candidates)) {
        $candidates = [$normalized];
    }

    // Собираем уникальные (order, filter_label) из плана гофры за ~90 дней + из orders
    $matchedRows = [];
    $seenKey = [];

    foreach ($candidates as $cand) {
        $like = $cand . '%';
        $likeAnywhere = '%' . $cand . '%';

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                cp.order_number,
                cp.filter_label,
                MAX(cp.plan_date) AS last_plan_date
            FROM corrugation_plan cp
            WHERE (
                cp.filter_label LIKE ?
                OR TRIM(SUBSTRING_INDEX(COALESCE(cp.filter_label,''), ' [', 1)) = ?
                OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(cp.filter_label,''), ' [', 1)), ' ', '') = REPLACE(?, ' ', '')
            )
              AND cp.order_number IS NOT NULL
              AND cp.order_number != ''
              AND cp.plan_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY cp.order_number, cp.filter_label
            ORDER BY last_plan_date DESC
            LIMIT 30
        ");
        $stmt->execute([$like, $cand, $cand]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['order_number'] . '|' . $row['filter_label'];
            if (!isset($seenKey[$key])) {
                $seenKey[$key] = true;
                $matchedRows[] = $row;
            }
        }

        $stmt2 = $pdo->prepare("
            SELECT DISTINCT
                o.order_number,
                TRIM(o.filter) AS filter_label,
                NULL AS last_plan_date
            FROM orders o
            WHERE (
                TRIM(o.filter) LIKE ?
                OR TRIM(o.filter) = ?
                OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(o.filter,''), ' [', 1)), ' ', '') = REPLACE(?, ' ', '')
            )
              AND o.order_number IS NOT NULL
              AND o.order_number != ''
              AND COALESCE(o.hide, 0) != 1
            ORDER BY o.order_number DESC
            LIMIT 20
        ");
        $stmt2->execute([$like, $cand, $cand]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['order_number'] . '|' . $row['filter_label'];
            if (!isset($seenKey[$key])) {
                $seenKey[$key] = true;
                $matchedRows[] = $row;
            }
        }
    }

    // Сортировка: свежие plan_date выше, затем номер заявки
    usort($matchedRows, static function ($a, $b) {
        $da = $a['last_plan_date'] ?? '';
        $db = $b['last_plan_date'] ?? '';
        if ($da !== $db) {
            return strcmp((string)$db, (string)$da);
        }
        return strcmp((string)$b['order_number'], (string)$a['order_number']);
    });

    $matchedRows = array_slice($matchedRows, 0, 15);

    $geomCache = [];
    $results = [];

    foreach ($matchedRows as $row) {
        $orderNumber = (string)$row['order_number'];
        $filterLabel = (string)$row['filter_label'];
        $base = scan_base_filter($filterLabel);
        $baseCompact = preg_replace('/\s+/', '', $base);

        // Геометрия / особенности
        $geomKey = mb_strtoupper($baseCompact, 'UTF-8');
        if (!isset($geomCache[$geomKey])) {
            $geomStmt = $pdo->prepare("
                SELECT
                    pfs.filter AS filter_name,
                    ppp.p_p_length AS length_mm,
                    ppp.p_p_width AS width_mm,
                    ppp.p_p_height AS height_mm,
                    ppp.p_p_pleats_count AS pleats,
                    ppp.p_p_amplifier AS amplifiers,
                    ppp.p_p_remark AS package_remark,
                    pfs.glueing AS glueing,
                    pfs.glueing_remark AS glueing_remark,
                    pfs.form_factor_remark AS form_factor_remark,
                    pfs.comment AS structure_comment,
                    pf.p_name AS prefilter_name,
                    pf.p_remark AS prefilter_remark
                FROM panel_filter_structure pfs
                LEFT JOIN paper_package_panel ppp ON ppp.p_p_name = pfs.paper_package
                LEFT JOIN prefilter_panel pf ON pf.p_name = pfs.prefilter
                WHERE TRIM(pfs.filter) = ?
                   OR REPLACE(TRIM(pfs.filter), ' ', '') = ?
                   OR TRIM(pfs.filter) LIKE CONCAT(?, '%')
                ORDER BY
                    CASE
                        WHEN TRIM(pfs.filter) = ? THEN 0
                        WHEN REPLACE(TRIM(pfs.filter), ' ', '') = ? THEN 1
                        ELSE 2
                    END
                LIMIT 1
            ");
            $geomStmt->execute([$base, $baseCompact, $base, $base, $baseCompact]);
            $geom = $geomStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $geomCache[$geomKey] = $geom;
        }
        $geom = $geomCache[$geomKey];

        // План / факт гофры
        $corrPlanStmt = $pdo->prepare("
            SELECT COALESCE(SUM(count), 0)
            FROM corrugation_plan
            WHERE order_number = ?
              AND (
                filter_label = ?
                OR TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
                OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)), ' ', '') = ?
              )
        ");
        $corrPlanStmt->execute([$orderNumber, $filterLabel, $base, $baseCompact]);
        $corrPlan = (int)$corrPlanStmt->fetchColumn();

        $corrFactStmt = $pdo->prepare("
            SELECT COALESCE(SUM(count), 0)
            FROM manufactured_corrugated_packages
            WHERE order_number = ?
              AND (
                filter_label = ?
                OR TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
                OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)), ' ', '') = ?
              )
        ");
        $corrFactStmt->execute([$orderNumber, $filterLabel, $base, $baseCompact]);
        $corrFact = (int)$corrFactStmt->fetchColumn();

        // План / факт сборки фильтров
        $buildPlan = 0;
        try {
            $buildPlanStmt = $pdo->prepare("
                SELECT COALESCE(SUM(count), 0)
                FROM build_plan
                WHERE order_number = ?
                  AND (
                    filter_label = ?
                    OR TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
                    OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)), ' ', '') = ?
                  )
            ");
            $buildPlanStmt->execute([$orderNumber, $filterLabel, $base, $baseCompact]);
            $buildPlan = (int)$buildPlanStmt->fetchColumn();
        } catch (Throwable $e) {
            $buildPlan = 0;
        }

        $buildFact = 0;
        try {
            $buildFactStmt = $pdo->prepare("
                SELECT COALESCE(SUM(count_of_filters), 0)
                FROM manufactured_production
                WHERE name_of_order = ?
                  AND (
                    TRIM(name_of_filter) = ?
                    OR TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) = ?
                    OR REPLACE(TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)), ' ', '') = ?
                  )
            ");
            $buildFactStmt->execute([$orderNumber, $base, $base, $baseCompact]);
            $buildFact = (int)$buildFactStmt->fetchColumn();
        } catch (Throwable $e) {
            $buildFact = 0;
        }

        $remarks = [];
        if ($geom) {
            foreach (['structure_comment', 'package_remark', 'glueing_remark', 'form_factor_remark', 'prefilter_remark'] as $rk) {
                $v = trim((string)($geom[$rk] ?? ''));
                if ($v !== '') {
                    $remarks[] = $v;
                }
            }
        }

        $results[] = [
            'order_number' => $orderNumber,
            'filter_label' => $filterLabel,
            'base_filter' => $base,
            'last_plan_date' => $row['last_plan_date'] ?? null,
            'geometry' => [
                'length_mm' => $geom['length_mm'] ?? null,
                'width_mm' => $geom['width_mm'] ?? null,
                'height_mm' => $geom['height_mm'] ?? null,
                'pleats' => $geom['pleats'] ?? null,
                'amplifiers' => $geom['amplifiers'] ?? null,
                'glueing' => $geom['glueing'] ?? null,
                'prefilter' => $geom['prefilter_name'] ?? null,
            ],
            'remarks' => $remarks,
            'corrugated' => [
                'plan' => $corrPlan,
                'fact' => $corrFact,
            ],
            'built' => [
                'plan' => $buildPlan,
                'fact' => $buildFact,
            ],
        ];
    }

    echo json_encode([
        'success' => true,
        'recognized' => $normalized,
        'candidates' => $candidates,
        'results' => $results,
        'message' => empty($results) ? 'Позиции не найдены. Проверьте текст подписи.' : null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка поиска: ' . $e->getMessage(),
        'results' => [],
    ], JSON_UNESCAPED_UNICODE);
}
