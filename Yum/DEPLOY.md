# Инструкция по развертыванию Yum на Apache

## Предварительные требования

1. **XAMPP** установлен и работает
2. **Node.js** установлен и доступен в PATH
3. Зависимости установлены (`npm install` в `frontend` и `backend`)

## Шаг 1: Сборка фронтенда

```bash
cd frontend
npm run build
```

Это создаст папку `frontend/dist` со статическими файлами.

## Шаг 2: Настройка Apache

### Вариант A: Через виртуальный хост (рекомендуется)

1. Скопируйте файл `apache/yum.conf` в `C:\xampp\apache\conf\extra\yum.conf`

2. Откройте `C:\xampp\apache\conf\httpd.conf` и добавьте в конец:
   ```apache
   Include conf/extra/yum.conf
   ```

3. Если хотите использовать домен `yum.local`, добавьте в `C:\Windows\System32\drivers\etc\hosts`:
   ```
   127.0.0.1    yum.local
   ```

4. Перезапустите Apache через XAMPP Control Panel

### Вариант B: Через поддиректорию

Если хотите разместить приложение в поддиректории (например, `http://yourdomain.com/yum/`):

1. Соберите фронтенд с базовым путем:
   ```bash
   cd frontend
   # Измените base в vite.config.ts на '/yum/'
   npm run build
   ```

2. Скопируйте содержимое `frontend/dist` в `C:\xampp\htdocs\yum\`

3. Добавьте в `.htaccess` в корне `htdocs` или создайте конфигурацию для поддиректории

## Шаг 3: Запуск бэкенда

Бэкенд должен работать постоянно. Есть несколько вариантов:

### Вариант 1: Через PM2 (рекомендуется для production)

```bash
# Установите PM2 глобально
npm install -g pm2

# Соберите бэкенд
cd backend
npm run build

# Запустите через PM2
pm2 start ecosystem.config.js

# Настройте автозапуск
pm2 startup
pm2 save
```

### Вариант 2: Через скрипт Windows

1. Соберите бэкенд:
   ```bash
   cd backend
   npm run build
   ```

2. Запустите `start.vbs` (запускает в фоне) или `start.bat` (в консоли)

3. Для автозапуска добавьте `start.vbs` в автозагрузку Windows

### Вариант 3: Через NSSM (Windows Service)

```bash
# Скачайте NSSM с https://nssm.cc/download
# Установите как сервис:
nssm install YumBackend "C:\Program Files\nodejs\node.exe" "C:\xampp\htdocs\Yum\Yum\backend\dist\server.js"
nssm start YumBackend
```

## Шаг 4: Проверка

1. Откройте браузер и перейдите на `http://yum.local` (или ваш домен)
2. Проверьте, что фронтенд загружается
3. Проверьте API: `http://yum.local/api/health` (должен вернуть JSON)

## Настройка для внешнего доступа через туннель

Если вы используете туннель (ngrok, Cloudflare Tunnel и т.д.):

1. **Бэкенд должен быть доступен на localhost:3001** - туннель будет проксировать запросы через Apache

2. **Настройте CORS в бэкенде** (если нужно):
   ```typescript
   // backend/src/server.ts
   app.use(cors({
     origin: ['http://your-tunnel-domain.com', 'http://localhost'],
     credentials: true
   }));
   ```

3. **Проверьте, что Apache проксирует правильно** - все запросы к `/api` должны идти на `localhost:3001`

## Устранение проблем

### Фронтенд не загружается
- Проверьте путь к `DocumentRoot` в конфигурации Apache
- Убедитесь, что выполнили `npm run build`
- Проверьте права доступа к папке `dist`

### API не работает (404 или ошибки)
- Убедитесь, что бэкенд запущен на порту 3001
- Проверьте логи Apache: `C:\xampp\apache\logs\yum_error.log`
- Проверьте, что модули `mod_proxy` и `mod_rewrite` включены в Apache:
  ```apache
  LoadModule proxy_module modules/mod_proxy.so
  LoadModule proxy_http_module modules/mod_proxy_http.so
  LoadModule rewrite_module modules/mod_rewrite.so
  ```

### CORS ошибки
- Проверьте настройки CORS в бэкенде
- Убедитесь, что заголовки в Apache конфигурации правильные

## Обновление приложения

1. Остановите бэкенд (если используете PM2: `pm2 stop yum-backend`)
2. Обновите код
3. Пересоберите фронтенд: `cd frontend && npm run build`
4. Пересоберите бэкенд: `cd backend && npm run build`
5. Перезапустите бэкенд
6. Перезапустите Apache (или просто обновите страницу)
