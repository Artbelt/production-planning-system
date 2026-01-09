/**
 * Модель продукта питания
 * Предоставляет методы для работы с продуктами в базе данных
 */

import { getDatabase } from '../db/database.js';

export interface FoodRow {
  id: string;
  name: string;
  calories: number;
  protein?: number;
  fat?: number;
  carbs?: number;
  created_at?: string;
}

export class Food {
  /**
   * Поиск продуктов по названию
   */
  static search(query: string, limit: number = 20): FoodRow[] {
    const db = getDatabase();
    const stmt = db.prepare(`
      SELECT * FROM foods 
      WHERE name LIKE ? 
      ORDER BY name 
      LIMIT ?
    `);
    return stmt.all(`%${query}%`) as FoodRow[];
  }

  /**
   * Получить продукт по ID
   */
  static getById(id: string): FoodRow | null {
    const db = getDatabase();
    const stmt = db.prepare('SELECT * FROM foods WHERE id = ?');
    return (stmt.get(id) as FoodRow) || null;
  }

  /**
   * Создать новый продукт
   */
  static create(food: Omit<FoodRow, 'created_at'>): FoodRow {
    const db = getDatabase();
    const stmt = db.prepare(`
      INSERT INTO foods (id, name, calories, protein, fat, carbs)
      VALUES (?, ?, ?, ?, ?, ?)
    `);
    stmt.run(
      food.id,
      food.name,
      food.calories,
      food.protein || null,
      food.fat || null,
      food.carbs || null
    );
    return this.getById(food.id)!;
  }

  /**
   * Получить все продукты (для синхронизации)
   */
  static getAll(limit: number = 100): FoodRow[] {
    const db = getDatabase();
    const stmt = db.prepare('SELECT * FROM foods ORDER BY name LIMIT ?');
    return stmt.all(limit) as FoodRow[];
  }
}
