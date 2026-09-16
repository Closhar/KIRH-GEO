# Architecture

Modular Laravel monolith: Identity, Workspaces, Consent, Access, Billing, Partners, Location, Geofencing, Safety, Sharing, Notifications, Activity, Administration. Each module owns domain/application/infrastructure/HTTP concerns. Shared data contracts are explicit. API controllers call application services. Database constraints enforce scope and idempotency; authorization always runs server-side.

PostgreSQL is durable truth; Redis current position is a replaceable projection. Domain writes and outbox commit atomically. Consumers recheck consent before external delivery. Device sessions are bound to UUID identities. Group visibility edges do not replace sender grants. Configured history retention is the minimum of platform cap, entitlement and subject setting.

Monorepo paths are described in AGENTS.md. Implementation proceeds schema/contracts, backend, administration, mobile, then staged deployment and production gates. Independent agent work is permitted by user; root coordinates integration.
