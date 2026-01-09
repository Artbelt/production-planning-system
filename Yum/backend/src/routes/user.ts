/**
 * API роуты для работы с настройками пользователя
 * GET /api/user/settings - получить настройки
 * PUT /api/user/settings - обновить настройки
 */

import express from 'express';
import { UserSettings } from '../models/UserSettings.js';

const router = express.Router();

/**
 * GET /api/user/settings
 * Получить текущие настройки пользователя
 */
router.get('/settings', (req, res) => {
  try {
    const settings = UserSettings.get();
    res.json({
      dailyCalorieGoal: settings.daily_calorie_goal
    });
  } catch (error) {
    console.error('Error getting user settings:', error);
    res.status(500).json({ error: 'Failed to get user settings' });
  }
});

/**
 * PUT /api/user/settings
 * Обновить настройки пользователя
 * Body: { dailyCalorieGoal: number }
 */
router.put('/settings', (req, res) => {
  try {
    const { dailyCalorieGoal } = req.body;

    // Валидация
    if (typeof dailyCalorieGoal !== 'number' || dailyCalorieGoal < 0) {
      return res.status(400).json({ 
        error: 'dailyCalorieGoal must be a positive number' 
      });
    }

    const settings = UserSettings.updateDailyCalorieGoal(dailyCalorieGoal);
    res.json({
      dailyCalorieGoal: settings.daily_calorie_goal
    });
  } catch (error) {
    console.error('Error updating user settings:', error);
    res.status(500).json({ error: 'Failed to update user settings' });
  }
});

export default router;
