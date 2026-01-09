/**
 * IndexedDB обертка для офлайн хранения данных
 * Обеспечивает локальное хранение и синхронизацию с бэкендом
 */

import type { Food, FoodEntry, DailyLog, UserSettings } from '../types';

const DB_NAME = 'YumDB';
const DB_VERSION = 1;

// Названия хранилищ (object stores)
const STORES = {
  FOODS: 'foods',
  DAILY_LOGS: 'daily_logs',
  USER_SETTINGS: 'user_settings',
  SYNC_QUEUE: 'sync_queue',
} as const;

let db: IDBDatabase | null = null;

/**
 * Инициализация базы данных IndexedDB
 */
export async function initIndexedDB(): Promise<IDBDatabase> {
  if (db) {
    return db;
  }

  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onerror = () => {
      reject(new Error('Failed to open IndexedDB'));
    };

    request.onsuccess = () => {
      db = request.result;
      resolve(db);
    };

    request.onupgradeneeded = (event) => {
      const database = (event.target as IDBOpenDBRequest).result;

      // Хранилище продуктов
      if (!database.objectStoreNames.contains(STORES.FOODS)) {
        const foodStore = database.createObjectStore(STORES.FOODS, { keyPath: 'id' });
        foodStore.createIndex('name', 'name', { unique: false });
      }

      // Хранилище дневных логов
      if (!database.objectStoreNames.contains(STORES.DAILY_LOGS)) {
        const logStore = database.createObjectStore(STORES.DAILY_LOGS, { keyPath: 'id' });
        logStore.createIndex('date', 'date', { unique: false });
        logStore.createIndex('timestamp', 'timestamp', { unique: false });
      }

      // Хранилище настроек пользователя
      if (!database.objectStoreNames.contains(STORES.USER_SETTINGS)) {
        database.createObjectStore(STORES.USER_SETTINGS, { keyPath: 'id' });
      }

      // Очередь синхронизации (для офлайн операций)
      if (!database.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
        const syncStore = database.createObjectStore(STORES.SYNC_QUEUE, { 
          keyPath: 'id',
          autoIncrement: true 
        });
        syncStore.createIndex('type', 'type', { unique: false });
      }
    };
  });
}

/**
 * Получить экземпляр базы данных
 */
async function getDB(): Promise<IDBDatabase> {
  if (!db) {
    return await initIndexedDB();
  }
  return db;
}

/**
 * Базовые операции с хранилищами
 */
async function getStore(storeName: string, mode: IDBTransactionMode = 'readonly'): Promise<IDBObjectStore> {
  const database = await getDB();
  const transaction = database.transaction([storeName], mode);
  return transaction.objectStore(storeName);
}

/**
 * Операции с продуктами
 */
export const foodDB = {
  /**
   * Сохранить продукт локально
   */
  async save(food: Food): Promise<void> {
    const store = await getStore(STORES.FOODS, 'readwrite');
    return new Promise((resolve, reject) => {
      const request = store.put(food);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Получить продукт по ID
   */
  async getById(id: string): Promise<Food | null> {
    const store = await getStore(STORES.FOODS);
    return new Promise((resolve, reject) => {
      const request = store.get(id);
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Поиск продуктов по названию
   */
  async search(query: string): Promise<Food[]> {
    const store = await getStore(STORES.FOODS);
    const index = store.index('name');
    return new Promise((resolve, reject) => {
      const request = index.getAll();
      request.onsuccess = () => {
        const foods = request.result as Food[];
        const filtered = foods.filter(food => 
          food.name.toLowerCase().includes(query.toLowerCase())
        );
        resolve(filtered);
      };
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Сохранить несколько продуктов
   */
  async saveMany(foods: Food[]): Promise<void> {
    const store = await getStore(STORES.FOODS, 'readwrite');
    return new Promise((resolve, reject) => {
      const transaction = store.transaction;
      let completed = 0;
      
      foods.forEach(food => {
        const request = store.put(food);
        request.onsuccess = () => {
          completed++;
          if (completed === foods.length) {
            resolve();
          }
        };
        request.onerror = () => reject(request.error);
      });
    });
  },
};

/**
 * Операции с дневными логами
 */
export const dailyLogDB = {
  /**
   * Сохранить запись локально
   */
  async saveEntry(entry: FoodEntry): Promise<void> {
    const store = await getStore(STORES.DAILY_LOGS, 'readwrite');
    return new Promise((resolve, reject) => {
      const request = store.put(entry);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Получить все записи за дату
   */
  async getByDate(date: string): Promise<FoodEntry[]> {
    const store = await getStore(STORES.DAILY_LOGS);
    const index = store.index('date');
    return new Promise((resolve, reject) => {
      const request = index.getAll(date);
      request.onsuccess = () => {
        const entries = request.result as FoodEntry[];
        // Сортируем по timestamp (новые сначала)
        entries.sort((a, b) => 
          new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime()
        );
        resolve(entries);
      };
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Удалить запись
   */
  async deleteEntry(id: string): Promise<void> {
    const store = await getStore(STORES.DAILY_LOGS, 'readwrite');
    return new Promise((resolve, reject) => {
      const request = store.delete(id);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Получить все даты с записями
   */
  async getDates(): Promise<string[]> {
    const store = await getStore(STORES.DAILY_LOGS);
    const index = store.index('date');
    return new Promise((resolve, reject) => {
      const request = index.getAllKeys();
      request.onsuccess = () => {
        const dates = [...new Set(request.result as string[])] as string[];
        dates.sort().reverse(); // Новые даты сначала
        resolve(dates);
      };
      request.onerror = () => reject(request.error);
    });
  },
};

/**
 * Операции с настройками пользователя
 */
export const settingsDB = {
  /**
   * Сохранить настройки локально
   */
  async save(settings: UserSettings): Promise<void> {
    const store = await getStore(STORES.USER_SETTINGS, 'readwrite');
    return new Promise((resolve, reject) => {
      const request = store.put({ id: 'default', ...settings });
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  },

  /**
   * Получить настройки
   */
  async get(): Promise<UserSettings | null> {
    const store = await getStore(STORES.USER_SETTINGS);
    return new Promise((resolve, reject) => {
      const request = store.get('default');
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  },
};
