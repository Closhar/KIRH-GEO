import {describe, expect, it} from 'vitest';
import {intervals, resolveMode, retryDelay, type Policy} from './policy';
import {validateReceipt} from './receipt';

const signals = {paused: false, permission: true, sos: false, sport: false, live: false, moving: true, battery: 1, charging: false};
const modes = {idle: {capture_seconds: 600, upload_seconds: 600}, normal: {capture_seconds: 60, upload_seconds: 60}, live: {capture_seconds: 5, upload_seconds: 5}, sport: {capture_seconds: 3, upload_seconds: 15}, sos: {capture_seconds: 3, upload_seconds: 3}};
const policy: Policy = {modes, history_retention_days: 7};
describe('consensual engine policy', () => {
  it('pause and OS denial win even during SOS', () => {
    expect(resolveMode({...signals, sos: true, paused: true})).toBe('stopped');
    expect(resolveMode({...signals, sos: true, permission: false})).toBe('stopped');
  });
  it('resolves mode priority and movement', () => {
    expect(resolveMode({...signals, sos: true, sport: true, live: true})).toBe('sos');
    expect(resolveMode({...signals, sport: true, live: true})).toBe('sport');
    expect(resolveMode({...signals, live: true})).toBe('live');
    expect(resolveMode({...signals, moving: false})).toBe('idle');
    expect(resolveMode(signals)).toBe('normal');
  });
  it('adapts battery without slowing SOS or ignoring server minimum', () => {
    expect(intervals(policy, 'normal', 0.1, false)).toEqual({capture: 180, upload: 180});
    expect(intervals(policy, 'normal', 0.1, true).capture).toBe(60);
    expect(intervals(policy, 'sos', 0.1, false).capture).toBe(3);
    expect(intervals(policy, 'normal', -1, false).capture).toBe(60);
  });
  it('backs off and honors Retry-After', () => {
    expect(retryDelay(0, 0, 1)).toBe(2000);
    expect(retryDelay(100, 0, 1)).toBe(300000);
    expect(retryDelay(1, 600, 0)).toBe(600000);
  });
});
describe('durable batch acknowledgment', () => {
  const batch = {client_batch_id: 'batch', points: [{client_point_id: 'a'}, {client_point_id: 'b'}]};
  const receipt = {client_batch_id: 'batch', results: [{client_point_id: 'a', status: 'accepted'}, {client_point_id: 'b', status: 'duplicate'}]};
  it('accepts exact complete receipt including safe permanent rejection', () => {
    expect(validateReceipt(batch, receipt)).toEqual(['a', 'b']);
    expect(validateReceipt(batch, {...receipt, results: receipt.results.map(r => ({...r, status: 'permanently_rejected'}))})).toEqual(['a', 'b']);
  });
  it('never clears partial, unrelated, duplicate or retryable acknowledgments', () => {
    expect(() => validateReceipt(batch, {...receipt, client_batch_id: 'other'})).toThrow();
    expect(() => validateReceipt(batch, {...receipt, results: receipt.results.slice(0, 1)})).toThrow();
    expect(() => validateReceipt(batch, {...receipt, results: [receipt.results[0], receipt.results[0]]})).toThrow();
    expect(() => validateReceipt(batch, {...receipt, results: receipt.results.map(r => ({...r, status: 'retryable'}))})).toThrow();
  });
});
