/**
 * Компонент дневного лога
 * Отображает все записи о приеме пищи за день
 */

import React, { useEffect } from 'react';
import { useDailyLogStore } from '../stores/useDailyLogStore';
import { CaloriesDisplay } from './CaloriesDisplay';
import { AddFood } from './AddFood';

export const DailyLog: React.FC = () => {
  const { currentLog, currentDate, isLoading, deleteEntry, setDate } = useDailyLogStore();

  // Загружаем лог при монтировании
  useEffect(() => {
    setDate(currentDate);
  }, [currentDate, setDate]);

  /**
   * Удалить запись
   */
  const handleDelete = async (entryId: string) => {
    if (window.confirm('Удалить эту запись?')) {
      await deleteEntry(entryId);
    }
  };

  /**
   * Изменить дату
   */
  const handleDateChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setDate(e.target.value);
  };

  /**
   * Переключение на предыдущий/следующий день
   */
  const changeDate = (days: number) => {
    const date = new Date(currentDate);
    date.setDate(date.getDate() + days);
    setDate(date.toISOString().split('T')[0]);
  };

  return (
    <div style={{ maxWidth: '600px', margin: '0 auto', padding: '20px' }}>
      <h1 style={{ 
        fontSize: '2rem', 
        marginBottom: '24px', 
        textAlign: 'center',
        color: '#333'
      }}>
        Yum
      </h1>

      {/* Выбор даты */}
      <div className="card" style={{ marginBottom: '20px' }}>
        <div style={{ 
          display: 'flex', 
          alignItems: 'center', 
          justifyContent: 'space-between',
          gap: '12px'
        }}>
          <button 
            className="btn-secondary"
            onClick={() => changeDate(-1)}
            style={{ padding: '8px 16px' }}
          >
            ←
          </button>
          <input
            type="date"
            value={currentDate}
            onChange={handleDateChange}
            style={{ 
              flex: 1, 
              fontSize: '1.1rem',
              textAlign: 'center',
              border: '2px solid #ddd',
              borderRadius: '8px',
              padding: '10px'
            }}
          />
          <button 
            className="btn-secondary"
            onClick={() => changeDate(1)}
            style={{ padding: '8px 16px' }}
          >
            →
          </button>
        </div>
      </div>

      {/* Отображение калорий */}
      <CaloriesDisplay />

      {/* Добавление еды */}
      <AddFood />

      {/* Список записей */}
      <div className="card">
        <h2 style={{ 
          fontSize: '1.3rem', 
          marginBottom: '16px',
          color: '#333'
        }}>
          Записи за день
        </h2>

        {isLoading && (
          <div style={{ textAlign: 'center', padding: '20px', color: '#999' }}>
            Загрузка...
          </div>
        )}

        {!isLoading && (!currentLog || currentLog.entries.length === 0) && (
          <div style={{ 
            textAlign: 'center', 
            padding: '40px 20px', 
            color: '#999',
            fontSize: '1.1rem'
          }}>
            Нет записей за этот день
          </div>
        )}

        {!isLoading && currentLog && currentLog.entries.length > 0 && (
          <div>
            {currentLog.entries.map((entry) => (
              <div
                key={entry.id}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  padding: '12px 0',
                  borderBottom: '1px solid #f0f0f0'
                }}
              >
                <div style={{ flex: 1 }}>
                  <div style={{ 
                    fontSize: '1rem', 
                    fontWeight: 500,
                    marginBottom: '4px'
                  }}>
                    {entry.foodName}
                  </div>
                  <div style={{ fontSize: '0.85rem', color: '#666' }}>
                    {entry.quantity > 1 && `${entry.quantity} × `}
                    {entry.calories} ккал
                    {entry.quantity > 1 && ` = ${Math.round(entry.calories * entry.quantity)} ккал`}
                  </div>
                </div>
                <button
                  className="btn-secondary"
                  onClick={() => handleDelete(entry.id)}
                  style={{ 
                    padding: '6px 12px',
                    fontSize: '0.9rem',
                    backgroundColor: '#ffebee',
                    color: '#c62828'
                  }}
                >
                  Удалить
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};
