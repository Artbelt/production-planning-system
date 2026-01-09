/**
 * Типы данных для приложения Yum
 */

// Продукт питания
export interface Food {
  id: string;
  name: string;
  calories: number;
  // Для будущего расширения (БЖУ)
  protein?: number;
  fat?: number;
  carbs?: number;
}

// Запись о приеме пищи
export interface FoodEntry {
  id: string;
  foodId: string;
  foodName: string; // Дублируем для быстрого доступа
  calories: number;
  quantity: number; // Количество (порций, грамм и т.д.)
  date: string; // YYYY-MM-DD
  timestamp: string; // ISO timestamp
}

// Дневной лог
export interface DailyLog {
  date: string; // YYYY-MM-DD
  entries: FoodEntry[];
  totalCalories: number;
}

// Настройки пользователя
export interface UserSettings {
  dailyCalorieGoal: number; // Суточная норма калорий
}

// Результат поиска продуктов
export interface FoodSearchResult {
  foods: Food[];
  total: number;
}
