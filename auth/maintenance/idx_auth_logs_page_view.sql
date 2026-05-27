-- Опционально: ускорение отчётов по page_view (после появления данных)
USE auth;

CREATE INDEX idx_auth_logs_action_created
  ON auth_logs (action, created_at);
