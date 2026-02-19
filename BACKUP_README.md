# Резервное копирование БД на сервере

## 1. Подготовка на сервере

### Создайте файл с паролем MySQL (безопасно, не в git)

```bash
nano /root/.my_backup.cnf
```

Содержимое:
```ini
[client]
user=plan_user
password=ваш_пароль_от_plan_user
```

Сохраните и ограничьте доступ:
```bash
chmod 600 /root/.my_backup.cnf
```

### Скопируйте скрипт на сервер

```powershell
scp c:\xampp\htdocs\backup_mysql.sh root@49.13.143.76:/root/
```

### На сервере: сделайте скрипт исполняемым

```bash
chmod +x /root/backup_mysql.sh
```

### Проверка вручную

```bash
/root/backup_mysql.sh
```

Файлы появятся в `/var/backups/mysql/`:
- `auth_2026-02-19_1200.sql.gz`
- `plan_2026-02-19_1200.sql.gz`
- и т.д.

---

## 2. Автозапуск по расписанию (cron)

```bash
crontab -e
```

Добавьте строку (ежедневно в 3:00):

```
0 3 * * * /root/backup_mysql.sh >> /var/log/mysql_backup.log 2>&1
```

---

## 3. Где хранить

### Локально на сервере (уже настроено)
- Папка: `/var/backups/mysql/`
- Хранятся последние 14 дней (RETENTION_DAYS в скрипте)
- Риск: при полной потере сервера бэкапы пропадут

### Вынос копий на другой носитель

**Вариант A: SCP на другой сервер**
Добавьте в конец скрипта (после find):
```bash
# Копировать на другой сервер (если есть)
# scp /var/backups/mysql/*.sql.gz user@backup-server:/backups/
```

**Вариант B: Hetzner Storage Box (Samba/SFTP)**
- Заказать Storage Box в панели Hetzner
- Подмонтировать или использовать `rsync`/`scp` в cron

**Вариант C: Облако (S3, Cloudflare R2, Яндекс.Облако)**
- Установить `aws s3` или rclone
- Добавить в скрипт: `rclone copy /var/backups/mysql/ remote:backups/`

**Вариант D: Скачивание на ваш ПК**
- Раз в неделю вручную: `scp root@49.13.143.76:/var/backups/mysql/*.sql.gz C:\Backups\`
- Или настроить задачу Windows + scp в .bat

---

## 4. Восстановление

```bash
gunzip -c /var/backups/mysql/plan_2026-02-19_1200.sql.gz | mysql -u plan_user -p plan
```
