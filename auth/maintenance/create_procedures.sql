-- =====================================================
-- Создание процедур для обслуживания БД auth
-- Выполнять отдельно через phpMyAdmin или MySQL Workbench
-- =====================================================

USE auth;

-- Включаем планировщик событий
SET GLOBAL event_scheduler = ON;

-- =====================================================
-- Процедуры для очистки данных
-- =====================================================

DELIMITER //

-- Процедура очистки устаревших сессий
CREATE PROCEDURE IF NOT EXISTS CleanExpiredSessions()
BEGIN
    DELETE FROM auth_sessions WHERE expires_at < NOW();
    SELECT ROW_COUNT() as deleted_sessions;
END //

-- Процедура очистки старых логов (старше 6 месяцев)
CREATE PROCEDURE IF NOT EXISTS CleanOldLogs()
BEGIN
    DELETE FROM auth_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);
    SELECT ROW_COUNT() as deleted_logs;
END //

-- Процедура разблокировки аккаунтов
CREATE PROCEDURE IF NOT EXISTS UnlockExpiredAccounts()
BEGIN
    UPDATE auth_users 
    SET locked_until = NULL, failed_login_attempts = 0 
    WHERE locked_until IS NOT NULL AND locked_until < NOW();
    SELECT ROW_COUNT() as unlocked_accounts;
END //

DELIMITER ;

-- =====================================================
-- События для автоматической очистки
-- =====================================================

-- Очистка сессий каждый час
CREATE EVENT IF NOT EXISTS ev_clean_sessions
ON SCHEDULE EVERY 1 HOUR
DO CALL CleanExpiredSessions();

-- Очистка логов каждый день в 2:00
CREATE EVENT IF NOT EXISTS ev_clean_logs
ON SCHEDULE EVERY 1 DAY STARTS '2025-10-03 02:00:00'
DO CALL CleanOldLogs();

-- Разблокировка аккаунтов каждые 5 минут
CREATE EVENT IF NOT EXISTS ev_unlock_accounts
ON SCHEDULE EVERY 5 MINUTE
DO CALL UnlockExpiredAccounts();

-- =====================================================
-- Проверка созданных процедур и событий
-- =====================================================

-- Показать созданные процедуры
SHOW PROCEDURE STATUS WHERE Db = 'auth';

-- Показать созданные события
SHOW EVENTS FROM auth;

SELECT 'Процедуры и события успешно созданы!' as message;
















