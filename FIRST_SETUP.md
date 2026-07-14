# Первая Настройка на Новом Устройстве

После клонирования репозитория нужны эти шаги.

## 1.环境переменные (.env)

```bash
# Скопируйте шаблон
cp .env.example .env

# Отредактируйте .env и добавьте реальные значения
nano .env  # или другой редактор
```

### Необходимые переменные:

```env
# Gemini API (опционально - если не используете Model API)
GEMINI_API_KEY=your_gemini_api_key_here

# Model API Gateway (обычно используется)
MODEL_API_URL=http://65.21.210.122:8017

# Остальные параметры (обычно не требуют изменений)
APP_ENV=development
DB_PATH=./storage/bot.sqlite
STORAGE_PATH=./storage
```

## 2. Зависимости PHP

PHP расширения (должны быть установлены):

```bash
# Проверить установленные расширения
php -m | grep -E "pdo|sqlite|curl|json|mbstring"
```

### Требуемые расширения:
- ✅ `pdo_sqlite` — SQLite database
- ✅ `curl` — HTTP requests
- ✅ `json` — JSON handling
- ✅ `mbstring` — String functions

### Установка на macOS:
```bash
# Если используется Homebrew
brew install php@8.2
brew install php@8.2-pdo-sqlite
```

### Установка на Linux (Ubuntu/Debian):
```bash
sudo apt-get install php8.2 php8.2-sqlite3 php8.2-curl php8.2-mbstring
```

## 3. PHP Конфигурация

Проверить лимиты для больших файлов:

```bash
# Проверить текущие лимиты
php -i | grep -E "upload_max_filesize|post_max_size|memory_limit"
```

### Должно быть минимум:
```
upload_max_filesize: 500M
post_max_size: 500M
memory_limit: 512M
max_execution_time: 3600
```

Если меньше - отредактировать `php.ini`:

```bash
# Найти php.ini
php --ini

# Отредактировать (замените /path/to/php.ini на путь из команды выше)
sudo nano /path/to/php.ini

# Добавить/изменить строки:
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 3600
```

## 4. Директории Storage

Создать и установить права:

```bash
# Создать директории
mkdir -p storage/uploads storage/results

# Установить права доступа
chmod 755 storage
chmod 755 storage/uploads
chmod 755 storage/results

# Если используется Docker - права уже установлены
```

## 5. Database

SQLite база создаётся автоматически при первом запросе:

```bash
# База создастся в: ./storage/bot.sqlite
# Если нужно пересоздать:
rm storage/bot.sqlite
```

## 6. API Ключи

### Вариант A: Использовать внутренний Model API Gateway (РЕКОМЕНДУЕТСЯ)

```env
# В .env оставить как есть:
MODEL_API_URL=http://65.21.210.122:8017
GEMINI_API_KEY=  # оставить пустым
```

**Требования:**
- ✅ Интернет соединение
- ✅ Доступ к http://65.21.210.122:8017
- ✅ Квота не превышена

### Вариант B: Использовать Google Gemini API

```env
# В .env добавить свой ключ:
GEMINI_API_KEY=your_actual_gemini_api_key
MODEL_API_URL=  # оставить пустым или удалить
```

**Как получить:**
1. Перейти на https://aistudio.google.com/app/apikeys
2. Создать новый API key
3. Вставить в GEMINI_API_KEY

## 7. Запуск на новом устройстве

### Вариант 1: Встроенный PHP сервер (быстрый старт)

```bash
# Из корня проекта
./start-dev.sh

# Или вручную
php -c php.ini -S localhost:8000 -t public public/index.php

# Открыть в браузере
# http://localhost:8000
```

### Вариант 2: Docker (рекомендуется)

```bash
# Убедиться что Docker установлен
docker --version
docker-compose --version

# Запустить
docker-compose -f docker-compose.dev.yml up

# Открыть в браузере
# http://localhost:8000
```

### Вариант 3: Apache/Nginx (production)

Смотри [DEPLOYMENT.md](DEPLOYMENT.md)

## 8. Проверка что всё работает

### 1. API endpoint доступен:
```bash
curl http://localhost:8000/api/transcribe?job_id=1
# Должен вернуть JSON с ошибкой 404 (нет такого job)
```

### 2. Web UI загружается:
```bash
curl http://localhost:8000/ | head -5
# Должен вернуть HTML
```

### 3. Загрузить тестовый файл:
```bash
echo "test audio" > /tmp/test.mp3

curl -X POST http://localhost:8000/api/transcribe \
  -F "file=@/tmp/test.mp3" \
  -F "language=auto"

# Должен вернуть JSON с job_id
```

## 9. Troubleshooting

### Ошибка: "File is too large"
```bash
# Проверить php.ini и увеличить лимиты
# Смотри раздел 3
```

### Ошибка: "Could not write to database"
```bash
# Проверить права на storage/ директорию
chmod 755 storage storage/uploads storage/results
```

### Ошибка: "API gateway unreachable"
```bash
# Проверить интернет соединение
curl http://65.21.210.122:8017/health

# Если 429 - истекла квота, используйте GEMINI_API_KEY
```

### Ошибка: "Extension pdo_sqlite not loaded"
```bash
# Установить расширение
# Смотри раздел 2
```

## 10. Конфигурация для разных платформ

### macOS:
```bash
# Установить зависимости
brew install php@8.2 sqlite

# Клонировать и запустить
git clone https://github.com/Alishnis/transcription.git
cd transcription
cp .env.example .env

# Отредактировать .env

# Запустить
./start-dev.sh
```

### Ubuntu/Debian:
```bash
# Установить зависимости
sudo apt-get update
sudo apt-get install php8.2 php8.2-sqlite3 php8.2-curl php8.2-mbstring

# Клонировать
git clone https://github.com/Alishnis/transcription.git
cd transcription
cp .env.example .env

# Отредактировать .env

# Запустить
./start-dev.sh
```

### Windows:
```bash
# Рекомендуется использовать Docker Desktop или WSL2
# С Docker:
docker-compose -f docker-compose.dev.yml up

# Или установить XAMPP/WAMP с PHP 8.2+
```

## 11. Минимальный чеклист

- [ ] `cp .env.example .env`
- [ ] Отредактировать `.env` с API ключами
- [ ] `mkdir -p storage/uploads storage/results`
- [ ] `chmod 755 storage storage/uploads storage/results`
- [ ] Проверить PHP расширения: `php -m`
- [ ] Проверить PHP лимиты: `php -i | grep -E "upload_max|post_max|memory"`
- [ ] Запустить `./start-dev.sh` или `docker-compose up`
- [ ] Открыть http://localhost:8000

## 12. Production Setup

Для production deployment смотри:
- [DEPLOYMENT.md](DEPLOYMENT.md) — Apache/Nginx
- [GITLAB_DEPLOY.md](GITLAB_DEPLOY.md) — GitLab CI/CD
- [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) — Pre-deployment checklist

---

**Вопросы?** Смотри README.md или документацию выше.
