[← Начало работы](getting-started.md) · [Назад к README](../README.md)

# Статус выпуска

## Проверено локально

| Область | Проверка |
|---|---|
| Backend | 69 тестов, 262 assertions на PostgreSQL/PostGIS |
| Mobile | TypeScript, unit tests, Metro Android export, Android prebuild |
| Share web | unit tests и production Vite build |
| Contracts | OpenAPI validation и соответствие зарегистрированным маршрутам |
| Schema | документационная и migration bootstrap-схемы идентичны |
| Branding | исходный `kt-geo-logo.png` в mobile/admin/share/favicon |

## Не является проверенным production-фактом

| Gate | Что требуется |
|---|---|
| Сервер `159.194.241.77` | рабочая SSH-команда/ключ, read-only inventory, затем отдельное подтверждение deploy |
| Домены и TLS | API/admin/share domains, DNS и сертификаты |
| Платежи | договор и sandbox YooKassa; App Store/Google Play adapters и restore |
| Push | FCM service account, APNs credentials и реальные устройства |
| Почта | SMTP и HTTPS `IDENTITY_ACTION_URL` |
| Карты | коммерческий tile/search provider и лицензия |
| Mobile | подписанные native builds и матрица foreground/background/reboot/Doze/low-power |
| Operations | load/security tests, backup restore drill, monitoring и alerts |

До закрытия этих gate’ов систему нельзя публиковать как коммерческий production-сервис. Незавершённые пункты остаются unchecked в `.ai-factory/plans/platform-foundation.md`.

## Известные ограничения

- спортивный режим собирает данные, но полная спортивная аналитика отложена;
- mobile checkout по умолчанию одноразовый; auto-renew требует отдельного пользовательского opt-in и включённого backend feature flag;
- Apple/Google purchase verification намеренно fail-closed до реализации и получения store credentials;
- большая MapLibre-часть share-web загружается лениво только при настроенном map style.

## См. также

- [Начало работы](getting-started.md) — локальная проверка.
- [Архитектурный план](../.ai-factory/plans/platform-foundation.md) — критерии этапов.
- [Deployment runbook](runbooks/deployment.md) — staging и production.
