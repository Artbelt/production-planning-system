/**
 * Zustand store для управления дневными логами
 * Обеспечивает работу с записями о приеме пищи и синхронизацию с бэкендом
 */

import { create } from 'zustand';
import type { FoodEntry, DailyLog, UserSettings } from '../types';
import { dailyLogAPI, userAPI } from '../services/api';
import { dailyLogDB, settingsDB, initIndexedDB } from '../db/indexedDB';

interface DailyLogStore {
  // Состояние
  currentDate: string; // YYYY-MM-DD
  currentLog: DailyLog | null;
  userSettings: UserSettings | null;
  isLoading: boolean;
  error: string | null;

  // Действия
  setDate: (date: string) => Promise<void>;
  addEntry: (foodId: string, quantity: number) => Promise<void>;
  deleteEntry: (entryId: string) => Promise<void>;
  loadSettings: () => Promise<void>;
  updateSettings: (settings: Partial<UserSettings>) => Promise<void>;
  refreshCurrentLog: () => Promise<void>;
}

/**
 * Получить текущую дату в формате YYYY-MM-DD
 */
function getTodayDate(): string {
  const now = new Date();
  return now.toISOString().split('T')[0];
}

export const useDailyLogStore = create<DailyLogStore>((set, get) => ({
  // Начальное состояние
  currentDate: getTodayDate(),
  currentLog: null,
  userSettings: null,
  isLoading: false,
  error: null,

  /**
   * Установить дату и загрузить лог
   */
  setDate: async (date: string) => {
    set({ currentDate: date, isLoading: true, error: null });

    try {
      await initIndexedDB();

      // Сначала загружаем из IndexedDB для быстрого отображения
      const localEntries = await dailyLogDB.getByDate(date);
      const localTotal = localEntries.reduce((sum, entry) => sum + entry.calories * entry.quantity, 0);
      
      set({ 
        currentLog: { 
          date, 
          entries: localEntries, 
          totalCalories: localTotal 
        },
        isLoading: false 
      });

      // Затем синхронизируем с API
      try {
        const apiLog = await dailyLogAPI.getByDate(date);
        
        // Обновляем локальный кэш
        for (const entry of apiLog.entries) {
          await dailyLogDB.saveEntry(entry);
        }

        set({ currentLog: apiLog, isLoading: false });
      } catch (apiError) {
        // Если API недоступен, используем локальные данные
        console.warn('API unavailable, using cached data:', apiError);
      }
    } catch (error) {
      console.error('Error loading daily log:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка загрузки лога',
        isLoading: false 
      });
    }
  },

  /**
   * Добавить запись о приеме пищи
   */
  addEntry: async (foodId: string, quantity: number = 1) => {
    const { currentDate } = get();
    set({ isLoading: true, error: null });

    try {
      await initIndexedDB();

      // Получаем информацию о продукте
      const { foodAPI } = await import('../services/api');
      const { foodDB } = await import('../db/indexedDB');
      let food;
      
      try {
        food = await foodAPI.getById(foodId);
      } catch (error) {
        // Если API недоступен, пробуем из локального кэша
        food = await foodDB.getById(foodId);
        if (!food) {
          throw new Error('Продукт не найден');
        }
      }

      // Создаем запись
      const entry: FoodEntry = {
        id: crypto.randomUUID(),
        foodId: food.id,
        foodName: food.name,
        calories: food.calories,
        quantity,
        date: currentDate,
        timestamp: new Date().toISOString(),
      };

      // Сохраняем локально сразу
      await dailyLogDB.saveEntry(entry);

      // Обновляем текущий лог
      const currentLog = get().currentLog;
      const updatedEntries = [entry, ...(currentLog?.entries || [])];
      const updatedTotal = updatedEntries.reduce(
        (sum, e) => sum + e.calories * e.quantity,
        0
      );

      set({ 
        currentLog: {
          date: currentDate,
          entries: updatedEntries,
          totalCalories: updatedTotal,
        },
        isLoading: false 
      });

      // Пытаемся синхронизировать с API
      try {
        await dailyLogAPI.createEntry(foodId, quantity, currentDate);
      } catch (apiError) {
        console.warn('Failed to sync entry to API:', apiError);
        // Запись уже сохранена локально, можно продолжить работу
      }
    } catch (error) {
      console.error('Error adding entry:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка добавления записи',
        isLoading: false 
      });
      throw error;
    }
  },

  /**
   * Удалить запись
   */
  deleteEntry: async (entryId: string) => {
    set({ isLoading: true, error: null });

    try {
      await initIndexedDB();

      // Удаляем локально
      await dailyLogDB.deleteEntry(entryId);

      // Обновляем текущий лог
      const { currentLog, currentDate } = get();
      if (currentLog) {
        const updatedEntries = currentLog.entries.filter(e => e.id !== entryId);
        const updatedTotal = updatedEntries.reduce(
          (sum, e) => sum + e.calories * e.quantity,
          0
        );

        set({ 
          currentLog: {
            date: currentDate,
            entries: updatedEntries,
            totalCalories: updatedTotal,
          },
          isLoading: false 
        });
      }

      // Пытаемся удалить через API
      try {
        await dailyLogAPI.deleteEntry(entryId);
      } catch (apiError) {
        console.warn('Failed to delete entry from API:', apiError);
      }
    } catch (error) {
      console.error('Error deleting entry:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка удаления записи',
        isLoading: false 
      });
    }
  },

  /**
   * Загрузить настройки пользователя
   */
  loadSettings: async () => {
    try {
      await initIndexedDB();

      // Сначала проверяем локальные настройки
      const localSettings = await settingsDB.get();
      if (localSettings) {
        set({ userSettings: localSettings });
      }

      // Затем загружаем с API
      try {
        const apiSettings = await userAPI.getSettings();
        await settingsDB.save(apiSettings);
        set({ userSettings: apiSettings });
      } catch (apiError) {
        console.warn('API unavailable, using cached settings:', apiError);
        if (!localSettings) {
          // Если нет локальных настроек, устанавливаем по умолчанию
          const defaultSettings: UserSettings = { dailyCalorieGoal: 2000 };
          await settingsDB.save(defaultSettings);
          set({ userSettings: defaultSettings });
        }
      }
    } catch (error) {
      console.error('Error loading settings:', error);
    }
  },

  /**
   * Обновить настройки
   */
  updateSettings: async (settings: Partial<UserSettings>) => {
    set({ isLoading: true, error: null });

    try {
      await initIndexedDB();

      const currentSettings = get().userSettings || { dailyCalorieGoal: 2000 };
      const updatedSettings: UserSettings = { ...currentSettings, ...settings };

      // Сохраняем локально
      await settingsDB.save(updatedSettings);
      set({ userSettings: updatedSettings, isLoading: false });

      // Синхронизируем с API
      try {
        const apiSettings = await userAPI.updateSettings(updatedSettings);
        await settingsDB.save(apiSettings);
        set({ userSettings: apiSettings });
      } catch (apiError) {
        console.warn('Failed to sync settings to API:', apiError);
      }
    } catch (error) {
      console.error('Error updating settings:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка обновления настроек',
        isLoading: false 
      });
    }
  },

  /**
   * Обновить текущий лог
   */
  refreshCurrentLog: async () => {
    const { currentDate } = get();
    await get().setDate(currentDate);
  },
}));
