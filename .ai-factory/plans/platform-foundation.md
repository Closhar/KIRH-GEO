# KIRH GEO — архитектура и план реализации

Дата: 2026-09-15. Статус: проект для обсуждения, реализация не начата.
Branch: main. При первой проверке папка была пустой. После предоставления remote создан Git-репозиторий и каталоги monorepo; исходного кода приложений пока нет.

## Settings

- Testing: yes — обязательное требование пользователя.
- Logging: standard в production, выборочный DEBUG в development; без координат, токенов, адресов и платежных данных.
- Docs: yes — архитектурные решения, OpenAPI, эксплуатация и восстановление входят в каждый этап.
- Работа последовательно: архитектура/БД → backend → админка → мобильный клиент → production.
- Сейчас прорабатывается план. Пользователь предоставил GitHub remote и ранее разрешил синхронизацию: Git и каталоги monorepo созданы; реализация приложений и серверные изменения не начаты.
- Версии библиотек фиксируются после проверки совместимости перед scaffold; нельзя использовать плавающий latest в production.

## 1. Продукт и границы первого выпуска

Коммерческая платформа добровольного обмена геопозицией. Рынок первого запуска — Россия. Аккаунты обычные, специальная модель детских аккаунтов в MVP не предусмотрена. Первый сценарий — владелец приглашает своих людей кодом; каждый устанавливает приложение, присоединяется и явно разрешает передачу собственных координат. Пользователь может состоять в нескольких рабочих пространствах, выбирать аудиторию передачи и приостанавливать её.

Первый выпуск: регистрация, устройства, приглашения, семья/группы, согласия, карта, дневная история, круговые геозоны, SOS, live-сессии, временные ссылки, уведомления, подписки, админка, удаление/экспорт данных. Sport получает режим записи и задел сущностей; тренировки, рекорды, сегменты, калории и полноценная аналитика отложены. Командные и корпоративные интерфейсы тоже отложены.

Предлагаемые исходные решения:

- Подписка принадлежит workspace; оплачивает назначенный billing owner. Оплата не даёт доступа к чужим координатам.
- Приглашение и членство не равны согласию на передачу. Никто, включая владельца семьи и администратора платформы, не может незаметно включить GPS.
- Отсутствие согласия, пауза или отзыв блокируют приём и выдачу координат для соответствующей аудитории.
- Разрешение ОС и продуктовое согласие — разные состояния; нужны оба.
- История до вступления в группу или до начала разрешённого интервала по умолчанию недоступна.
- Из нескольких телефонов пользователь явно выбирает основной источник позиции; политика исключает скачки карты между устройствами.
- SOS предложено включить в базовый доступ без платного барьера, с ограничением злоупотреблений. Это оповещение контактов, не вызов экстренных служб.
- Детские аккаунты и правовые правила зависят от рынка; нельзя автоматически приравнивать роль родителя к полномочию давать любое согласие.

## 2. Архитектура

Модульный монолит Laravel с очередями и отдельными процессами API, scheduler, workers и WebSocket. Один PostgreSQL/PostGIS на первом этапе. EntitlementService — внутренний сервис с интерфейсом, не отдельный сетевой микросервис. Разделение на микросервисы допускается по измеренной нагрузке.

Поток координат:

`Native Location Engine → encrypted SQLite queue → HTTPS GPS batch → policy + consent + entitlement → PostgreSQL transaction (points, dedup, outbox) → workers → Redis current location + geofences + authorized WebSocket + push`

PostgreSQL — долговечная истина. Redis current_locations — восстанавливаемая проекция с TTL за интерфейсом CurrentLocationStore, не единственное место сохранения принятой точки. Redis для очередей/кэша разделяется конфигурационно; eviction не должен удалять задания. Outbox позволяет восстановить задания после сбоя очереди.

Модули backend:

| Модуль | Ответственность |
|---|---|
| Identity | users, auth, devices, sessions, tokens |
| Workspaces | членство, приглашения, groups, scoped roles |
| Consent | согласия, аудитории, пауза, отзыв, версии |
| Access | permission policies, EntitlementService, квоты |
| Billing | каталог, подписки, платежные адаптеры, счета, trials, promos |
| Partners | партнёры, атрибуция приглашений, комиссии, ledger, заявки на выплаты |
| Location | batch ingestion, история, current position, конфигурация режимов |
| Geofencing | зоны, состояния и события перехода |
| Safety | SOS, подтверждения, завершение |
| Sharing | live sessions, временные capabilities |
| Notifications | in-app, push, доставки и предпочтения |
| Activity | каркас sport sessions без аналитики |
| Administration | поддержка, настройки, аудит и эксплуатация |

Модуль имеет Domain, Application, Infrastructure, Http. HTTP-контроллеры тонкие; бизнес-транзакции — application services. Другой модуль использует явный сервис/контракт или событие; не меняет чужие модели напрямую. Синхронно проверяем права и лимиты, асинхронно доставляем уведомления. Outbox-события версионируются, обработчики идемпотентны.

React Native + TypeScript, native Android/iOS location adapters. Таймеры JavaScript не отвечают за фоновый GPS. Нативный прототип обязателен до полной разработки клиента. Админка: Laravel + Filament как исходное предложение, отдельный guard, MFA и политики на сервере. Публичная страница временного просмотра — небольшой web-клиент с картой, без установки приложения.

## 3. Предлагаемая структура monorepo

```text
KIRH-GEO/
  apps/
    api/
      app/Modules/{Identity,Workspaces,Consent,Access,Billing,Location,...}/
      app/Filament/
      database/{migrations,seeders,factories}/
      tests/{Unit,Feature,Integration}/
    mobile/
      src/{app,features,shared}/
      src/features/location/{engine,queue,permissions,adapters}/
      android/
      ios/
      e2e/
    share-web/
  packages/
    contracts/                 # OpenAPI и генерируемый TS API-клиент
  infra/
    compose/
    nginx/
    deploy/
    monitoring/
  docs/
    architecture/
    database/
    product/
    security/
    runbooks/
    adr/
  .github/workflows/           # либо GitLab CI после выбора remote
  .ai-factory/plans/
```

Админка физически внутри api, самостоятельный frontend для неё пока не нужен. Секреты и реальные GPS-наборы не попадают в Git. Клиенты используют сгенерированные контракты, не копируют серверные правила как источник истины.

## 4. Логическая схема БД

Общие правила: UUID для публичных идентификаторов; timestamptz UTC; деньги bigint в минимальных единицах + currency; FK и CHECK на допустимые состояния и диапазоны. Для tenant-таблиц workspace_id NOT NULL. Составные FK/уникальные ключи запрещают ссылки на сущности другого workspace. Soft delete применяется выборочно, не заменяет удаление персональных данных.

### Identity / tenancy / access

| Таблицы | Ключевые поля и ограничения |
|---|---|
| users | id, normalized_email/phone, password_hash, status, locale, timezone; уникальность подтверждённых идентификаторов |
| devices | id, user_id, installation_id, platform, app_version, revoked_at, last_seen_at; unique(user_id, installation_id); аппаратный ID не используем |
| auth_sessions | user_id, device_id, refresh_token_hash, expires_at, revoked_at; ротация и обнаружение повторного refresh |
| workspaces | id, type(family/team/business), name, owner_user_id, billing_owner_user_id, status |
| workspace_memberships | workspace_id, user_id, status, joined_at, left_at; unique(workspace_id,user_id) |
| invitations | workspace_id, group_id?, token_hash, expires_at, invited_by, accepted_at; одноразовый токен |
| groups | id, workspace_id, type, name |
| group_memberships | workspace_id, group_id, user_id, joined_at, left_at; unique(group_id,user_id), FK в членство workspace |
| roles / permissions / role_permissions | стабильные permission keys; системные шаблоны ролей |
| workspace_role_assignments / group_role_assignments | явные scope FK; роль пользователя в конкретной области |
| admin_role_assignments | изолированные роли платформы; не наследуют доступ к геопозициям |
| consent_logs | append-only: subject, actor, purpose, audience, action, policy_version, recorded_at, device_id; минимальная доказательная запись |
| sharing_grants | user_id, device_id?, workspace_id, group_id?, scope, starts_at, ends_at, revoked_at, consent_version; текущее продуктовое разрешение |
| location_preferences | user_id, primary_device_id, sharing_paused, revision |

Видимость нескольких групп — объединение явно разрешённых аудиторий внутри workspace. Повторное согласие создаёт новую версию/интервал, старое не оживляет. membership и grant проверяются и на момент записи, и на момент чтения согласно политике истории.

### Каталог, биллинг, права и использование

| Таблицы | Ключевые поля и ограничения |
|---|---|
| plans | code, version, active; опубликованная версия неизменяема |
| features | key, value_type(bool/int/duration), unit, merge_strategy |
| plan_features | plan_id, feature_id, typed_value; unique(plan_id,feature_id) |
| plan_prices | plan_id, interval(month/year), currency, amount_minor, provider, external_product_id, external_price_id |
| subscriptions | workspace_id, plan_price_id, provider, external_id, status, period_start/end, cancel_at_period_end, revision; unique(provider,external_id) |
| payments | subscription_id, provider, external_id, amount_minor, currency, status; unique(provider,external_id) |
| invoices / invoice_items | workspace_id, subscription_id, provider_reference, totals, tax, issued_at; снимок реквизитов/цен |
| refunds | payment_id, provider, external_id, amount_minor, status; отдельная сущность частичных возвратов |
| promo_codes / promo_redemptions | code_hash или normalized_code, window, discount/trial_extension, max_uses, eligibility; атомарный расход |
| trials | workspace_id, campaign_id, starts_at, ends_at, status; ограничения повторной активации по согласованной политике |
| entitlements | workspace_id, feature_id, source_type/id, typed_value, starts_at, ends_at, revoked_at; выдача/изменение только EntitlementService |
| usage_events | idempotency_key, workspace_id, feature_id, quantity, occurred_at, period_id; unique(workspace_id,idempotency_key) |
| usage_counters / usage_reservations | workspace_id, feature_id, period, consumed/reserved; уникальный счётчик периода, TTL резервов |
| payment_webhook_events | provider, external_event_id, payload_hash, received_at, status, attempts; unique(provider,external_event_id) |

Для одного workspace в первом выпуске один основной источник подписки; повторную покупку на другой платформе предупреждаем, не удваиваем права автоматически. Права от promos/trials/support grants имеют явный приоритет. Для bool — разрешённое объединение, для лимитов — max либо override, но никогда не неявное сложение. Все решения объяснимы через API effective entitlements.

### Геолокация, события и sharing

| Таблицы / хранилища | Ключевые поля и ограничения |
|---|---|
| location_batches | device_id, client_batch_id, payload_hash, response_summary, accepted_at; unique(device_id,client_batch_id) |
| location_point_receipts | device_id, client_point_id, payload_hash, point_time; unique(device_id,client_point_id), срок не меньше окна повторной отправки |
| location_points | device_id, user_id, client_point_id, captured_at, received_at, position geography(Point,4326), accuracy_m, altitude_m?, speed_mps?, heading?, battery_pct?, mode, consent_version |
| location_point_audiences | point partition key + point_id, workspace_id, grant_id; снимок разрешённых аудиторий точки, не произвольный workspace от клиента |
| current_locations (Redis) | ключ workspace/user/device, point_id, captured_at, received_at, accuracy, battery, consent_revision, TTL; через CurrentLocationStore |
| geofences | workspace_id, group_id?, owner_id, center geography(Point,4326), radius_m, active; круги MVP, polygons позже |
| geofence_targets | workspace_id, geofence_id, user_id; определяет, для кого действуют зоны |
| geofence_states | geofence_id, user_id, state, last_processed_at, candidate_since, transition_seq; unique(geofence_id,user_id) |
| geofence_events | workspace_id, geofence_id, user_id, transition_seq, type, occurred_at, detected_at, point_id; уникальный переход |
| sos_events / sos_acknowledgements | workspace_id, user_id, device_id, started_at, ended_at, status; recipients snapshots и подтверждения адресатов |
| live_sessions / live_session_participants | workspace_id, initiator_id, subject_id, consent_grant_id, expires_at, status; получатели и добровольное участие |
| temporary_shares | issuer_id, workspace_id, subject_id, token_hash, grant_id, expires_at, revoked_at, scope, optional_passcode_hash; только разрешённые subject/scope |
| activity_sessions | user_id, workspace_id?, device_id, type, started_at, ended_at, status; собственная запись sport |
| activity_laps / activity_metric_samples | session_id, timestamp/sequence, metric, value, unit; резерв схемы для будущей аналитики |

location_points партиционируется по captured_at (первоначально месяц; уточнить нагрузкой). Индексы: (user_id,captured_at), (device_id,captured_at), GiST(position) только для реально используемых пространственных запросов. Геозоны используют ST_DWithin в метрах. Глобальную дедупликацию между партициями обеспечивает location_point_receipts: unique на partitioned history без partition key недостаточен.

Предлагаем одно физическое измерение и отдельные аудитории: это предотвращает повторное хранение точки на каждый workspace. Retention аудитории определяется её подпиской, физическое удаление — после истечения всех законных оснований хранения, с отдельной политикой личной sport-записи. Отзыв доступа действует сразу, даже когда физическое удаление выполняется очередью. Точный retention и модель истории после выхода фиксируются до SQL DDL.

### Доставка / эксплуатация

| Таблицы | Ключевые поля |
|---|---|
| notifications / notification_deliveries | получатель, тип, событие, channel, dedup_key, status, attempts, delivered_at; unique(event,recipient,channel) |
| notification_preferences | user_id, workspace_id?, type, channel, enabled |
| device_tokens | device_id, provider, token_encrypted, token_fingerprint, invalidated_at; unique(provider,token_fingerprint) |
| audit_logs | actor, action, target, workspace_id?, correlation_id, redacted_changes, timestamp |
| system_events | component, severity, event_code, correlation_id, redacted_context, timestamp |
| application_settings | namespace, key, typed_value, version, updated_by; секреты только во внешнем secret storage |
| outbox_events / consumer_receipts | transactional events, retries, consumed event IDs, dead-letter status |
| export_requests / deletion_requests | subject, state, requested_at, finished_at; проверяемое удаление и экспорт |

## 5. Единая проверка доступа

Любое действие проходит: authentication → account/device active → workspace membership → permission на ресурс → consent/visibility для геоданных → entitlement → atomic limit/usage → операция.

Permission отвечает «может ли этот участник выполнять действие», entitlement — «доступна ли возможность этому workspace». Frontend может скрывать кнопку для удобства, но не принимает окончательное решение.

Примеры permission: location.read_current, location.read_history, location.publish_own, geofence.manage, members.invite, billing.manage, sos.create, sharing.create_temporary. Примеры features: members.max, groups.max, devices.max, history.retention_days, geofences.max, live.enabled, live.minutes_per_period, temporary_shares.max_active.

EntitlementService.resolve/assert/reserve/consume/release получает subject, feature и контекст. Версионированный cache с инвалидированием при billing/grant changes. При недоступном cache проверяет PostgreSQL; не использует устаревшее разрешение для критического допуска. Истёкшее право определяется временем даже без запуска scheduler. Ни одного `if plan == premium`.

Лимиты количества объектов проверяются под блокировкой счётчика/строки workspace в той же транзакции, что создание. Время live резервируется и списывается идемпотентными порциями. GPS abuse-лимиты технические, отделены от коммерческих квот. Downgrade не удаляет семью: запрещает новые превышения и применяет явно описанную политику существующих объектов.

API возвращает стабильные коды PERMISSION_DENIED, CONSENT_REQUIRED, ENTITLEMENT_REQUIRED, LIMIT_EXCEEDED, с безопасными деталями. Наличие чужого ресурса не раскрывается. Правила распространяются на REST, WebSocket, workers, exports, admin actions и временные ссылки. Capability-ссылка заменяет обычный login только в строго ограниченном endpoint; остальные проверки сохраняются.

WebSocket: короткоживущие credentials, авторизация private channels, серверная проверка аудитории каждой публикации. По отзыву grant/выходу закрываем подписки, меняем revision, очищаем current cache; старые queued events не доставляются. Нельзя раздавать все координаты в общий workspace-канал, если права отличаются.

## 6. Location Engine

Детерминированная state machine в TypeScript + нативные исполнители. Приоритет: выключенное разрешение/пауза → stop; иначе SOS > sport > live > normal > idle. Удалённый запрос live предлагает участие и не отменяет локальный запрет. Sport может писать личный маршрут без передачи группе только при отдельном явном выборе.

Начальные параметры для измерений на устройствах, не SLA:

| Режим | Получение точек | Выгрузка | Поведение |
|---|---|---|---|
| idle | significant changes / 5–15 минут при возможности ОС | при событии / 5–15 минут | покой, экономия энергии |
| normal | 30–60 сек при движении, distance filter 25–50 м | 30–120 сек | семейная карта |
| live | 5–10 сек | 5–15 сек | ограниченная по времени сессия |
| sport | 1–5 сек | 15–60 сек | локально точный трек, аналитика позже |
| SOS | 3–5 сек | как можно скорее, 3–10 сек | повышение частоты, явное завершение |

Учитывать motion, speed, accuracy, network, charging, low power mode, battery thresholds с hysteresis. Ниже 20% normal редеет; SOS продолжает best effort с предупреждением. Лимит длительности SOS и напоминания согласовать. На Android нужен разрешённый foreground location service с видимым уведомлением; на iOS — Core Location background configuration. После force-stop/системной остановки непрерывность не обещается.

Очередь: encrypted SQLite, ключ в Keychain/Keystore, client_point_id генерируется один раз; captured_at отдельно от received_at. Персистентная очередь переживает рестарт; ACK удаляет только подтверждённые точки. Backoff+jitter, retry-after, ограничение диска и максимального возраста. Отозванное согласие отменяет отправку старой очереди соответствующей аудитории. Локальная история не читается другими аккаунтами после logout.

Контракт batch: device-auth, client_batch_id, schema_version, массив точек, consent revision. Начальное ограничение 500 точек / 512 KiB и окно оффлайна 72 часа — гипотезы до нагрузки. Ошибочный envelope отклоняется целиком; ответ на корректный envelope содержит accepted/duplicate/permanently_rejected для каждой точки. Транзиентный серверный сбой не выдаёт ACK принятия.

Один batch ID + тот же hash возвращает сохранённый ответ; другой payload — 409. Те же point IDs в новых batches не создают повторную историю и usage. В транзакции сохраняются receipts, точки, аудитории, outbox и результат. При гонках unique constraints + retry. Актуальная позиция обновляется compare-and-set по server-validated ordering; старые/offline точки не отматывают карту. Future timestamp, плохая точность и неправдоподобная скорость маркируются/отклоняются по правилам; GPS не является доказательством физического присутствия.

## 7. Геозоны, SOS и временный доступ

Геозоны: серверная проверка принятых точек, accuracy-aware boundary, отдельные радиусы входа/выхода или буфер, dwell time и последовательное состояние на geofence/user. Первая точка устанавливает состояние без ложного события входа. Старые оффлайн-точки доступны в истории, но не вызывают внезапные push «вошёл сейчас»; порог realtime, порядок и повторная обработка зафиксировать тестами. Push at-least-once с дедупликацией, delivery не равно прочтению.

SOS: пользователь запускает сам; создание идемпотентно; сервер сохраняет событие и outbox, уведомляет разрешённых адресатов, приложение повышает режим. Экран отличает локальное начало от принятия сервером и подтверждения получателем. Без сети SOS находится в ожидании доставки, UI предлагает позвонить. Завершение возвращает предыдущий допустимый режим. Нельзя рассчитывать на push как на гарантированный будильник фонового GPS.

Temporary share: криптографически случайный токен >=256 bit, только hash в БД, ограниченный срок и субъект. По умолчанию только текущая точка, история отдельной явной опцией. Секрет из URL fragment обменивается на ограниченную сессию; не попадает в access logs/referrer/analytics. No-store, noindex, rate limiting, optional passcode. Проверять expiry/revocation/consent при каждом чтении и stream event. Владелец видит список ссылок и отзывает их.

## 8. Биллинг

BillingProvider: createCheckout/changeSubscription/cancel/getSubscription/verifyWebhook/normalizeEvent/refund при поддержке. StorePurchaseProvider отдельно покрывает server-side verification, purchase restore, original transaction mapping. Адаптеры Apple и Google плюс выбранный web PSP; единая доменная state machine, но не искусственно одинаковые возможности.

Состояния: pending, trialing, active, past_due, grace, paused (если поддерживается), canceled, expired. cancel_at_period_end не отнимает оплаченный срок. Учитывать renewal, price change, grace, refund/revocation, upgrade/downgrade, timezone boundaries и восстановление покупок. Суммы и сроки определяет проверенный провайдер/каталог, не клиент.

Webhook: проверка подлинности по протоколу конкретного провайдера (подпись по raw body и допустимому времени там, где она предусмотрена; иначе обязательная server-to-server проверка объекта через authenticated provider API), durable inbox, unique(provider,event_id), быстрый ACK только после сохранения. Если event ID отсутствует, адаптер определяет устойчивый dedup key по объекту/типу/версии состояния. Worker под блокировкой подписки применяет событие; старое событие не откатывает новое. При неоднозначном порядке запрашивает актуальное состояние провайдера. Entitlement updates + audit + outbox в одной транзакции. Повторная сверка провайдеров по расписанию; неподтверждённое событие никогда не даёт права. Исходящие запросы тоже используют idempotency keys.

Месячные/годовые цены — записи каталога, а не условные ветки. Trials и промокоды web/store имеют отдельные ограничения; собственный promo не обещает скидку на StoreKit/Play purchase. Длительность триала, цены, валюта, налоги, чеки и поддерживаемые регионы пока не определены. Не хранить карточные реквизиты; использовать hosted checkout/native purchase.

## 9. API и пользовательские экраны

API /api/v1: auth/sessions/devices; workspaces/members/invitations/groups; permissions/effective-entitlements/usage; consents/sharing-grants; locations/batches/current/history; geofences/events; sos; live-sessions; temporary-shares; notifications; billing/catalog/subscription/checkout/restore; exports/deletion. Отдельные provider webhook routes и ограниченные share endpoints.

История запрашивается за локальный день с IANA timezone; сервер переводит его границы в UTC, учитывая DST. Cursor pagination; упрощение линии по масштабу, лимит точек/окна. Разрывы между точками отображаются, не соединяются как подтверждённый путь. Карта показывает captured_at, свежесть, accuracy circle, battery timestamp, offline/stale/paused/permission denied как разные состояния.

Онбординг: аккаунт → создать/принять приглашение → выбрать аудиторию → объяснение данных и паузы → foreground permission → объяснение фоновой функции → background permission при необходимости → push permission → проверка работоспособности. Отказ не создаёт тупик. Главный экран постоянно показывает, кому передаётся позиция, и кнопку паузы.

Админка: пользователи/блокировки, workspace metadata, каталог и права, подписки/платежи/счета, триалы/промо, usage, доставки, system events, настройки, audit. Координаты и маршруты не доступны поддержке по умолчанию. Изменения финансовых прав требуют причины и аудита. Impersonation с доступом к GPS исключён из MVP.

## 10. Безопасность и эксплуатация

Threat model: IDOR/cross-tenant, abusive family member, stolen device/token/share URL, GPS spoofing, replay, webhook forgery, quota races, insider access. Короткие access tokens, refresh rotation/revocation, rate limits на login/invite/GPS/SOS/share, MFA админов, TLS, закрытые DB/Redis, CSRF для cookie sessions, минимальные CORS origins, validation и bounded queries.

Геоданные не идут в обычные логи, error tracking, analytics или push-текст. В push — тип события и непрозрачный ID; детали читаются после проверки доступа. Шифрование backups и дисков, раздельные ключи. Audit append-only на уровне роли приложения; это не абсолютная защита от суперпользователя БД. Export/delete включают историю, аудитории, current cache, ссылки, устройства; финансовые обязательства хранения уточняются для выбранного рынка. Удалённые данные не должны возвращаться после восстановления backup: журнал tombstones применяется при restore.

Сервер 159.194.241.77: конфигурация, доступ, ресурсы и чужие проекты пока не проверены. Сначала read-only inventory: ОС, CPU/RAM/disk, контейнеры/службы, порты, reverse proxy, сертификаты, DB/Redis, firewall и backup подход. Никаких массовых upgrade, перезапусков общих служб или изменения firewall без анализа влияния.

Предложение: отдельный Linux user, каталог /srv/kirh-geo, отдельный Compose project/network/volumes, закрытые PostGIS/Redis, API/worker/scheduler/Reverb, лимиты CPU/RAM/logs. Вход через существующий reverse proxy с отдельными доменами и проверкой конфигурации перед reload. Если ресурсов недостаточно — отдельная VM/БД; вместимость не предполагать по IP.

Deploy: immutable image SHA, staging, health/readiness, expand-contract migrations, migration lock, резервное копирование и проверенный rollback приложения. Деструктивные миграции отдельным релизом. Off-host encrypted backups + WAL/PITR по согласованным RPO/RTO; обязательный restore drill. Один сервер остаётся единой точкой отказа, не является HA.

Метрики: API p95/errors, ingest lag, location freshness, worker lag, dead letters, delivery retries, DB/storage/Redis, webhook age, entitlement mismatch. Целевые SLO и бюджет батареи утверждаются после нагрузки и device spike.

Оценка порядка нагрузки: 10 000 одновременно передающих устройств с точкой раз в 30 секунд = ~333 точки/сек и 28,8 млн точек/сутки до снижения частоты. Это сценарий расчёта, не прогноз и не измерение. Batch сокращает HTTP, но не число точек; retention, fan-out, индексы и батарея определяют стоимость.

## 11. Tasks — последовательные этапы и критерии готовности

Каждый пункт включает реализацию, относящиеся к нему тесты и обновление документации. Подробные миграции/API payloads оформляются в этапе A до модулей. Зависимости указаны по ID. Логи всех задач подчиняются Settings.

### A. Зафиксировать проект и архитектурные контракты

- [ ] A1. Зафиксировать российский запуск, обычные аккаунты и режим передачи по коду; утвердить аудитории/retention, MVP и бюджет нагрузки. Файлы: docs/product/{scope,consent,pricing}.md. Logging: определить события согласия/отзыва без PII. Зависимости: ответы владельца продукта; базовый сценарий уже выбран.
- [ ] A2. Создать подробный ERD, словарь колонок, FK/CHECK/index/partition DDL и таблицу доступа всех ролей. Файлы: docs/database/*, docs/security/access-matrix.md. Logging: схема audit event. Зависимости: A1.
- [ ] A3. Описать OpenAPI, event contracts, billing/consent/engine state machines и ADR. Файлы: packages/contracts/openapi.yaml, docs/adr/*.md. Logging: correlation IDs и безопасные error codes. Зависимости: A2.
- [x] A4a. Инициализировать Git/main, origin, ignore и структуру monorepo. Пользователь предоставил git@github.com:Closhar/KIRH-GEO.git; пустой remote доступен. Файлы: .gitignore, .gitattributes, README.md, apps/, infra/, packages/, docs/. Logging: git checks без секретов. Зависимости: remote destination выполнена.
- [ ] A4b. Проверить содержимое первого коммита, синхронизировать main и проверить совпадение local/remote SHA. Файлы: начальное дерево репозитория. Logging: commit SHA и статус без секретов. Зависимости: A4a.

Gate A: согласованная архитектура, SQL-дизайн, permission/feature matrix и API без спорных продуктовых правил.

### B. Backend foundation

- [ ] B1. Scaffold Laravel, PostGIS/Redis/Reverb, local Compose, CI (lint/static analysis/tests), health endpoints. Файлы: apps/api/*, infra/compose/*, CI config. Logging: INFO startup, ERROR dependencies. Зависимости: A2–A4.
- [ ] B2. Auth, device registration/revocation, rotating sessions, login limits. Файлы: Modules/Identity, tests/Feature/Identity. Logging: INFO security events, WARN rejected login. Зависимости: B1.
- [ ] B3. Workspace/groups/invites/scoped roles и cross-tenant constraints. Файлы: Modules/Workspaces, Modules/Access. Logging: INFO membership changes, WARN policy denial. Зависимости: B2.
- [ ] B4. Consent ledger, grants, primary device, pause/revoke и очистка доступа. Файлы: Modules/Consent, tests/Feature/Consent. Logging: INFO consent transitions с revision. Зависимости: B3.

Gate B: тесты доказывают невозможность чужого доступа и принудительного включения передачи.

### C. Billing / entitlement

- [ ] C1. Versioned catalog, EntitlementService и атомарные usage reservations/counters. Файлы: Modules/Access, Modules/Billing, migrations. Logging: INFO grant changes, WARN quota rejections. Зависимости: B3.
- [ ] C2. Subscription state machine, month/year/trial/promo/cancel/downgrade и fake provider для тестов. Файлы: Modules/Billing/Domain и Application. Logging: INFO lifecycle transitions. Зависимости: C1.
- [ ] C3. Provider adapters, store verification/restore, inbox/outbox, refunds и reconciliation. Файлы: Modules/Billing/Infrastructure, tests/Integration/Billing. Logging: INFO provider event IDs, ERROR verification/reconciliation failures без payload secrets. Зависимости: C2, выбранные провайдеры и sandbox accounts.
- [ ] C4. Конкурентные лимиты, webhook duplicates/out-of-order, trial abuse и entitlement expiry tests. Файлы: tests/Integration/{Billing,Access}. Logging: безопасные diagnostics failed assertions. Зависимости: C3.
- [ ] C5. Партнёрские программы, атрибуция, commission ledger/reversals, hold/approval и идемпотентные выплаты через интерфейс с ручным первым адаптером. Файлы: Modules/Partners, tests/Integration/Partners. Logging: INFO commission transitions и actor/reason, WARN fraud flags без реквизитов. Зависимости: C3; правила раздела 14. Покрыть дубль платежа, частичный/полный refund, самореферал, смену версии программы, повторное подтверждение выплаты.

Gate C: повторные и переставленные callbacks не меняют сумму/права повторно; API закрывает превышения при гонках.

### D. Геоплатформа backend

- [ ] D1. GPS batch ingestion, point receipts, partitions, offline windows, audience snapshots, transactional outbox. Файлы: Modules/Location, migrations, tests/Integration/Location. Logging: INFO batch count/latency, WARN rejects без координат. Зависимости: B4,C1.
- [ ] D2. CurrentLocationStore, CAS/TTL/rebuild, history query/downsampling и authorized broadcasts. Файлы: Modules/Location/Infrastructure, routes/channels.php. Logging: lag/cache errors, access denial. Зависимости: D1.
- [ ] D3. Geofences/hysteresis/dwell/ordering, push pipeline и notification preferences. Файлы: Modules/Geofencing, Modules/Notifications. Logging: INFO transition/event ID, ERROR delivery retries. Зависимости: D2.
- [ ] D4. SOS/ack/end, live sessions, temporary share endpoints и Activity schema. Файлы: Modules/{Safety,Sharing,Activity}. Logging: INFO session transitions без link tokens. Зависимости: D3,C1.

Gate D: повторный batch, Redis outage/rebuild, outbox retry, revoke-during-send, DST history и поздние точки покрыты интеграционно на реальных PostgreSQL/PostGIS/Redis.

### E. Админка и публичный просмотр

- [ ] E1. Filament guard/MFA/policies, users/workspaces metadata и audit. Файлы: app/Filament, tests/Feature/Admin. Logging: INFO admin mutations и login. Зависимости: C4,D4.
- [ ] E2. Отдельные страницы «Тарифы и лимиты», «Промокоды», «Партнёрская программа» с настройками из раздела 14, preview/publication и audit; billing/usage/support views; отдельный partner panel для собственных ссылок/баланса/заявок с tenant isolation tests. Файлы: app/Filament/Resources, app/Filament/Partner, docs/runbooks/support.md. Logging: reasoned audited configuration/entitlement changes. Зависимости: E1,C5.
- [ ] E3. Share web map, expiry/revoke/passcode, secure token exchange и privacy headers. Файлы: apps/share-web, tests/e2e. Logging: aggregate rate/errors без URL secrets. Зависимости: D4.

Gate E: админ не видит маршруты без отдельного разрешённого процесса; временная ссылка теряет доступ сразу после отзыва.

### F. Мобильный клиент

- [ ] F1. Device spike: native Android/iOS background capture, restart/offline/battery tests; выбрать map/location SDK и подтвердить лицензии. Файлы: apps/mobile/android, ios, docs/adr/mobile-location.md. Logging: opt-in diagnostics без координат. Зависимости: E1, macOS/device access.
- [ ] F2. RN shell, typed API, secure auth, onboarding/permissions, workspace/invites/settings. Файлы: apps/mobile/src/{app,features}. Logging: redacted flow/error codes. Зависимости: F1,E2.
- [ ] F3. Engine modes, encrypted queue, batch sync, consent pause/revoke и recovery. Файлы: features/location/{engine,queue,adapters}, tests. Logging: state changes, queue size, sync latency. Зависимости: F2,D1.
- [ ] F4. Participant map/history/day picker/geofences/notifications. Файлы: features/{map,history,geofences,notifications}. Logging: map/API errors без positions. Зависимости: F3,D3.
- [ ] F5. SOS/live/shares, subscription paywall/store restore, account export/delete. Файлы: features/{sos,sharing,billing,privacy}. Logging: event IDs и lifecycle statuses. Зависимости: F4,C3,D4.
- [ ] F6. Android/iOS E2E и field tests: denied/approximate/revoked permission, foreground/background/force-stop/reboot, Doze/low power, weak GPS/offline, billing restore, stale map, accessibility. Файлы: apps/mobile/e2e, docs/product/device-matrix.md. Logging: anonymized test diagnostics. Зависимости: F5.

Gate F: реальные телефоны подтверждают корректность consent, очереди, статусов свежести и измеренную батарею; заявленные интервалы соответствуют реальным ограничениям ОС.

### G. Сервер и выпуск

- [ ] G1. Read-only inventory 159.194.241.77 и capacity/deployment design с учётом соседей. Файлы: docs/runbooks/server-inventory.md (без secrets), infra/deploy. Logging: sanitized inventory. Зависимости: A, SSH identity; можно выполнить раньше остальных фаз как планирование.
- [ ] G2. Изолированное staging окружение, domains/TLS, pipeline, migration/rollback, мониторинг, backups. Файлы: infra/*, docs/runbooks/{deploy,restore}.md. Logging: deploy SHA, health, backup status. Зависимости: G1,E2.
- [ ] G3. Load/security tests и restore drill; прогон cross-tenant/replay/limits/revocation под нагрузкой. Файлы: apps/api/tests, infra/load, docs/runbooks/release.md. Logging: performance aggregates и recovery timing. Зависимости: G2,F6.
- [ ] G4. Production rollout и store submissions с актуальными privacy/permission/billing declarations, smoke checks и alerts. Файлы: infra/deploy, docs/runbooks/release.md. Logging: release markers, errors/latency/lag. Зависимости: G3, магазинные аккаунты, продуктовые и правовые материалы.

Gate G: проверенный restore, rollback, изоляция соседних проектов, опубликованные условия, мониторинг и готовность поддержки. Решение store review — внешняя зависимость, сроки не гарантируются.

## Commit Plan

- A1–A4: docs: define platform architecture and delivery plan
- B1–B4: feat: add identity tenancy and consent foundation
- C1–C5: feat: add billing entitlements promotions and partner accounting
- D1–D4: feat: add location ingestion sharing and safety
- E1–E3: feat: add administration and temporary share viewer
- F1–F3: feat: add mobile onboarding and location engine
- F4–F6: feat: add family tracking and mobile release flows
- G1–G4: ops: add isolated deployment and recovery workflow

Это контрольные точки; внутри фаз допустимы меньшие проверяемые коммиты. Push — в выбранный приватный remote, secrets scan перед первым push.

## 12. Открытые решения

1. Рынок выбран: Россия; обычные аккаунты, без отдельной детской модели. Остаются юридическое лицо, допустимый возраст и фактическое место размещения БД.
2. GitHub SSH подтверждён для Closhar; предложен приватный KIRH-GEO, remote пока не доступен. Нужны создание remote, API/admin/share domains и рабочий SSH user/alias. Пароли и ключи не просить вставлять в план или репозиторий.
3. Фактическая нагрузка неизвестна; сценарии и стартовые рекомендации — раздел 14. Утвердить бюджет, retention и RPO/RTO.
4. Login: исходное предложение email/password с verification; нужен ли phone/OTP/social login.
5. Цены/валюта, trial duration/eligibility, число участников/устройств/геозон, live quotas, providers/store accounts.
6. Map/tile/search provider и коммерческие лицензии; не использовать публичный OSM tile server как бесплатный production CDN.
7. macOS/Xcode или macOS CI и физические iPhone/Android для сборок, подписи и фоновых испытаний.

## 13. Проверенные внешние ограничения

Официальные источники просмотрены 2026-09-15; перед выпуском правила перепроверить для целевого региона.

- Apple background location: https://developer.apple.com/documentation/corelocation/handling-location-updates-in-the-background
- Android background limits: https://developer.android.com/about/versions/oreo/background-location-limits
- Android location battery guidance: https://developer.android.com/develop/sensors-and-location/location/battery
- Google Play background permission policy: https://support.google.com/googleplay/android-developer/answer/9799150
- Apple review/billing/location guidelines: https://developer.apple.com/app-store/review/guidelines/
- Apple subscriptions: https://developer.apple.com/app-store/subscriptions/
- Google Play payments policy: https://support.google.com/googleplay/android-developer/answer/10281818

Выводы для проекта: фоновая геолокация зависит от ОС и требует понятного согласия; цифровые подписки должны учитывать store billing и региональные исключения. Рынок уточнён как Россия; последствия и оставшиеся вопросы — раздел 14.7.

## 14. Уточнения владельца и результаты проверки — 2026-09-15

Этот раздел конкретизирует исходные предложения выше. Реализация ещё не начата; пользователь продолжает проработку плана.

### 14.1. Одно приложение, два пользовательских режима

- «Моя группа»: создать workspace, пригласить людей кодом, видеть разрешённые позиции, управлять тарифом.
- «Передавать мою геопозицию»: присоединиться кодом, видеть имя пригласившего и получателей, дать согласие, включить системные разрешения; на главном экране статус отправки, последнее успешное обновление, батарея, пауза, SOS и выход из группы.
- Это режимы интерфейса одного приложения, не два APK/Bundle ID и не жёсткие типы users. Один человек может совмещать оба режима в разных группах.
- Отправителю не нужна своя платная подписка: его участие покрывает workspace и его лимит участников. Чужие точки отправителю доступны только по отдельным permissions + consent; взаимность не подразумевается.
- Отдельный grant_recipients(grant_id, workspace_id, viewer_user_id) фиксирует конкретных получателей. Для первого выпуска предпочтительна явная аудитория людей; добавление владельцем нового наблюдателя не расширяет её без подтверждения отправителя.
- Код приглашения — одноразовый bootstrap, а не постоянный общий пароль владельца. Предложение: 10 символов из алфавита без похожих символов, TTL 15 минут, QR/deep link как удобная альтернатива, серверный HMAC/hash, rate limits по приглашению/IP/устройству и атомарное погашение.
- После кода создаётся собственная identity и device session отправителя, показывается подтверждение получателей; передача начинается лишь после согласия. Человек может привязать существующий аккаунт. Для нового аккаунта возможен упрощённый device-bound вход без пароля с последующим добавлением подтверждённого recovery contact. Переустановка без recovery требует нового приглашения и согласия, не доступа к старому аккаунту по старому коду.
- Восстановление владельца, смена телефона и связь между устройствами требуют отдельного подтверждённого способа входа; для MVP предложен email/password для владельца. Код не позволяет входить от имени другого человека.
- Принятие кода резервирует слот участника транзакционно с лимитом. Нужны тесты гонок, перебора, истечения, повторного redemption и отсутствия передачи до согласия.
- Локальная пауза останавливает сбор сразу; серверное закрытие аудитории распространяется после получения revoke. Если отправитель оффлайн, мгновенное удаление ранее переданной точки на чужом устройстве невозможно: UI сообщает «отправка остановлена, отзыв доступа ожидает сети», кеш получателя имеет короткий срок и очищается при серверном revoke. Уже увиденные данные нельзя отозвать из памяти или скриншота получателя.

### 14.2. Настраиваемые тарифы и лимиты

Отдельная страница админки «Тарифы и лимиты»:

- Название, описание, доступность, порядок, RUB/валюты, месячная/годовая цена, provider price mappings, trial duration и eligibility.
- Матрица зарегистрированных features: bool/число/длительность; участники, устройства, группы, геозоны, история, live, активные ссылки. Значения хранятся в БД, технические верхние пределы валидируются сервером.
- Draft → preview (сравнение старой/новой версии и effective entitlements) → publish с датой начала → archive. Отдельные permissions billing.catalog.manage и billing.catalog.publish, журнал actor/reason.
- Новая цена не меняет автоматически существующие оплаченные договоры; политика grandfathering или миграции подписчиков явная. Store prices синхронизируются по возможностям провайдера, ввод суммы в админке сам по себе магазин не меняет.
- Лимиты редактируются без релиза. Новый feature key требует серверного обработчика/проверки, произвольная строка в БД не создаёт работающую функцию.
- История хранится не дольше выбранной пользователем политики и разрешённого лимита. Рост тарифа не восстанавливает уже удалённые точки. Бесплатный тариф тоже задаётся через каталог.

### 14.3. Промокоды

Раздел «Промокоды» поддерживает три отдельных benefit types:

1. free_access: доступ к определённому набору features/версии плана на N дней или до даты. Для бессрочного гранта — отдельное явное поле и причина; по умолчанию конечный срок.
2. discount: процент или фиксированная сумма с currency, на первый/следующие N платежей или ограниченный период, применимые тарифы и запрет отрицательной суммы.
3. free_months: N календарных месяцев бесплатного доступа; last-day-of-month правило и UTC anchor фиксируются тестами.

Настройки: validity, max redemptions global/per user/per workspace, eligibility, применимые цены/периоды, совместимость с trial/другими промо, бюджет кампании, attribution partner. По умолчанию промо не суммируются.

Добавить promo_benefits, promotion_applications (snapshot benefit, starts/ends, cycles_remaining, status) и billing_credits при денежном кредите. Redeem атомарно: уникальный redemption + расход campaign budget + entitlement grant/application + outbox. Client retry не расходует код повторно.

Для активной web-подписки free_months не должны одновременно продлевать доступ и оставлять прежнее списание без изменений: перенос next_charge либо кредит следующего периода — явная возможность адаптера. Для оплаченного года бесплатный период добавляется после paid-through date. Store-подписку нельзя самовольно продлить в нашем календаре и обещать остановку списаний: использовать совместимый offer/provider механизм; неподдерживаемое сочетание отклонять до redemption с понятной причиной.

У free_access отдельно определяется, заменяет ли он очередной платёж; до активации показать пользователю следующий платёж и дату. Автопродление не включается только от ввода бесплатного промокода без платёжного согласия. Финансовая скидка применяется при создании платежа на сервере, фактическая сумма проверяется по провайдеру.

### 14.4. Партнёрская программа

Самостоятельный модуль Partners и отдельная страница настроек, а не только поле partner_id у промокода.

Сущности:

| Таблица | Назначение |
|---|---|
| partners | связанный user, status(pending/active/suspended), payout profile reference |
| partner_programs / partner_program_versions | правила и неизменяемые опубликованные версии |
| referral_links / referral_codes | партнёр, campaign, token/code, validity |
| referral_attributions | привлечённый workspace, partner, source, attributed_at, version; один победитель по политике |
| partner_commissions | payment_id, attribution_id, version, base_minor, rate/fixed, amount_minor, currency, hold_until, status; unique(payment_id,attribution_id,commission_kind) |
| partner_ledger_entries | неизменяемые начисления, reversals, резервы, выплаты; денежные значения integer, ссылка на исходную операцию |
| partner_payouts / partner_payout_items | payable entries, external_reference/idempotency_key, status; нельзя включить начисление в две выплаты |

Админ настраивает: включение программы, правила регистрации/одобрения партнёров, процент или фиксированную комиссию, первый платёж/повторные платежи N месяцев, окно атрибуции, first/last touch, приоритет промокода над ссылкой, hold days, минимальную выплату, лимиты/валюту и исключённые тарифы/promos. Рабочая гипотеза: одна атрибуция workspace фиксируется до первой оплаты; более поздние ссылки не переписывают источник уже заработанной комиссии.

Комиссия только с подтверждённой реально уплаченной суммы после скидки; бесплатные месяцы/free access начисляют ноль. Определение базы (учитывать ли налог/комиссию PSP) хранится в версии программы. Процент считается целочисленно в basis points с фиксированным округлением. Изменение настроек не пересчитывает старые начисления.

Статусы: pending → held → payable → reserved → paid, отдельно canceled/reversed. Refund/chargeback создаёт reversal, включая частичный пропорциональный возврат. Возврат после выплаты создаёт долг/отрицательный баланс, не удаляет проведённую запись. Hold и период сверки защищают от выплаты по отменённому платежу.

MVP: одноуровневая программа; кабинет партнёра показывает ссылки, агрегаты привлечений/платежей/комиссий, баланс и заявки, без GPS и чужих персональных данных. Можно разместить минимальный отдельный partner panel внутри Laravel с собственными policies; админка остаётся доступной только персоналу. Первые выплаты — ручное исполнение с документированным подтверждением, резервированием и audit, через PayoutProvider interface для будущей автоматизации.

Защита: запрет саморефералов по установленной identity/billing relation, антифрод-флаги и ручная проверка подозрительных цепочек; один IP не является достаточным доказательством (семья может пользоваться одним Wi-Fi). Двойные callbacks и payout retry не создают двойное начисление/выплату. Юридические условия и реквизиты зависят от статуса партнёра; комиссия не отправляется автоматически до оформления платёжного процесса.

### 14.5. Масштабирование при неизвестном количестве пользователей

Основная переменная — одновременно передающие устройства и их средняя частота, а не число регистраций. Формула points/day = active_devices × 86400 / avg_interval_seconds. Примеры при круглосуточном среднем интервале 150 сек; реальное idle может уменьшить объём, sport/SOS увеличить:

| Передающих устройств | Точек/сек | Точек/сутки | История за 30 дней при условных 0,6–1,2 KB/точку |
|---|---:|---:|---:|
| 100 | 0,67 | 57 600 | ~1–2 GB |
| 1 000 | 6,67 | 576 000 | ~10–21 GB |
| 10 000 | 66,67 | 5 760 000 | ~104–207 GB |

Это арифметическая модель, не benchmark. Размер условно включает основные row/index расходы, но не гарантирует размеры audiences/dedup/outbox; отдельно резервировать WAL, backups, временные индексы и свободное место. Ускорение до 30 сек умножает объёмы на 5. WebSocket fan-out = обновления × число активных разрешённых наблюдателей, а не только ingest RPS.

Рекомендация: начать с закрытой беты 50–100 отправителей, history 7 дней по умолчанию, протестировать нагрузку 1 000 одновременно передающих и короткие пики ×3 до коммерческого расширения. Retention 30/90 дней можно продавать только после измерения стоимости хранения. Платёжные тарифы пока не фиксировать наугад.

Исходный бюджет отдельной VM для пилота: 4 vCPU, 8 GB RAM, 100–160 GB SSD/NVMe с резервом вне сервера. Это ориентир для замеров, не обещание вместимости; на общем сервере требуется именно доступный ресурс сверх соседних проектов. При развитии до ~1 000 активных устройств рассмотреть 8 vCPU/16 GB или выделенную БД по профилю нагрузки; покупать заранее не требуется.

Эволюция: отдельные worker pools → отдельный PostGIS → горизонтальные stateless API/WS → replicas для истории при необходимости → пересмотр хранения/партиций. Очереди ingestion/SOS/notifications/billing изолируются приоритетами. Масштабировать по p95/lag/disk: кандидат порогов — CPU стабильно >65–70%, disk >65%, queue lag >5 сек для realtime; проверить их нагрузкой. Не увеличивать количество API replicas при bottleneck в БД.

Предварительные инженерные цели для пилота: принятие batch p95 <500 ms, обработка current location после приёма <3 сек при нормальной связи, восстановление backup RPO ≤1 час/RTO ≤4 часа. Цели требуют измерения и утверждения бюджета; доставка ОС/push не включается в серверный SLA.

### 14.6. GitHub и SSH — фактические проверки

- Git установлен; GitHub CLI gh не найден. Локальные git name/email уже настроены.
- В SSH config есть github-closhar → github.com, user git, отдельный ключ.
- `ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes github-closhar` вернул `Hi Closhar! You've successfully authenticated`. Код выхода 1 в этом тесте ожидаем для GitHub.
- `git ls-remote git@github-closhar:Closhar/KIRH-GEO.git` вернул Repository not found: репозиторий отсутствует либо недоступен этому аккаунту. Origin не настроен, Git не инициализирован в рамках продолжающегося планирования.
- Следующий шаг пользователя: создать пустой Private repository KIRH-GEO в аккаунте Closhar без README/.gitignore/license; затем сообщить ссылку. После этого агент организует monorepo, initial commit, origin и push, без force push.
- SSH 159.194.241.77: сервер отвечает, сохранённый host key прошёл strict verification. BatchMode вход root с тремя обнаруженными локальными identity (default, kirhtarg_admin, sportrep_codex) отклонён publickey/password. Это подтверждает доступность SSH, но не доступ к shell. Другие usernames/ключи не перебираются; нужна рабочая команда другого проекта. Приватные ключи не читались.
- ОС, CPU/RAM/disk, running services, соседние приложения и физический регион сервера пока неизвестны. Конфигурация сервера не менялась.

### 14.7. Российский запуск: последствия для размещения и оплаты

Основную БД пользователей/геопозиций и backups проектируем в РФ. Физический регион 159.194.241.77 подтвердить по панели/договору хостинга; IP или hostname не служат достаточным доказательством. Основание: ч. 5 ст. 18 152-ФЗ, актуальный текст на https://mintrud.gov.ru/docs/laws/130. До production определить оператора, документы согласия/оферту и применимые уведомления; обычный тип аккаунта не отменяет возрастные вопросы.

Google Play Billing для пользователей в РФ приостановлен; официальная страница описывает региональное исключение из обязательного Play Billing: https://support.google.com/googleplay/android-developer/answer/11950272. Поэтому нельзя делать единственный российский платёжный путь зависимым от Google Play. Кандидат web PSP — ЮKassa (RUB, recurring, refunds): https://yookassa.ru/developers/payment-acceptance/overview и https://yookassa.ru/developers/payment-acceptance/scenario-extensions/recurring-payments/pay-with-saved. Выбор зависит от формы бизнеса и договора.

Для Android предусмотреть distribution-aware adapters (web PSP / альтернативный магазин / Play по доступным регионам); для iOS проверить разрешённые purchase/external-link сценарии отдельно, не переносить Android-исключение на Apple. Backend entitlements едины независимо от канала покупки. Для сторонних карт, push и аналитики составить data flow и минимизировать персональные данные: локальная БД сама по себе не разрешает любые внешние передачи.

## 15. Вариант Beget DBaaS и Git — 2026-09-15

### Git

Пользователь предоставил `git@github.com:Closhar/KIRH-GEO.git`. Read-only `ls-remote` завершился успешно, refs отсутствовали: remote пуст. Локально создан main, origin хранит предоставленный URL. Выбор существующего SSH-ключа настроен только в локальном .git/config. Текущий статус A4 заменяет исторические сведения о недоступном remote в разделе 14.6. Приватность remote по SSH ls-remote не определяется.

### Решение по базе: кандидат, ожидает технического подтверждения

Предлагаемая схема: Laravel API/workers/WebSocket/Redis на сервере приложения, PostgreSQL+PostGIS в Beget DBaaS в РФ. Приложение мобильного пользователя не подключается к БД напрямую. Локальная разработка использует PostGIS в Compose; production подключение задаётся окружением. Это меняет размещение, но не доменную схему.

В официальном [руководстве Beget DBaaS PostgreSQL](https://beget.com/ru/kb/manual/cloud-postgresql) подтверждены приватная сеть аккаунта, TLS-подключения, ограничение внешнего доступа по IP, увеличение конфигурации; уменьшение конфигурации не поддерживается через описанный механизм. Расширения устанавливаются через поддержку. PostGIS по имени не подтверждён.

[SLA Beget](https://beget.com/ru/sla-vps) содержит российские площадки ru1/ru2 и доступность DBaaS 99,98% в год с исключением планового обслуживания. Этот показатель не доказывает наличие реплик, автоматического failover или PITR и не является SLA всего приложения.

Не подтверждены: актуальные версии PostgreSQL/PostGIS и security updates; цена нужной конфигурации; расписание/retention/регион backups; PITR; downtime при resize; HA; IOPS и connection limits. Общие статьи про PostgreSQL backups не доказывают состав услуги DBaaS. Тариф из каталога PostgreSQL на VPS нельзя выдавать за цену managed DBaaS.

Предварительная рекомендация ресурса БД для пилота: 2 vCPU, 4 GB RAM, 40–80 GB NVMe (или ближайший доступный тариф), отдельно от ресурса приложения. Это инженерная оценка, не найденный тариф и не гарантия вместимости. До оплаты сверить доступные конфигурации/цену, затем нагрузочный профиль из 14.5; свободное место резервировать под индексы, временные операции и WAL. Учитывать ограничения дальнейшего уменьшения ресурса.

Предпочтительно API и БД размещать рядом по сетевой задержке и в доступной приватной сети. Нельзя предполагать, что 159.194.241.77 относится к Beget/тому же аккаунту и имеет такой маршрут. Для внешнего API: TLS с проверкой сертификата/имени, разрешён только его egress IP /32, измерены RTT/reconnect/timeouts. Если задержка неприемлема, рассмотреть API в том же регионе Beget. Новая покупка или перенос пока не выполняются.

### Готовый текст технического запроса в Beget (не отправлен)

Планируем приложение геолокации на Laravel с облачным PostgreSQL. Просим уточнить для DBaaS в РФ:

1. Доступен ли PostGIS 3.x, с какими актуальными PostgreSQL, кто устанавливает и обновляет расширение? Нужны geography(Point,4326), GiST, ST_DWithin, ST_Distance. Поддерживаются ли обычные declarative partitioning и создание/удаление партиций ролью миграций?
2. Какие автоматические backups входят в услугу: периодичность, retention, физический регион, восстановление в отдельный экземпляр? Есть ли WAL/PITR и каковы RPO/RTO и стоимость восстановления?
3. Есть ли реплика/failover, какой простой при увеличении CPU/RAM/диска, major/minor upgrade и установке PostGIS?
4. Какие connection/IOPS/disk limits, доступен ли PgBouncer или его самостоятельное подключение, pg_stat_statements? Возможны ли логический export и перенос к другому провайдеру?
5. Как организовать приватный доступ с VPS аккаунта и TLS с внешнего сервера, какой CA/hostname проверять? Где физически находятся primary/replicas/backups?
6. Какова текущая стоимость подходящей стартовой конфигурации около 2 vCPU / 4 GB RAM / 40–80 GB NVMe, что оплачивается отдельно и как увеличивается диск?

### Приёмка до выбора production DB

Получить подтверждение поддержки PostGIS и условий восстановления. На отдельно согласованном тестовом экземпляре проверить `SELECT PostGIS_Full_Version()`, `ST_DWithin`, GiST query plan, partition migrations, batch concurrency и Laravel connectivity с ролью приложения без superuser. Выполнить restore drill в отдельную БД; измерить p95 ingestion, RTT и storage amplification. Возможность оплаты не означает завершённую техническую приёмку.

Если PostGIS или приемлемое восстановление недоступны: отдельный VPS с PostgreSQL+PostGIS под нашим управлением либо другой managed provider, сохраняя контракт приложения. Daily dump сам по себе не выполняет предложенный RPO ≤1 часа. Выбор Beget пока условный; услуги не созданы и не оплачены.
