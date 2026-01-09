/**
 * Модель настроек пользователя
 * Управляет настройками, такими как суточная норма калорий
 */

import { getDatabase } from '../db/database.js';

export interface UserSettingsRow {
  id: number;
  daily_calorie_goal: number;
  updated_at?: string;
}

export class UserSettings {
  /**
   * Получить текущие настройки
   */
  static get(): UserSettingsRow {
    const db = getDatabase();
    const stmt = db.prepare('SELECT * FROM user_settings ORDER BY id DESC LIMIT 1');
    return stmt.get() as UserSettingsRow;
  }

  /**
   * Обновить суточную норму калорий
   */
  static updateDailyCalorieGoal(goal: number): UserSettingsRow {
    const db = getDatabase();
    // Проверяем, есть ли уже настройки
    const existing = this.get();
    
    if (existing) {
      // Обновляем существующие
      const stmt = db.prepare(`
        UPDATE user_settings 
        SET daily_calorie_goal = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
      `);
      stmt.run(goal, existing.id);
    } else {
      // Создаем новые
      const stmt = db.prepare(`
        INSERT INTO user_settings (daily_calorie_goal) 
        VALUES (?)
      `);
      stmt.run(goal);
    }
    
    return this.get();
  }
}
