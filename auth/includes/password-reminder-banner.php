<?php
/**
 * Компонент баннера напоминания о смене пароля
 */

if (!defined('AUTH_SYSTEM')) {
    die('Прямой доступ запрещен');
}

/**
 * Получение информации о необходимости показа напоминания
 */
function getPasswordReminderInfo($userId) {
    $db = Database::getInstance();
    
    $user = $db->selectOne("
        SELECT 
            is_default_password,
            password_changed_at,
            password_reminder_sent_at,
            password_reminder_count,
            DATEDIFF(NOW(), password_changed_at) as days_since_change
        FROM auth_users 
        WHERE id = ?
    ", [$userId]);
    
    if (!$user || !$user['is_default_password']) {
        return null;
    }
    
    $daysSinceChange = (int)$user['days_since_change'];
    $reminderCount = (int)$user['password_reminder_count'];
    
    // Определяем уровень важности
    $severity = 'warning';
    $message = '';
    
    if ($daysSinceChange >= 30) {
        $severity = 'gentle';
        $message = 'Рекомендуем сменить базовый пароль на персональный для повышения безопасности вашего аккаунта.';
    } elseif ($daysSinceChange >= 14) {
        $severity = 'friendly';
        $message = 'Для вашей безопасности рекомендуем сменить базовый пароль на персональный.';
    } elseif ($daysSinceChange >= 7) {
        $severity = 'info';
        $message = 'Напоминание: вы можете сменить базовый пароль на персональный для повышения безопасности.';
    } else {
        return null; // Не показываем напоминание если прошло меньше 7 дней
    }
    
    return [
        'severity' => $severity,
        'message' => $message,
        'days_since_change' => $daysSinceChange,
        'reminder_count' => $reminderCount
    ];
}

/**
 * Отображение баннера напоминания
 */
function renderPasswordReminderBanner($userId) {
    $reminderInfo = getPasswordReminderInfo($userId);
    
    if (!$reminderInfo) {
        return '';
    }
    
    $severity = $reminderInfo['severity'];
    $message = $reminderInfo['message'];
    $days = $reminderInfo['days_since_change'];
    
    // Определяем цвета в зависимости от уровня важности (мягкие и дружелюбные)
    $colors = [
        'gentle' => [
            'bg' => '#f0f9ff',
            'border' => '#7dd3fc',
            'text' => '#0369a1',
            'icon' => '🔐',
            'button' => '#0ea5e9'
        ],
        'friendly' => [
            'bg' => '#fefce8',
            'border' => '#fde047',
            'text' => '#854d0e',
            'icon' => '💡',
            'button' => '#eab308'
        ],
        'info' => [
            'bg' => '#f0fdf4',
            'border' => '#86efac',
            'text' => '#166534',
            'icon' => '✨',
            'button' => '#22c55e'
        ]
    ];
    
    $color = $colors[$severity];
    
    return '
    <div id="password-reminder-banner" style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: linear-gradient(135deg, ' . $color['bg'] . ' 0%, ' . adjustBrightness($color['bg'], 5) . ' 100%);
        border-bottom: 2px solid ' . $color['border'] . ';
        padding: 16px 20px;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        backdrop-filter: blur(10px);
    ">
        <div style="
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        ">
            <div style="display: flex; align-items: center; gap: 14px; flex: 1;">
                <div style="
                    width: 44px;
                    height: 44px;
                    background: ' . $color['border'] . ';
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 22px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                ">' . $color['icon'] . '</div>
                <div>
                    <div style="
                        font-weight: 500;
                        font-size: 15px;
                        color: ' . $color['text'] . ';
                        margin-bottom: 4px;
                        line-height: 1.4;
                    ">' . htmlspecialchars($message) . '</div>
                    <div style="
                        font-size: 13px;
                        color: ' . $color['text'] . ';
                        opacity: 0.7;
                    ">Базовый пароль используется ' . $days . ' ' . getDaysWord($days) . '</div>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="auth/change-password.php" style="
                    padding: 10px 24px;
                    background: linear-gradient(135deg, ' . $color['button'] . ' 0%, ' . adjustBrightness($color['button'], -10) . ' 100%);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 500;
                    font-size: 14px;
                    transition: all 0.2s;
                    white-space: nowrap;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                " onmouseover="this.style.transform=\'translateY(-1px)\'; this.style.boxShadow=\'0 4px 10px rgba(0,0,0,0.2)\'" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'0 2px 6px rgba(0,0,0,0.15)\'">
                    Сменить пароль
                </a>
                <button onclick="dismissPasswordReminder()" style="
                    padding: 10px 14px;
                    background: transparent;
                    border: 1.5px solid ' . $color['border'] . ';
                    color: ' . $color['text'] . ';
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 16px;
                    transition: all 0.2s;
                    width: 38px;
                    height: 38px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                " onmouseover="this.style.background=\'' . $color['bg'] . '\'; this.style.borderColor=\'' . $color['text'] . '\'" onmouseout="this.style.background=\'transparent\'; this.style.borderColor=\'' . $color['border'] . '\'">
                    ✕
                </button>
            </div>
        </div>
    </div>
    <script>
        function dismissPasswordReminder() {
            const banner = document.getElementById("password-reminder-banner");
            if (banner) {
                banner.style.display = "none";
                // Сохраняем в sessionStorage что пользователь закрыл баннер на эту сессию
                sessionStorage.setItem("passwordReminderDismissed", "true");
            }
        }
        
        // Проверяем при загрузке страницы, был ли баннер закрыт в этой сессии
        // Баннер будет показан снова при следующем входе, если пароль не изменен
        if (sessionStorage.getItem("passwordReminderDismissed") === "true") {
            const banner = document.getElementById("password-reminder-banner");
            if (banner) {
                banner.style.display = "none";
            }
        }
    </script>
    ';
}

/**
 * Вспомогательная функция для правильного склонения слова "день"
 */
function getDaysWord($days) {
    $lastDigit = $days % 10;
    $lastTwoDigits = $days % 100;
    
    if ($lastTwoDigits >= 11 && $lastTwoDigits <= 19) {
        return 'дней';
    }
    
    if ($lastDigit == 1) {
        return 'день';
    } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
        return 'дня';
    } else {
        return 'дней';
    }
}

/**
 * Вспомогательная функция для изменения яркости цвета
 */
function adjustBrightness($hex, $percent) {
    // Удаляем # если есть
    $hex = ltrim($hex, '#');
    
    // Конвертируем в RGB
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Изменяем яркость и округляем до целого числа
    $r = (int)round(max(0, min(255, $r + ($r * $percent / 100))));
    $g = (int)round(max(0, min(255, $g + ($g * $percent / 100))));
    $b = (int)round(max(0, min(255, $b + ($b * $percent / 100))));
    
    // Конвертируем обратно в hex
    return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . 
           str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . 
           str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
}

