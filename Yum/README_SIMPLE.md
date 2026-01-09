# Yum - Простая инструкция

## Что нужно сделать (3 простых шага):

### 1. Собрать и скопировать фронтенд
Запустите файл: **`BUILD_FRONTEND.bat`** (двойной клик)

Затем запустите: **`COPY_TO_HTDOCS.bat`** (двойной клик) - он сам скопирует файлы

### 2. Настроить Apache
Откройте `C:\xampp\apache\conf\httpd.conf` и убедитесь, что эти строки НЕ закомментированы (нет # перед ними):
```
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule rewrite_module modules/mod_rewrite.so
```

Создайте файл `C:\xampp\htdocs\yum\.htaccess` и скопируйте туда содержимое файла `htdocs-yum-htaccess.txt`

### 3. Запустить бэкенд
Запустите файл: **`START_BACKEND.bat`** (двойной клик)

Оставьте окно открытым!

### Готово!
Откройте: **http://localhost/yum/**

---

## Если не работает:

- **Страница не открывается** → Проверьте, что файлы скопированы в `C:\xampp\htdocs\yum\`
- **API не работает** → Убедитесь, что `START_BACKEND.bat` запущен и окно открыто
- **Ошибка 500** → Проверьте, что модули Apache включены (шаг 2)
