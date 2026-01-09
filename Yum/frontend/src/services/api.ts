/**
 * API клиент для взаимодействия с бэкендом
 * Все запросы обрабатывают ошибки и возвращают типизированные данные
 */

import type { Food, FoodEntry, DailyLog, UserSettings, FoodSearchResult } from '../types';

const API_BASE_URL = '/api';

/**
 * Базовый fetch с обработкой ошибок
 */
async function fetchAPI<T>(endpoint: string, options?: RequestInit): Promise<T> {
  try {
    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...options?.headers,
      },
    });

    if (!response.ok) {
      const error = await response.json().catch(() => ({ error: 'Unknown error' }));
      throw new Error(error.error || `HTTP error! status: ${response.status}`);
    }

    return await response.json();
  } catch (error) {
    console.error(`API Error [${endpoint}]:`, error);
    throw error;
  }
}

/**
 * API для работы с продуктами
 */
export const foodAPI = {
  /**
   * Поиск продуктов по названию
   */
  async search(query: string, limit: number = 20): Promise<FoodSearchResult> {
    const params = new URLSearchParams({ q: query, limit: limit.toString() });
    return fetchAPI<FoodSearchResult>(`/foods?${params}`);
  },

  /**
   * Получить продукт по ID
   */
  async getById(id: string): Promise<Food> {
    return fetchAPI<Food>(`/foods/${id}`);
  },

  /**
   * Создать новый продукт
   */
  async create(food: Omit<Food, 'id'>): Promise<Food> {
    return fetchAPI<Food>('/foods', {
      method: 'POST',
      body: JSON.stringify(food),
    });
  },
};

/**
 * API для работы с дневными логами
 */
export const dailyLogAPI = {
  /**
   * Получить лог за определенную дату
   */
  async getByDate(date: string): Promise<DailyLog> {
    return fetchAPI<DailyLog>(`/daily-logs/${date}`);
  },

  /**
   * Создать новую запись о приеме пищи
   */
  async createEntry(foodId: string, quantity: number, date: string): Promise<FoodEntry> {
    return fetchAPI<FoodEntry>('/daily-logs', {
      method: 'POST',
      body: JSON.stringify({ foodId, quantity, date }),
    });
  },

  /**
   * Удалить запись
   */
  async deleteEntry(id: string): Promise<void> {
    await fetchAPI(`/daily-logs/${id}`, {
      method: 'DELETE',
    });
  },

  /**
   * Получить список дат с записями (для истории)
   */
  async getHistoryDates(limit: number = 30): Promise<{ dates: string[] }> {
    return fetchAPI<{ dates: string[] }>(`/daily-logs/history/dates?limit=${limit}`);
  },
};

/**
 * API для работы с настройками пользователя
 */
export const userAPI = {
  /**
   * Получить настройки
   */
  async getSettings(): Promise<UserSettings> {
    return fetchAPI<UserSettings>('/user/settings');
  },

  /**
   * Обновить настройки
   */
  async updateSettings(settings: Partial<UserSettings>): Promise<UserSettings> {
    return fetchAPI<UserSettings>('/user/settings', {
      method: 'PUT',
      body: JSON.stringify(settings),
    });
  },
};
