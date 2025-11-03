-- Добавление роли оператора бумагорезки
INSERT INTO auth_roles (name, display_name, description, permissions) 
VALUES ('cut_operator', 'Оператор бумагорезки', 'Просмотр и управление заданиями на порезку со всех участков', 
JSON_OBJECT('permissions', JSON_ARRAY('view_cut_tasks', 'mark_cut_task_complete', 'view_all_departments')));






