/**
 * API роуты для работы с дневными логами
 * GET /api/daily-logs/:date - получить записи за дату
 * POST /api/daily-logs - создать новую запись
 * DELETE /api/daily-logs/:id - удалить запись
 */

import express from 'express';
import { DailyLog } from '../models/DailyLog.js';
import { Food } from '../models/Food.js';
import { v4 as uuidv4 } from 'uuid';

const router = express.Router();

/**
 * GET /api/daily-logs/:date
 * Получить все записи за определенную дату
 * Формат даты: YYYY-MM-DD
 */
router.get('/:date', (req, res) => {
  try {
    const { date } = req.params;
    
    // Валидация формата даты
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      return res.status(400).json({ error: 'Invalid date format. Use YYYY-MM-DD' });
    }

    const entries = DailyLog.getByDate(date);
    const totalCalories = DailyLog.getTotalCaloriesByDate(date);

    res.json({
      date,
      entries,
      totalCalories
    });
  } catch (error) {
    console.error('Error getting daily log:', error);
    res.status(500).json({ error: 'Failed to get daily log' });
  }
});

/**
 * POST /api/daily-logs
 * Создать новую запись о приеме пищи
 * Body: { foodId: string, quantity: number, date: string }
 */
router.post('/', (req, res) => {
  try {
    const { foodId, quantity = 1, date } = req.body;

    // Валидация
    if (!foodId || !date) {
      return res.status(400).json({ error: 'foodId and date are required' });
    }

    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      return res.status(400).json({ error: 'Invalid date format. Use YYYY-MM-DD' });
    }

    // Получаем продукт
    const food = Food.getById(foodId);
    if (!food) {
      return res.status(404).json({ error: 'Food not found' });
    }

    // Создаем запись
    const entry = DailyLog.create({
      id: uuidv4(),
      food_id: foodId,
      food_name: food.name,
      calories: food.calories,
      quantity: quantity || 1,
      date,
      timestamp: new Date().toISOString()
    });

    res.status(201).json(entry);
  } catch (error) {
    console.error('Error creating daily log entry:', error);
    res.status(500).json({ error: 'Failed to create daily log entry' });
  }
});

/**
 * DELETE /api/daily-logs/:id
 * Удалить запись
 */
router.delete('/:id', (req, res) => {
  try {
    const deleted = DailyLog.delete(req.params.id);
    if (!deleted) {
      return res.status(404).json({ error: 'Entry not found' });
    }
    res.json({ success: true });
  } catch (error) {
    console.error('Error deleting daily log entry:', error);
    res.status(500).json({ error: 'Failed to delete entry' });
  }
});

/**
 * GET /api/daily-logs/history/dates
 * Получить список дат с записями (для истории)
 */
router.get('/history/dates', (req, res) => {
  try {
    const limit = parseInt(req.query.limit as string) || 30;
    const dates = DailyLog.getDatesWithLogs(limit);
    res.json({ dates });
  } catch (error) {
    console.error('Error getting history dates:', error);
    res.status(500).json({ error: 'Failed to get history dates' });
  }
});

export default router;
