# Дубликаты в paper_package_salon

## Проблема

В таблице `paper_package_salon` поле `p_p_name` должно быть уникальным (один гофропакет — одна запись). При наличии дубликатов:

- JOIN в запросах умножает строки (11 записей "гофропакет AF5203a" → 11x дублирование)
- Во всплывающих подсказках NP_roll_plan отображаются сотни лишних полос вместо реальных

## Проверка дубликатов

Откройте в браузере:

```
/plan_U5/tools/check_paper_package_duplicates.php
```

Скрипт покажет:
- Наличие ограничения UNIQUE на `p_p_name`
- Список дубликатов с количеством записей
- Детали по каждой группе дубликатов

## Исправление

**Перед исправлением сделайте бэкап базы данных!**

1. Откройте: `/plan_U5/tools/fix_paper_package_duplicates.php`
2. Нажмите «Выполнить исправление»
3. Скрипт удалит лишние записи (оставит одну на каждый `p_p_name`) и добавит `UNIQUE KEY` если его нет

## Профилактика

### 1. Ограничение UNIQUE

После выполнения `fix_paper_package_duplicates.php` будет добавлено:

```sql
ALTER TABLE paper_package_salon ADD UNIQUE KEY uk_p_p_name (p_p_name(100));
```

Это запретит вставку новых дубликатов на уровне БД.

### 2. INSERT с ON DUPLICATE KEY UPDATE

В `processing_add_salon_filter_into_db.php` при добавлении фильтра используется:

```sql
INSERT INTO paper_package_salon (...) VALUES (...)
ON DUPLICATE KEY UPDATE p_p_height = VALUES(p_p_height), ...
```

Если гофропакет уже существует — обновляются данные, новая строка не создаётся.

### 3. Рекомендации

- При добавлении записей в `paper_package_salon` всегда используйте `ON DUPLICATE KEY UPDATE` или проверку `SELECT ... WHERE p_p_name = ?` перед INSERT
- Периодически запускайте `check_paper_package_duplicates.php` для контроля
