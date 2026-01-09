/**
 * Скрипт для копирования .htaccess в dist после сборки
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const source = path.join(__dirname, '.htaccess');
const dest = path.join(__dirname, 'dist', '.htaccess');

try {
  if (fs.existsSync(source)) {
    if (!fs.existsSync(path.dirname(dest))) {
      fs.mkdirSync(path.dirname(dest), { recursive: true });
    }
    fs.copyFileSync(source, dest);
    console.log('✅ .htaccess copied to dist/');
  } else {
    console.warn('⚠️  .htaccess not found, skipping copy');
  }
} catch (error) {
  console.error('❌ Error copying .htaccess:', error);
  process.exit(1);
}
