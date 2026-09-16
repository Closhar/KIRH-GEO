import * as SecureStore from 'expo-secure-store';
import * as Crypto from 'expo-crypto';

export interface Session {
  access_token: string;
  refresh_token: string;
  device_id: string;
  user?: {id: string; name: string; email?: string};
}
const SESSION_KEY = 'kirh.session.v1';
const BIOMETRIC_KEY = 'kirh.biometric.v1';
const options = {keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY};
export async function getSession(): Promise<Session | null> {
  const stored = await SecureStore.getItemAsync(SESSION_KEY);
  return stored ? JSON.parse(stored) as Session : null;
}
export async function saveSession(session: Session | null): Promise<void> {
  if (session) await SecureStore.setItemAsync(SESSION_KEY, JSON.stringify(session), options);
  else await SecureStore.deleteItemAsync(SESSION_KEY);
}
export async function installationId(): Promise<string> {
  let id = await SecureStore.getItemAsync('kirh.installation.v1');
  if (!id) {
    id = Crypto.randomUUID();
    await SecureStore.setItemAsync('kirh.installation.v1', id, options);
  }
  return id;
}

export async function getBiometricEnabled(): Promise<boolean> {
  const value = await SecureStore.getItemAsync(BIOMETRIC_KEY);
  return value === '1';
}

export async function setBiometricEnabled(enabled: boolean): Promise<void> {
  if (enabled) await SecureStore.setItemAsync(BIOMETRIC_KEY, '1', options);
  else await SecureStore.deleteItemAsync(BIOMETRIC_KEY);
}
