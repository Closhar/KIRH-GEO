# Размещение и эксплуатация

## Изоляция

Compose-проект `kirh-geo` создаёт собственные сети и volumes. PostgreSQL 17 + PostGIS 3.6 и Redis доступны только в сети данных. На хосте production открыт только `127.0.0.1:18080`; существующий TLS reverse proxy получает отдельный virtual host. Скрипты не меняют firewall, SSH, другие compose-проекты и конфиги соседних сайтов. До установки проверить порт, RAM/CPU/диск и регион сервера. Рабочий SSH-доступ на сервер пока не подтверждён.

Состав: API PHP-FPM 8.3, очередь, scheduler, Reverb, nginx, PostGIS, Redis. Redis использует AOF и `noeviction`: заполнение памяти вызывает наблюдаемую ошибку, не тихую потерю очереди. Current locations должны иметь TTL; историю восстанавливаем из PostgreSQL.

## Разработка

1. Скопировать `infra/compose/.env.example` в `.env` рядом и заполнить два разных пароля.
2. Скопировать `apps/api/.env.example` в `.env`; настроить DB_DATABASE=`kirh_geo`, DB_USERNAME=`kirh_app`, DB_PASSWORD как в compose env, DB_CONNECTION=`pgsql`, REDIS_CLIENT согласно установленному адаптеру, QUEUE_CONNECTION=`redis`. APP_KEY генерируется `php artisan key:generate`.
3. Из корня: `docker compose --env-file infra/compose/.env -f infra/compose/compose.yml -f infra/compose/compose.development.yml up -d --build`.
4. Выполнить миграции ролью `kirh_migrator`, пароль которой хранится только в compose env. Runtime роль `kirh_app` не должна быть владельцем таблиц.

API: `http://127.0.0.1:18080`, PostgreSQL: `127.0.0.1:15432`, Redis: `127.0.0.1:16379`. Эти порты публикует только development overlay. Первый образ устанавливает расширение и создаёт runtime роль через init script; повторный запуск не переписывает пароли существующей БД.

### Проверенная локальная альтернатива без Docker (Windows + WSL)

На машине разработки установлен WSL Ubuntu 24.04. Для тестов установлены PostgreSQL 16.15 / PostGIS 3.4, Redis 7.0 и PHP 8.3 с расширениями. `bootstrap-wsl-tests.sh` создал отдельный кластер `16/kirh_geo`, порт 15432; БД `kirh_geo_test` и `kirh_geo_dev`; роль `kirh_test`, пароль `kirh-local-test-only`. Это исключительно локальные тестовые реквизиты. Redis тестов слушает 16379. Подключение Windows PHP PDO к PostGIS и Redis PONG проверены. Пакеты также создали стандартные пустые службы PG5432/Redis6379; проект их не использует.

Команда PHP на Windows: `C:/php/php.exe -d extension=pdo_pgsql -d extension=pgsql -d extension=intl artisan test`. Глобальный php.ini не менялся. Не запускайте одновременно Compose development и WSL-кластер на тех же портах. После перезагрузки WSL кластер запускается `wsl -d Ubuntu-24.04 -u root -- pg_ctlcluster 16 kirh_geo start`; Redis test daemon запускается отдельно параметрами из bootstrap script. CI проверяет более новую целевую PostgreSQL17/PostGIS3.6, локальная проверка PG16 не заменяет эту проверку.

## Production release

Собрать образы с неизменяемым release tag (например SHA коммита), проверить CI и vulnerability scan:

```sh
docker build -f infra/docker/Dockerfile --target production -t REGISTRY/kirh-api:SHA .
docker build -f infra/docker/Dockerfile --target proxy -t REGISTRY/kirh-proxy:SHA .
```

Разместить проверенные образы в выбранном registry; заполнить `API_IMAGE` и `PROXY_IMAGE`. Registry, DNS, TLS, push и платежные production credentials конфигурируются до публикации. Ни один секрет не включается в image. Env-файлы ограничить правами 0600. APP_DEBUG=false. APP_URL=https://домен. Настроить точные allowed origins Reverb и доверенные адреса host proxy. Изменение прокси должно пройти `nginx -t` до reload.

Первый запуск: `bash infra/deploy/deploy.sh`. Обновление после изменения release tags: `bash infra/deploy/update.sh`. Скрипт останавливается при ошибке backup или migration; destructive migrations требуют отдельного согласованного окна. Это single-instance deployment с возможным кратким перерывом, не zero downtime. Скрипты не делают git pull или reset.

Миграционная роль является bootstrap superuser PostgreSQL и никогда не используется HTTP API. Runtime роль имеет DML на public tables. Перед коммерческим запуском отделить provisioning superuser от DDL-only migration role и ограничить runtime grants по таблицам; текущий bootstrap не реализует PostgreSQL RLS.

## Backups / restore / rollback

`bash infra/deploy/backup.sh` создаёт private custom-format dump, проверяет архив и сохраняет SHA256. `KIRH_BACKUP_DIR` задаёт абсолютный путь. Расписание и offsite encrypted storage необходимо настроить до production. Daily dump не обеспечивает RPO 1 час; для этого нужен как минимум почасовой backup с измерением времени или WAL archiving/PITR. WAL/PITR здесь ещё не включены. Удаление старых копий намеренно не автоматизировано до проверки offsite retention.

`bash infra/deploy/restore-drill.sh /absolute/archive.dump` проверяет checksum и восстанавливает в новую уникальную drill database; никакие рабочие БД не удаляются. Затем выполнить миграции/чтение контрольных пользователей и координат на этой БД, измерить RTO. Тестовая БД остаётся для проверки и удаляется отдельно по её точному имени.

Для совместимой схемы вернуть предыдущие API_IMAGE и PROXY_IMAGE через `rollback.sh`. Автоматического отката схемы нет: новая запись пользователя не должна потеряться из-за отката приложения.

## Наблюдение

`health-check.sh` проверяет наличие всех семи сервисов, Docker health, SQL PostGIS и HTTP `/up`. Worker/scheduler checks проверяют процесс и загрузку Laravel, но сами по себе не подтверждают обработку задач: дополнительно наблюдать возраст outbox, queue lag, last scheduler heartbeat. Алерты: недоступность API, рост 5xx, failed jobs, oldest queued event >5 сек, disk >65%, отсутствие свежей offsite backup, ошибки push/payment delivery. `/up` — liveness, не end-to-end readiness. Nginx не пишет URL и query tokens; журналы ограничены ротацией.

Выбор образов проверен по официальным каталогам: [PostGIS](https://github.com/postgis/docker-postgis), [Redis](https://hub.docker.com/_/redis), [nginx](https://hub.docker.com/_/nginx). Minor tags фиксированы, но digest ещё не закреплён; release процесс должен закреплять digest после проверки уязвимостей.
