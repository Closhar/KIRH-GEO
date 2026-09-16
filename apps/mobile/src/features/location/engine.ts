import * as Location from 'expo-location';
import * as Battery from 'expo-battery';
import * as Network from 'expo-network';
import * as Crypto from 'expo-crypto';
import {api, ApiFailure} from '../../shared/api';
import {getSession} from '../../shared/session';
import {validateReceipt, type Receipt} from './receipt';
import {log} from '../../shared/logger';
import {intervals, retryDelay, type Mode, type Policy} from './policy';
import {acknowledge, batch, db, enqueue, pauseAndQueueRevoke, queueSize, setState, state, updateActive} from './queue';

export const LOCATION_TASK = 'kirh-consensual-location-v1';
let syncing: Promise<void> | undefined;

export async function startTracking(input: {workspaceId: string; grantId: string; revision: number; mode?: Mode; policy: Policy}): Promise<void> {
  const session = await getSession();
  if (!session) throw new Error('Войдите в приложение.');
  const previous = await state();
  if (previous?.enabled && previous.grantId !== input.grantId) await pauseTracking();
  if ((await Location.requestForegroundPermissionsAsync()).status !== 'granted') throw new Error('Геопозиция не разрешена. Вы можете продолжить без передачи.');
  if ((await Location.requestBackgroundPermissionsAsync()).status !== 'granted') throw new Error('Для передачи при закрытом экране разрешите фоновую геопозицию в настройках телефона.');
  await setState({...input, deviceId: session.device_id, enabled: true, mode: input.mode ?? 'normal', policyFetchedAt: Date.now()});
  try {await configureNative();}
  catch (error) {await pauseAndQueueRevoke(); throw error;}
}
async function configureNative(): Promise<void> {
  const current = await state();
  if (!current?.enabled) return;
  const level = await Battery.getBatteryLevelAsync();
  const charge = await Battery.getBatteryStateAsync();
  const timing = intervals(current.policy, current.mode, level, charge === Battery.BatteryState.CHARGING || charge === Battery.BatteryState.FULL);
  await Location.startLocationUpdatesAsync(LOCATION_TASK, {
    accuracy: ['sport', 'sos', 'live'].includes(current.mode) ? Location.Accuracy.High : Location.Accuracy.Balanced,
    timeInterval: timing.capture * 1000, distanceInterval: current.mode === 'idle' ? 100 : current.mode === 'normal' ? 25 : 5,
    deferredUpdatesInterval: timing.capture * 1000,
    pausesUpdatesAutomatically: current.mode === 'idle', showsBackgroundLocationIndicator: true,
    foregroundService: {notificationTitle: 'KIRH GEO — передача включена', notificationBody: 'Ваши координаты доступны выбранным людям. Остановить передачу можно в приложении.', notificationColor: '#56318F', killServiceOnDestroy: false},
  });
}
export async function pauseTracking(): Promise<{pendingRevocation: boolean}> {
  await pauseAndQueueRevoke();
  if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK)) await Location.stopLocationUpdatesAsync(LOCATION_TASK);
  try {await syncPending();} catch {/* Pending revoke remains durable and is shown in UI. */}
  const row = await (await db()).getFirstAsync<{count: number}>('SELECT count(*) AS count FROM controls');
  return {pendingRevocation: (row?.count ?? 0) > 0};
}
export async function setMode(mode: Mode, until?: string | number): Promise<void> {
  const current = await state();
  if (!current?.enabled) throw new Error('Сначала включите передачу.');
  if (current.mode === 'sos' && mode !== 'normal' && mode !== 'sos') return;
  const modeUntil = until === undefined ? undefined : typeof until === 'number' ? until : Date.parse(until);
  if (mode === 'live' && (!modeUntil || !Number.isFinite(modeUntil) || modeUntil <= Date.now())) throw new Error('Нужна активная live-сессия с временем завершения.');
  if (mode === 'sos' && current.mode !== 'sos') {
    await updateActive(current, {mode, modeUntil, resumeMode: current.mode, resumeUntil: current.modeUntil});
  } else if (mode === 'normal' && current.mode === 'sos') {
    const resume = current.resumeMode === 'live' && (!current.resumeUntil || current.resumeUntil <= Date.now()) ? 'normal' : current.resumeMode ?? 'normal';
    await updateActive(current, {mode: resume, modeUntil: current.resumeUntil, resumeMode: undefined, resumeUntil: undefined, sosEventId: undefined});
  } else await updateActive(current, {mode, modeUntil});
  await configureNative();
}
export async function getTrackingStatus(): Promise<{enabled: boolean; mode: Mode; queueSize: number; lastSyncAt?: number; error?: string; pendingSOS: boolean; sosEventId?: string}> {
  const current = await state();
  const pending = await (await db()).getFirstAsync<{count: number}>("SELECT count(*) AS count FROM controls WHERE path LIKE '%/sos'");
  return {enabled: current?.enabled ?? false, mode: current?.mode ?? 'normal', queueSize: await queueSize(), lastSyncAt: current?.lastSyncAt, error: current?.error, pendingSOS: (pending?.count ?? 0) > 0, sosEventId: current?.sosEventId};
}
export async function requestSOS(workspaceId: string): Promise<{pending: boolean; id?: string}> {
  const current = await state();
  if (!current?.enabled || current.workspaceId !== workspaceId) throw new Error('Для SOS сначала включите добровольную передачу в этом пространстве.');
  const connection = await db();
  const id = `sos:${workspaceId}`;
  await connection.runAsync('INSERT OR IGNORE INTO controls(id,path,method,value) VALUES(?,?,?,?)', id, `workspaces/${workspaceId}/sos`, 'POST', JSON.stringify({client_requested_at: Date.now(), idempotency_key: Crypto.randomUUID()}));
  log('warn', 'sos.delivery_queued', {workspace_id: workspaceId});
  try {await syncPending();} catch {/* Durable control remains pending; no delivery claim. */}
  const status = await getTrackingStatus();
  log(status.pendingSOS ? 'warn' : 'info', status.pendingSOS ? 'sos.delivery_pending' : 'sos.delivery_confirmed', {workspace_id: workspaceId});
  return {pending: status.pendingSOS, id: status.sosEventId};
}
export async function receiveLocations(locations: Location.LocationObject[]): Promise<void> {
  let current = await state();
  if (!current?.enabled) return;
  const permission = await Location.getForegroundPermissionsAsync();
  if (!permission.granted || Date.now() - current.policyFetchedAt > 86400000) {await pauseTracking(); return;}
  const level = await Battery.getBatteryLevelAsync();
  if (current.mode === 'live' && (!current.modeUntil || Date.now() >= current.modeUntil)) {
    current = await updateActive(current, {mode: 'normal', modeUntil: undefined});
    if (!current) return;
    await configureNative();
  }
  if (current.mode === 'normal' || current.mode === 'idle') {
    const moving = locations.some(point => (point.coords.speed ?? -1) >= 0.8);
    const lastMovementAt = moving ? Date.now() : current.lastMovementAt ?? Date.now();
    const mode = moving ? 'normal' : Date.now() - lastMovementAt >= 180000 ? 'idle' : current.mode;
    const previousMode = current.mode;
    current = await updateActive(current, {lastMovementAt, mode});
    if (!current) return;
    if (mode !== previousMode) await configureNative();
  }
  const captureState = current;
  const points = locations.filter(point => point.coords.accuracy !== null && point.coords.accuracy >= 0).map(point => ({
    client_point_id: Crypto.randomUUID(), captured_at: new Date(point.timestamp).toISOString(),
    latitude: point.coords.latitude, longitude: point.coords.longitude, accuracy_m: point.coords.accuracy!,
    battery_pct: level < 0 ? null : Math.round(level * 100), mode: captureState.mode, consent_revision: captureState.revision,
  }));
  await enqueue(points, current.revision);
  if (!current.lastSyncAt || Date.now() - current.lastSyncAt >= current.policy.modes[current.mode].upload_seconds * 1000) await syncPending();
}
export function syncPending(): Promise<void> {
  return syncing ??= sync().finally(() => {syncing = undefined;});
}
async function sync(): Promise<void> {
  const session = await getSession();
  if (!session || !(await Network.getNetworkStateAsync()).isConnected) return;
  const connection = await db();
  const controls = await connection.getAllAsync<{id: string; path: string; method: string; value: string | null}>('SELECT * FROM controls');
  for (const control of controls) {
    const value = control.value ? JSON.parse(control.value) : undefined;
    const isSOS = control.path.endsWith('/sos');
    if (isSOS && Date.now() - value.client_requested_at > 15 * 60000) {
      await connection.runAsync('DELETE FROM controls WHERE id=?', control.id);
      const latest = await state();
      if (latest) await updateActive(latest, {error: 'SOS не доставлен за 15 минут. При необходимости отправьте заново или позвоните 112.'});
      continue;
    }
    try {
      const response = await api<{id?: string}>(control.path, control.method, isSOS ? {} : value, isSOS ? value.idempotency_key : undefined);
      if (isSOS) {
        const latest = await state();
        if (latest?.enabled) {await setMode('sos'); await updateActive(latest, {sosEventId: response.id});}
        log('info', 'sos.delivery_confirmed', {workspace_id: latest?.workspaceId});
      }
    }
    catch (error) {if (!(error instanceof ApiFailure && error.status === 404)) throw error;}
    await connection.runAsync('DELETE FROM controls WHERE id=?', control.id);
  }
  let current = await state();
  if (!current?.enabled || current.deviceId !== session.device_id) return;
  if (Date.now() - current.policyFetchedAt > 60000) {
    const policy = await api<Policy>(`workspaces/${current.workspaceId}/location-policy`);
    current = await updateActive(current, {policy, policyFetchedAt: Date.now()});
    if (!current) return;
    await configureNative();
  }
  for (let i = 0; i < 5; i++) {
    if (!(await state())?.enabled) return;
    const pending = await batch(session.device_id);
    if (!pending || pending.retry_at > Date.now()) return;
    try {
      const receipt = await api<Receipt>('locations/batches', 'POST', pending.payload);
      await acknowledge(receipt.client_batch_id, validateReceipt(pending.payload, receipt));
      if (receipt.results.some(r => r.code === 'CONSENT_REVISION_CHANGED')) {
        await pauseAndQueueRevoke();
        if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK)) await Location.stopLocationUpdatesAsync(LOCATION_TASK);
        return;
      }
      const latest = await state();
      if (latest) await updateActive(latest, {lastSyncAt: Date.now(), error: receipt.results.some(r => r.status === 'permanently_rejected') ? 'Часть точек отклонена сервером.' : undefined});
    } catch (error) {
      if (error instanceof ApiFailure && [401, 403, 409].includes(error.status)) {
        await pauseAndQueueRevoke();
        if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK)) await Location.stopLocationUpdatesAsync(LOCATION_TASK);
      } else {
        await connection.runAsync('UPDATE batches SET attempt=attempt+1,retry_at=? WHERE id=?', Date.now() + retryDelay(pending.attempt, error instanceof ApiFailure ? error.retryAfterSeconds : 0), pending.payload.client_batch_id);
      }
      const latest = await state();
      if (latest) await updateActive(latest, {error: 'Нет подтверждения доставки. Очередь сохранена.'});
      return;
    }
  }
}
