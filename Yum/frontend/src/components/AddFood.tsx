/**
 * Компонент быстрого добавления еды
 * Поддерживает поиск по базе и быстрый ввод нового продукта
 */

import React, { useState, useEffect, useRef } from 'react';
import { useFoodStore } from '../stores/useFoodStore';
import { useDailyLogStore } from '../stores/useDailyLogStore';
import type { Food } from '../types';

export const AddFood: React.FC = () => {
  const [query, setQuery] = useState('');
  const [showResults, setShowResults] = useState(false);
  const [quantity, setQuantity] = useState(1);
  const [isCreating, setIsCreating] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);

  const { foods, isLoading, searchFoods, createFood, clearSearch } = useFoodStore();
  const { addEntry } = useDailyLogStore();

  // Поиск при вводе (с задержкой)
  useEffect(() => {
    if (!query.trim()) {
      setShowResults(false);
      clearSearch();
      return;
    }

    const timer = setTimeout(() => {
      searchFoods(query);
      setShowResults(true);
    }, 300);

    return () => clearTimeout(timer);
  }, [query, searchFoods, clearSearch]);

  // Закрытие результатов при клике вне компонента
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        resultsRef.current &&
        !resultsRef.current.contains(event.target as Node) &&
        inputRef.current &&
        !inputRef.current.contains(event.target as Node)
      ) {
        setShowResults(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  /**
   * Добавить продукт из списка результатов
   */
  const handleSelectFood = async (food: Food) => {
    try {
      await addEntry(food.id, quantity);
      setQuery('');
      setShowResults(false);
      setQuantity(1);
      if (inputRef.current) {
        inputRef.current.focus();
      }
    } catch (error) {
      console.error('Error adding food:', error);
    }
  };

  /**
   * Создать новый продукт и добавить его
   */
  const handleCreateAndAdd = async () => {
    if (!query.trim()) return;

    setIsCreating(true);
    try {
      // Парсим калории из запроса (формат: "название 100 ккал" или просто "название")
      const parts = query.trim().split(/\s+/);
      let name = query;
      let calories = 0;

      // Пытаемся найти число в конце (калории)
      const lastPart = parts[parts.length - 1];
      if (!isNaN(Number(lastPart)) && Number(lastPart) > 0) {
        calories = Number(lastPart);
        name = parts.slice(0, -1).join(' ');
      }

      // Если калории не указаны, используем значение по умолчанию
      if (calories === 0) {
        calories = 100; // Можно сделать запрос к пользователю
      }

      const newFood = await createFood({ name, calories });
      await addEntry(newFood.id, quantity);
      
      setQuery('');
      setShowResults(false);
      setQuantity(1);
      if (inputRef.current) {
        inputRef.current.focus();
      }
    } catch (error) {
      console.error('Error creating food:', error);
    } finally {
      setIsCreating(false);
    }
  };

  /**
   * Обработка Enter для быстрого добавления
   */
  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') {
      if (foods.length > 0) {
        // Если есть результаты, выбираем первый
        handleSelectFood(foods[0]);
      } else if (query.trim()) {
        // Иначе создаем новый продукт
        handleCreateAndAdd();
      }
    }
  };

  return (
    <div className="card" style={{ position: 'relative' }}>
      <div style={{ marginBottom: '12px' }}>
        <input
          ref={inputRef}
          type="text"
          placeholder="Введите название еды или калории (например: яблоко 52)"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyPress={handleKeyPress}
          onFocus={() => query && setShowResults(true)}
          style={{ fontSize: '1.1rem', padding: '14px' }}
          autoFocus
        />
      </div>

      {/* Количество */}
      <div style={{ 
        display: 'flex', 
        alignItems: 'center', 
        gap: '12px',
        marginBottom: '12px'
      }}>
        <label style={{ fontSize: '0.9rem', color: '#666' }}>Количество:</label>
        <input
          type="number"
          min="0.1"
          step="0.1"
          value={quantity}
          onChange={(e) => setQuantity(parseFloat(e.target.value) || 1)}
          style={{ width: '80px', padding: '8px' }}
        />
      </div>

      {/* Результаты поиска */}
      {showResults && query && (
        <div 
          ref={resultsRef}
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            right: 0,
            backgroundColor: 'white',
            border: '1px solid #ddd',
            borderRadius: '8px',
            marginTop: '4px',
            maxHeight: '300px',
            overflowY: 'auto',
            boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
            zIndex: 1000
          }}
        >
          {isLoading && (
            <div style={{ padding: '16px', textAlign: 'center', color: '#999' }}>
              Поиск...
            </div>
          )}

          {!isLoading && foods.length === 0 && (
            <div style={{ padding: '16px' }}>
              <div style={{ marginBottom: '8px', color: '#666' }}>
                Не найдено. Создать новый продукт?
              </div>
              <button
                className="btn-primary"
                onClick={handleCreateAndAdd}
                disabled={isCreating}
                style={{ width: '100%' }}
              >
                {isCreating ? 'Создание...' : `Создать "${query}"`}
              </button>
            </div>
          )}

          {!isLoading && foods.length > 0 && (
            <div>
              {foods.slice(0, 10).map((food) => (
                <div
                  key={food.id}
                  onClick={() => handleSelectFood(food)}
                  style={{
                    padding: '12px 16px',
                    cursor: 'pointer',
                    borderBottom: '1px solid #f0f0f0',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center'
                  }}
                  onMouseEnter={(e) => {
                    e.currentTarget.style.backgroundColor = '#f5f5f5';
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.backgroundColor = 'white';
                  }}
                >
                  <span style={{ fontSize: '1rem', fontWeight: 500 }}>
                    {food.name}
                  </span>
                  <span style={{ fontSize: '0.9rem', color: '#4CAF50', fontWeight: 600 }}>
                    {food.calories} ккал
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
};
