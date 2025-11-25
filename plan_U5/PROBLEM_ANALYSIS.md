# Анализ проблемы с пропавшими данными в salon_filter_structure

## Обнаруженная проблема в коде

### Файл: `processing_edit_filter_properties.php`

**Строки 21, 24, 26, 30:**
```php
$insertion_count = $_POST['insertions_count'] ?? '';
$box = $_POST['box'] ?? '';
$g_box = $_POST['g_box'] ?? '';
$side_type = $_POST['side_type'] ?? '';
```

**Проблема:** Если поля приходят пустыми из формы, они устанавливаются в пустую строку `''`, что приводит к очистке данных в БД при выполнении UPDATE.

**Строка 94:**
```php
$stmt->bind_param('ssssssssssi', ...)
```

Все поля передаются как строки, включая пустые значения, которые перезаписывают существующие данные.

## Как это могло произойти

1. **Массовое редактирование через форму**
   - Если кто-то открыл форму редактирования фильтра и отправил её с пустыми полями
   - Или если форма была отправлена автоматически/случайно
   - Все поля будут очищены

2. **Ошибка в форме**
   - Если форма не загрузила существующие значения в поля
   - И пользователь отправил форму, не заполнив поля
   - Данные будут перезаписаны пустыми строками

3. **Прямой SQL-запрос**
   - Кто-то мог выполнить UPDATE напрямую в БД
   - Проверьте binary log или general log MySQL

## Рекомендуемое исправление

### Вариант 1: Использовать NULL вместо пустых строк

```php
// Заменить строки 21, 24, 26, 30:
$insertion_count = !empty($_POST['insertions_count']) ? $_POST['insertions_count'] : null;
$box = !empty($_POST['box']) ? $_POST['box'] : null;
$g_box = !empty($_POST['g_box']) ? $_POST['g_box'] : null;
$side_type = !empty($_POST['side_type']) ? $_POST['side_type'] : null;

// И изменить bind_param на строке 94:
// Использовать 's' для строк и NULL для пустых значений
// Или использовать условный UPDATE только для заполненных полей
```

### Вариант 2: Обновлять только заполненные поля

```php
// Собирать только заполненные поля
$updates = [];
$params = [];
$types = '';

if (!empty($_POST['insertions_count'])) {
    $updates[] = "insertion_count = ?";
    $params[] = $_POST['insertions_count'];
    $types .= 's';
}

if (!empty($_POST['box'])) {
    $updates[] = "box = ?";
    $params[] = $_POST['box'];
    $types .= 's';
}

// ... и т.д.

if (!empty($updates)) {
    $sql = "UPDATE salon_filter_structure SET " . implode(', ', $updates) . " WHERE filter = ?";
    $params[] = $filter_name;
    $types .= 's';
    // Выполнить запрос
}
```

## Как проверить когда это произошло

1. **Запустите диагностические скрипты:**
   - `check_salon_filter_data.php` - покажет статистику и примеры
   - `check_sql_history.php` - покажет историю изменений

2. **Проверьте логи:**
   ```powershell
   # Логи доступа Apache
   Select-String -Path 'C:\xampp\apache\logs\access.log' -Pattern 'processing_edit_filter_properties' | Select-Object -Last 100
   
   # Проверьте время последнего изменения
   Get-ChildItem 'C:\xampp\apache\logs\access.log' | Select-Object LastWriteTime
   ```

3. **Проверьте audit_log (если настроен):**
   ```sql
   SELECT * FROM audit_log 
   WHERE table_name = 'salon_filter_structure' 
     AND (changed_fields LIKE '%box%' OR changed_fields LIKE '%insertion_count%' OR changed_fields LIKE '%g_box%')
   ORDER BY timestamp DESC 
   LIMIT 50;
   ```

4. **Проверьте binary log MySQL:**
   ```bash
   mysqlbinlog mysql-bin.000001 | grep -A 20 "salon_filter_structure"
   ```

## Восстановление данных

### Если есть резервная копия БД:

```sql
-- 1. Создайте резервную копию текущего состояния
CREATE TABLE salon_filter_structure_backup_current AS 
SELECT * FROM salon_filter_structure;

-- 2. Восстановите данные из резервной копии
UPDATE salon_filter_structure sfs
INNER JOIN backup_salon_filter_structure backup ON sfs.filter = backup.filter
SET 
    sfs.box = COALESCE(NULLIF(sfs.box, ''), backup.box),
    sfs.insertion_count = COALESCE(NULLIF(sfs.insertion_count, ''), backup.insertion_count),
    sfs.g_box = COALESCE(NULLIF(sfs.g_box, ''), backup.g_box),
    sfs.side_type = COALESCE(NULLIF(sfs.side_type, ''), backup.side_type)
WHERE (backup.box IS NOT NULL AND backup.box != '')
   OR (backup.insertion_count IS NOT NULL AND backup.insertion_count != '')
   OR (backup.g_box IS NOT NULL AND backup.g_box != '');
```

### Если нет резервной копии:

1. Проверьте, есть ли данные в других таблицах (orders, manufactured_production)
2. Проверьте логи приложений, которые могут содержать старые значения
3. Обратитесь к пользователям, которые работали с этими фильтрами

## Предотвращение в будущем

1. **Исправьте код** согласно рекомендациям выше
2. **Включите binary log** для возможности восстановления:
   ```sql
   SET GLOBAL log_bin = 'ON';
   ```
3. **Настройте регулярные резервные копии**
4. **Добавьте проверку перед массовыми обновлениями**
5. **Используйте транзакции** для критических операций



