<?php

require_once __DIR__ . '/label_print_lib.php';

function laser_request_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS laser_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_name VARCHAR(255) NOT NULL,
        department VARCHAR(10) NOT NULL,
        component_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        progress_count INT NOT NULL DEFAULT 0,
        desired_delivery_time DATETIME NULL,
        is_completed BOOLEAN DEFAULT FALSE,
        completed_at TIMESTAMP NULL,
        is_cancelled BOOLEAN DEFAULT FALSE,
        cancelled_at TIMESTAMP NULL,
        completed_by VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function laser_request_current_department(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $dept = trim((string)($_SESSION['auth_department'] ?? ''));
    return $dept !== '' ? $dept : 'U2';
}

/**
 * Создаёт заявку на лазер для предфильтра, если он есть у фильтра.
 * @return int|null id созданной заявки
 */
function laser_request_create_for_prefilter(
    PDO $pdo,
    string $filterLabel,
    int $quantity,
    ?string $operatorName = null,
    ?string $department = null
): ?int {
    if ($quantity < 1) {
        return null;
    }

    $parsed = label_print_parse_filter_label($filterLabel);
    $struct = label_print_lookup_panel_structure($pdo, $filterLabel, $parsed['name']);
    if (!$struct) {
        return null;
    }

    $prefilterName = trim((string)($struct['prefilter'] ?? ''));
    if ($prefilterName === '') {
        return null;
    }

    $operator = $operatorName !== null ? trim($operatorName) : label_print_current_operator();
    if ($operator === '') {
        $operator = 'Гофромашина';
    }

    $dept = $department !== null && trim($department) !== ''
        ? trim($department)
        : laser_request_current_department();

    laser_request_ensure_table($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO laser_requests (user_name, department, component_name, quantity, desired_delivery_time)
        VALUES (?, ?, ?, ?, NULL)
    ");
    $stmt->execute([$operator, $dept, $prefilterName, $quantity]);

    return (int)$pdo->lastInsertId();
}
