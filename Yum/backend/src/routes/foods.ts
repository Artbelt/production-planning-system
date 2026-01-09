/**
 * API роуты для работы с продуктами питания
 * GET /api/foods - поиск продуктов
 * POST /api/foods - создание нового продукта
 */

import express from 'express';
import { Food } from '../models/Food.js';
import { v4 as uuidv4 } from 'uuid';

const router = express.Router();

/**
 * GET /api/foods?q=query&limit=20
 * Поиск продуктов по названию
 */
router.get('/', (req, res) => {
  try {
    const query = (req.query.q as string) || '';
    const limit = parseInt(req.query.limit as string) || 20;

    if (!query.trim()) {
      return res.json({ foods: [], total: 0 });
    }

    const foods = Food.search(query, limit);
    res.json({ foods, total: foods.length });
  } catch (error) {
    console.error('Error searching foods:', error);
    res.status(500).json({ error: 'Failed to search foods' });
  }
});

/**
 * POST /api/foods
 * Создание нового продукта
 * Body: { name: string, calories: number, protein?: number, fat?: number, carbs?: number }
 */
router.post('/', (req, res) => {
  try {
    const { name, calories, protein, fat, carbs } = req.body;

    // Валидация
    if (!name || !calories) {
      return res.status(400).json({ error: 'Name and calories are required' });
    }

    if (typeof calories !== 'number' || calories < 0) {
      return res.status(400).json({ error: 'Calories must be a positive number' });
    }

    // Создаем продукт
    const food = Food.create({
      id: uuidv4(),
      name: name.trim(),
      calories,
      protein,
      fat,
      carbs
    });

    res.status(201).json(food);
  } catch (error) {
    console.error('Error creating food:', error);
    res.status(500).json({ error: 'Failed to create food' });
  }
});

/**
 * GET /api/foods/:id
 * Получить продукт по ID
 */
router.get('/:id', (req, res) => {
  try {
    const food = Food.getById(req.params.id);
    if (!food) {
      return res.status(404).json({ error: 'Food not found' });
    }
    res.json(food);
  } catch (error) {
    console.error('Error getting food:', error);
    res.status(500).json({ error: 'Failed to get food' });
  }
});

export default router;
