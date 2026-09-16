export type Mode = 'idle' | 'normal' | 'live' | 'sport' | 'sos';
export interface Policy {modes: Record<Mode, {capture_seconds: number; upload_seconds: number}>; history_retention_days: number}
export interface EngineSignals {paused: boolean; permission: boolean; sos: boolean; sport: boolean; live: boolean; moving: boolean; battery: number; charging: boolean}
export function resolveMode(signals: EngineSignals): Mode | 'stopped' {
  if (signals.paused || !signals.permission) return 'stopped';
  if (signals.sos) return 'sos';
  if (signals.sport) return 'sport';
  if (signals.live) return 'live';
  return signals.moving ? 'normal' : 'idle';
}
export function intervals(policy: Policy, mode: Mode, battery: number, charging: boolean): {capture: number; upload: number} {
  const multiplier = battery >= 0 && battery < 0.2 && !charging && mode !== 'sos' ? 3 : 1;
  return {capture: Math.max(1, policy.modes[mode].capture_seconds) * multiplier, upload: Math.max(1, policy.modes[mode].upload_seconds) * multiplier};
}
export function retryDelay(attempt: number, retryAfterSeconds = 0, random = Math.random()): number {
  return Math.max(retryAfterSeconds * 1000, Math.min(300000, 2000 * 2 ** Math.min(attempt, 8)) * (0.5 + random / 2));
}
