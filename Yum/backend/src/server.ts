/**
 * Express сервер для API приложения Yum
 * Обрабатывает запросы для продуктов, дневных логов и настроек пользователя
 */

import express from 'express';
import cors from 'cors';
import { initDatabase } from './db/database.js';
import foodRoutes from './routes/foods.js';
import dailyLogRoutes from './routes/dailyLogs.js';
import userRoutes from './routes/user.js';

const app = express();
const PORT = process.env.PORT || 3001;

// Middleware
// CORS настройки для работы через Apache и туннель
app.use(cors({
  origin: process.env.ALLOWED_ORIGINS?.split(',') || '*',
  credentials: true,
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization']
}));
app.use(express.json());

// Инициализация базы данных
initDatabase();

// API Routes
app.use('/api/foods', foodRoutes);
app.use('/api/daily-logs', dailyLogRoutes);
app.use('/api/user', userRoutes);

// Health check
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', message: 'Yum API is running' });
});

// Запуск сервера
app.listen(PORT, () => {
  console.log(`🚀 Yum API server running on http://localhost:${PORT}`);
});
