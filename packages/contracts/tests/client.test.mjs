import test from 'node:test';
import assert from 'node:assert/strict';
import { GeoApiClient, ApiError } from '../.test-build/src/client.js';

test('client encodes tenant/query parameters and adds current token', async () => {
  let request;
  const client = new GeoApiClient({
    baseUrl: 'https://example.invalid/api/v1/', getAccessToken: async () => 'session-token',
    fetch: async (url, options) => { request = { url, options }; return new Response(JSON.stringify({ data: [] })); },
  });
  assert.deepEqual(await client.request('get', '/workspaces/{workspace}/members/{member}/locations/history', {
    path: { workspace: 'workspace', member: 'member' }, query: { date: '2026-09-15', timezone: 'Europe/Moscow' },
  }), { data: [] });
  assert.equal(new URL(request.url).searchParams.get('timezone'), 'Europe/Moscow');
  assert.equal(request.options.headers.Authorization, 'Bearer session-token');
  assert.equal(request.options.cache, 'no-store');
});

test('client preserves idempotency key and never retries implicitly', async () => {
  let count = 0;
  const client = new GeoApiClient({ baseUrl: 'https://example.invalid/api/v1', getAccessToken: () => null,
    fetch: async (_, options) => {
      count++;
      assert.equal(options.headers['Idempotency-Key'], 'stable-key');
      return new Response(JSON.stringify({ error: { code: 'LIMIT_EXCEEDED', message: 'Limit exceeded' } }), { status: 429, headers: { 'Retry-After': '30' } });
    },
  });
  await assert.rejects(client.request('post', '/workspaces/{workspace}/sos', { path: { workspace: 'w' }, headers: { 'Idempotency-Key': 'stable-key' } }), error => error instanceof ApiError && error.envelope.error.code === 'LIMIT_EXCEEDED' && error.retryAfter === '30');
  assert.equal(count, 1);
});

test('proxy errors do not expose raw response body and empty success works', async () => {
  const client = new GeoApiClient({ baseUrl: 'https://example.invalid/api/v1', getAccessToken: () => null, fetch: async () => new Response('private debug payload', { status: 502 }) });
  await assert.rejects(client.request('get', '/auth/me', {}), error => error.message === 'Request failed (502)');
  const empty = new GeoApiClient({ baseUrl: 'https://example.invalid/api/v1', getAccessToken: () => null, fetch: async () => new Response(null, { status: 204 }) });
  assert.equal(await empty.request('post', '/auth/logout', {}), undefined);
});
