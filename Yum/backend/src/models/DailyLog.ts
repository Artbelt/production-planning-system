/**
 * Модель дневного лога
 * Предоставляет методы для работы с записями о приеме пищи
 */

import { getDatabase } from '../db/database.js';

export interface DailyLogRow {
  id: string;
  food_id: string;
  food_name: string;
  calories: number;
  quantity: number;
  date: string;
  timestamp: string;
  created_at?: string;
}

export class DailyLog {
  /**
   * Получить все записи за определенную дату
   */
  static getByDate(date: string): DailyLogRow[] {
    const db = getDatabase();
    const stmt = db.prepare(`
      SELECT * FROM daily_logs 
      WHERE date = ? 
      ORDER BY timestamp DESC
    `);
    return stmt.all(date) as DailyLogRow[];
  }

  /**
   * Получить запись по ID
   */
  static getById(id: string): DailyLogRow | null {
    const db = getDatabase();
    const stmt = db.prepare('SELECT * FROM daily_logs WHERE id = ?');
    return (stmt.get(id) as DailyLogRow) || null;
  }

  /**
   * Создать новую запись
   */
  static create(log: Omit<DailyLogRow, 'created_at'>): DailyLogRow {
    const db = getDatabase();
    const stmt = db.prepare(`
      INSERT INTO daily_logs (id, food_id, food_name, calories, quantity, date, timestamp)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    `);
    stmt.run(
      log.id,
      log.food_id,
      log.food_name,
      log.calories,
      log.quantity,
      log.date,
      log.timestamp
    );
    return this.getById(log.id)!;
  }

  /**
   * Удалить запись
   */
  static delete(id: string): boolean {
    const db = getDatabase();
    const stmt = db.prepare('DELETE FROM daily_logs WHERE id = ?');
    const result = stmt.run(id);
    return result.changes > 0;
  }

  /**
   * Получить суммарные калории за дату
   */
  static getTotalCaloriesByDate(date: string): number {
    const db = getDatabase();
    const stmt = db.prepare(`
      SELECT SUM(calories * quantity) as total 
      FROM daily_logs 
      WHERE date = ?
    `);
    const result = stmt.get(date) as { total: number | null };
    return result.total || 0;
  }

  /**
   * Получить все даты с записями (для истории)
   */
  static getDatesWithLogs(limit: number = 30): string[] {
    const db = getDatabase();
    const stmt = db.prepare(`
      SELECT DISTINCT date 
      FROM daily_logs 
      ORDER BY date DESC 
      LIMIT ?
    `);
    const rows = stmt.all(limit) as { date: string }[];
    return rows.map(row => row.date);
  }
}
