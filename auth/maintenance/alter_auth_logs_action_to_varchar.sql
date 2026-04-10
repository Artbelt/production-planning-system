-- =====================================================
-- auth_logs.action: расширение типа колонки
-- =====================================================
-- Проблема: при ENUM значение 'user_created' (и др.) отсутствует в списке —
-- MySQL 1265 Data truncated for column 'action'.
-- Решение: VARCHAR — любые новые типы событий без повторных ALTER.
-- =====================================================

USE auth;

ALTER TABLE auth_logs
  MODIFY COLUMN action VARCHAR(64) NOT NULL COMMENT 'Тип события';

SELECT 'auth_logs.action переведён в VARCHAR(64)' AS message;
