# KIRH GEO

![KIRH GEO](kt-geo-logo.png)

> Добровольный обмен геопозицией для семьи и групп — только с явным согласием каждого участника.

Монорепозиторий содержит Laravel API и Filament-админку, мобильное приложение React Native/Expo, защищённый web-просмотр временных ссылок, OpenAPI-контракты и изолированную инфраструктуру PostgreSQL/PostGIS + Redis + Reverb.

## Что реализовано

- направленный взаимный доступ: разрешение администратора и согласие отправителя проверяются независимо;
- карта участников, история дня, геозоны, SOS, live-сессии и временные ссылки;
- адаптивный Location Engine с режимами idle/normal/live/sport/SOS, SQLCipher-очередью и идемпотентными GPS-батчами;
- тарифы без проверок имени плана: permission + entitlement + usage limits на backend;
- месячные и годовые цены, trial, три вида промокодов, YooKassa boundary и партнёрский ledger;
- Filament-панель тарифов, лимитов GPS/retention, промокодов, партнёров и support metadata;
- экспорт/удаление аккаунта, восстановление пароля, push и приватные WebSocket invalidation-события;
- исходный зелёно-фиолетовый логотип во всех пользовательских поверхностях.

## Быстрый старт проверок

Требуются PHP 8.3+, Composer 2.8+, Node.js 22.13+ и PostgreSQL 16 с PostGIS. Локальные команды Windows:

```powershell
cd apps/api
php -d extension=intl -d extension=pdo_pgsql artisan test

cd ../mobile
npm ci
npm run typecheck
npm test

cd ../share-web
npm ci
npm test
npm run build

cd ../../packages/contracts
npm ci
npm test
npm run audit:backend
```

Полная настройка БД, Redis и `.env` описана в [руководстве разработчика](docs/getting-started.md).

## Структура

| Каталог | Назначение |
|---|---|
| `apps/api` | Laravel 13 API, Filament, jobs и scheduler |
| `apps/mobile` | Expo SDK 57 / React Native Android и iOS |
| `apps/share-web` | Временный просмотр и одноразовые account actions |
| `packages/contracts` | OpenAPI 3.1, TypeScript client, backend route audit |
| `infra` | Compose, nginx, backup/restore/deploy scripts |
| `docs` | Архитектура, БД, безопасность и runbooks |

## Статус выпуска

Код и автоматические тесты работают локально. Это ещё не production-релиз: не выполнены SSH-инвентаризация сервера, реальные sandbox-платежи, настройка доменов/TLS/push/SMTP и испытания фоновой геолокации на физических Android/iPhone. Актуальная матрица — в [статусе выпуска](docs/release-status.md).

## Документация

| Документ | Описание |
|---|---|
| [Начало работы](docs/getting-started.md) | Установка, конфигурация и команды проверки |
| [Архитектура и план](.ai-factory/plans/platform-foundation.md) | Полная архитектура и честный статус задач |
| [Схема данных](docs/database/README.md) | PostgreSQL/PostGIS, ограничения и retention |
| [Матрица доступа](docs/security/access-matrix.md) | Permission, consent и entitlement |
| [State machines](docs/adr/state-machines.md) | Согласие, GPS, billing и privacy |
| [Развёртывание](docs/runbooks/deployment.md) | Изолированное окружение и rollback |
| [Статус выпуска](docs/release-status.md) | Проверено, отложено и внешние зависимости |
| [OpenAPI](packages/contracts/README.md) | Активный API и генерация клиента |

## GitHub

Remote уже настроен на `git@github.com:Closhar/KIRH-GEO.git`. Для нового компьютера добавьте публичный SSH-ключ в GitHub, проверьте `ssh -T git@github.com`, затем выполните `git clone git@github.com:Closhar/KIRH-GEO.git`.

## Лицензия

Проект коммерческий. Публичная лицензия владельцем пока не назначена; права не предоставляются по умолчанию.
