[Назад к README](../../README.md) · [Deployment →](deployment.md)

# Поддержка и администрирование

## Доступ

SupportOverview доступен только роли с `admin.access` и `admin.support.read`; флаг `is_platform_admin` сам по себе прав не даёт. Вход требует TOTP. Назначение выполняется локальной командой `geo:admin` с обязательной причиной и audit event.

## Что разрешено видеть

- UUID, статус и даты аккаунта/пространства;
- статус подписки, версия цены и агрегированные usage counters;
- отредактированные audit metadata.

Экран поддержки не показывает координаты, маршруты, bearer/share/invite tokens, push credentials, платёжные реквизиты или необработанные webhook payloads.

## Безопасная диагностика

1. Найти пользователя или workspace только по UUID.
2. Проверить status, subscription period и usage limit.
3. Сопоставить correlation ID с серверным логом; URL и request body не копировать в тикет.
4. Изменения тарифов, промокодов и партнёрской программы проводить на профильной странице с причиной.
5. Доступ к геоданным не запрашивать. Для расследования согласия использовать только consent/audit event IDs.

## Инциденты

- при подозрении на компрометацию отозвать устройство либо всю session family;
- при жалобе на скрытую передачу проверить `sharing_paused`, active grant и consent revision без просмотра координат;
- при сбое callback запускать reconciliation, не создавать payment/invoice вручную;
- при запросе удаления сначала устранить active auto-renew и передать владение общей группой;
- секреты и персональные данные не отправлять в чат поддержки.

## См. также

- [Матрица доступа](../security/access-matrix.md) — серверные gates.
- [Privacy и notifications](privacy-notifications.md) — retention и delivery.
- [Deployment](deployment.md) — операции и rollback.
