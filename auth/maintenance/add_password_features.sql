-- =====================================================
-- Добавление функций управления паролями
-- =====================================================

USE auth;

-- Добавляем поля для отслеживания паролей
ALTER TABLE auth_users 
ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Когда последний раз менялся пароль',
ADD COLUMN is_default_password BOOLEAN DEFAULT TRUE COMMENT 'Использует ли базовый пароль',
ADD COLUMN password_reminder_sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Когда отправлено последнее напоминание',
ADD COLUMN password_reminder_count INT DEFAULT 0 COMMENT 'Количество отправленных напоминаний';

-- Обновляем существующих пользователей
UPDATE auth_users 
SET password_changed_at = created_at, 
    is_default_password = TRUE 
WHERE password_changed_at IS NULL;

-- Добавляем индексы для производительности
CREATE INDEX idx_password_changed_at ON auth_users(password_changed_at);
CREATE INDEX idx_is_default_password ON auth_users(is_default_password);

SELECT 'Поля для управления паролями добавлены успешно!' as message;
















