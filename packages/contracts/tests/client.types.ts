import { GeoApiClient } from '../src/client.js';
const client = new GeoApiClient({ baseUrl: 'https://example.invalid/api/v1', getAccessToken: () => null });
void client.request('get', '/auth/me', {});
void client.request('get', '/workspaces/{workspace}/locations/current', { path: { workspace: 'uuid' } });
// @ts-expect-error Missing required tenant path.
void client.request('get', '/workspaces/{workspace}/locations/current', {});
// @ts-expect-error No arbitrary endpoints.
void client.request('get', '/unknown', {});
// @ts-expect-error Location write requires a validated contract body.
void client.request('post', '/locations/batches', { body: { points: [] } });
// @ts-expect-error No GET request bodies.
void client.request('get', '/auth/me', { body: {} });
// @ts-expect-error History requires both a local date and IANA timezone.
void client.request('get', '/workspaces/{workspace}/members/{member}/locations/history', { path: { workspace: 'w', member: 'm' } });
