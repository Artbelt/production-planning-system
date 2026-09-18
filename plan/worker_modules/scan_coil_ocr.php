<?php
/**
 * OCR подписи бухты через vision API (Gemini / OpenAI).
 * POST JSON: { "image": "data:image/jpeg;base64,..." }
 *
 * Важно: прикладные ошибки всегда отдаём HTTP 200 + JSON,
 * чтобы nginx/прокси не подменяли ответ своим HTML 502.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
@ini_set('memory_limit', '256M');
@set_time_limit(120);

function scan_ocr_json(array $payload, int $code = 200): void
{
    // Всегда HTTP 200: иначе nginx/прокси подменяет тело на HTML 502
    http_response_code(200);
    if (!isset($payload['success'])) {
        $payload['success'] = false;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Фатальная ошибка PHP: ' . ($err['message'] ?? 'unknown'),
    ], JSON_UNESCAPED_UNICODE);
});

if (!function_exists('curl_init')) {
    scan_ocr_json(['success' => false, 'message' => 'На сервере нет PHP curl. Установите php-curl.'], 200);
}

$configFile = __DIR__ . '/scan_ocr_config.php';
if (!is_file($configFile)) {
    scan_ocr_json(['success' => false, 'message' => 'Нет файла scan_ocr_config.php', 'need_key' => true], 200);
}

$config = require $configFile;
$apiKey = trim((string)($config['api_key'] ?? ''));
if ($apiKey === '') {
    $apiKey = trim((string)(getenv('SCAN_OCR_API_KEY') ?: ''));
}
$provider = strtolower(trim((string)($config['provider'] ?? 'gemini')));

if ($apiKey === '' || $apiKey === 'YOUR_KEY_HERE') {
    scan_ocr_json([
        'success' => false,
        'need_key' => true,
        'message' => 'Не задан API-ключ OCR (scan_ocr_config.php или SCAN_OCR_API_KEY)',
    ], 200);
}

$image = '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$rawInput = file_get_contents('php://input');

if (stripos($contentType, 'application/json') !== false || (is_string($rawInput) && $rawInput !== '' && $rawInput[0] === '{')) {
    $json = json_decode((string)$rawInput, true);
    if (is_array($json) && !empty($json['image']) && is_string($json['image'])) {
        $image = $json['image'];
    } elseif ($rawInput !== '' && $json === null) {
        scan_ocr_json([
            'success' => false,
            'message' => 'Не удалось разобрать JSON (тело ' . strlen((string)$rawInput) . ' байт). Возможно лимит post_max_size.',
        ], 200);
    }
}

if ($image === '' && !empty($_POST['image']) && is_string($_POST['image'])) {
    $image = $_POST['image'];
}

$image = trim($image);
if ($image === '') {
    scan_ocr_json(['success' => false, 'message' => 'Нет изображения в запросе'], 200);
}

$mime = 'image/jpeg';
$base64 = $image;
if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $image, $m)) {
    $mime = $m[1];
    $base64 = $m[2];
} else {
    $base64 = preg_replace('#\s+#', '', $image);
}

$bin = base64_decode($base64, true);
if ($bin === false || strlen($bin) < 100) {
    scan_ocr_json(['success' => false, 'message' => 'Повреждённое изображение'], 200);
}
if (strlen($bin) > 4 * 1024 * 1024) {
    scan_ocr_json(['success' => false, 'message' => 'Слишком большой файл (макс. 4 МБ)'], 200);
}

$prompt = "Ты читаешь рукописную подпись на жёлтом скотче бухты фильтра.\n"
    . "На фото красным маркером написан текст вида: AF 1956 48 203 33-38\n"
    . "Верни ТОЛЬКО сам текст подписи в одну строку, без кавычек и пояснений.\n"
    . "Сохрани пробелы между группами. Если не уверен в символе — всё равно дай наиболее вероятный вариант.";

try {
    if ($provider === 'openai') {
        $text = scan_ocr_openai($apiKey, (string)($config['openai_model'] ?? 'gpt-4o-mini'), $mime, $base64, $prompt);
    } else {
        $text = scan_ocr_gemini($apiKey, (string)($config['gemini_model'] ?? 'gemini-3.6-flash'), $mime, $base64, $prompt);
    }
} catch (Throwable $e) {
    scan_ocr_json([
        'success' => false,
        'message' => 'Ошибка OCR API: ' . $e->getMessage(),
        'provider' => $provider,
    ], 200);
}

$text = trim(preg_replace('/\s+/u', '', $text));
$text = trim($text, " \t\n\r\0\x0B\"'`");
$text = mb_strtoupper($text, 'UTF-8');
if ($text === '') {
    scan_ocr_json(['success' => false, 'message' => 'Модель не смогла прочитать текст', 'provider' => $provider], 200);
}

scan_ocr_json([
    'success' => true,
    'text' => $text,
    'provider' => $provider,
], 200);

function scan_ocr_gemini(string $apiKey, string $model, string $mime, string $base64, string $prompt): string
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data' => $base64,
                    ],
                ],
            ],
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 2048,
        ],
    ];

    $resp = scan_ocr_http_json($url, $payload);
    $text = '';
    $parts = $resp['candidates'][0]['content']['parts'] ?? [];
    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (!empty($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
    }
    $text = trim($text);
    if ($text === '') {
        $block = $resp['promptFeedback']['blockReason'] ?? null;
        $finish = $resp['candidates'][0]['finishReason'] ?? null;
        $detail = $block ?: $finish ?: 'пустой ответ Gemini';
        throw new RuntimeException((string)$detail);
    }
    return $text;
}

function scan_ocr_openai(string $apiKey, string $model, string $mime, string $base64, string $prompt): string
{
    $url = 'https://api.openai.com/v1/chat/completions';
    $dataUrl = 'data:' . $mime . ';base64,' . $base64;
    $payload = [
        'model' => $model,
        'temperature' => 0.1,
        'max_tokens' => 128,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]],
    ];

    $resp = scan_ocr_http_json($url, $payload, [
        'Authorization: Bearer ' . $apiKey,
    ]);
    $text = $resp['choices'][0]['message']['content'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('пустой ответ OpenAI');
    }
    return $text;
}

function scan_ocr_http_json(string $url, array $payload, array $extraHeaders = []): array
{
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);

    $bodyJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($bodyJson === false) {
        throw new RuntimeException('json_encode payload failed');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $bodyJson,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('curl: ' . $err);
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('ответ API не JSON (HTTP ' . $code . ')');
    }
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ($data['error']['status'] ?? ('HTTP ' . $code));
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        throw new RuntimeException((string)$msg);
    }
    return $data;
}
