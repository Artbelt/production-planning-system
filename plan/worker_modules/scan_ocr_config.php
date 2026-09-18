<?php
/**
 * Конфиг OCR подписи бухты (vision).
 * Ключ Gemini: https://aistudio.google.com/apikey
 *
 * Провайдеры: gemini | openai
 * Ключ задайте здесь локально или через env SCAN_OCR_API_KEY (не коммитьте реальный ключ).
 */
return [
    'provider' => 'gemini',
    // Вставьте ключ сюда или задайте SCAN_OCR_API_KEY:
    'api_key' => getenv('SCAN_OCR_API_KEY') ?: '',
    // Модели по умолчанию
    'gemini_model' => 'gemini-3.6-flash',
    'openai_model' => 'gpt-4o-mini',
];
