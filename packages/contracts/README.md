# API contracts

`scripts/build-openapi.mjs` defines the OpenAPI 3.1 contract. `openapi.json` contains the full target; `openapi.active.json` includes only routes captured from Laravel in `backend-routes.json`. `src/schema.ts` is generated from the active subset so the typed client cannot accidentally call a planned route. `x-implementation-status` distinguishes registered routes, planned routes and disabled provider gates; registration alone does not claim production acceptance.

```powershell
npm ci
npm run audit:backend
npm run generate
npm run typecheck
npm test
```

The generator requires TypeScript 5.x; versions and lockfile are pinned. Edit the source and regenerate both artifacts together. Responses use `{data:...}`; failures use `{error:{code,message,details?}}`. UUIDs are public IDs and times are UTC RFC3339 unless an endpoint explicitly accepts a local day plus IANA timezone.

`audit:backend` requires the local Laravel dependencies and PHP with intl. It refreshes the route inventory and fails for registered operations missing from the target spec. Request/response fields were checked against module services during initial implementation; this route-level audit cannot prove field-level compatibility. Broadcasting authorization returns its protocol-native JSON; export download returns NDJSON.

```typescript
const api = new GeoApiClient({
  baseUrl: 'https://api.example.org/api/v1',
  getAccessToken: () => secureSession.accessToken,
});
const response = await api.request('get', '/workspaces/{workspace}/locations/current', {
  path: { workspace: workspaceId },
});
```

The client does not store tokens or retry writes. Supply stable idempotency keys where required and keep GPS batch/point IDs unchanged across retries. UI handles refresh and revoked sessions explicitly. Generated TypeScript is compile-time validation only; the backend remains responsible for runtime validation, permission, consent, entitlements, and limits.

Never log request/response bodies, tokens, invitation codes, temporary share fragments, or GPS positions. Temporary-share access uses a separate client/token context; do not replace the user's main session token.
