/**
 * Главный компонент приложения Yum
 * Интегрирует все компоненты и обеспечивает навигацию
 */

import React, { useEffect, useState } from 'react';
import { DailyLog } from './components/DailyLog';
import { History } from './components/History';
import { useDailyLogStore } from './stores/useDailyLogStore';
import { initIndexedDB } from './db/indexedDB';

type View = 'daily' | 'history' | 'settings';

function App() {
  const [currentView, setCurrentView] = useState<View>('daily');
  const { loadSettings } = useDailyLogStore();

  // Инициализация при загрузке
  useEffect(() => {
    const initialize = async () => {
      try {
        // Инициализируем IndexedDB
        await initIndexedDB();
        
        // Загружаем настройки
        await loadSettings();
      } catch (error) {
        console.error('Error initializing app:', error);
      }
    };

    initialize();
  }, [loadSettings]);

  return (
    <div style={{ minHeight: '100vh', backgroundColor: '#f5f5f5' }}>
      {/* Навигация */}
      <nav style={{
        backgroundColor: 'white',
        borderBottom: '1px solid #e0e0e0',
        padding: '12px 20px',
        display: 'flex',
        justifyContent: 'center',
        gap: '8px',
        position: 'sticky',
        top: 0,
        zIndex: 100,
        boxShadow: '0 2px 4px rgba(0,0,0,0.05)'
      }}>
        <button
          className={currentView === 'daily' ? 'btn-primary' : 'btn-secondary'}
          onClick={() => setCurrentView('daily')}
          style={{ 
            padding: '10px 20px',
            fontSize: '1rem',
            borderRadius: '8px'
          }}
        >
          День
        </button>
        <button
          className={currentView === 'history' ? 'btn-primary' : 'btn-secondary'}
          onClick={() => setCurrentView('history')}
          style={{ 
            padding: '10px 20px',
            fontSize: '1rem',
            borderRadius: '8px'
          }}
        >
          История
        </button>
      </nav>

      {/* Контент */}
      <main style={{ paddingBottom: '40px' }}>
        {currentView === 'daily' && <DailyLog />}
        {currentView === 'history' && <History />}
      </main>
    </div>
  );
}

export default App;
