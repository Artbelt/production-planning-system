/**
 * Компонент истории
 * Показывает список дней с записями (базовая версия для MVP)
 */

import React, { useEffect, useState } from 'react';
import { useDailyLogStore } from '../stores/useDailyLogStore';
import { dailyLogAPI } from '../services/api';
import { dailyLogDB, initIndexedDB } from '../db/indexedDB';

export const History: React.FC = () => {
  const [dates, setDates] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const { setDate } = useDailyLogStore();

  useEffect(() => {
    loadHistory();
  }, []);

  /**
   * Загрузить историю дат
   */
  const loadHistory = async () => {
    setIsLoading(true);
    try {
      await initIndexedDB();

      // Сначала загружаем из локального кэша
      const localDates = await dailyLogDB.getDates();
      setDates(localDates);

      // Затем синхронизируем с API
      try {
        const result = await dailyLogAPI.getHistoryDates(30);
        setDates(result.dates);
      } catch (apiError) {
        console.warn('API unavailable, using cached dates:', apiError);
      }
    } catch (error) {
      console.error('Error loading history:', error);
    } finally {
      setIsLoading(false);
    }
  };

  /**
   * Форматировать дату для отображения
   */
  const formatDate = (dateString: string): string => {
    const date = new Date(dateString);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    // Проверяем, сегодня ли это
    if (date.toDateString() === today.toDateString()) {
      return 'Сегодня';
    }

    // Проверяем, вчера ли это
    if (date.toDateString() === yesterday.toDateString()) {
      return 'Вчера';
    }

    // Иначе форматируем дату
    return date.toLocaleDateString('ru-RU', {
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    });
  };

  /**
   * Перейти к выбранной дате
   */
  const handleDateClick = (date: string) => {
    setDate(date);
    // Можно добавить навигацию обратно к DailyLog
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  return (
    <div style={{ maxWidth: '600px', margin: '0 auto', padding: '20px' }}>
      <h1 style={{ 
        fontSize: '2rem', 
        marginBottom: '24px', 
        textAlign: 'center',
        color: '#333'
      }}>
        История
      </h1>

      <div className="card">
        {isLoading && (
          <div style={{ textAlign: 'center', padding: '20px', color: '#999' }}>
            Загрузка истории...
          </div>
        )}

        {!isLoading && dates.length === 0 && (
          <div style={{ 
            textAlign: 'center', 
            padding: '40px 20px', 
            color: '#999',
            fontSize: '1.1rem'
          }}>
            История пуста
          </div>
        )}

        {!isLoading && dates.length > 0 && (
          <div>
            {dates.map((date) => (
              <div
                key={date}
                onClick={() => handleDateClick(date)}
                style={{
                  padding: '16px',
                  borderBottom: '1px solid #f0f0f0',
                  cursor: 'pointer',
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
                <span style={{ fontSize: '1.1rem', fontWeight: 500 }}>
                  {formatDate(date)}
                </span>
                <span style={{ color: '#999' }}>→</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};
