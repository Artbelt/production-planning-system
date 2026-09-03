<?php
/**
 * PDF этикетки гофропакета 65×38 мм по job_id из очереди.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../auth/includes/db.php';
require_once __DIR__ . '/label_print_lib.php';
require_once __DIR__ . '/../fpdf.php';

$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
if ($jobId < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'job_id required';
    exit;
}

$token = label_print_request_token();
if ($token !== '' && !label_print_check_token($token)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'unauthorized';
    exit;
}

$pdo = getPdo('plan');
label_print_ensure_table($pdo);

$stmt = $pdo->prepare("
    SELECT id, order_number, filter_label, label_payload
    FROM label_print_jobs
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'job not found';
    exit;
}

$payload = json_decode((string)$job['label_payload'], true);
if (!is_array($payload)) {
    $payload = label_print_build_payload(
        (string)$job['order_number'],
        (string)$job['filter_label'],
        0,
        $pdo
    );
}

$filterName = (string)($payload['filter_name'] ?? '');
$analog = (string)($payload['analog_name'] ?? '');
$hasPrefilter = !empty($payload['has_prefilter']);
$hasGlueing = !empty($payload['has_glueing']);
$order = (string)($payload['order_number'] ?? $job['order_number']);
$box = (string)($payload['box_number'] ?? '');
$producedAt = (string)($payload['produced_at'] ?? '');
$operator = (string)($payload['operator'] ?? '');
$qty = (int)($payload['batch_count'] ?? 0);
$dateRu = $producedAt !== '' ? date('d.m.Y', strtotime($producedAt)) : date('d.m.Y');

$size = [38, 65];
$pdf = new FPDF('L', 'mm', $size);
$pdf->AddPage();
$pdf->AddFont('Ariall', '', 'ariall.php');
$pdf->SetMargins(1.5, 1.5, 1.5);
$pdf->SetAutoPageBreak(false);

// Пустой квадрат 15×15 мм справа вверху — количество от руки
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);
$pdf->Rect(65 - 1.5 - 15, 1.5, 15, 15);

$y = 3;
$pdf->SetFont('Ariall', '', 14);
$pdf->Text(2.5, $y + 4, $filterName);
$y += 7;

if ($analog !== '') {
    $pdf->SetFont('Ariall', '', 9);
    $pdf->Text(2.5, $y + 3, $analog);
    $y += 5;
}

$pdf->SetFont('Ariall', '', 8);
if ($hasPrefilter) {
    $pdf->Text(2.5, $y + 3, 'Предфильтр');
    $y += 4;
}
if ($hasGlueing) {
    $pdf->Text(2.5, $y + 3, 'Проливка');
}

$pdf->SetDrawColor(180, 180, 180);
$pdf->Line(2, 26.5, 63, 26.5);

$pdf->SetFont('Ariall', '', 6);
$pdf->SetTextColor(100, 100, 100);
$pdf->Text(2.5, 28.5, 'Заявка');
$pdf->Text(24, 28.5, 'Коробка');
$pdf->Text(46, 28.5, 'Кол-во');
$pdf->Text(2.5, 33.5, 'Дата');
$pdf->Text(33, 33.5, 'Оператор');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Ariall', '', 8);
$pdf->Text(2.5, 31.5, $order);
$pdf->Text(24, 31.5, $box);
$pdf->SetFont('Ariall', '', 10);
$pdf->Text(46, 31.8, $qty > 0 ? (string)$qty : '');
$pdf->SetFont('Ariall', '', 8);
$pdf->Text(2.5, 36.5, $dateRu);
$pdf->SetFont('Ariall', '', 9);
$pdf->Text(33, 36.5, $operator);

$pdf->Output('I', 'label_' . $jobId . '.pdf');
