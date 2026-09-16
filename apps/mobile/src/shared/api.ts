import {getSession, saveSession} from './session';

export class ApiFailure extends Error {
  constructor(public readonly status: number, public readonly code: string, message: string, public readonly retryAfterSeconds = 0) { super(message); }
}
export const apiOrigin = process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000/api/v1';
const origin = apiOrigin;
let refreshInFlight: Promise<void> | null = null;
export async function api<T>(path: string, method = 'GET', body?: unknown, idempotencyKey?: string, retry = true): Promise<T> {
  if (!__DEV__ && !origin.startsWith('https://')) throw new Error('Для подключения требуется HTTPS.');
  const session = await getSession();
  const response = await fetch(`${origin.replace(/\/$/, '')}/${path.replace(/^\//, '')}`, {
    method, headers: {Accept: 'application/json', 'Content-Type': 'application/json',
      ...(session ? {Authorization: `Bearer ${session.access_token}`} : {}),
      ...(idempotencyKey ? {'Idempotency-Key': idempotencyKey} : {})},
    ...(body === undefined ? {} : {body: JSON.stringify(body)}), signal: AbortSignal.timeout(20000),
  });
  if (response.status === 401 && session && retry && !['auth/login', 'auth/register', 'auth/refresh'].includes(path)) {
    if (!refreshInFlight) refreshInFlight = (async () => {
      const current = await getSession();
      if (current?.access_token !== session.access_token) return;
      const next = await api<Partial<typeof session>>('auth/refresh', 'POST', {refresh_token: session.refresh_token}, undefined, false);
      if ((await getSession())?.refresh_token === session.refresh_token) await saveSession({...session, ...next});
    })().finally(() => {refreshInFlight = null;});
    await refreshInFlight;
    return api<T>(path, method, body, idempotencyKey, false);
  }
  if (response.status === 204) return undefined as T;
  let json: {data?: T; error?: {code: string; message: string}};
  try { json = await response.json(); }
  catch { throw new ApiFailure(response.status, 'INVALID_RESPONSE', 'Сервер временно недоступен.'); }
  if (!response.ok) throw new ApiFailure(response.status, json.error?.code ?? 'HTTP_ERROR', json.error?.message ?? 'Не удалось выполнить запрос.', Number(response.headers.get('Retry-After')) || 0);
  return json.data as T;
}
