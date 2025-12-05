# Диагностика пропавших данных в salon_filter_structure

## Проблема
В таблице `salon_filter_structure` пропали данные из полей:
- `box`
- `insertion_count`
- `g_box`
- `side_type` (возможно неверные данные)

## Как проверить что произошло и когда

### Шаг 1: Запустите диагностический скрипт

Откройте в браузере:
```
http://localhost/plan_U5/check_salon_filter_data.php
```

Этот скрипт покажет:
- Сколько записей имеют пустые поля
- Примеры записей с пустыми данными
- Историю изменений из таблицы audit_log (если она настроена)

### Шаг 2: Проверьте историю SQL-запросов

Откройте в браузере:
```
http://localhost/plan_U5/check_sql_history.php
```

Этот скрипт покажет:
- Статус логов MySQL (general_log, binary log)
- Статистику изменений из audit_log
- Подозрительные массовые обновления

### Шаг 3: Проверьте логи веб-сервера

#### В PowerShell выполните:

```powershell
# Проверьте последние запросы к скрипту редактирования фильтров
Select-String -Path 'C:\xampp\apache\logs\access.log' -Pattern 'processing_edit_filter_properties' | Select-Object -Last 50

# Проверьте ошибки PHP
Select-String -Path 'C:\xampp\php\logs\php_error_log' -Pattern 'salon_filter_structure' | Select-Object -Last 50

# Проверьте время последнего изменения логов
Get-ChildItem 'C:\xampp\apache\logs\access.log' | Select-Object LastWriteTime
```

### Шаг 4: Проверьте резервные копии БД

Если у вас есть резервные копии базы данных, сравните данные:

```sql
-- В резервной копии проверьте данные
SELECT filter, box, insertion_count, g_box, side_type 
FROM salon_filter_structure 
WHERE filter = 'НАЗВАНИЕ_ФИЛЬТРА';

-- В текущей БД
SELECT filter, box, insertion_count, g_box, side_type 
FROM salon_filter_structure 
WHERE filter = 'НАЗВАНИЕ_ФИЛЬТРА';
```

## Возможные причины

1. **Массовое обновление через форму редактирования**
   - Файл: `processing_edit_filter_properties.php`
   - Если форма была отправлена с пустыми значениями, поля будут очищены
   - Проверьте логи доступа к этому файлу

2. **Прямой SQL-запрос в БД**
   - Кто-то мог выполнить UPDATE с пустыми значениями
   - Проверьте binary log или general log MySQL

3. **Ошибка в коде**
   - Проверьте файл `processing_edit_filter_properties.php` строки 21, 24, 26, 30
   - Если поля приходят пустыми из POST, они устанавливаются в пустую строку

## Восстановление данных

### Если есть резервная копия:

```sql
-- Создайте резервную копию текущего состояния
CREATE TABLE salon_filter_structure_backup_current AS 
SELECT * FROM salon_filter_structure;

-- Восстановите данные из резервной копии
UPDATE salon_filter_structure sfs
INNER JOIN backup_salon_filter_structure backup ON sfs.filter = backup.filter
SET 
    sfs.box = backup.box,
    sfs.insertion_count = backup.insertion_count,
    sfs.g_box = backup.g_box,
    sfs.side_type = backup.side_type
WHERE backup.box IS NOT NULL AND backup.box != '';
```

### Если есть binary log:

```bash
# Просмотрите изменения в binary log
mysqlbinlog mysql-bin.000001 | grep -A 20 "salon_filter_structure"

# Восстановите данные из binlog (осторожно!)
mysqlbinlog mysql-bin.000001 | mysql -u root -p plan_u5
```

## Предотвращение в будущем

1. **Включите binary log:**
   ```sql
   SET GLOBAL log_bin = 'ON';
   ```

2. **Настройте регулярные резервные копии**

3. **Исправьте код** - не устанавливайте пустые строки, используйте NULL:
   ```php
   // Вместо:
   $box = $_POST['box'] ?? '';
   
   // Используйте:
   $box = !empty($_POST['box']) ? $_POST['box'] : null;
   ```

4. **Добавьте проверку перед массовыми обновлениями**

## Контакты для помощи

Если проблема не решена, соберите следующую информацию:
- Результаты диагностических скриптов
- Выдержки из логов веб-сервера
- Информацию о последних изменениях в БД
- Резервные копии (если есть)








