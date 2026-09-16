import type { paths, components } from './schema.js';

export type { paths, components } from './schema.js';
export type LocationBatch = components['schemas']['LocationBatch'];
export type BatchReceipt = components['schemas']['BatchReceipt'];
export type LocationPolicy = components['schemas']['LocationPolicy'];
export type ApiErrorEnvelope = components['schemas']['Error'];

type Method = 'get' | 'post' | 'put' | 'delete' | 'patch';
type PathFor<M extends Method> = { [P in keyof paths]: M extends keyof paths[P] ? paths[P][M] extends undefined ? never : P : never }[keyof paths];
type Operation<P extends keyof paths, M extends Method> = M extends keyof paths[P] ? NonNullable<paths[P][M]> : never;
type Body<O> = O extends { requestBody: { content: { 'application/json': infer B } } } ? B : never;
type Success<O> = O extends { responses: infer R } ? { [S in keyof R]: S extends 200 | 201 | 202 | 204 ? R[S] extends { content: { 'application/json': infer B } } ? B : R[S] extends { content: { 'application/x-ndjson': unknown } } ? string : void : never }[keyof R] : void;
type Params<O> = O extends { parameters: infer P } ? P : never;
type PathParams<O> = Params<O> extends { path: infer P } ? P : never;
type QueryParams<O> = Params<O> extends { query?: infer Q } ? Q : never;
type Headers<O> = Params<O> extends { header: infer H } ? H : never;
type QueryOptions<O> = Params<O> extends { query: infer Q } ? { query: Q } : { query?: QueryParams<O> };
type RequestOptions<O> = (Body<O> extends never ? { body?: never } : { body: Body<O> })
  & (PathParams<O> extends never ? { path?: never } : { path: PathParams<O> })
  & (Headers<O> extends never ? { headers?: Record<string, string> } : { headers: Headers<O> & Record<string, string> })
  & QueryOptions<O> & { signal?: AbortSignal };

export class ApiError extends Error {
  constructor(readonly status: number, readonly envelope: ApiErrorEnvelope, readonly retryAfter: string | null = null) {
    super(envelope.error.message);
    this.name = 'ApiError';
  }
}

export interface ClientOptions {
  baseUrl: string;
  getAccessToken: () => string | null | Promise<string | null>;
  fetch?: typeof globalThis.fetch;
}

/** No automatic replay: caller owns stable idempotency keys and GPS batch identity. */
export class GeoApiClient {
  private readonly fetcher: typeof globalThis.fetch;
  constructor(private readonly options: ClientOptions) {
    this.fetcher = options.fetch ?? globalThis.fetch;
  }

  async request<M extends Method, P extends PathFor<M>>(
    method: M, path: P, options: RequestOptions<Operation<P, M>>,
  ): Promise<Success<Operation<P, M>>> {
    let route = String(path);
    const values = options.path as Record<string, string> | undefined;
    route = route.replace(/\{([^}]+)\}/g, (_, key: string) => {
      const value = values?.[key];
      if (!value) throw new Error(`Missing path parameter: ${key}`);
      return encodeURIComponent(value);
    });
    const url = new URL(`${this.options.baseUrl.replace(/\/$/, '')}${route}`);
    for (const [key, value] of Object.entries(options.query ?? {})) {
      if (value !== undefined && value !== null) url.searchParams.set(key, String(value));
    }
    const token = await this.options.getAccessToken();
    const response = await this.fetcher(url.toString(), {
      method: method.toUpperCase(),
      headers: { Accept: 'application/json', ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
      ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
      signal: options.signal,
      cache: 'no-store',
    });
    if (!response.ok) {
      let envelope: ApiErrorEnvelope = { error: { code: 'HTTP_ERROR', message: `Request failed (${response.status})` } };
      try {
        const candidate: unknown = await response.json();
        if (isErrorEnvelope(candidate)) envelope = candidate;
      } catch { /* Never expose raw server/proxy response or location payloads. */ }
      throw new ApiError(response.status, envelope, response.headers.get('Retry-After'));
    }
    if (response.status === 204 || response.headers.get('Content-Length') === '0') return undefined as Success<Operation<P, M>>;
    const text = await response.text();
    if (response.headers.get('Content-Type')?.includes('application/x-ndjson')) return text as Success<Operation<P, M>>;
    return (text.length ? JSON.parse(text) : undefined) as Success<Operation<P, M>>;
  }
}

function isErrorEnvelope(value: unknown): value is ApiErrorEnvelope {
  if (!value || typeof value !== 'object' || !('error' in value)) return false;
  const error = value.error;
  return !!error && typeof error === 'object' && 'code' in error && typeof error.code === 'string' && 'message' in error && typeof error.message === 'string';
}
