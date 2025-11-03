-- Добавление роли оператора лазерной резки
USE auth;

INSERT INTO auth_roles (name, display_name, description, permissions) VALUES
('laser_operator', 'Оператор лазерной резки', 'Права для оператора лазерной резки - просмотр и управление заявками со всех участков', JSON_OBJECT(
    'permissions', JSON_ARRAY('view_laser_requests', 'manage_laser_requests', 'mark_laser_completed', 'view_all_departments_laser')
));

-- Проверим, что роль добавлена
SELECT * FROM auth_roles WHERE name = 'laser_operator';







