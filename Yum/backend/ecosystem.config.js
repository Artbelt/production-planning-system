/**
 * PM2 конфигурация для запуска бэкенда как сервиса
 * Установите PM2: npm install -g pm2
 * Запуск: pm2 start ecosystem.config.js
 * Автозапуск: pm2 startup && pm2 save
 */

module.exports = {
  apps: [{
    name: 'yum-backend',
    script: './dist/server.js',
    instances: 1,
    exec_mode: 'fork',
    env: {
      NODE_ENV: 'production',
      PORT: 3001
    },
    error_file: './logs/err.log',
    out_file: './logs/out.log',
    log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    merge_logs: true,
    autorestart: true,
    watch: false,
    max_memory_restart: '1G'
  }]
};
