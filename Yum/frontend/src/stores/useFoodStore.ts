/**
 * Zustand store для управления продуктами питания
 * Обеспечивает поиск продуктов и кэширование в IndexedDB
 */

import { create } from 'zustand';
import type { Food, FoodSearchResult } from '../types';
import { foodAPI } from '../services/api';
import { foodDB, initIndexedDB } from '../db/indexedDB';

interface FoodStore {
  // Состояние
  foods: Food[];
  searchQuery: string;
  isLoading: boolean;
  error: string | null;

  // Действия
  searchFoods: (query: string) => Promise<void>;
  createFood: (food: Omit<Food, 'id'>) => Promise<Food>;
  getFoodById: (id: string) => Promise<Food | null>;
  clearSearch: () => void;
}

export const useFoodStore = create<FoodStore>((set, get) => ({
  // Начальное состояние
  foods: [],
  searchQuery: '',
  isLoading: false,
  error: null,

  /**
   * Поиск продуктов
   * Сначала проверяет IndexedDB, затем делает запрос к API
   */
  searchFoods: async (query: string) => {
    if (!query.trim()) {
      set({ foods: [], searchQuery: '' });
      return;
    }

    set({ isLoading: true, error: null, searchQuery: query });

    try {
      // Инициализируем IndexedDB если нужно
      await initIndexedDB();

      // Сначала проверяем локальный кэш
      const localResults = await foodDB.search(query);
      
      // Если есть результаты в кэше, показываем их сразу
      if (localResults.length > 0) {
        set({ foods: localResults, isLoading: false });
      }

      // Затем делаем запрос к API для актуальных данных
      try {
        const result: FoodSearchResult = await foodAPI.search(query);
        
        // Сохраняем найденные продукты в IndexedDB
        if (result.foods.length > 0) {
          await foodDB.saveMany(result.foods);
        }

        set({ foods: result.foods, isLoading: false });
      } catch (apiError) {
        // Если API недоступен, используем локальные данные
        console.warn('API unavailable, using cached data:', apiError);
        if (localResults.length === 0) {
          set({ 
            error: 'Нет подключения к серверу. Проверьте локальный кэш.',
            isLoading: false 
          });
        }
      }
    } catch (error) {
      console.error('Error searching foods:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка поиска продуктов',
        isLoading: false 
      });
    }
  },

  /**
   * Создать новый продукт
   */
  createFood: async (food: Omit<Food, 'id'>) => {
    set({ isLoading: true, error: null });

    try {
      await initIndexedDB();

      // Создаем продукт через API
      const newFood = await foodAPI.create(food);
      
      // Сохраняем локально
      await foodDB.save(newFood);

      // Обновляем список, если текущий запрос соответствует
      const { searchQuery } = get();
      if (searchQuery && newFood.name.toLowerCase().includes(searchQuery.toLowerCase())) {
        const currentFoods = get().foods;
        set({ foods: [newFood, ...currentFoods], isLoading: false });
      } else {
        set({ isLoading: false });
      }

      return newFood;
    } catch (error) {
      console.error('Error creating food:', error);
      set({ 
        error: error instanceof Error ? error.message : 'Ошибка создания продукта',
        isLoading: false 
      });
      throw error;
    }
  },

  /**
   * Получить продукт по ID
   */
  getFoodById: async (id: string) => {
    try {
      await initIndexedDB();

      // Сначала проверяем локальный кэш
      const localFood = await foodDB.getById(id);
      if (localFood) {
        return localFood;
      }

      // Если нет в кэше, запрашиваем с API
      const food = await foodAPI.getById(id);
      await foodDB.save(food);
      return food;
    } catch (error) {
      console.error('Error getting food by id:', error);
      return null;
    }
  },

  /**
   * Очистить результаты поиска
   */
  clearSearch: () => {
    set({ foods: [], searchQuery: '', error: null });
  },
}));
