export interface SharedPoint {
  latitude: number;
  longitude: number;
  captured_at: string;
  received_at: string;
  accuracy_m: number;
  battery_pct: number | null;
}
export type ShareState = {
  status: 'ready' | 'opening' | 'active' | 'offline' | 'unavailable' | 'expired' | 'invalid';
  point: SharedPoint | null;
  expiresAt: number | null;
  message: string;
};

/** Must run before loading maps or starting any API request. Query tokens are never accepted. */
export function takeFragmentToken(location: Pick<Location, 'hash' | 'pathname' | 'search'>, history: Pick<History, 'replaceState'>): string | null {
  const fragment = location.hash.replace(/^#/, '');
  // Strip all search/fragment content as well, to prevent accidental sharing/referrer leakage.
  history.replaceState(null, '', location.pathname);
  const candidate = fragment.startsWith('token=') ? fragment.slice(6) : fragment;
  return /^[a-f0-9]{64}$/i.test(candidate) ? candidate : null;
}

export class ShareSession {
  private token: string | null;
  private accessToken: string | null = null;
  private request: AbortController | null = null;
  private generation = 0;
  private pollTimer: ReturnType<typeof setTimeout> | null = null;
  private expiryTimer: ReturnType<typeof setTimeout> | null = null;
  private state: ShareState;
  private listeners = new Set<(state: ShareState) => void>();

  constructor(token: string | null, private readonly baseUrl: string, private readonly fetcher: typeof fetch = fetch, private readonly clock: () => number = Date.now) {
    this.token = token;
    this.state = { status: token ? 'ready' : 'invalid', point: null, expiresAt: null, message: '' };
  }

  getState = (): ShareState => this.state;
  subscribe = (listener: (state: ShareState) => void): (() => void) => {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  };

  private update(next: Partial<ShareState>) {
    this.state = { ...this.state, ...next };
    for (const listener of this.listeners) listener(this.state);
  }

  async open(passcode = ''): Promise<void> {
    if (!this.token || this.state.status === 'opening') return;
    const generation = ++this.generation;
    this.request?.abort();
    this.request = new AbortController();
    this.update({ status: 'opening', message: '' });
    try {
      const response = await this.fetcher(`${this.baseUrl}/shares/exchange`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token: this.token, ...(passcode ? { passcode } : {}) }),
        signal: this.request.signal, cache: 'no-store', referrerPolicy: 'no-referrer', credentials: 'omit',
      });
      if (generation !== this.generation) return;
      if (!response.ok) {
        if ([401, 403, 404, 410].includes(response.status)) {
          // Invalid passcode is deliberately indistinguishable from revoked link. Reopen the original link to retry.
          this.close('unavailable');
        } else {
          this.update({ status: 'ready', message: response.status === 429 ? 'Слишком много попыток. Подождите минуту.' : 'Не удалось открыть ссылку. Попробуйте ещё раз.' });
        }
        return;
      }
      const payload: unknown = await response.json();
      if (generation !== this.generation) return;
      if (!isExchange(payload)) throw new Error('Invalid response');
      this.token = null;
      this.accessToken = payload.data.access_token;
      const expiresAt = this.clock() + payload.data.expires_in * 1000;
      this.update({ status: 'active', expiresAt });
      this.expiryTimer = setTimeout(() => this.close('expired'), payload.data.expires_in * 1000);
      await this.poll();
    } catch {
      if (generation === this.generation) this.update({ status: 'ready', message: 'Нет связи с сервером. Проверьте интернет и повторите.' });
    }
  }

  private async poll(): Promise<void> {
    if (!this.accessToken) return;
    if (this.state.expiresAt !== null && this.clock() >= this.state.expiresAt) {
      this.close('expired');
      return;
    }
    const generation = this.generation;
    this.request = new AbortController();
    try {
      const response = await this.fetcher(`${this.baseUrl}/shares/current`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${this.accessToken}` },
        signal: this.request.signal, cache: 'no-store', referrerPolicy: 'no-referrer', credentials: 'omit',
      });
      if (generation !== this.generation) return;
      if ([401, 403, 404, 410].includes(response.status)) {
        this.close('unavailable');
        return;
      }
      if (!response.ok) throw new Error('Request failed');
      const payload: unknown = await response.json();
      if (generation !== this.generation) return;
      if (!isCurrent(payload)) throw new Error('Invalid response');
      this.update({ status: 'active', point: payload.data, message: '' });
    } catch {
      if (generation === this.generation) this.update({ status: 'offline', message: 'Обновления недоступны. Показана последняя полученная позиция.' });
    } finally {
      if (generation === this.generation && this.accessToken) this.pollTimer = setTimeout(() => void this.poll(), 10000);
    }
  }

  close(status: 'expired' | 'unavailable' = 'unavailable'): void {
    ++this.generation;
    this.token = null;
    this.accessToken = null;
    this.request?.abort();
    if (this.pollTimer) clearTimeout(this.pollTimer);
    if (this.expiryTimer) clearTimeout(this.expiryTimer);
    this.update({ status, point: null, expiresAt: null, message: '' });
  }
}

function isExchange(value: unknown): value is { data: { access_token: string; expires_in: number } } {
  if (!value || typeof value !== 'object' || !('data' in value) || !value.data || typeof value.data !== 'object') return false;
  const data = value.data;
  return 'access_token' in data && typeof data.access_token === 'string' && data.access_token.length > 0 && 'expires_in' in data && typeof data.expires_in === 'number' && data.expires_in > 0 && data.expires_in <= 900;
}

function isCurrent(value: unknown): value is { data: SharedPoint | null } {
  if (!value || typeof value !== 'object' || !('data' in value)) return false;
  if (value.data === null) return true;
  const point = value.data as Partial<SharedPoint> | undefined;
  return !!point && typeof point.latitude === 'number' && Number.isFinite(point.latitude) && Math.abs(point.latitude) <= 90 && typeof point.longitude === 'number' && Number.isFinite(point.longitude) && Math.abs(point.longitude) <= 180 && typeof point.accuracy_m === 'number' && Number.isFinite(point.accuracy_m) && point.accuracy_m >= 0 && typeof point.captured_at === 'string' && Number.isFinite(Date.parse(point.captured_at)) && typeof point.received_at === 'string' && Number.isFinite(Date.parse(point.received_at)) && (point.battery_pct === null || (typeof point.battery_pct === 'number' && point.battery_pct >= 0 && point.battery_pct <= 100));
}
