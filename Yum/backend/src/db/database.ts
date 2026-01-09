/**
 * Подключение к SQLite базе данных и миграции
 * Создает таблицы для продуктов, дневных логов и настроек пользователя
 */

import Database from 'better-sqlite3';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Путь к файлу базы данных
const DB_PATH = path.join(__dirname, '../../data/yum.db');

let db: Database.Database | null = null;

/**
 * Инициализация базы данных и создание таблиц
 */
export function initDatabase(): Database.Database {
  if (db) {
    return db;
  }

  // Создаем директорию для базы данных, если её нет
  const dbDir = path.dirname(DB_PATH);
  if (!fs.existsSync(dbDir)) {
    fs.mkdirSync(dbDir, { recursive: true });
  }

  db = new Database(DB_PATH);
  
  // Включаем foreign keys
  db.pragma('foreign_keys = ON');

  // Создаем таблицы
  createTables();

  console.log('✅ Database initialized at:', DB_PATH);
  return db;
}

/**
 * Получить экземпляр базы данных
 */
export function getDatabase(): Database.Database {
  if (!db) {
    return initDatabase();
  }
  return db;
}

/**
 * Создание таблиц базы данных
 */
function createTables() {
  if (!db) return;

  // Таблица продуктов
  db.exec(`
    CREATE TABLE IF NOT EXISTS foods (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      calories REAL NOT NULL,
      protein REAL,
      fat REAL,
      carbs REAL,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
  `);

  // Таблица дневных логов
  db.exec(`
    CREATE TABLE IF NOT EXISTS daily_logs (
      id TEXT PRIMARY KEY,
      food_id TEXT NOT NULL,
      food_name TEXT NOT NULL,
      calories REAL NOT NULL,
      quantity REAL NOT NULL DEFAULT 1,
      date TEXT NOT NULL,
      timestamp TEXT NOT NULL,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (food_id) REFERENCES foods(id)
    )
  `);

  // Таблица настроек пользователя
  db.exec(`
    CREATE TABLE IF NOT EXISTS user_settings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      daily_calorie_goal INTEGER NOT NULL DEFAULT 2000,
      updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )
  `);

  // Создаем индексы для быстрого поиска
  db.exec(`
    CREATE INDEX IF NOT EXISTS idx_foods_name ON foods(name);
    CREATE INDEX IF NOT EXISTS idx_daily_logs_date ON daily_logs(date);
    CREATE INDEX IF NOT EXISTS idx_daily_logs_timestamp ON daily_logs(timestamp);
  `);

  // Инициализируем настройки по умолчанию, если их нет
  const settingsCount = db.prepare('SELECT COUNT(*) as count FROM user_settings').get() as { count: number };
  if (settingsCount.count === 0) {
    db.prepare('INSERT INTO user_settings (daily_calorie_goal) VALUES (2000)').run();
  }
}
