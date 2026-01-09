# Простая настройка Yum для Apache

## Шаг 1: Соберите фронтенд

Откройте терминал в папке проекта и выполните:

```bash
cd frontend
npm install
npm run build
```

Готово! Файлы собраны в папку `frontend/dist`

## Шаг 2: Скопируйте файлы в htdocs

Скопируйте ВСЁ содержимое папки `frontend/dist` в:
```
C:\xampp\htdocs\yum\
```

Если папки `yum` нет - создайте её.

## Шаг 3: Запустите бэкенд

Откройте НОВЫЙ терминал и выполните:

```bash
cd C:\xampp\htdocs\Yum\Yum\backend
npm install
npm run build
node dist/server.js
```

Оставьте этот терминал открытым - бэкенд должен работать постоянно.

## Шаг 4: Настройте Apache

Откройте файл `C:\xampp\apache\conf\httpd.conf` и найдите строки (они могут быть закомментированы с #):

```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule rewrite_module modules/mod_rewrite.so
```

Убедитесь, что перед ними НЕТ символа #. Если есть - уберите.

## Шаг 5: Создайте .htaccess

Создайте файл `C:\xampp\htdocs\yum\.htaccess` со следующим содержимым:

```apache
RewriteEngine On

# Проксирование API на бэкенд
RewriteCond %{REQUEST_URI} ^/yum/api
RewriteRule ^api/(.*)$ http://localhost:3001/api/$1 [P,L]

# Для SPA - все запросы на index.html
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /yum/index.html [L]
```

## Шаг 6: Перезапустите Apache

В XAMPP Control Panel нажмите "Stop" у Apache, затем "Start".

## Готово! 

Откройте в браузере: `http://localhost/yum/`

---

## Если что-то не работает:

1. **Страница не открывается** - проверьте, что файлы скопированы в `C:\xampp\htdocs\yum\`
2. **API не работает** - убедитесь, что бэкенд запущен (терминал должен быть открыт)
3. **Ошибка 500** - проверьте, что модули Apache включены (шаг 4)
