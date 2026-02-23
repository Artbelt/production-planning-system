#!/bin/bash
# Резервное копирование баз данных на сервере Hetzner
# Использование: ./backup_mysql.sh

# === Настройки ===
BACKUP_DIR="/var/backups/mysql"
RETENTION_DAYS=14
DATE=$(date +%Y-%m-%d_%H%M)
CONFIG_FILE="/root/.my_backup.cnf"

# Список баз для бэкапа
DATABASES="auth plan plan_u3 plan_u4 plan_u5 press_module"

# === Создаём директорию ===
mkdir -p "$BACKUP_DIR"

# Используем конфиг mysql (создайте: chmod 600 /root/.my_backup.cnf)
# Содержимое .my_backup.cnf:
#   [client]
#   user=plan_user
#   password=ваш_пароль

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Создайте $CONFIG_FILE с учётными данными MySQL"
    exit 1
fi

# Экспорт каждой базы
for DB in $DATABASES; do
    DUMP_FILE="$BACKUP_DIR/${DB}_${DATE}.sql.gz"
    mysqldump --defaults-extra-file="$CONFIG_FILE" --single-transaction --routines --default-character-set=utf8mb4 "$DB" 2>/dev/null | gzip > "$DUMP_FILE"
    if [ $? -eq 0 ] && [ -s "$DUMP_FILE" ]; then
        echo "[OK] $DB -> $DUMP_FILE"
    else
        echo "[FAIL] $DB"
        rm -f "$DUMP_FILE"
    fi
done

# Удаляем бэкапы старше RETENTION_DAYS дней
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +$RETENTION_DAYS -delete
echo "Удалены бэкапы старше $RETENTION_DAYS дней"
