# KIRH GEO implementation context

Source of product scope and task status: `.ai-factory/plans/platform-foundation.md`.
User authorized implementation of all modules, tests, Git synchronization and parallel agents. Latest corrections: mutual subject-to-viewer sharing; platform admin configures GPS mode intervals and history retention. Consent remains mandatory for every subject including workspace administrators.

## Structure and ownership

- `apps/api`: Laravel 13 backend and Filament administration; PHP 8.3+.
- `apps/mobile`: React Native TypeScript with native Android/iOS location execution.
- `apps/share-web`: limited temporary location viewer.
- `packages/contracts`: OpenAPI and typed client.
- `docs/database/schema.sql`: PostgreSQL/PostGIS schema source, mirrored by migrations as implementation progresses.
- `infra`: isolated self-managed PostgreSQL/PostGIS, Redis, deployment and recovery.

Main agent coordinates shared entry points, dependency manifests, migrations and final verification. Agents edit assigned paths only; no independent commits or changes to other projects. Preserve incomplete tasks as incomplete. Never claim store/device/server verification without executing it.

## Implementation constraints

UUID identities; explicit workspace scope; deny by default. Permission, subject consent and entitlement are independent server-side gates. No plan-name branching. API success envelope `{data: ...}`, failures `{error:{code,message,details?}}`; snake_case JSON. Persist accepted GPS and outbox before ACK. Replays must not double-write points, payments, usage or commissions. Never log coordinates, bearer tokens, invite/share secrets or payment credentials.

Self-managed database is selected. Cloud migration is deferred. Existing server SSH login remains unavailable; no brute-force attempts or changes to neighboring projects. Infrastructure code can be prepared and tested locally.

## Documentation

| Document | Path | Description |
|---|---|---|
| README | `README.md` | Project landing page |
| Getting started | `docs/getting-started.md` | Local setup and verification |
| Release status | `docs/release-status.md` | Verified and external gates |
| Database | `docs/database/README.md` | PostGIS schema and retention |
| Access matrix | `docs/security/access-matrix.md` | Permission, consent, entitlement |
| Deployment | `docs/runbooks/deployment.md` | Isolated server deployment |
