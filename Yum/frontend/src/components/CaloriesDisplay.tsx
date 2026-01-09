/**
 * Компонент отображения калорий и прогресса
 * Показывает текущее количество калорий и прогресс к цели
 */

import React from 'react';
import { useDailyLogStore } from '../stores/useDailyLogStore';

export const CaloriesDisplay: React.FC = () => {
  const { currentLog, userSettings } = useDailyLogStore();

  const totalCalories = currentLog?.totalCalories || 0;
  const goal = userSettings?.dailyCalorieGoal || 2000;
  const percentage = goal > 0 ? Math.min((totalCalories / goal) * 100, 100) : 0;
  const remaining = Math.max(goal - totalCalories, 0);

  return (
    <div className="card">
      <div style={{ textAlign: 'center', marginBottom: '20px' }}>
        <div style={{ fontSize: '0.9rem', color: '#666', marginBottom: '8px' }}>
          Калории сегодня
        </div>
        <div className="large-number" style={{ color: '#4CAF50' }}>
          {Math.round(totalCalories)}
        </div>
        <div style={{ fontSize: '1.2rem', color: '#999', marginTop: '4px' }}>
          из {goal} ккал
        </div>
      </div>

      {/* Прогресс-бар */}
      <div style={{ 
        width: '100%', 
        height: '12px', 
        backgroundColor: '#e0e0e0', 
        borderRadius: '6px',
        overflow: 'hidden',
        marginBottom: '12px'
      }}>
        <div style={{
          width: `${percentage}%`,
          height: '100%',
          backgroundColor: percentage >= 100 ? '#f44336' : '#4CAF50',
          transition: 'width 0.3s ease'
        }} />
      </div>

      {/* Осталось */}
      <div style={{ 
        textAlign: 'center', 
        fontSize: '1.1rem',
        color: remaining > 0 ? '#666' : '#f44336',
        fontWeight: 500
      }}>
        {remaining > 0 ? `Осталось: ${Math.round(remaining)} ккал` : 'Дневная норма достигнута!'}
      </div>
    </div>
  );
};
