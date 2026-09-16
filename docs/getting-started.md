[Назад к README](../README.md) · [Статус выпуска →](release-status.md)

# Начало работы

## Требования

- PHP 8.3+, расширения `intl`, `pdo_pgsql`, `bcmath`, `zip`, `pcntl`;
- Composer 2.8+;
- Node.js 22.13+ и npm;
- PostgreSQL 16 + PostGIS 3.x и Redis 7+;
- Android Studio/SDK для native Android; macOS + Xcode для iOS.

Docker-конфигурация находится в `infra/compose`. В текущем Windows-окружении тестовая БД работает на `127.0.0.1:15432`, Redis — на `127.0.0.1:16379`. Пароли из локального bootstrap нельзя переносить в production.

## Backend

```powershell
cd apps/api
Copy-Item .env.example .env
composer install
php artisan key:generate
php -d extension=intl -d extension=pdo_pgsql artisan migrate --force
php -d extension=intl -d extension=pdo_pgsql artisan db:seed --force
php -d extension=intl -d extension=pdo_pgsql artisan test
```

Запуск процессов разработки:

```powershell
php artisan serve
php artisan queue:work
php artisan schedule:work
php artisan reverb:start
```

Production обязан использовать HTTPS, отдельные случайные `APP_KEY`/Reverb secrets, SMTP, внешний secret store для FCM/APNs/YooKassa и реальный queue worker.

## Администратор

Сначала создайте обычный аккаунт через API/приложение, затем на серверной консоли назначьте роль с причиной:

```powershell
php artisan geo:admin admin@example.ru --reason="Первичный владелец платформы" --yes
```

Вход в `/admin` дополнительно требует TOTP. Команда не создаёт стандартный пароль и пишет audit event. Для отзыва добавьте `--revoke`.

## Mobile

```powershell
cd apps/mobile
Copy-Item .env.example .env.local
npm ci
npm run typecheck
npm test
npx expo start --dev-client
```

Фоновая геолокация и SQLCipher требуют development/production build, а не Expo Go. `ANDROID_MAPS_API_KEY` ограничьте package ID `ru.kirh.geo` и сертификатом подписи. Сервисные ключи в приложение не помещаются.

## Share web и contracts

```powershell
cd apps/share-web
npm ci
npm test
npm run build

cd ../../packages/contracts
npm ci
npm test
npm run audit:backend
```

`IDENTITY_ACTION_URL` должен указывать на HTTPS-адрес `share-web`: reset/verify secrets передаются только во fragment и стираются до загрузки интерфейса.

## Проверка результата

- `GET /api/v1/health` возвращает `{ "data": { "status": "ok" }`;
- backend test suite работает с PostgreSQL/PostGIS, а не SQLite;
- `expo-doctor` проходит все проверки совместимости;
- сборка share-web не требует публичного OSM tile CDN.

## См. также

- [Статус выпуска](release-status.md) — что нельзя считать проверенным локально.
- [Deployment runbook](runbooks/deployment.md) — безопасное размещение рядом с другими проектами.
- [Матрица доступа](security/access-matrix.md) — обязательные backend gates.
