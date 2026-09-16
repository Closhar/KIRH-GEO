import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ShareSession, takeFragmentToken } from '../src/share-session';
import {takeIdentityAction} from '../src/action-fragment';

const token = 'a'.repeat(64);
const point = { latitude: 55.75, longitude: 37.61, accuracy_m: 8, battery_pct: 72, captured_at: '2026-09-15T10:00:00Z', received_at: '2026-09-15T10:00:01Z' };
const json = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), { status });

describe('temporary share privacy lifecycle', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('removes fragment/search before token can be used and rejects query tokens', () => {
    const replaceState = vi.fn();
    expect(takeFragmentToken({ hash: `#${token}`, pathname: '/share', search: '?campaign=unsafe' }, { replaceState })).toBe(token);
    expect(replaceState).toHaveBeenCalledWith(null, '', '/share');
    expect(takeFragmentToken({ hash: '', pathname: '/share', search: `?token=${token}` }, { replaceState })).toBeNull();
    expect(takeFragmentToken({ hash: '#broken', pathname: '/share', search: '' }, { replaceState })).toBeNull();
  });

  it('sends original token only in exchange body, never URL/referrer or location request', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json({ access_token: 'limited-secret', expires_in: 900 })).mockResolvedValueOnce(json(point));
    const session = new ShareSession(token, 'https://api.example.invalid/api/v1', fetcher);
    await session.open('123456');
    expect(session.getState().point).toEqual(point);
    const [exchangeUrl, exchangeOptions] = fetcher.mock.calls[0];
    expect(String(exchangeUrl)).not.toContain(token);
    expect(JSON.parse(exchangeOptions!.body as string)).toEqual({ token, passcode: '123456' });
    expect(exchangeOptions!.referrerPolicy).toBe('no-referrer');
    expect(exchangeOptions!.credentials).toBe('omit');
    const [currentUrl, currentOptions] = fetcher.mock.calls[1];
    expect(String(currentUrl)).not.toContain(token);
    expect(currentOptions!.headers).toEqual({ Accept: 'application/json', Authorization: 'Bearer limited-secret' });
    expect(currentOptions!.body).toBeUndefined();
    expect(currentOptions!.cache).toBe('no-store');
    session.close();
  });

  it('revocation clears coordinates and tokens and stops polling', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json({ access_token: 'limited-secret', expires_in: 900 })).mockResolvedValueOnce(json(point)).mockResolvedValueOnce(json(null, 404));
    const session = new ShareSession(token, '/api/v1', fetcher);
    await session.open();
    await vi.advanceTimersByTimeAsync(10000);
    expect(session.getState()).toMatchObject({ status: 'unavailable', point: null, expiresAt: null });
    await session.open();
    await vi.advanceTimersByTimeAsync(30000);
    expect(fetcher).toHaveBeenCalledTimes(3);
  });

  it('local expiry clears last known position even when offline', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json({ access_token: 'limited-secret', expires_in: 15 })).mockResolvedValueOnce(json(point)).mockRejectedValue(new Error('offline'));
    const session = new ShareSession(token, '/api/v1', fetcher);
    await session.open();
    await vi.advanceTimersByTimeAsync(10000);
    expect(session.getState().status).toBe('offline');
    await vi.advanceTimersByTimeAsync(5000);
    expect(session.getState()).toMatchObject({ status: 'expired', point: null });
  });

  it('late in-flight response cannot repopulate revoked coordinates', async () => {
    let resolve: (value: Response) => void = () => {};
    const pending = new Promise<Response>(done => { resolve = done; });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json({ access_token: 'limited-secret', expires_in: 900 })).mockReturnValueOnce(pending);
    const session = new ShareSession(token, '/api/v1', fetcher);
    const opening = session.open();
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
    session.close();
    resolve(json(point));
    await opening;
    expect(session.getState()).toMatchObject({ status: 'unavailable', point: null });
  });

  it('null position is an honest waiting state', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json({ access_token: 'limited-secret', expires_in: 900 })).mockResolvedValueOnce(json(null));
    const session = new ShareSession(token, '/api/v1', fetcher);
    await session.open();
    expect(session.getState()).toMatchObject({ status: 'active', point: null });
    session.close();
  });
});

describe('identity action fragment', () => {
  it('accepts only known actions with a 64-hex token and clears it immediately', () => {
    const replaceState = vi.fn();
    expect(takeIdentityAction({hash: `#action=reset_password&token=${token}`, pathname: '/account-action'}, {replaceState}))
      .toEqual({purpose: 'reset_password', token});
    expect(replaceState).toHaveBeenCalledWith(null, '', '/account-action');
    expect(takeIdentityAction({hash: `#action=admin&token=${token}`, pathname: '/'}, {replaceState})).toBeNull();
    expect(takeIdentityAction({hash: '#action=verify_email&token=bad', pathname: '/'}, {replaceState})).toBeNull();
  });
});
