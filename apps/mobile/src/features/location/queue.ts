import * as SQLite from 'expo-sqlite';
import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import type {Mode, Policy} from './policy';

export interface TrackingState {enabled: boolean; workspaceId: string; deviceId: string; grantId: string; revision: number; mode: Mode; policy: Policy; policyFetchedAt: number; modeUntil?: number; resumeMode?: Mode; resumeUntil?: number; sosEventId?: string; lastSyncAt?: number; lastCapturedAt?: number; lastMovementAt?: number; error?: string}
export interface Point {client_point_id: string; captured_at: string; latitude: number; longitude: number; accuracy_m: number; battery_pct: number | null; mode: Mode; consent_revision: number}
export interface Batch {device_id: string; client_batch_id: string; points: Point[]}
let database: Promise<SQLite.SQLiteDatabase> | undefined;
function isDatabaseError(error: unknown): boolean {
  const text = String(error instanceof Error ? error.message : error);
  return text.includes("not a database") || text.includes("prepareAsync");
}
async function resetDatabase(): Promise<void> {
  database = undefined;
  try {
    await SQLite.deleteDatabaseAsync("kirh-geo-queue.db");
  } catch {
    // Ignore missing/locked files and attempt a fresh open.
  }
}
export function db(): Promise<SQLite.SQLiteDatabase> {
  if (database) return database;
  database = openDatabase().catch(async () => {
    database = undefined;
    try {
      await SQLite.deleteDatabaseAsync("kirh-geo-queue.db");
    } catch {
      // The corrupted file may already be missing or locked; a fresh open is still attempted.
    }
    return openDatabase();
  });
  return database;
}

async function openDatabase(): Promise<SQLite.SQLiteDatabase> {
    let key = await SecureStore.getItemAsync('kirh.queue.key.v1');
    if (!key) {
      key = Array.from(Crypto.getRandomBytes(32), b => b.toString(16).padStart(2, '0')).join('');
      await SecureStore.setItemAsync('kirh.queue.key.v1', key, {keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY});
    }
    if (!/^[0-9a-f]{64}$/.test(key)) throw new Error('Повреждён ключ локального хранилища.');
    const connection = await SQLite.openDatabaseAsync('kirh-geo-queue.db');
    await connection.execAsync(`PRAGMA key = "x'${key}'";`);
    const cipher = await connection.getFirstAsync<Record<string, unknown>>('PRAGMA cipher_version');
    if (!cipher || Object.keys(cipher).length === 0) throw new Error('Требуется сборка приложения с шифрованием SQLCipher.');
    await connection.execAsync('PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY CHECK(id=1), value TEXT NOT NULL); CREATE TABLE IF NOT EXISTS points (id TEXT PRIMARY KEY, value TEXT NOT NULL, created INTEGER NOT NULL, revision INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS batches (id TEXT PRIMARY KEY, value TEXT NOT NULL, attempt INTEGER NOT NULL DEFAULT 0, retry_at INTEGER NOT NULL DEFAULT 0); CREATE TABLE IF NOT EXISTS controls (id TEXT PRIMARY KEY, path TEXT NOT NULL, method TEXT NOT NULL, value TEXT);');
    return connection;
}
export async function state(): Promise<TrackingState | null> {
  try {
    const row = await (await db()).getFirstAsync<{value: string}>('SELECT value FROM state WHERE id=1');
    return row ? JSON.parse(row.value) as TrackingState : null;
  } catch (error) {
    if (!isDatabaseError(error)) throw error;
    await resetDatabase();
    const row = await (await db()).getFirstAsync<{value: string}>('SELECT value FROM state WHERE id=1');
    return row ? JSON.parse(row.value) as TrackingState : null;
  }
}
export async function setState(value: TrackingState): Promise<void> {
  try {
    await (await db()).runAsync('INSERT INTO state(id,value) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET value=excluded.value', JSON.stringify(value));
  } catch (error) {
    if (!isDatabaseError(error)) throw error;
    await resetDatabase();
    await (await db()).runAsync('INSERT INTO state(id,value) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET value=excluded.value', JSON.stringify(value));
  }
}
// Native tasks and UI run concurrently. A delayed network result must never undo a pause or overwrite a newer grant.
export async function updateActive(expected: TrackingState, patch: Partial<TrackingState>): Promise<TrackingState | null> {
  let updated: TrackingState | null = null;
  await (await db()).withExclusiveTransactionAsync(async tx => {
    const row = await tx.getFirstAsync<{value: string}>('SELECT value FROM state WHERE id=1');
    const current: TrackingState | null = row ? JSON.parse(row.value) : null;
    if (!current?.enabled || current.grantId !== expected.grantId || current.revision !== expected.revision || current.deviceId !== expected.deviceId) return;
    updated = {...current, ...patch, enabled: true, grantId: current.grantId, revision: current.revision, deviceId: current.deviceId};
    await tx.runAsync('UPDATE state SET value=? WHERE id=1', JSON.stringify(updated));
  });
  return updated;
}
export async function enqueue(points: Point[], revision: number): Promise<void> {
  await (await db()).withExclusiveTransactionAsync(async tx => {
    const row = await tx.getFirstAsync<{value: string}>('SELECT value FROM state WHERE id=1');
    const current: TrackingState | null = row ? JSON.parse(row.value) : null;
    if (!current?.enabled || current.revision !== revision) return;
    let lastCapturedAt = current.lastCapturedAt ?? 0;
    for (const point of [...points].sort((a, b) => Date.parse(a.captured_at) - Date.parse(b.captured_at))) {
      const capturedAt = Date.parse(point.captured_at);
      if (capturedAt - lastCapturedAt < current.policy.modes[point.mode].capture_seconds * 1000) continue;
      await tx.runAsync('INSERT OR IGNORE INTO points(id,value,created,revision) VALUES(?,?,?,?)', point.client_point_id, JSON.stringify(point), capturedAt, revision);
      lastCapturedAt = capturedAt;
    }
    await tx.runAsync('UPDATE state SET value=? WHERE id=1', JSON.stringify({...current, lastCapturedAt}));
    await tx.runAsync('DELETE FROM points WHERE created < ?', Date.now() - 72 * 3600000);
    await tx.execAsync('DELETE FROM points WHERE id IN (SELECT id FROM points ORDER BY created DESC LIMIT -1 OFFSET 10000)');
  });
}
export async function batch(deviceId: string): Promise<{payload: Batch; attempt: number; retry_at: number} | null> {
  let result: {payload: Batch; attempt: number; retry_at: number} | null = null;
  await (await db()).withExclusiveTransactionAsync(async tx => {
    const existing = await tx.getFirstAsync<{value: string; attempt: number; retry_at: number}>('SELECT * FROM batches LIMIT 1');
    if (existing) {result = {payload: JSON.parse(existing.value), attempt: existing.attempt, retry_at: existing.retry_at}; return;}
    const rows = await tx.getAllAsync<{value: string}>('SELECT value FROM points ORDER BY created LIMIT 100');
    if (!rows.length) return;
    const payload: Batch = {device_id: deviceId, client_batch_id: Crypto.randomUUID(), points: rows.map(r => JSON.parse(r.value))};
    await tx.runAsync('INSERT INTO batches(id,value) VALUES(?,?)', payload.client_batch_id, JSON.stringify(payload));
    result = {payload, attempt: 0, retry_at: 0};
  });
  return result;
}
export async function acknowledge(batchId: string, ids: string[]): Promise<void> {
  await (await db()).withExclusiveTransactionAsync(async tx => {
    for (const id of ids) await tx.runAsync('DELETE FROM points WHERE id=?', id);
    await tx.runAsync('DELETE FROM batches WHERE id=?', batchId);
  });
}
export async function pauseAndQueueRevoke(): Promise<void> {
  await (await db()).withExclusiveTransactionAsync(async tx => {
    const row = await tx.getFirstAsync<{value: string}>('SELECT value FROM state WHERE id=1');
    if (row) {
      const previous: TrackingState = JSON.parse(row.value);
      await tx.runAsync('UPDATE state SET value=? WHERE id=1', JSON.stringify({...previous, enabled: false}));
      await tx.runAsync('INSERT OR IGNORE INTO controls(id,path,method) VALUES(?,?,?)', previous.grantId,
        `workspaces/${previous.workspaceId}/sharing-grants/${previous.grantId}`, 'DELETE');
    }
    await tx.execAsync('DELETE FROM points; DELETE FROM batches;');
  });
}
export async function queueSize(): Promise<number> {return (await (await db()).getFirstAsync<{count: number}>('SELECT count(*) AS count FROM points'))?.count ?? 0;}
