<?php
require_once __DIR__ . '/../auth/includes/db.php';
require_once __DIR__ . '/gofro_machine_helpers.php';

$pdo = getPdo('plan_u3');

try {
    echo renderGofroHalfYearCalendar($pdo);
} catch (Throwable $e) {
    echo '<p>Ошибка загрузки календаря</p>';
}
